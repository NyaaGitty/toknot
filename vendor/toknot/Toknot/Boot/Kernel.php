<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Boot;

use Toknot\Boot\Tookit;
use Toknot\Boot\Import;
use Toknot\Boot\Object;
use Toknot\Boot\Configuration;
use Toknot\Exception\BaseException;
use Toknot\Exception\ShutdownException;
use Toknot\Boot\Logs;

final class Kernel extends Object {

    private $argc;
    private $argv;
    private $cfg;
    private $import;
    private $isCLI = false;
    private $cmdOption = [];

    const PASS_STATE = 0;

    /**
     *
     * @var \Toknot\Share\Request
     * @access public
     * @readonly
     * @after Kernel::router()
     */
    private $request;
    private $runResult = [];

    /**
     * 
     * @param array $argc
     * @param int $argv
     */
    protected function __construct($argc, $argv) {
        define('PHP_NS', '\\');
        $this->setArg($argc, $argv);
        $this->initImport();

        $this->phpIniSet();
        if (PHP_SAPI == 'cli') {
            $this->isCLI = true;
            $this->console();
        }
        try {
            $this->setResponse(self::PASS_STATE);

            set_error_handler(array($this, 'errorReportHandler'));
            set_exception_handler(array($this, 'uncaughtExceptionHandler'));
            register_shutdown_function(array($this, '__destruct'));
        } catch (\Exception $e) {
            $this->echoException($e);
            $this->response();
            exit();
        }
    }

    private function setRuntimeEnv() {
        $this->initRuntime();

        $this->cfg = $this->loadConfig();

        if ($this->cfg->app->short_except_path) {
            Logs::$shortPath = strlen(dirname(dirname(TKROOT)));
        }
        $this->importVendor();
        $this->initRouter();
    }

    private function phpIniSet() {
        ini_set('html_errors', 0);
        ini_set('log_errors', 0);
    }

    public function run() {
        $this->setRuntimeEnv();

        try {
            $this->router();
        } catch (\Exception $e) {
            $this->echoException($e);
        }
        return $this->response();
    }

    private function response() {
        if ($this->isCLI) {
            echo $this->runResult['content'];
            exit($this->runResult['code']);
        }
        if ($this->runResult['code'] == 500) {
            $this->runResult['message'] = 'Internal Server Error';
        }
        header($this->runResult['message'], true, $this->runResult['code']);
        if (!empty($this->runResult['option'])) {
            header($this->runResult['option']);
        } else {
            echo $this->runResult['content'];
        }
        return $this->runResult['code'];
    }

    private function shutdown() {
        throw new ShutdownException;
    }

    /**
     * 
     * @param int $status
     * @param string $message
     * @param string $content
     * @param string $option
     */
    public function setResponse($status = self::PASS_STATE, $message = '', $content = '', $option = '') {
        $this->runResult['code'] = $status;
        $this->runResult['message'] = $message;
        $this->runResult['content'] = $content;
        $this->runResult['option'] = $option;
    }

    /**
     * 
     * @return \Toknot\Boot\Route
     * @throws BaseException
     */
    private function routerIns() {
        $routerClass = $this->cfg->app->router;
        if (is_subclass_of($routerClass, 'Toknot\Boot\Route')) {
            return $routerClass::single();
        }
        throw new BaseException("$routerClass must implements Toknot\Boot\Route");
    }

    private function initRouter() {
        $ini = APPDIR . '/config/router.ini';
        $php = APPDIR . '/runtime/config/route.php';

        $this->routerIns()->load($php, $ini);
    }

    private function console() {
        $_SERVER['REQUEST_METHOD'] = 'CLI';
        if ($this->argc < 2) {
            return;
        }
        $_SERVER['REQUEST_URI'] = '/' . str_replace('.', '/', $this->argv[1]);
    }

    private function launch($parameters, $ns, $type, $requireParams) {
        if (empty($parameters[$type])) {
            return false;
        }

        if (is_array($parameters[$type])) {
            foreach ($parameters[$type] as $name) {
                $this->invoke($name, $ns, $parameters);
            }
        } else {
            $this->invoke($parameters[$type], $ns, $requireParams);
        }
    }

