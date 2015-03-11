<?php
/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2015 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

/**
 * Create a application, the script is a guide that help you create a application
 * of base directory struncture and create some code of php
 * just run the script, like : php CreateApp.php
 */
class CreateApp {

    public $workDir = '';
    public $appName = '';
    public $isAdmin = false;
    public $toknotDir = '';

    public function __construct() {
        $this->toknotDir = dirname(__DIR__);
        $this->workDir = getcwd();
        $this->versionInfo();

        Toknot\Boot\Log::colorMessage("Whether create to current path yes/no(default:no):", null, false);
        $isCurrent = trim(fgets(STDIN));
        $dir = $this->createAppRootDir($isCurrent);
        //Toknot\Boot\Log::colorMessage('Whether admin of applicaton yes/no(default:no):', null, false);
        //$admin = trim(fgets(STDIN));
        $admin = 'no';
        if ($admin == 'yes') {
            $this->isAdmin = true;
            while (($password = $this->enterRootPass()) === false) {
                Toknot\Boot\Log::colorMessage('Twice password not same, enter again:', 'red');
            }

            \Toknot\Boot\StandardAutoloader::importToknotModule('User', 'UserAccessControl');
            Toknot\Boot\Log::colorMessage('Generate hash salt');
            $salt = substr(str_shuffle('1234567890qwertyuiopasdfghjklzxcvbnm'), 0, 8);
            $algo = Toknot\Lib\User\Root::bestHashAlgos();
            $password = Toknot\Lib\User\Root::getTextHashCleanSalt($password, $algo, $salt);
            Toknot\Boot\Log::colorMessage('Generate Root password hash string');
        }

        while (file_exists($dir)) {
            Toknot\Boot\Log::colorMessage("$dir is exists, change other");
            $dir = $this->createAppRootDir($isCurrent);
        }
        Toknot\Boot\Log::colorMessage("Create $dir");
        $res = mkdir($dir, 0777, true);
        if ($res === false) {
            return Toknot\Boot\Log::colorMessage("$dir create fail");
        }
        $dir = realpath($dir);
        $this->appName = basename($dir);

        Toknot\Boot\Log::colorMessage("Create $dir/Controller");
        mkdir($dir . '/Controller');
        $this->writeIndexController($dir . '/Controller');

        Toknot\Boot\Log::colorMessage("Create $dir/WebRoot");
        mkdir($dir . '/WebRoot');

        Toknot\Boot\Log::colorMessage("Create $dir/config");
        mkdir($dir . '/config');

        Toknot\Boot\Log::colorMessage("Create $dir/config/config.ini");

        $configure = file_get_contents($this->toknotDir . '/Config/default.ini');
        $configure = str_replace(array(";DO NOT EDIT THIS FILE !!!\n", ";EDIT APP OF CONFIG.INI INSTEAD !!!\n"), '', $configure);
        $configure = preg_replace('/(rootNamespace)\s*=\s.*/', "rootNamespace = \\{$this->appName}", $configure);
        if ($this->isAdmin) {
            $configure = preg_replace('/(allowRootLogin\040*)=(.*)$/im', "$1= true", $configure);
            $configure = preg_replace('/(rootPassword\040*)=(.*)$/im', "$1={$password}", $configure);
            $configure = preg_replace('/(userPasswordEncriyptionAlgorithms\040*)=(.*)$/im', "$1={$algo}", $configure);
            $configure = preg_replace('/(userPasswordEncriyptionSalt\040*)=(.*)$/im', "$1={$salt}", $configure);
        }
        file_put_contents($dir . '/config/config.ini', $configure);

        $this->writeIndex($dir . '/WebRoot');
        if (!$this->isAdmin) {
            $this->writeAppBaseClass($dir);
        }
        Toknot\Boot\Log::colorMessage("Create $dir/View");
        mkdir($dir . '/View');
        if ($this->isAdmin) {
            mkdir($dir . '/Controller/User');
            Toknot\Boot\Log::colorMessage("Create $dir/Controller/User");
            $this->writeAdminAppUserController($dir . '/Controller/User');
            $this->copyDir($this->toknotDir . '/Admin/View', $dir . '/View');
            $this->copyDir($this->toknotDir . '/Admin/Static', $dir . '/WebRoot/static');
            $this->writeManageListConfig($dir);
        }
        Toknot\Boot\Log::colorMessage("Create $dir/var/view");
        mkdir($dir . '/var/view', 0777, true);

        Toknot\Boot\Log::colorMessage("Create $dir/var/view/compile");
        mkdir($dir . '/var/view/compile', 0777, true);

        Toknot\Boot\Log::colorMessage('Create Success', 'green');
        Toknot\Boot\Log::colorMessage('You should configure ' . $dir . '/config/config.ini');
        Toknot\Boot\Log::colorMessage("Configure your web root to $dir/WebRoot and visit your Application on browser");
    }

    public function writeManageListConfig($dir) {
        $configure = <<<EOF
; this is manage list configure of Toknot Admin

;one section is a manage category
[User]

;category name
name = UserManage

;wheteher has sub item
hassub = true

;the category name whether has action jump
action = false

;sub is the category child item list
;one item contain action and show name and use | split
sub[] = 'UserList'
sub[] = 'AddUser'

[UserList]
name = UserList
hassub = false
action = User\Lists
                
[AddUser]
name = Add User
hassub = false
action = User\Add

EOF;
        file_put_contents($dir . '/config/navigation.ini', $configure);
    }

