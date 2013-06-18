<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2013 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\User;

use Toknot\Di\ArrayObject;
use Toknot\Di\DataCacheControl;
use Toknot\Config\ConfigLoader;

class Session extends ArrayObject {

    protected $havePHPSession = true;
    private $cacheInstance = null;
    private static $fileStorePath = '';
    public static $fileStore = true;
    private static $sessionStatus = false;

    /**
     * if use file store the properties can not be set, otherwise must set one opreate 
     * of class of instance be called by {@see Toknot\Di\DataCacheControl}
     *
     * @var mixed
     * <code>
     * Session::$storeHandle = new Memcache;
     * $session = Session::singleton();
     * $session->start();
     * </code>
     */
    public static $storeHandle = null;
    private static $sessionName = 'TKSID';
    private $sessionId = '';
    private static $maxLifeTime = 3600;

    public static function singleton() {
        return parent::__singleton();
    }

    /**
     * construct a Session class
     * 
     * <code>
     * 
     * //if not use file store session data
     * Session::$storeHandle = new Memcache;
     * 
     * $session = Session::singleton();
     * $session->start();
     * 
     * //set session value
     * Session('username', 'username');
     * 
     * //above same below if enable php session extension:
     * $_SESSION['username'] = 'username';
     * 
     * //or
     * $session['usrname'] = 'username';
     * </code>
     * 
     * @access protected
     */
    public function __construct() {
        if (extension_loaded('session')) {
            session_set_save_handler(array($this, 'open'), array($this, 'close'), array($this, 'read'), array($this, 'write'), array($this, 'destroy'), array($this, 'gc'));
            $this->havePHPSession = true;
        } else {
//register_shutdown_function(array($this, 'writeClose'));
            $this->havePHPSession = false;
        }
        $this->loadConfigure();
    }

    public function name($name = null) {
        if ($name == null) {
            return self::$sessionName;
        } else {
            self::$sessionName = $name;
        }
    }

    private function loadConfigure() {
        $CFG = ConfigLoader::CFG();
        self::$fileStore = $CFG->Session->fileStoreSession;
        self::$sessionName = $CFG->Session->sessionName;
        self::$fileStorePath = $CFG->Session->fileStorePath;
        self::$maxLifeTime = $CFG->Session->maxLifeTime;
    }

    public function writeClose() {
        $this->write($this->sessionId, serialize($this->interatorArray));
        $this->close();
    }

    public function start() {
        if (self::$sessionStatus) {
            //trigger_error('Session started', E_USER_WARNING);
            return;
        }
        if (isset($_COOKIE[self::$sessionName])) {
            $this->sessionId = $_COOKIE[self::$sessionName];
        } else {
            $this->regenerate_id();
        }
        if ($this->havePHPSession) {
            session_name(self::$sessionName);
            session_start();
        } else {
            self::$sessionStatus = true;
            $this->open(self::$storeHandle, self::$sessionName);
            $this->read($this->sessionId);
        }
    }

    public function regenerate_id() {
        if ($this->sessionId) {
            $this->destroy($this->sessionId);
        }
        $this->sessionId = sha1(str_shuffle('~`@#$%^&*()_+-={}[];:"\'?/>.<,1234567890QWERTYUIOPLKJHGFDAZXCVBNMqwertyuioplkjhgfdsazxcvbnm'));
        setcookie(self::$sessionName, $this->sessionId);
    }

    private function setValue($name, $value) {
        if ($this->havePHPSession) {
            $_SESSION[$name] = $value;
        }
        $this->interatorArray[$name] = $value;
    }

    private function getValue($name) {
        return $this->interatorArray[$name];
    }

    public function __invoke($name) {
        $num = func_num_args();
        if ($num > 2) {
            $this->setValue($name, func_get_arg(1));
        } else {
            $this->getValue($name);
        }
    }

    public function open($dsn, $sessionName) {
        $this->path = $dsn . '.' . $sessionName;
        if (self::$fileStore) {
            if (!is_dir(self::$fileStorePath)) {
                DataCacheControl::createCachePath(self::$fileStorePath);
            }
            self::$storeHandle = self::$fileStorePath;
            $type = DataCacheControl::CACHE_FILE;
        } else {
            $type = DataCacheControl::CACHE_SERVER;
        }
        $this->cacheInstance = new DataCacheControl(self::$storeHandle, 0, $type);
        $this->cacheInstance->useExpire(self::$maxLifeTime);
        return true;
    }

    public function close() {
        return true;
    }

    public function read($sessionId) {
        if (self::$fileStore) {
            $sessionId = DIRECTORY_SEPARATOR . $sessionId;
            if (!$this->cacheInstance->exists($sessionId)) {
                $this->regenerate_id();
            }
        }
        $data = $this->cacheInstance->get($sessionId);
        if ($this->havePHPSession) {
            session_decode($data);
            $this->interatorArray = $_SESSION;
        } else {
            $this->interatorArray = unserialize($data);
        }
        return $data;
    }

    public function write($sessionId, $data) {
        if (self::$fileStore)
            $sessionId = DIRECTORY_SEPARATOR . $sessionId;
        return $this->cacheInstance->save($data, $sessionId);
    }

    public function destroy($sessionId) {
        $this->cacheInstance->del($sessionId);
        return true;
    }

    public function gc($lifetime) {
        if (self::$fileStore) {
            foreach (glob(self::$fileStorePath . '/*') as $file) {
                if (file_exists($file) && filemtime($file) + $lifetime < time()) {
                    unlink($file);
                }
            }
        }
        return true;
    }

    public function unsetSession() {
        $this->interatorArray = array();
    }

    public function __destruct() {
        if (!$this->havePHPSession) {
            $this->writeClose();
            $p = mt_rand(1, 100);
            if ($p < 10) {
                $this->gc(self::$maxLifeTime);
            }
        }
    }

}
