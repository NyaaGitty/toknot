<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2013 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Lib;
use Toknot\Config\ConfigLoader;
use Toknot\Lib\SSH2;

class InotifySync {

    /**
     * inotify_instance
     *
     * @var mixed
     * @access private
     */
    private $inotifyInstance = null;
    private $watchDescriptor = array();
    private $pendingEvent = 0;
    private $watchListConfWD = null;

    /**
     * max_sync_process_num
     * max send file process
     *
     * @var float
     * @access public
     */
    public $maxSyncProcessNum = 5;
    public $maxTransporter = 5;
    private $logFileDir = null;
    private $sshIns = null;
    private $runDir = '/tmp';
    private $watchListConf = null;

    public function __construct($watch_list_conf) {
        dl_extension('inotify', 'inotify_init');
        dl_extension('proctitle', 'setproctitle');
        dl_extension('posix', 'posix_getpid');

        $cfg = ConfigLoader::CFG();
        $cfg = $cfg->app;
        $this->logFileDir = __X_APP_DATA_DIR__ . "/{$cfg->log_dir}/sync";
        xmkdir($this->logFileDir);
        $this->runDir = __X_APP_DATA_DIR__ . "/{$cfg->runDir}/sync";
        xmkdir($this->runDir);
        $watch_list_conf = __X_APP_DATA_DIR__ . "/conf/{$watch_list_conf}";
        if (!file_exists($watch_list_conf)) {
            throw new XException("Watch config file ({$watch_list_conf}) not exists");
        }
        $ips = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        //fork inotify process
        setproctitle('php:XInotifySync Main process');
        $pid = pcntl_fork();
        if ($pid == -1)
            throw new XException('fork inotify process failure');
        if ($pid == 0) {
            setproctitle('php:XInotifySync Watcher');
            $this->logs('Watcher Starter');
            $this->inotify_sock = $ips[0];
            fclose($ips[1]);
            $this->createInotifyInstance();
            $this->watchListConf = $watch_list_conf;
            $this->watchListConfWD = inotify_add_watch($this->inotifyInstance, $watch_list_conf, IN_CLOSE_WRITE);
            $this->addFormFile($this->watchListConf);
            $this->watchLoop();
            $this->logs('Watcher Exit');
            exit(0);
        }
        //fork sync process
        $pid = pcntl_fork();
        if ($pid == -1)
            throw new XException('fork sync process failure');
        if ($pid == 0) {
            $this->logs('Dispatcher start');
            $this->sync_sock = $ips[1];
            setproctitle('php:XInotifySync Dispatcher');
            fclose($ips[0]);
            $this->syncMasterProcessLoop();
            $this->logs('Dispatcher Exit');
            exit(0);
        }
        $i = 0;
        while (true) {
            pcntl_wait($status);
        }
    }

    private function err($msg) {
        $this->msg($msg);
        exit(1);
    }

    private function msg($msg) {
        $time = microtime(true);
        $pid = posix_getpid();
        echo "$time:PID:$pid:$msg\r\n";
    }

    private function logs($msg) {
        list(, $time) = explode(' ', microtime());
        $pid = posix_getpid();
        $date = date('Ymd.H:i:s');
        $str = "{$date}.{$time}:PID:$pid:$msg\r\n";
        $date = date('Ymd');
        file_put_contents("{$this->logFileDir}/log_{$date}", $str, FILE_APPEND);
    }

    private function createInotifyInstance() {
        $this->inotifyInstance = inotify_init();
    }

    public function watch($path, $ip, $port, $username, $password, $tpath) {
        $path = trim($path);
        $path = rtrim($path, '/');
        $wd = inotify_add_watch($this->inotifyInstance, $path, IN_IGNORED | IN_ISDIR | IN_CLOSE_WRITE | IN_CREATE | IN_MOVE | IN_DELETE);
        $this->watchDescriptor[$wd]['wd'] = $wd;
        $this->watchDescriptor[$wd]['local_path'] = $path;
        $this->watchDescriptor[$wd]['target_ip'] = $ip;
        $this->watchDescriptor[$wd]['target_port'] = $port;
        $this->watchDescriptor[$wd]['target_path'] = $tpath;
        $this->watchDescriptor[$wd]['username'] = $username;
        $this->watchDescriptor[$wd]['password'] = $password;
        if (is_dir($path)) {
            $this->addSubDir($wd);
        }
        return $wd;
    }

