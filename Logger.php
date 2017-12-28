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
    const MAX_LOG_BUFFER_SIZE = 1048576;
    const WRITE_LOG_TIME = 60;
    protected $logBuffer = '';
    protected $collectData = [];
    public $logDir = 'log/';
    public $logFromData = null;
    public function __construct($socket_name,$context_option=[])
    {
        $log_dir = __DIR__.'/../../../'. $this->logDir;
        if(!is_dir($log_dir)){
            umask(0);
            mkdir($log_dir, 0777, true);
        }
        parent::__construct($socket_name,$context_option);
    }

    public function run(){
        $this->onWorkerStart = [$this,'onWorkerStart'];
        $this->onMessage = [$this,'onMessage'];
        $this->onWorkerStop = [$this,'onWorkerStop'];
        $this->onWorkerReload = [$this,'onWorkerReload'];
        parent::run();
    }

    public function onWorkerStart($worker){
        Timer::add(self::WRITE_LOG_TIME,[$this,'writeLog']);
    }


    public function onMessage($connection,$buffer){
        $data = LoggerClient::decode($buffer);
        $data['log_ip'] = $connection->getRemoteIp();
        if (is_array($this->logFromData)){
            if (!in_array($data['log_ip'],$this->logFromData)){
                return false;
            }
        }
        $this->collectData($data);

        $this->logBuffer .= $data['msg_id']."\t".$data['log_ip']."\t".date('Y-m-d H:i:s',$data['log_time'])."\t{$data['run_time']}\t{$data['url']}\t{$data['status']}\t{$data['request']}\t{$data['response']}\n";
        if (strlen($this->logBuffer)>=self::MAX_LOG_BUFFER_SIZE){
            $this->writeLog();
        }
    }
    public function collectData($data){
        if (!isset($this->collectData[$data['ip']])){
            $this->collectData[$data['ip']] = [];
        }
        if (!isset($this->collectData[$data['ip']][$data['url']])){
            $this->collectData[$data['ip']][$data['url']] = [];
        }
        if (!isset($this->collectData[$data['ip']][$data['url']][$data['status']])){
            $this->collectData[$data['ip']][$data['url']][$data['status']] = ['num'=>1,'run_time'=>$data['run_time'],'msg_id'=>[$data['msg_id']]];
        }else{
            $this->collectData[$data['ip']][$data['url']][$data['status']]['num']++;
            $num = $this->collectData[$data['ip']][$data['url']][$data['status']]['num'];
            $run_time = $this->collectData[$data['ip']][$data['url']][$data['status']]['run_time'];
            $this->collectData[$data['ip']][$data['url']][$data['status']]['run_time'] = ($run_time*($num-1)+$data['run_time'])/$num;
            array_push($this->collectData[$data['ip']][$data['url']][$data['status']]['msg_id'],$data['msg_id']);
        }
    }
    public function writeCollectData(){
        $data = $this->collectData;
        $this->collectData = [];
        foreach ($data as $ip => $value){

        }
    }

    public function writeLog(){
        // 没有统计数据则返回
        if(empty($this->logBuffer)){
            return;
        }
        // 写入磁盘
        if(!is_dir(__DIR__.'/../../../'.$this->logDir.'data/'.date('Y-m').'/')){
            umask(0);
            @mkdir(__DIR__.'/../../../'.$this->logDir.'data/'.date('Y-m').'/', 0777, true);
        }
        file_put_contents(__DIR__.'/../../../'.$this->logDir.'data/'.date('Y-m').'/'.date('Y-m-d').'.txt', $this->logBuffer, FILE_APPEND | LOCK_EX);
        $this->logBuffer = '';
    }

    public function onWorkerStop($worker){
        $this->writeLog();
    }
    public function onWorkerReload($worker){
        $this->writeLog();
    }

}