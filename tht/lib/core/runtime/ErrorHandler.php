<?php

// TODO: separate print functions to a separate module and only include when needed

namespace o;

class ThtException extends \Exception {
    function u_message () {
        return $this->getMessage();
    }
}

class StartupException extends \Exception {
}

class ErrorHandler {

    /// Handlers

    static private $trapErrors = false;
    static private $trappedError = null;
    static private $errorDoc = null;

    static function startTrapErrors() {
        ErrorHandler::$trapErrors = true;
        ErrorHandler::$trappedError = null;
    }

    static function endTrapErrors() {
        ErrorHandler::$trapErrors = false;
        return ErrorHandler::$trappedError;
    }

    static function setErrorDoc($link, $name) {
        $link = str_replace('o\\u_', '', $link);
        $name = str_replace('o\\u_', '', $name);
        self::$errorDoc = ['link' => $link, 'name' => $name];
    }

    static function handlePhpRuntimeError ($severity, $message, $phpFile, $phpLine) {

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // PHP 5.6 - missing argument
        if (preg_match('/missing argument/i', $message)) {
            array_shift($trace);
            $phpFile = $trace[0]['file'];
            $phpLine = $trace[0]['line'];
        }

        ErrorHandler::printError([
            'type'    => 'RuntimeError',
            'message' => $message,
            'phpFile' => $phpFile,
            'phpLine' => $phpLine,
            'trace'   => $trace
        ]);
    }