    public function rm($wd) {
        try {
            @inotify_rm_watch($this->inotifyInstance, $wd);
            $parent_path = $this->watchDescriptor[$wd]['local_path'];
            $path_len = strlen($parent_path) - 1;
            unset($this->watchDescriptor[$wd]);
            if (is_dir($this->watchDescriptor[$wd]['local_path'])) {
                foreach ($this->watchDescriptor as $wd => $info) {
                    if (substr($info['local_path'], 0, $path_len) == $parent_path) {
                        @inotify_rm_watch($this->inotifyInstance, $wd);
                        unset($this->watchDescriptor[$wd]);
                    }
                }
            }
        } catch (XException $e) {
            
        }
    }

    public function rmDirWd($path) {
        foreach ($this->watchDescriptor as $wd => $info) {
            if ($path == $info['local_path']) {
                $this->rm($wd); //force remove watch_descriptor even it auto remove
                unset($this->watchDescriptor[$wd]);
                break;
            }
        }
    }

    public function rmAllWatch() {
        foreach ($this->watchDescriptor as $wd => $path) {
            $this->rm($wd);
        }
    }

    public function queue() {
        $this->pendingEvent = inotify_queue_len($this->inotifyInstance);
    }

    public function get() {
        return inotify_read($this->inotifyInstance);
    }

    public function addFormArray($file_list) {
        if (!is_array($file_list))
            return;
        foreach ($file_list as $server) {
            $wd = $this->watch($server['local_path'], $server['target_ip'], $server['target_port'], $server['username'], $server['password'], $server['target_path']);
        }
    }

    public function fileTransportProcessExit($signo) {
        pcntl_wait($status);
    }

    /**
     * sync_master_process_loop
     * file sync master process
     *
     * @access public
     * @return void
     */
    private function syncMasterProcessLoop() {
        while (1) {
            if (is_resource($this->sync_sock) == false) {
                $this->err('pip error');
                return;
            }
            pcntl_signal_dispatch();
            $read = array($this->sync_sock);
            $write = null;
            $except = null;
            $chg_num = stream_select($read, $write, $except, 200000);
            if ($chg_num > 0) {
                usleep(100000);
                $str = fread($this->sync_sock, 10000);
                $message_group = explode("\r\n", $str);
                $ph = opendir($this->runDir);
                $trans_num = 0;
                while (false === ($f = readdir($ph))) {
                    if ($f == '.' || $f == '..')
                        continue;
                    $trans_num++;
                }
                if ($trans_num <= $this->maxTransporter) {
                    $file_info = array('U' => array(),
                        'D' => array(),
                        'MF' => array(),
                        'MT' => array());
                }
                foreach ($message_group as $mstr) {
                    $unit = unserialize($mstr);
                    if (is_array($unit)) {
                        $this->merge($file_info, $unit);
                    }
                }
                if ($trans_num <= $this->maxTransporter) {
                    $tmp_pid = $this->transporterFile($file_info);
                    pcntl_waitpid($tmp_pid, $status);
                }
            }
        }
    }

    private function merge(&$file_info, $unit) {
        foreach ($unit as $t => $value) {
            if ($t == 'MT' || $t == 'MF') {
                foreach ($value as $i => $f) {
                    $file_info[$t][$i] = $f;
                }
            } else {
                foreach ($value as $i => $f) {
                    $file_info[$t][] = $f;
                }
            }
        }
    }

    private function transporterPid() {
        $pid = posix_getpid();
        file_put_contents($this->runDir . '/' . $pid, $pid);
    }

    private function rmTransporterPid() {
        $pid = posix_getpid();
        unlink($this->runDir . '/' . $pid);
    }

