<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2015 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Boot;

use Toknot\Exception\BaseException;
use Toknot\Exception\BadClassCallException;
use Toknot\Boot\Object;
use Toknot\Boot\Exception\NotFoundException;
use Toknot\Boot\Exception\MethodNotAllowedException;
use Toknot\Boot\Exception\ControllerInvalidException;
use Toknot\Boot\Autoloader;
use Toknot\Boot\FileObject;
use Toknot\Boot\StringObject;
use Toknot\Config\ConfigLoader;

class Router extends Object {

    /**
     * The property value is {@see Toknot\Boot\Router::ROUTER_PATH} or 
     * {@see Toknot\Boot\Router::ROUTER_GET_QUERY},
     * {@see Toknot\Boot\Router::ROUTER_MAP_TABLE}
     * the property set by {@see Toknot\Boot\Application::run} 
     * be invoke with passed of 4th parameter, Toknot default router of runtimeArgs method
     * will set be passed of first parameter
     * 
     * @var integer 
     * @access private
     */
    private $routerMode = 1;

    /**
     * router class of root namespace , usual it is application root namespace
     *
     * @var string 
     * @access private
     */
    private static $routerNameSpace = '';

    /**
     * visit URI to application namespace route
     *
     * @var string
     * @access private 
     */
    private $spacePath = '\\';

    /**
     * the application path
     *
     * @var string
     * @access private
     * @static
     */
    private static $routerPath = '';

    /**
     * like webserver set index.html, if set null will return 404 when no debug and develop will throw 
     * {@see BadClassCallException} exception
     *
     * @var string
     * @access private
     */
    private $defaultClass = '\Index';

    /**
     * Alow namespace max level under Controller, only on Router::ROUTER_PATH in effect
     * if set 0 will no limit
     *
     * @var int 
     * @access private
     */
    private $routerDepth = 1;

    /**
     * Array of url path query without Controller path
     *
     * @var array 
     */
    private $suffixPart = array();

    /**
     * the class be invoked when the request controller not found, the class
     * namspace under Application root namespace
     *
     * @var string
     */
    private $notFoundController = null;

    /**
     * the class be invoked when the request controller not has support the method of the http request, 
     * the class namspace under Application root namespace
     *
     * @var string
     */
    private $methodNotAllowedController = null;
    private $charset = 'UTF-8';
    private $method = 'GET';
    private $beforeInvoke = null;
    private $afterInvoke = null;

    /**
     * use URI of path controller invoke application controller of class
     */
    const ROUTER_PATH = 1;

    /**
     * use requset query of $_GET['c'] parameter controller invoke application controller of class
     */
    const ROUTER_GET_QUERY = 2;

    /**
     * use router map table which be configure
     */
    const ROUTER_MAP_TABLE = 3;

    /**
     * Set Controler info that under application, if CLI mode, will set request method is CLI
     * 
     */
    public function routerRule() {
        $this->checkCLIRequest();
        
        if ($this->routerMode == self::ROUTER_GET_QUERY) {
            $this->queryMode();
        } elseif ($this->routerMode == self::ROUTER_MAP_TABLE) {
            $this->mapMode();
        } else {
            $this->defaultMode();
        }
    }

    private function checkCLIRequest() {
        if (empty($_SERVER['REQUEST_METHOD']) && PHP_SAPI == 'cli') {
            if (isset($_SERVER['argv'][1])) {
                $_SERVER['REQUEST_URI'] = $_SERVER['argv'][1];
            } else {
                $_SERVER['REQUEST_URI'] = '/';
            }
        }
    }

    private function mapMode() {
        $maplist = $this->loadRouterMapTable();
        
        $matches = array();
        foreach ($maplist as $map) {
            $map['pattern'] = str_replace('/', '\/', $map['pattern']);
            if (preg_match("/{$map['pattern']}/i", $_SERVER['REQUEST_URI'], $matches)) {
                $this->spacePath = $map['action'];
                $this->suffixPart = $matches;
                break;
            }
        }
        if($this->spacePath == Autoloader::NS_SEPARATOR) {
            $this->spacePath = $this->defaultClass;
        }
    }