    private function invoke($names, $ns, $requireParams) {
        if ($this->runResult['code'] !== self::PASS_STATE) {
            return false;
        }
        if (empty($names)) {
            return false;
        }
        $group = explode('::', $names);

        $groupclass = Tookit::nsJoin($ns, $group[0]);
        $paramsCount = $requireParams->count();

        $params = iterator_to_array($requireParams, false);
        if ($paramsCount > 0) {
            $groupins = self::constructArgs($paramsCount, $params, $groupclass);
        } else {
            $groupins = new $groupclass();
        }

        if (isset($group[1])) {
            if ($paramsCount > 0) {
                self::callMethod($paramsCount, $group[1], $params, $groupins);
            } else {
                $groupins->{$group[1]}();
            }
        }
    }

    private function router() {
        $parameters = $this->routerIns()->match();
        $appCfg = $this->cfg->app;

        $this->request = $this->routerIns()->getRequest();
        $requireParams = $this->request->attributes;

        $exec = $this->routerIns()->middlewareNamespace($appCfg);
        foreach ($exec as $key => $ns) {
            $this->launch($parameters, $ns, $key, $requireParams);
        }
    }

    public function echoException($e) {
        if ($e instanceof ShutdownException) {
            return;
        }
        try {
            throw new BaseException($e->getMessage(), $e->getCode(), $e->getFile(), $e->getLine(), $e);
        } catch (BaseException $se) {
            $this->runResult = [];
            $this->runResult['code'] = 500;
            $this->runResult['message'] = $se->getMessage();
            $this->runResult['content'] = $se->getDebugTraceAsString();
            $this->runResult['option'] = '';
        }
    }

    private function setArg($argc, $argv) {
        $GLOBALS['argc'] = 0;
        $GLOBALS['argv'] = [];
        if (PHP_SAPI === 'cli') {
            $this->argc = $argc;
            $this->argv = $argv;
        }
        $this->cmdOption = $this->walkOption();
    }

    private function walkOption() {
        $shortParam = false;
        $option = [];
        foreach ($this->argv as $idx => $arg) {
            if (strpos($arg, '--') === 0) {
                $par = explode('=', $arg);
                $option[$par[0]] = count($par) == 2 ? $par[1] : '';
                $shortParam = false;
            } elseif (strpos($arg, '-') === 0) {
                $shortParam = $arg;
                $option[$arg] = null;
            } elseif ($shortParam) {
                $option[$shortParam] = $arg;
                $shortParam = false;
            } else {
                $option[$idx] = $arg;
            }
        }
        return $option;
    }

    /**
     * Get option of command line
     * 
     * @param string $key
     * @return string
     */
    public function getOption($key = null) {
        if ($key) {
            return Tookit::coalesce($this->cmdOption, $key, '');
        } else {
            return $this->cmdOption;
        }
    }

    private function initRuntime() {
        if (!is_dir(APPDIR . '/runtime')) {
            mkdir(APPDIR . '/runtime');
        }
        if (!is_dir(APPDIR . '/runtime/config')) {
            mkdir(APPDIR . '/runtime/config');
        }
    }

    private function initImport() {
        include __DIR__ . '/Import.php';
        $this->import = new Import();
        $this->import->register();
    }

    private function importVendor() {
        $vendor = dirname(TKROOT);

        foreach ($this->cfg->vendor as $v) {
            $this->import->addPath("$vendor/$v");
        }
        $appname = ucfirst(basename(APPDIR));
        $this->import->addPath(APPDIR . "/$appname");
    }

    public function __get($name) {
        switch ($name) {
            case 'argc':
                return $this->argc;
            case 'argv':
                return $this->argv;
            case 'import':
                return $this->import;
            case 'cfg':
                return $this->cfg;
            case 'request':
                return $this->request;
        }
    }

    private function loadConfig() {
        $ini = APPDIR . '/config/config.ini';
        return $this->loadini($ini);
    }

    public function config() {
        $keys = func_get_args();
        return Configuration::getItem($this->cfg, $keys);
    }

    public function loadini($ini) {
        return Configuration::loadConfig($ini);
    }

    /**
     * transform php error to Exceptoion, all error will use {@link BaseException}
     * 
     * @access private
     */
    public function errorReportHandler() {
        $argv = func_get_args();
        throw BaseException::errorReportHandler($argv);
    }

    public function uncaughtExceptionHandler($e) {
        $this->echoException($e);
        $this->response();
    }

    public function __destruct() {
        Tookit::releaseShutdownHandler();
    }

}