    /**
     * sync_file
     * file or directory transport opreate process
     *
     * @param mixed $str
     * @access private
     * @return void
     */
    private function transporterFile($change_list) {
        $fock_pid = pcntl_fork();
        if ($fock_pid == -1)
            throw new XException('fork #1 Error');
        if ($fock_pid > 0)
            return $fock_pid;
        $fock_pid = pcntl_fork();
        if ($fock_pid == -1)
            throw new XException('fork #2 ERROR');
        if ($fock_pid > 0)
            exit(0);
        chdir('/');
        umask('0');
        posix_setsid();
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $this->transporterPid();
        fclose($this->sync_sock);
        setproctitle('php:XInotifySync Transporter');
        $this->logs('new Transporter Start');
        $sendfile_list = $change_list['U'];
        $file_num = count($sendfile_list);
        $move_del = array_diff_key($change_list['MF'], $change_list['MT']);
        $move_create = array_diff_key($change_list['MT'], $change_list['MF']);
        if (count($move_create) > 0) {
            $sendfile_list += $move_create;
        }
        $move = array_intersect_key($change_list['MF'], $change_list['MT']);
        if (count($move) > 0) {
            $pid = $this->execSyncMv($move, $move_to);
            pcntl_waitpid($pid, $status);
        }
        if (count($move_del) > 0) {
            $change_list['D'] += $move_del;
        }
        if (count($change_list['D']) > 0) {
            $pid = $this->execSyncRm($change_list['D']);
            pcntl_waitpid($pid, $status);
        }
        if ($file_num >= $this->maxSyncProcessNum) {
            $max_num = $this->maxSyncProcessNum;
        } else {
            $max_num = $file_num;
        }
        $pnum = 1;
        $current_sync_queen = array();
        while (true) {
            if (count($sendfile_list) == 0)
                break;
            $file = array_shift($sendfile_list);
            $pid = $this->execSyncSendFile($file);
            $pnum++;
            $current_sync_queen[$pid] = $pnum;
            if ($pnum >= $max_num) {
                while (true) {
                    if (count($current_sync_queen) == 0)
                        break;
                    $pid = pcntl_wait($status);
                    unset($current_sync_queen[$pid]);
                }
            }
        }
        $this->logs('transporter exit');
        $this->rmTransporterPid();
        exit(0);
    }

    /**
     * exec_sync_send_file
     * create a file or directory sysnc of instance
     *
     * @param mixed $watch_info
     * @access private
     * @return void
     */
    private function execSyncSendFile($watch_info) {
        $pid = pcntl_fork();
        if ($pid > 0)
            return $pid;
        if ($pid == -1) {
            $this->logs('fork file send process error');
            return;
        }
        setproctitle('php:XInotifySync Execer');
        $this->logs("send file {$watch_info['local_path']} to {$watch_info['target_path']}");
        $ssh_ins = new SSH2($watch_info['target_ip'], $watch_info['target_port'], $watch_info['username'], $watch_info['password']);
        $ssh_ins->connect();
        $ssh_ins->create_sftp();
        if (is_dir($watch_info['local_path'])) {
            $ssh_ins->mkdir($watch_info['target_path'], 0644);
            $this->sendAllSubFile($ssh_ins, $watch_info['local_path'], $watch_info['target_path'], 0644);
        } else {
            $ssh_ins->sendfile($watch_info['local_path'], $watch_info['target_path'], 0644);
        }
        exit(0);
    }

    /**
     * send_all_sub_file
     * send files and directories inside the local path
     *
     * @param mixed $ssh_ins
     * @param mixed $local_path
     * @param mixed $target_path
     * @access private
     * @return void
     */
    private function sendAllSubFile($ssh_ins, $local_path, $target_path) {
        $dh = opendir($local_path);
        while (false !== ($f = readdir($dh))) {
            if ($f == '.' || $f == '..')
                continue;
            $local_file = "{$local_path}/{$f}";
            $remote_file = "{$target_path}/{$f}";
            if (is_dir($local_file)) {
                $ssh_ins->mkdir($remote_file);
                $this->sendAllSubFile($ssh_ins, $local_file, $remote_file);
            } else {
                $ssh_ins->sendfile($local_file, $remote_file, 0644);
            }
        }
    }