    private function defaultMode() {
        $requestUri = new StringObject($_SERVER['REQUEST_URI']);
        if (($pos = $requestUri->strpos('?')) !== false) {
            $urlPath = $requestUri->substr(0, $pos);
        } else {
            $urlPath = new StringObject($_SERVER['REQUEST_URI']);
        }
        $spacePath = $urlPath->strtr('/', Autoloader::NS_SEPARATOR);
        $spacePath = $spacePath == Autoloader::NS_SEPARATOR ? $this->defaultClass : $spacePath;
        if ($this->routerDepth > 0) {
            $name = strtok($spacePath, Autoloader::NS_SEPARATOR);
            $this->spacePath = '';
            $depth = 0;
            while ($name) {
                if ($depth <= $this->routerDepth) {
                    $this->spacePath .= Autoloader::NS_SEPARATOR . ucfirst($name);
                } else {
                    $this->suffixPart[] = $name;
                }
                $name = strtok(Autoloader::NS_SEPARATOR);
                $depth++;
            }
        } else {
            $this->spacePath = $spacePath;
        }
    }

    private function queryMode() {
        $key = each($_GET);
        if (!empty($key[1]) || strpos($key[0], '/') !== 0) {
            $this->spacePath = $this->defaultClass;
        } else {
            $this->spacePath = strtr($key[0], '/', Autoloader::NS_SEPARATOR);
        }
    }

    private function loadRouterMapTable() {
        $filePath = self::$routerPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'router_map.ini';
        return ConfigLoader::loadCfg($filePath);
    }

    /**
     * set application path, implements RouterInterface
     * 
     * @param string $path
     */
    public function routerPath($path) {
        self::$routerPath = $path;
    }

    /**
     * Set default controller class name that without namespace
     * 
     * @param string $defaultClass
     */
    public function defaultInvoke($defaultClass) {
        $this->defaultClass = $defaultClass;
    }

    public function getRouterMode() {
        return $this->routerMode;
    }

    /**
     * transfrom relative class name to full class name
     * 
     * @param string $invokeClass
     * @static
     * @return string
     */
    public static function controllerNameTrans($invokeClass) {
        if (strpos($invokeClass, self::$routerNameSpace . "\Controller") !== 0) {
            $invokeClass = self::$routerNameSpace . "\Controller{$invokeClass}";
        }
        $classFile = Autoloader::transformClassNameToFilename($invokeClass, self::$routerPath);
        if (is_file($classFile)) {
            include_once $classFile;
        }
        return $invokeClass;
    }

    /**
     * invoke not found controller
     * 
     * @param type $invokeClass
     * @throws BadClassCallException
     */
    public function invokeNotFoundController(&$invokeClass) {
        if (self::checkController($this->notFoundController, null)) {
            NotFoundException::$displayController = $this->notFoundController;
            NotFoundException::$method = $this->method;
        }
        throw new NotFoundException("Controller $invokeClass Not Found");
    }

    /**
     * Invoke Application Controller, the method will call application of Controller what is
     * self::$routerNameSpace\Controller{$this->spacePath}, and router action by request method
     * 
     * @throws BadClassCallException
     * @throws BaseException
     */
    public function invoke() {
        $method = $this->getRequestMethod();
        $this->method = $method;
        $invokeClass = self::controllerNameTrans($this->spacePath);
        $classFile = Autoloader::transformClassNameToFilename($invokeClass, self::$routerPath);
        $classExist = false;

        //not case sensitive check file whether exist
        if ($this->routerDepth > 0) {
            $caseClassFile = FileObject::fileExistCase($classFile);
        } else {
            //if not set routerDepth, controller is first finded class, suffix of url
            //will be ignored and push to paramers
            $classPath = self::$routerNameSpace . "\Controller";
            $classPart = explode(Autoloader::NS_SEPARATOR, $this->spacePath);
            foreach ($classPart as $key => $part) {
                if (empty($part))
                    continue;
                $classPath .= DIRECTORY_SEPARATOR . $part;
                $classFile = Autoloader::transformClassNameToFilename($classPath, self::$routerPath);
                $caseClassFile = FileObject::fileExistCase($classFile);
                if ($caseClassFile) {
                    $this->suffixPart = array_slice($classPart, $key + 1);
                    break;
                }
            }
        }
        if ($caseClassFile) {
            include_once $caseClassFile;
            $invokeClass = str_replace(self::$routerPath, '', $caseClassFile);
            $invokeClass = strtr($invokeClass, DIRECTORY_SEPARATOR, Autoloader::NS_SEPARATOR);
            $invokeClass = self::$routerNameSpace . strtok($invokeClass, '.');
            $classExist = class_exists($invokeClass, false);
        }

        //if url mapped controller not exist
        if (!$classExist) {
            //The url mapping to a namespace but not a controller class, will invoke 
            //the namespace of under default controller class, it like index.html for
            //web server
            $dir = Autoloader::transformClassNameToFilename($invokeClass, self::$routerPath);
            if (is_dir($dir) && $this->defaultClass != null) {
                $invokeClass = self::controllerNameTrans("{$this->spacePath}\\{$this->defaultClass}");

                if (!class_exists($invokeClass, false)) {
                    $invokeClass = $this->invokeNotFoundController();
                }
            } else {
                $this->invokeNotFoundController($invokeClass);
            }
        }

        $this->instanceController($invokeClass, $method);
    }

