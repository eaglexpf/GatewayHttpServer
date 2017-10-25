<?php
/**
 * User: Roc.xu
 * Date: 2017/10/23
 * Time: 17:15
 */

namespace GatewayHttpServer;


use GatewayHttpServer\lib\LoggerClient;
use Workerman\Lib\Timer;
use Workerman\Worker;

class Logger extends Worker
{
    const MAX_LOG_BUFFER_SIZE = 1024000;
    const WRITE_LOG_TIME = 60;
    protected $logBuffer = '';
    public $logDir = 'log/';
    public $logFromData = null;
    public function run(){
        $this->onWorkerStart = [$this,'onWorkerStart'];
        $this->onMessage = [$this,'onMessage'];
        $this->onWorkerStop = [$this,'onWorkerStop'];
        parent::run();
    }

    public function onWorkerStart($worker){
        $log_dir = __DIR__.'/../../../'. $this->logDir;
        if(!is_dir($log_dir)){
            mkdir($log_dir, 0777, true);
        }
        Timer::add(self::WRITE_LOG_TIME,[$this,'writeLog']);
    }


    public function onMessage($connection,$buffer){
        $data = LoggerClient::decode($buffer);
        $data['log_ip'] = $connection->getRemoteIp();
        if (is_array($this->logFromData)){
            if (!in_array($data['log_ip'],$this->logFromData)){
                return false;
            }
        }var_dump('aaa');

        $this->logBuffer .= $data['msg_id']."\t".$data['log_ip']."\t".date('Y-m-d H:i:s',$data['log_time'])."\t{$data['run_time']}\t{$data['url']}\t{$data['status']}\t{$data['request']}\t{$data['response']}\n";
        if (strlen($this->logBuffer)>=self::MAX_LOG_BUFFER_SIZE){
            $this->writeLog();
        }
    }

    public function writeLog(){
        // 没有统计数据则返回
        if(empty($this->logBuffer)){
            return;
        }
        // 写入磁盘
        file_put_contents(__DIR__.'/../../../'.$this->logDir.date('Y-m-d').'.txt', utf8_encode($this->logBuffer), FILE_APPEND | LOCK_EX);
        $this->logBuffer = '';
    }

    public function onWorkerStop($worker){

    }

}