    private function execSyncMv($move_form, $move_to) {
        $pid = pcntl_fork();
        if ($pid > 0)
            return $pid;
        if ($pid == -1) {
            $this->logs('fork move file process error');
            return;
        }
        setproctitle('php:XInotifySync Mover');
        $ssh_conn_list = array();
        foreach ($move_form as $cookie => $file) {
            if (!in_array($file['target_ip'], $ssh_conn_list)) {
                $ssh_ins = new XSSH2($file['target_ip'], $file['target_port'], $file['username'], $file['password']);
                $ssh_conn_list[$file['target_ip']] = $ssh_ins;
                $ssh_ins->connect();
                $ssh_ins->create_sftp();
            } else {
                $ssh_ins = $ssh_conn_list[$file['target_ip']];
            }
            $this->logs("mv {$file['target_path']} to {$move_to[$cookie]['target_path']}");
            $ssh_ins->mv($file['target_path'], $move_to[$cookie]['target_path']);
        }
        foreach ($ssh_conn_list as $ssh_ins) {
            $ssh_ins->disconnect();
        }
        exit(0);
    }

    private function execSyncRm($delete) {
        $pid = pcntl_fork();
        if ($pid > 0)
            return $pid;
        if ($pid == -1) {
            $this->logs('fork del file process error');
            return;
        }
        setproctitle('php:XInotifySync Deleter');
        $ssh_conn_list = array();
        foreach ($delete as $file) {
            if (!in_array($file['target_ip'], $ssh_conn_list)) {
                $ssh_ins = new XSSH2($file['target_ip'], $file['target_port'], $file['username'], $file['password']);
                $ssh_ins->connect();
                $ssh_ins->create_sftp();
            } else {
                $ssh_ins = $ssh_conn_list[$file['target_ip']];
            }
            $this->logs("rm file {$file['target_path']}");
            $ssh_ins->rm($file['target_path']);
        }
        foreach ($ssh_conn_list as $ssh_ins) {
            $ssh_ins->disconnect();
        }
        exit(0);
    }

    /**
     * notify_file_list
     * send the change list info to sysnc process
     *
     * @param mixed $watch_info
     * @param mixed $change
     * @access public
     * @return void
     */
    private function notifyFileList($change) {
        $change_str = serialize($change) . "\r\n";
        $read = null;
        $write = array($this->inotify_sock);
        $except = null;
        $chg_num = 0;
        $chg_num = stream_select($read, $write, $except, 200000);
        if ($chg_num > 0) {
            if (in_array($this->inotify_sock, $write)) {
                $len = fwrite($this->inotify_sock, $change_str, strlen($change_str));
                $write = array();
            }
        }
    }

    public function addFormFile($file) {
        $ini = XConfig::parse_ini($file);
        $this->addFormArray($ini);
    }

    private function addSubDir($wd) {
        $watch_descriptor = $this->watchDescriptor[$wd];
        $dh = opendir($watch_descriptor['local_path']);
        if ($dh === false)
            return;
        while (false !== ($name = readdir($dh))) {
            if ($name == '.' || $name == '..')
                continue;
            $path_dir = "{$watch_descriptor['local_path']}/{$name}";
            $tpath = "{$watch_descriptor['target_path']}/{$name}";
            if (is_dir($path_dir)) {
                $nwd = $this->watch($path_dir, $watch_descriptor['target_ip'], $watch_descriptor['target_port'], $watch_descriptor['username'], $watch_descriptor['password'], $tpath);
                $this->addSubDir($nwd);
            }
        }
    }

    public function reloadWatchList($ev_info) {
        $this->rmAllWatch();
        if ($ev_info['mask'] & IN_DELETE_SELF ||
                $ev_info['mask'] & IN_MOVE_SELF) {
            return;
        }
        $this->addFormFile($this->watchListConf);
    }