    public static function checkController(&$invokeClass, $method) {
        if ($method === null)
            return true;
        $invokeClass = self::controllerNameTrans($invokeClass);
        return method_exists($invokeClass, $method);
    }

    /**
     * new instance of controller
     * 
     * @param string $invokeClass
     * @param string $method
     * @throws ControllerInvalidException
     * @throws BaseException
     */
    public function instanceController($invokeClass, $method) {
        if (!self::checkController($invokeClass, $method)) {
            if (DEVELOPMENT) {
                throw new ControllerInvalidException($invokeClass);
            }
            $this->invokeNotFoundController($invokeClass);
        }
        if (!self::checkController($invokeClass, $method)) {
            $interface = self::getControllerInterface($method);
            if (self::checkController($this->methodNotAllowedController, $method)) {
                MethodNotAllowedException::$displayController = $this->methodNotAllowedController;
                MethodNotAllowedException::$method = $method;
            }
            throw new MethodNotAllowedException("{$invokeClass} not support request method ($method) or not implement {$interface}");
        }

        $status = $this->invokeBefore($method);

        if ($status !== false) {
            $invokeObject = new $invokeClass();
            $invokeObject->$method();
        }

        $this->invokeAfter($method);
    }

    private function invokeBefore($method) {
        $status = null;
        if ($this->beforeInvoke) {
            $bf = new $this->beforeInvoke;
            $status = $bf->$method();
        }
        return $status;
    }

    private function invokeAfter($method) {
        $status = null;
        if ($this->afterInvoke) {
            $bf = new $this->afterInvoke;
            $status = $bf->$method();
        }
    }

    /**
     * implements {@see Toknot\Boot\RouterInterface} of method, the method set Application
     * of top namespace 
     * 
     * @param string $appspace
     */
    public function routerSpace($appspace) {
        self::$routerNameSpace = $appspace;
    }

    /**
     * get HTTP request use method
     * 
     * @return string
     */
    private function getRequestMethod() {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            $_SERVER['REQUEST_METHOD'] = getenv('REQUEST_METHOD');
        }
        if (empty($_SERVER['REQUEST_METHOD'])) {
            $_SERVER['REQUEST_METHOD'] = 'CLI';
        }
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Get relatively name of not found controller
     * 
     * @return string
     */
    public function getNotFoundController() {
        return $this->notFoundController;
    }

    /**
     * Get relatively name of method not allowed controller
     * 
     * @return string
     */
    public function getMethodNotAllowedController() {
        return $this->methodNotAllowedController;
    }

    /**
     * load configure with Router seted
     */
    public function loadConfigure() {
        $cfg = ConfigLoader::CFG();
        if (!empty($cfg->App->notFoundController)) {
            $this->notFoundController = self::controllerNameTrans($cfg->App->notFoundController);
        }
        if (!empty($cfg->App->methodNotAllowedController)) {
            $this->methodNotAllowedController = self::controllerNameTrans($cfg->App->methodNotAllowedController);
        }
        if (!empty($cfg->App->defaultInvokeController)) {
            $this->defaultInvokeController = $cfg->App->defaultInvokeController;
        }
        if (!empty($cfg->App->routerMode)) {
            $this->routerMode = constant("self::{$cfg->App->routerMode}");
        }
        if (!empty($cfg->App->routerDepth)) {
            $this->routerDepth = $cfg->App->routerDepth;
        }
        if (!empty($cfg->App->charset)) {
            $this->charset = $cfg->App->charset;
        }

        if (!empty($cfg->App->beforeInvokeController)) {
            $this->beforeInvoke = $cfg->App->beforeInvokeController;
        }

        if (!empty($cfg->App->afterInvokeController)) {
            $this->beforeInvoke = $cfg->App->afterInvokeController;
        }
    }

}