    static function handleShutdown () {

        $error = error_get_last();

        if (!$error) {
            return;
        }

        $types = [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
        if (!in_array($error['type'], $types)) {
            return;
        }

        self::handleResourceErrors($error);

        $trace = self::parseInlineTrace($error['message']);

        // PHP 7
        // TODO: PHP5: strpos($error['message'], 'Missing argument') !== false
        if (strpos($error['message'], 'ArgumentCountError') !== false) {

            preg_match('/Too few arguments to function \\S+\\\\(.*?\\(\\))/i', $error['message'], $callee);
            preg_match('/passed in (\S+?) on line (\d+)/i', $error['message'], $caller);

            // TODO: file and line refer to signature line

            if ($caller) {
                $error['message'] = "Not enough arguments passed to `" . $callee[1] . "`";
                $error['file'] = $caller[1];
                $error['line'] = $caller[2];
            }
        }

        if (strpos($error['message'], 'TypeError') !== false) {
            if (count($trace)) {
                $error['file'] = $trace[0]['file'];
                $error['line'] = $trace[0]['line'];
            }
        }

        ErrorHandler::printError([
            'type'    => 'ThtRuntimeError',
            'message' => $error['message'],
            'phpFile' => $error['file'],
            'phpLine' => $error['line'],
            'trace'   => $trace,
        ]);
    }

    static function handleResourceErrors($error) {
        // Show minimal error message for memory and execution errors.
        preg_match('/Allowed memory size of (\d+)/i', $error['message'], $m);
        if ($m) {
            $max = Tht::getConfig('memoryLimitMb');
            print "<b>Page Error: Max memory limit exceeded ($max MB).  See `memoryLimitMb` in `app.jcon`.</b>";
            Tht::exitScript(1);
        }

        preg_match('/Maximum execution time of (\d+)/i', $error['message'], $m);
        if ($m) {
            $max = Tht::getConfig('maxExecutionTimeSecs');
            print "<b>Page Error: Max execution time exceeded ($max seconds).  See `maxExecutionTimeSecs` in `app.jcon`.</b>";
            Tht::exitScript(1);
        }
    }

    // Errors not related to a source file (e.g. config errors)
    static function handleConfigError ($message) {
        ErrorHandler::printError([
            'type'    => 'ThtConfigError',
            'message' => $message,
            'phpFile' => '',
            'phpLine' => 0,
            'trace'   => null
        ]);
    }

    // Triggered by Tht::error
    static function handleThtException ($error, $sourceFile) {

        $trace = $error->getTrace();
        $frame = [];

        foreach ($trace as $f) {
            if (!isset($f['file'])) {
                $f['file'] = '(anon)';
            }
            if (strpos($f['file'], '.tht') !== false) {
                $frame = $f;
                break;
            }
        }
        ErrorHandler::printError([
            'type'    => 'ThtException',
            'message' => $error->getMessage(),
            'phpFile' => isset($f['file']) ? $f['file'] : '',
            'phpLine' => isset($f['line']) ? $f['line'] : '',
            'trace'   => $trace
        ]);
    }

    // PHP exception during startup
    static function handleStartupException ($error) {

        $phpFile = $error->getFile();
        $phpLine = $error->getLine();
        $message = $error->getMessage();

        preg_match("/with message '(.*)' in \//i", $message, $match);
        $msg = (isset($match[1]) ? $match[1] : $message);

        print '<h2>Startup Error</h2>' . $message;
        Tht::exitScript(1);
    }

    // PHP exception - in theory, this should never leak through to end users
    static function handlePhpLeakedException ($error) {

        $phpFile = $error->getFile();
        $phpLine = $error->getLine();
        $message = $error->getMessage();

        preg_match("/with message '(.*)' in \//i", $message, $match);
        $msg = (isset($match[1]) ? $match[1] : $message);

        ErrorHandler::printError([
            'type'    => 'PhpException',
            'message' => $message,
            'phpFile' => $phpFile,
            'phpLine' => $phpLine,
            'trace'   => $error->getTrace(),
            '_rawTrace' => true
        ]);
    }

    static function handlePhpParseError ($msg) {

        $matches = [];
        $found = preg_match('/(\S*?) on line (\d+)/', $msg, $matches);
        if (!$found) {
            Tht::error($msg);
        }
        $phpFile = $matches[1];
        $phpLine = $matches[2];

        $found = preg_match('/:(.*) in/', $msg, $matches);
        $phpMsg = $found ? trim($matches[1]) : '';

        ErrorHandler::printError([
            'type'    => 'PhpParserError',
            'message' => $phpMsg,
            'phpFile' => $phpFile,
            'phpLine' => $phpLine,
            'trace'   => null
        ]);
    }

    static function handleCompilerError ($msg, $srcToken, $srcFile, $isLineError=false) {

        $srcPos = explode(',', $srcToken[TOKEN_POS]);
        $src = [
            'file' => $srcFile,
            'line' => $srcPos[0],
            'pos'  => $isLineError ? -1 : $srcPos[1]
        ];

        ErrorHandler::printError([
            'type'    => 'ThtParserError',
            'message' => $msg,
            'phpFile' => '',
            'phpLine' => '',
            'trace'   => null,
            'src'    => $src
        ]);
    }

    static function handleJconError ($msg, $srcFile, $lineNum, $line) {

        $src = [
            'file' => $srcFile,
            'line' => $lineNum,
            'pos'  => null,
            'srcLine' => trim($line),
        ];

        ErrorHandler::printError([
            'type'    => 'JconParserError',
            'message' => $msg,
            'phpFile' => '',
            'phpLine' => '',
            'trace'   => null,
            'src'     => $src,
        ]);
    }



    /////////  PRINT

    static function printError ($error, $logOut='') {

        if (Compiler::isSandboxMode()) {
            throw new \Exception ('[Sandbox] ' . $error['message']);
        }

        if (ErrorHandler::$trapErrors) {
            ErrorHandler::$trappedError = $error;
            return;
        }

        $eh = new ErrorHandler();

        $prepError = $eh->prepError($error);
        $plainOut = $eh->formatError($prepError);

        if (Tht::isMode('cli')) {
            $eh->printToConsole($plainOut);
        } else {
            if ($eh->doDisplayWebErrors() || $error['type'] == 'ThtConfigError') {
                $eh->printToWeb($prepError);
            } else {
                if (!$logOut) { $logOut = $plainOut; }
                $eh->printToLog($logOut);
                file_put_contents('php://stderr', $plainOut . "\n\n");
                Tht::module('Response')->u_send_error(500);
            }
        }

        $eh->saveErrorTelemetry($prepError);

        Tht::exitScript(1);
    }

    function prepError($error) {

        if (preg_match('/TypeError/', $error['message'])) {
            $error['type'] = 'Argument Checker';
        }

        $error['message'] = $this->cleanMessage($error['message']);

        if (!isset($error['src']) && $error['phpFile']) {
            $error['src'] = self::phpToSrc($error['phpFile'], $error['phpLine']);
        }

        $error['srcLine'] = '';
        if (isset($error['src'])) {
            if (isset($error['src']['srcLine'])) {
                $error['srcLine'] = $error['src']['srcLine'];
            } else {
                $error['srcLine'] = $this->getSourceLine($error['src']['file'], $error['src']['line'], $error['src']['pos']);
                if ($error['src']['file']) {
                    $error['src']['filePath'] = $error['src']['file'];
                    $error['src']['file'] = $this->cleanPath($error['src']['file']);
                }
            }
        }

        if ($error['trace']) {
            $forcePhp = isset($error['_rawTrace']) ? $error['_rawTrace'] : false;
            $error['trace'] = $this->cleanTrace($error['trace'], $forcePhp);
        }

        // e.g. Format Checker
        if (preg_match('/^\(([a-zA-Z ]+)\)/', $error['message'], $m)) {
            $error['message'] = preg_replace('/^\([a-zA-Z ]+\)/', '', $error['message']);
            $error['type'] = str_replace(' ', '', $m[1]);
        }

        if ($error['type'] == 'Template') {
            $error['type'] = 'Template Parser';
        }

        return $error;
    }

    function formatError ($error) {

        $out = "######### " . $error['type'] . " #########\n\n";
        $out .= $error['message'];
        if (isset($error['srcLine'])) {
            $out .= "\n\n" . $error['srcLine'];
        }

        $src = isset($error['src']) ? $error['src'] : null;
        if ($error['trace']) {
            $out .= "\n" . $error['trace'];
        } else if ($src['file']) {
            $out .= "\n\nFile: " . $src['file'] . "  Line: " . $src['line'];
            if (isset($src['pos'])) {
                $out .= "  Pos: " . $src['pos'];
            }
            $out .= "\n\n";
        }

        return $out;
    }

    function printToLog ($msg) {

        Tht::errorLog($msg);
    }

    function printToWeb ($error) {

        // Format heading
        $heading = v(v($error['type'])->u_to_token_case(' '))->u_to_title_case();
        $heading = preg_replace('/php/i', 'PHP', $heading);
        $heading = preg_replace('/tht/i', 'THT', $heading);

        $error['message'] = Security::escapeHtml($error['message']);
        $error['srcLine'] = Security::escapeHtml($error['srcLine']);

        if ($error['type'] == 'FormatChecker') {
            $error['context'] = 'See: <a href="https://tht-lang.org/reference/format-checker">Format Checker</a>';
        }

        $error['isLongSrc'] = strlen(rtrim($error['srcLine'], "^ \n")) > 50;

        // convert backticks to code
        $error['message'] = preg_replace("/`(.*?)`/", '<span class="tht-error-code">$1</span>', $error['message']);

        // Put hints on a separate line
        $error['message'] = preg_replace("/(Try|See|Got):(.*?)/", '<br /><br />$1: $2', $error['message']);

        if (!is_null(self::$errorDoc)) {
            $url = 'https://tht-lang.org' . self::$errorDoc['link'] . '?fromError=1';
            $error['message'] .= "<br /><br />Manual: <a href=\"$url\">" . self::$errorDoc['name'] . "</a>";
        }

        // format caret, wrap for color coding
        if ($error['srcLine']) {
            $error['srcLine'] = preg_replace("/\^$/", '</span><span class="tht-caret">&uarr;</span>', $error['srcLine']);
            $error['srcLine'] = '<span class="tht-color-code theme-dark">' . $error['srcLine'];
        }

        $this->printWebTemplate($heading, $error);

        $plugin = Tht::module('Js')->u_plugin('colorCode', 'dark');
        $colorJs = Tht::module('Js')->wrap($plugin->u_stringify());
        print($colorJs);
    }

    function printWebTemplate($heading, $error) {

        $zIndex = 99998;  // one less than print layer
        $cssMod = Tht::module('Css');

        $fmtFile = '';
        if (isset($error['src'])) {
            $fmtFile = preg_replace('#(.*/)(.*)#', '<span class="tht-error-dir">$1</span>$2', $error['src']['file']);
        }

        ?>

        <div style='position: fixed; overflow: auto; z-index: <?= $zIndex ?>; background-color: #333; color: #eee; margin: 0; top: 0; left: 0; right: 0; bottom: 0; color: #fff; padding: 32px 64px; -webkit-font-smoothing: antialiased;'>
            <style scoped>
                a { color: #ffd267; text-decoration: none; }
                a:hover { text-decoration: underline;  }
                .tht-error-header { font-weight: bold; margin-bottom: 32px; font-size: 140%; border-bottom: solid 4px #ecc25f; padding-bottom: 12px;  }
                .tht-error-message { margin-bottom: 32px; }
                .tht-error-content { font: 22px <?= $cssMod->u_font('sansSerif') ?>; line-height: 1.3; z-index: 1; position: relative; margin: 0 auto; max-width: 700px; }
                .tht-error-hint {   margin-top: 64px; line-height: 2; opacity: 0.5; font-size: 80%; }
                .tht-error-srcline { font-size: 90%; border-radius: 4px; margin-bottom: 32px; padding: 24px 24px 24px; background-color: #282828; white-space: pre; font-family: <?= $cssMod->u_font('monospace') ?>; overflow: auto; }
                .tht-src-small { font-size: 65%; }
                .tht-error-trace { font-size: 70%; border-radius: 4px; margin-bottom: 32px; margin-top: -28px; padding: 24px 24px; background-color: #282828; white-space: pre; line-height: 150%; font-family: <?= $cssMod->u_font('monospace') ?>; }
                .tht-caret { color: #eac222; font-size: 30px; position: relative; left: -3px; top: 2px; line-height: 0; }
                .tht-src-small .tht-caret { font-size: 24px; }
                .tht-error-file { margin-bottom: 32px; border-top: solid 1px rgba(255,255,255,0.1); padding-top: 32px; }
                .tht-error-file .tht-error-dir { opacity: 0.5; margin: 0;  }
                .tht-error-file span { margin-right: 32px; margin-left: 4px; font-size: 105%; color: inherit; }
                .tht-error-code {  display: inline-block; margin: 4px 0; border-radius: 4px; font-size: 90%; font-weight: bold; font-family: <?= $cssMod->u_font('monospace') ?>; background-color: rgba(255,255,255,0.1); padding: 2px 8px; }
            </style>

            <div class='tht-error-content'>

                <div class='tht-error-header'><?= $heading ?></div>
                <div class='tht-error-message'><?= $error['message'] ?></div>

                <?php if (isset($error['src'])) { ?>
                <div class='tht-error-file'>
                    File: <span><?= $fmtFile ?></span>
                </div>
                <?php } ?>

                <?php if ($error['srcLine']) { ?>
                <div class='tht-error-srcline <?= $error['isLongSrc'] ? 'tht-src-small' : '' ?>'><?= $error['srcLine'] ?></div>
                <?php } ?>

                <?php if ($error['trace']) { ?>
                <div class='tht-error-trace'><?= $error['trace'] ?></div>
                <?php } ?>

                <?php if (isset($error['context'])) { ?>
                <div class='tht-error-context'><?= $error['context'] ?></div>
                <?php } ?>

                <?php self::printPrintBuffer() ?>

            </div>
        </div>

        <?php
    }

    function printToConsole ($out) {
        $out = "\n\n" . str_repeat('`', 80) . "\n\n" . $out;
        print $out;
    }

    function printPrintBuffer() {
        if (PrintBuffer::hasItems()) {
            PrintBuffer::flush(true);
            print "<style> .tht-print-panel { color: inherit; width: auto; box-shadow: none; background-color: #282828; position: relative; } </style>";
        }

    }



    /////////  UTILS

    static function parseInlineTrace($message) {
        if (!preg_match('/Stack trace:/i', $message)) {
            return null;
        }

        $trace = [];
        // example:
        // #0 /dir/cache/php/00300703_pages_home.tht.php(123): tht\pages\home_x\u_do_something('a')
        preg_match_all('/#\d+\s+(\S+?)\((\d+)\):\s+(\S+?)\n/', $message, $lines, PREG_SET_ORDER);
        foreach($lines as $line) {
            $fun = preg_replace('/\(.*\)/', '', $line[3]);
            $frame = [
                'file' => $line[1],
                'line' => $line[2],
                'function' => $fun,
            ];
            $trace []= $frame;
        }
        return $trace;
    }

    static function phpToSrc ($phpFile, $phpLine) {

        $phpCode = file_get_contents($phpFile);
        $phpLines = explode("\n", $phpCode);
        $phpLines = array_reverse($phpLines);
        foreach ($phpLines as $l) {
            if (substr($l, 0, 2) === '/*') {
                $match = [];
                $found = preg_match('/SOURCE=(\{.*})/', $l, $match);
                if ($found) {
                    $json = $match[1];
                    $map = json_decode($json, true);
                    if (isset($map[$phpLine])) {
                        $src = [ 'file' => $map['file'], 'line' => $map[$phpLine], 'pos' => null ];
                        return $src;
                    }
                    break;
                }
            }
        }
        return [ 'line' => $phpLine, 'file' => $phpFile, 'pos' => null ];
    }

    function getSourceLines($srcPath, $srcLineNum1) {

        if (Tht::module('File')->u_is_relative_path($srcPath)) {
            $srcPath = Tht::path('app', $srcPath);
        }

        $source = file_get_contents($srcPath);
        $lines = preg_split('/\n/', $source);

        return $lines;
    }

    function getSourceLine ($srcPath, $srcLineNum1, $pos=null) {

        $srcLineNum0 = $srcLineNum1 - 1;  // convert to zero-index

        $lines = $this->getSourceLines($srcPath, $srcLineNum1);
        $line = (count($lines) > $srcLineNum0) ? $lines[$srcLineNum0] : '';

        // have to convert to spaces for pointer to line up
        $line = preg_replace('/\t/', '    ', $line);

        // trim indent
        preg_match('/^(\s*)/', $line, $matches);
        $numSpaces = strlen($matches[1]);
        $line = preg_replace('/^(\s*)/', '', $line);
        if (!trim($line)) { return ''; }
        $prefix = '' . $srcLineNum1 . ':  ';

        // make sure pointer is visible in long lines
        if (strlen($line) > 50 && $pos > 50) {
            $trimNum = abs(50 - strlen($line));
            $line = substr($line, $trimNum);
            $pos -= $trimNum;
            $prefix .= '... ';
        }

        $fmtLine = $prefix . $line;

        // pos marker
        $marker = "\n";
        if ($pos !== null && $pos >= $numSpaces && preg_match('/\S/', $line)) {
            $pointerPos = max($pos - ($numSpaces + 1) + strlen($prefix), 0);
            $fmtLine .= "\n";
            $marker = str_repeat(' ', $pointerPos) . '^';
        }

        return $fmtLine . $marker;
    }

    function cleanMessage ($raw) {

        $clean = $raw;
        $clean = $this->cleanVars($clean);

        $clean = str_replace('foreach()', 'for()', $clean);
        $clean = str_replace('supplied for', 'in', $clean);

        // Suppress leaked stack trace
        $clean = preg_replace('/stack trace:.*/is', '', $clean);

        // Make PHP error messages easier to read
        $clean = preg_replace('/Call to undefined function (.*)\(\)/', 'Unknown function: `$1`', $clean);
        $clean = preg_replace('/Call to undefined method (.*)\(\)/', 'Unknown method: `$1`', $clean);
        $clean = preg_replace('/Missing argument (\d+) for (.*)\(\)/', 'Missing argument $1 for `$2()`', $clean);
        $clean = preg_replace('/\{closure\}/i', '{function}', $clean);
        $clean = preg_replace('/callable/i', 'function', $clean);
        $clean = preg_replace('/, called.*/', '', $clean);
        $clean = preg_replace('/preg_\w+\(\)/', 'Regex Pattern', $clean);
        $clean = preg_replace('/\(T_.*?\)/', '', $clean);

        if (preg_match("/function '(.*?)' not found or invalid function name/i", $clean, $m)) {
            $clean = "PHP function does not exist: `" . $m[1] . "`";
        }

        // TODO: link to timezone list. Make this a Config Error with source line.
        if (preg_match("/Timezone ID '(.*?)' is invalid/i", $clean, $m)) {
            $clean = "Timezone in `settings/app.jcon` is invalid: `" . $m[1] . "`";
        }

        // Convert internal name conventions
        $clean = preg_replace('/<<<.*?\/(.*?)>>>/', '$1', $clean);
        $clean = preg_replace('/O(list|map|regex)/', '$1', $clean);

        // PHP7 errors
        if (preg_match('/TypeError/', $clean)) {
            $clean = preg_replace('/passed to (\S+)/i', 'passed to `$1`', $clean);
            $clean = preg_replace('/of the type (.*?),/i', 'of type `$1`.', $clean);
            $clean = preg_replace('/\.\s*(.*?) given/i', '. Got: `$1`', $clean);
        }
        $clean = preg_replace('/Uncaught TypeError:\s*/i', '', $clean);
        $clean = preg_replace('/Uncaught error:\s*/i', '', $clean);
        $clean = preg_replace('/in .*?.php:\d+/i', '', $clean);
        $clean = preg_replace('/[a-z_]+\\\\/i', '', $clean);  // namespaces

        if (preg_match('/Syntax error, unexpected \'return\'/i', $clean)) {
            $clean = 'Invalid statement at end of function.  Missing `return`?';
        }

        // Strip root directory from paths
        $clean = str_replace(Tht::path('files') . '/', '', $clean);
        $clean = str_replace(Tht::path('app') . '/', '', $clean);

        $clean = ucfirst($clean);

        return $clean;
    }

    function cleanVars ($raw) {

        $fnCamel = function ($m) {
            return v($m[1])->u_to_camel_case();
        };

        $clean = $raw;
        $clean = preg_replace('/o\\\\/', '', $clean);  // o namespace
        $clean = preg_replace('/tht.*?\\\\/', '', $clean); // tht namespage
        $clean = preg_replace_callback('/u_([a-z_]+)/', $fnCamel, $clean);  // user methods
        $clean = preg_replace('/(?<=\w)::/', '.', $clean);  // :: to dot .
        $clean = preg_replace('/->/', '.', $clean);  // :: to dot .
        $clean = preg_replace('/\bO(?=[A-Z][a-z])/', '', $clean);  // internal classes e.g. "OString"
        $clean = preg_replace('/\bu_/', '', $clean);  // u_ prefix
        $clean = preg_replace('#[a-zA-Z0-9_\\\\]*\\\\#', '', $clean);  // no namespace

        return $clean;
    }

    function cleanPath ($path) {
        $path = Tht::stripAppRoot($path);
        return $path;
    }

    function cleanTrace ($trace, $showPhp=false) {

        $out = '';

        $filterTrace = [];
        foreach ($trace as $phpFrame) {
            if (! isset($phpFrame['file'])) { continue; }

            $phpFrame['class'] = isset($phpFrame['class']) ? $phpFrame['class'] : '';
            $phpFrame['function'] = $fun = $this->cleanVars($phpFrame['function']);

            // Only show internal frames in core dev mode
            if (!Tht::getConfig('_coreDevMode') && !$showPhp) {
                if ($phpFrame['class'] === 'o\\OTemplate'
                    || $phpFrame['class'] === 'o\\Tht'
                    || strpos($phpFrame['file'], '.tht') === false
                    || substr($fun, 0, 2) === '__') {

                    continue;
                }
            }
            $filterTrace []= $phpFrame;
        }

        if (!count($filterTrace)) {
            return "";
        }

        $frameNum = 0;
        foreach (array_reverse($filterTrace) as $phpFrame) {

            $file = $this->cleanPath(Tht::getThtPathForPhp($phpFrame['file']));
            $file = preg_replace('/\.tht$/', '', $file);

            $frameNum += 1;

            $cl = $phpFrame['class'];
            $fun = $phpFrame['function'];

            if (OBare::isa($fun)) {
                $cl = '';
            }
            else if ($fun === 'handlePhpRuntimeError') {
                $fun = '';
            }
            else if ($phpFrame['class'] ) {
                $fun = $this->cleanVars($phpFrame['class'] ) . '.' . $fun;
            }

            $src = self::phpToSrc($phpFrame['file'], $phpFrame['line']);

            $lineMsg = abs($src['line']) . ($src['line'] > 0 ? '' : '(?)');
            $fun = !$fun ? '' : "· $fun()";

            $pre = $frameNum == count($filterTrace) ? '+' : '|';

            $out .= "$pre  $file · $lineMsg $fun\n";
        }

        $out = "|  start\n" . $out;

        return trim("- TRACE -\n\n" . $out);
    }

    function doDisplayWebErrors () {
        if (Security::isAdmin()) {
            return true;
        }
        return Compiler::getAppCompileTime() > time() - Tht::getConfig('showErrorPageForMins') * 60;
    }

    // only send if error is followed by a good compile
    function saveErrorTelemetry($error) {

        if (!isset($error['src'])) {
            return;
        }

        $cacheKey = 'tht.lastError.' . $error['src']['file'];

        $prevError = Tht::module('Cache')->u_get($cacheKey, '');
        if ($prevError && $prevError['message'] == $error['message']) {
            return;
        }

        $srcLine = preg_replace('/^\d+:\s*/', '', $error['srcLine']);
        $srcLine = preg_replace('/\s*\^\s*$/', '', $srcLine);

        $sendError = [
            'type'    => $error['type'],
            'time'    => time(),
            'srcFile' => $error['src']['file'],
            'srcLine' => $srcLine,
            'message' => $error['message'],
        ];

        Tht::module('Cache')->u_set($cacheKey, $sendError, 0);
    }

    static function sendErrorTelemetry($thtFile) {

        if (!Compiler::getDidCompile() || !Tht::getConfig('sendErrors')) {
            return;
        }

        $relPath = Tht::getRelativePath('app', $thtFile);
        $cacheKey = 'tht.lastError.' . $relPath;
        $error = Tht::module('Cache')->u_get($cacheKey, '');
        if (!$error) {
            return;
        }

        Tht::module('Cache')->u_delete($cacheKey);

        require_once(__DIR__ . '/../compiler/SourceAnalyzer.php');

        $sa = new SourceAnalyzer ($thtFile);
        $stats = $sa->getCurrentStats();

        $mergeStats = [
            'linesInFile'      => $stats['numLines'],
            'functionsInFile'  => $stats['numFunctions'],
            'linesPerFunction' => $stats['numLinesPerFunction'],
            'totalWorkTime'    => $stats['totalWorkTime'],
            'numCompiles'      => $stats['numCompiles'],
        ];

        $error = array_merge($error, $mergeStats);

        $error['fixDurationSecs'] = time() - $error['time'];
        $error['thtVersion'] = Tht::getThtVersion(true);
        $error['phpVersion'] = PHP_VERSION_ID;

        // Get local OS
        $os = strtolower(PHP_OS);
        if (substr($os, 0, 3) == 'win') {
            $os = 'windows';
        } else if ($os == 'darwin') {
            $os = 'mac';
        }
        $error['os'] = $os;

        try {
            $tUrl = new UrlTypeString(Tht::getConfig('_sendErrorUrl'));
            Tht::module('Net')->u_http_post($tUrl, OMap::create($error));
        }
        catch (\Exception $e) {
            // Drop on floor
        }
    }
}