    public function versionInfo() {
        Toknot\Boot\Log::colorMessage('Toknot Framework Application Create Script');
        Toknot\Boot\Log::colorMessage('Toknot ' . \Toknot\Boot\Version::VERSION . '-' . \Toknot\Boot\Version::STATUS . ';PHP ' . PHP_VERSION);
        Toknot\Boot\Log::colorMessage('Copyright (c) 2010-2013 Szopen Xiao');
        Toknot\Boot\Log::colorMessage('New BSD Licenses <http://toknot.com/LICENSE.txt>');
        Toknot\Boot\Log::colorMessage('');
    }

    public function enterRootPass() {
        Toknot\Boot\Log::colorMessage('Enter root password:', null, false);
        $password = trim(fgets(STDIN));
        while (strlen($password) < 6) {
            Toknot\Boot\Log::colorMessage('root password too short,enter again:', 'red', false);
            $password = trim(fgets(STDIN));
        }
        Toknot\Boot\Log::colorMessage('Enter root password again:', null, false);
        $repassword = trim(fgets(STDIN));
        while (empty($password)) {
            Toknot\Boot\Log::colorMessage('must enter root password again:', 'red', false);
            $repassword = trim(fgets(STDIN));
        }
        if ($repassword != $password) {
            return false;
        } else {
            return $password;
        }
    }

    public function writeAdminAppUserController($path) {
        $phpCode = <<<EOS
<?php
namespace {$this->appName}\Controller\User;

use Toknot\Lib\Admin\Login as AdminLogin;

class Login extends AdminLogin {
}
EOS;
        file_put_contents($path . '/Login.php', $phpCode);
        $phpCode = <<<EOS
<?php
namespace {$this->appName}\Controller\User;
use Toknot\Lib\Admin\Logout;
class Logout extends Logout {
}
EOS;
        file_put_contents("$path/Logout.php", $phpCode);
    }

    public function createAppRootDir($isCurrent) {
        if ($isCurrent == 'yes') {
            $topnamespace = '';
            while (empty($topnamespace)) {
                Toknot\Boot\Log::colorMessage("Enter application root namespace name:", null, false);
                $topnamespace = trim(fgets(STDIN));
            }
            $dir = $this->workDir . '/' . $topnamespace;
        } else {
            Toknot\Boot\Log::colorMessage("Enter application path, the basename is root namespace name:", null, false);
            $dir = trim(fgets(STDIN));
            while (empty($dir)) {
                Toknot\Boot\Log::colorMessage("must enter application path: ", null, false);
                $dir = trim(fgets(STDIN));
            }
        }
        if (file_exists($dir)) {
            Toknot\Boot\Log::colorMessage('Path (' . $dir . ') is exists, change other path', 'red');
            $this->createAppRootDir($isCurrent);
        }
        return $dir;
    }

    public function copyDir($source, $dest) {
        if (is_file($source)) {
            return copy($source, $dest);
        } else if (is_dir($source)) {
            $dir = dir($source);
            if (is_file($dest)) {
                return Toknot\Boot\Log::colorMessage($dest . ' is exist file');
            }
            if (!is_dir($dest)) {
                mkdir($dest, 0777, true);
            }
            while (false !== ($f = $dir->read())) {
                if ($f == '.' || $f == '..') {
                    continue;
                }
                $file = $source . '/' . $f;
                Toknot\Boot\Log::colorMessage("copy $file");
                $destfile = $dest . '/' . $f;
                if (is_dir($file)) {
                    $this->copyDir($file, $destfile);
                } else {
                    copy($file, $destfile);
                }
            }
        }
    }

    public function writeIndexController($path) {
        $use = $this->isAdmin ? 'Toknot\Lib\Admin\Admin' : "{$this->appName}\\{$this->appName}";
        $base = $this->isAdmin ? 'AdminBase' : "{$this->appName}Base";
        $phpCode = <<<EOS
<?php
namespace  {$this->appName}\Controller;
            
use {$use}Base;

EOS;
        if ($this->isAdmin) {
            $phpCode .= 'use Toknot\Lib\Admin\Menu;';
        }
        $phpCode .= <<<EOS
class Index extends {$base}{
EOS;
        $phpCode .= <<<'EOS'
     
    protected $permissions = 0770;
    protected $gid = 0;
    protected $uid = 0;
    protected $operateType = 'r';
    public function GET() {
        //$database = $this->AR->connect();
        print "hello world";
EOS;
        if ($this->isAdmin) {
            $phpCode .= <<<'EOS'
        $menu = new Menu;
EOS;
        }
        $phpCode .= <<<'EOS'
    }
 }
EOS;
        Toknot\Boot\Log::colorMessage("Create $path/Index.php");
        file_put_contents("$path/Index.php", $phpCode);
    }

    public function writeAppBaseClass($path) {
        $phpCode = <<<EOS
<?php
namespace {$this->appName};

use Toknot\Boot\Object;

class Header extends Object {
EOS;
        $phpCode .= <<<'EOS'

    public function __init() {
       
    }

    public function CLI() {
        $this->GET();
    }

}
EOS;
        Toknot\Boot\Log::colorMessage("Create $path/Header.php");
        file_put_contents("$path/Header.php", $phpCode);
    }

    public function writeIndex($path) {
        $toknot = dirname(__DIR__) . '/Toknot.php';
        $phpCode = "<?php
//If developement set true, product set false
define('DEVELOPMENT', true);
require_once '$toknot';";

        Toknot\Boot\Log::colorMessage("Create $path/index.php");
        file_put_contents($path . '/index.php', $phpCode);
    }

}