    private function watchLoop() {
        $proto_array = array(
            'D' => array(),
            'MT' => array(),
            'MF' => array(),
            'U' => array());
        $event_counter = 0;
        stream_set_blocking($this->inotifyInstance, 1);
        while (true) {
            $move_status = 0;
            $events = $this->get();
            if ($event_counter === 0) {
                $event_counter = 1;
                $first_timestamp = microtime(true);
            } else if ($event_counter === 1) {
                $event_counter = 2;
                $second_timestamp = microtime(true);
                $frep_time = (float) $second_timestamp - (float) $first_timestamp;
                if ($frep_time > 0.11) {
                    $event_counter = $second_timestamp = $first_timestamp = 0;
                } else if ($frep_time >= 0.099 && $frep_time <= 0.11) {
                    usleep(30000);
                    $event_counter = $second_timestamp = $first_timestamp = 0;
                }
            } else if ($event_counter === 2) {
                $event_counter = 0;
                $thrid_timestamp = microtime(true);
                $frep_time = (float) $thrid_timestamp - (float) $second_timestamp;
                if ($frep_time <= 0.09) {
                    usleep(50000);
                }
                $second_timestamp = $first_timestamp = 0;
            }
            $this->logs('New events');
            $change = $proto_array;
            foreach ($events as $ev => $ev_info) {
                if (!isset($this->watchDescriptor[$ev_info['wd']])) {
                    continue;
                }
                $watch_info = $this->watchDescriptor[$ev_info['wd']];
                $os_path = "{$watch_info['local_path']}/{$ev_info['name']}";
                $os_tpath = "{$watch_info['target_path']}/{$ev_info['name']}";
                $wd = $ev_info['wd'];
                if ($ev_info['wd'] == $this->watchListConfWD) {
                    $this->reloadWatchList($ev_info);
                    continue;
                }
                switch ($ev_info['mask']) {
                    case IN_CREATE | IN_ISDIR: //创建文件夹
                        $wd = $this->watch($os_path, $watch_info['target_ip'], $watch_info['target_port'], $watch_info['username'], $watch_info['password'], $os_tpath);
                        $chain = 'U';
                        $nid = $wd;
                        break;
                    case IN_CREATE:
                        $chain = null;
                        break;
                    case IN_CLOSE_WRITE: //修改
                        $chain = 'U';
                        $nid = $wd;
                        $this->msg('write');
                        break;
                    case IN_MOVED_TO:
                        $chain = 'U';
                        $nid = $wd;
                        break;
                    case IN_MOVED_TO | IN_ISDIR: //移进
                        $wd = $this->watch($os_path, $watch_info['target_ip'], $watch_info['target_port'], $watch_info['username'], $watch_info['password'], $os_tpath);
                        $nid = $ev_info['cookie'];
                        $chain = 'MT';
                        break;
                    case IN_MOVED_FROM | IN_ISDIR: //移除文件夹
                        $this->rmDirWd($os_path, true);
                        $nid = $ev_info['cookie'];
                        $chain = 'MF';
                        break;
                    case IN_MOVED_FROM: //移除文件
                        $nid = $wd;
                        $chain = 'D';
                        break;
                    case IN_DELETE:
                        $nid = $wd;
                        $chain = 'D';
                        break;
                    case IN_DELETE | IN_ISDIR: //删除
                        $nid = $wd;
                        $chain = 'D';
                        $this->rmDirWd($os_path);
                        break;
                    case IN_DELETE_SELF: //监视文件夹删除
                        $thsi->rm($ev_info['wd']);
                        break;
                    case IN_MOVE_SELF: //监视文件夹移动
                        break;
                    default:
                        break;
                }
                if ($chain == null)
                    continue;
                $array = array();
                $array['local_path'] = $os_path;
                $array['target_path'] = $os_tpath;
                $array['target_ip'] = $watch_info['target_ip'];
                $array['target_port'] = $watch_info['target_port'];
                $array['username'] = $watch_info['username'];
                $array['password'] = $watch_info['password'];
                $change[$chain][$nid] = $array;
            }
            if ($change != $proto_array) {
                $this->notifyFileList($change);
            }
        }
    }

    public function __destruct() {
        if (is_resource($this->inotifyInstance)) {
            fclose($this->inotifyInstance);
        }
    }

}