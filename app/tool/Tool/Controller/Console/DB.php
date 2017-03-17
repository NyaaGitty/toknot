<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Tool\Controller\Console;

use Toknot\Share\DB\DBA;
use Toknot\Boot\Kernel;
use Toknot\Boot\Tookit;
use Toknot\Boot\Logs;
use Toknot\Boot\Configuration;

class DB {

    /**
     *
     * @var Toknot\Share\DB
     */
    public $tkdb;
    public $dbcfg;
    public $appcfg;
    public $appdir;
    public $kernel;
    public $usedb;
    public $force = false;
    public $dbconn;
    public $tableOption = [];

    public function __construct() {
        $this->kernel = Kernel::single();
        $this->appdir = $this->kernel->getOption('-a');
        $type = $this->kernel->getOption('-t');
        if ($this->appdir) {
            $this->appdir = realpath($this->appdir);
            $type || $type = 'ini';
            $config = "{$this->appdir}/config/config.$type";
            $php = "{$this->appdir}/runtime/config/config.php";
            $this->appcfg = Configuration::loadConfig($config, $php);
        } else {
            $this->appcfg = $this->kernel->cfg;
        }

        $this->dbcfg = $this->appcfg->database;

        $this->usedb = $this->appcfg->app->default_db_config_key;
        $this->setOption();
        $this->tableOption = $this->dbcfg[$this->usedb];
        DBA::$appDir = $this->appdir;

        $this->tkdb = DBA::single($this->usedb, $this->appcfg);
        $dbs = $this->tkdb->getDBList();
        $dbname = $this->tableOption['table_config'];
        if (!in_array($dbname, $dbs)) {
            Logs::colorMessage("Try Create database: $dbname", 'purple', false);
            $this->tkdb->createDatabase($dbname);
            Logs::colorMessage('Create Success', 'green');
        }
        $this->dbconn = $this->tkdb->connect();
    }

    public function setOption() {
        if ($this->kernel->hasOption('-f')) {
            $this->force = true;
        } else {
            $this->force = false;
        }
        if ($this->kernel->getOption('-d')) {
            $passdb = $this->kernel->getOption('-d');
        }

        if (isset($passdb) && isset($this->dbcfg[$passdb])) {
            $this->usedb = $passdb;
        } else if (isset($passdb)) {
            Logs::colorMessage("The $passdb db config not exists,use default config ", 'red');
        }
    }

    /**
     * init database tables
     * 
     * -f drop table if exists
     * -d key of dbname config
     * -a set app path
     * -t config type
     * 
     * @console db.init
     */
    public function init() {
        $name = $this->tableOption['table_config'];
        Logs::colorMessage('Create database:', 'green');
        $res = $this->tkdb->initDatabaseTables($this->usedb, $name, $this->force);
        foreach ($res as $sql) {
            Logs::colorMessage('Exec: ', 'purple', false);
            Logs::colorMessage($sql);
        }
    }

    /**
     * update database table struct
     * 
     * -d key of dbname config
     * -a app path 
     * -t config type
     * 
     * @console db.update
     */
    public function update() {
        $tablefile = $this->tableOption['table_config'];
        $confType = Tookit::coalesce($this->tableOption, 'config_type', 'ini');
        $ini = "{$this->appdir}/config/{$tablefile}.{$confType}";
        $link = "{$this->appdir}/runtime/config/{$tablefile}.php";

        $ret = Tookit::createCache($ini, $link, function($ini, $php) {
                    $from = $this->tkdb->getAllTableStructureCacheArray();
                    $to = Tookit::parseConf($ini);
                    $this->tkdb->initModel($to, $this->usedb);
                    $sql = $this->tkdb->updateSchema($from, $to);
                    Logs::colorMessage('update database:', 'green');
                    foreach ($sql as $t) {
                        Logs::colorMessage('Exec: ', 'purple', false);
                        Logs::colorMessage($t);
                        $this->dbconn->executeUpdate($t);
                    }

                    $str = '<?php return ' . var_export($to, true) . ';';
                    file_put_contents($php, $str);
                }, $this->force);
        if ($ret > 0) {
            Logs::colorMessage('Update Success');
        } elseif ($ret == 0) {
            Logs::colorMessage('Update fail, have lock file', 'red');
        } else {
            Logs::colorMessage('config not modify');
        }
    }

}
