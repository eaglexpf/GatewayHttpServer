<?php
/**
 * User: Roc.xu
 * Date: 2018/3/1
 * Time: 14:47
 */

namespace GatewayHttpServer;


use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Protocols\Http;
use Workerman\Worker;

class Gateway extends Worker
{
    //服务名称
    public $name = 'gateway';
    //秘钥
    public $secretKey = '';
    //注册中心地址
    public $register_address = '';
    //本机ip
    public $lanIp = '127.0.0.1';
    //链接缓存最大值
    public $maxBufferSize = 50*1024*1024;
    //内部通讯起始端口
    public $startPort = 0;

    //注册中心链接
    protected $connections_register = null;
    //客户端链接集合
    protected $connections_client = [];
    //business链接集合
    protected $connections_business = [];
    //business_id集合
    protected $business_ids = [];

    //事件属性集合
    protected $event_code = [];

    //心跳
    public $ping_time = 0;

//    protected $num = 0;
//    protected $log_success_num = 0;
//    protected $log_error_num = 0;

    public $log_address;
    protected $asy_log = null;

    public function __construct($socket_name, $context_option=[])
    {
        $arr = explode(':',$socket_name);
        $protocol = array_shift($arr);
        if ($protocol=='http'){
            //为通讯协议类设置别名；可以让workerman使用
            if (!class_exists('\Protocols\GatewayHttpProtocol')){
                class_alias('GatewayHttpServer\Protocols\GatewayHttpProtocol','Protocols\GatewayHttpProtocol');
            }
            $socket_name = str_replace($protocol,'GatewayHttpProtocol',$socket_name);
        }
        parent::__construct($socket_name, $context_option);
    }

    public function run()
    {
        if (empty($this->register_address)||empty($this->startPort)){
            exit('please register_address and start_port');
        }
        $this->onWorkerStart = [$this,'onWorkerStart'];
        $this->onConnect = [$this,'onClientConnect'];
        $this->onMessage = [$this,'onClientMessage'];
        $this->onClose = [$this,'onClientClose'];
        parent::run(); // TODO: Change the autogenerated stub
    }
    //client监听服务启动
    public function onWorkerStart(){
        //默认设置
        TcpConnection::$defaultMaxSendBufferSize = $this->maxBufferSize;
        TcpConnection::$maxPackageSize = $this->maxBufferSize;
        $this->event_code = require_once __DIR__.'/config/event_code.php';

        //启动business监听服务
        $listen_port = $this->startPort+$this->id;
        //为通讯协议类设置别名；可以让workerman使用
        if (!class_exists('\Protocols\BusinessProtocol')){
            class_alias('GatewayHttpServer\Protocols\BusinessProtocol','Protocols\BusinessProtocol');
        }
        $innerTcpBusiness = new Worker("BusinessProtocol://0.0.0.0:$listen_port");
        $innerTcpBusiness->onConnect = [$this,'onListenConnect'];
        $innerTcpBusiness->onMessage = [$this,'onListenMessage'];
        $innerTcpBusiness->onClose = [$this,'onListenClose'];
        $innerTcpBusiness->onBufferFull = [$this,'onListenBufferFull'];
        $innerTcpBusiness->onBufferDrain = [$this,'onListenBufferDrain'];
        $innerTcpBusiness->listen();

//        Timer::add(20,function (){
//            var_dump("this is worker:".$this->id."   this is gateway_num:".$this->num." this is success_num:".$this->log_success_num." this is error_num".$this->log_error_num);
//        });
        //注册服务
        $this->registerAddress();
        $this->connectLog();
    }
    //client建立连接
    public function onClientConnect($connection){
        $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
        $this->connections_client[$connection->id] = $connection;
        //通知业务服务
        $this->sendToBusiness($this->event_code['clientConnect'],$connection);
    }
    //client接收消息
    public function onClientMessage($connection,$buffer){
//        $this->num++;
        //通知业务服务
        $this->sendToBusiness($this->event_code['clientMessage'],$connection,$buffer);
    }
    //client关闭连接
    public function onClientClose($connection){
        if (isset($this->connections_client[$connection->id])){
            unset($this->connections_client[$connection->id]);
        }
        //通知业务服务
        $this->sendToBusiness($this->event_code['clientClose'],$connection);
    }
    //绑定路由
    public function routeBind($connection){
        if (isset($connection->business_id)){
            if (isset($this->connections_business[$connection->business_id])){
                return $this->connections_business[$connection->business_id];
            }
        }
        $connection->business_id = array_rand($this->connections_business);
        return $this->connections_business[$connection->business_id];
    }
    public function connectLog(){
        if (!$this->log_address){
            return false;
        }
        //为通讯协议类设置别名；可以让workerman使用
        if (!class_exists('\Protocols\LogProtocol')){
            class_alias('GatewayHttpServer\Protocols\LogProtocol','Protocols\LogProtocol');
        }
        $asy = new AsyncTcpConnection('LogProtocol://'.$this->log_address);
        $asy->onConnect = function ($asy){
            $this->asy_log = $asy;
        };
        $asy->onClose = function ($asy){
            $this->asy_log = null;
            $this->connectLog();
        };
        $asy->connect();
    }
    //向business发送消息
    public function sendToBusiness($event,$connection,$buffer=''){
        if ($this->connections_business){
            $business_connection = $this->routeBind($connection);
            $msg_id = strtoupper(md5(uniqid(mt_rand(), true)));
            $data = [
                'event' => $event,
                'client_id' => $connection->id,
                'msg_id' => $msg_id,
                'data' => $buffer
            ];
            $log_address = $this->log_address;
            $time_id = 0;
            if ($log_address&&$buffer!=''){
                $now_time = time();
                $time_id = Timer::add(10,function ()use($log_address,$connection,$buffer,$msg_id,$now_time){
                        $http_data = Http::decode($buffer,$connection);
                        $log = [
                            'ip' => $connection->getRemoteIp(),
                            'request_time' => $now_time,
                            'run_time' => 10,
                            'status' => 408,
                            'method' => $http_data['server']['REQUEST_URI'],
                            'request' => $buffer,
                            'response' => '408 Request Timeout'
                        ];
                        if (!$this->asy_log){
                            $this->connectLog();
                        }else{
                            $this->asy_log->send($log);
                        }
                        $connection->close(Http::encode('408 Request Timeout',$connection));
                },null,false);
            }
            $connection->msg[$msg_id] = [
                'time' => microtime(true),
                'time_id' => $time_id,
                'request_time' => time(),
                'request' => $buffer
            ];
            $send_result = $business_connection->send($data);
            if (false === $send_result){
                $msg = "SendBufferToWorker fail. May be the send buffer are overflow. See http://wiki.workerman.net/Error2 for detail";
                self::log($msg);
            }
        }else{
            $connection->close(Http::encode('no business',$connection));
        }
    }

    public function onListenConnect($connection){
        $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
        $connection->isTrueConnection = $this->secretKey?false:true;
    }
    public function onListenMessage($connection,$data){
        if (empty($data['event'])){
            echo 'gateway:error,business msg must have event';
            return $connection->close("no event");
        }
        if (!$connection->isTrueConnection){
            if (empty($data['secret_key'])){
                echo 'gateway:error,business msg must have secret_key';
                return $connection->close('no auth');
            }elseif ($data['secret_key']!==$this->secretKey){
                echo 'gateway:error,business msg secret_key must ==';
                return $connection->close('error auth');
            }else{
                $connection->isTrueConnection = true;
            }
        }
        switch ($data['event']){
            case $this->event_code['businessConnectToGateway']:
                if (empty($data['business_id'])){
                    echo 'gateway:error,business msg must have business_id';
                    return false;
                }
                if (!in_array($data['business_id'],$this->business_ids)){
                    echo 'gateway:error,business msg the business_id not in array';
                    return false;
                }
                foreach ($this->connections_business as $k=>$v){
                    if ($v->business_id==$data['business_id']){
                        echo 'gateway:error,business msg the business_id repeat';
                        return $connection->close('repeat business_id');
                    }
                }
                $connection->business_id = $data['business_id'];
                $this->connections_business[$connection->id] = $connection;
                break;
            case $this->event_code['businessSendToClient']:
            case $this->event_code['businessSendToClientClose']:
                if (empty($data['client_id'])||empty($data['msg_id'])){
                    echo 'gateway:error,business msg must have client_id and msg_id';
                    return false;
                }
                $message = isset($data['data'])?$data['data']:'';
                if (!isset($this->connections_client[$data['client_id']])){
                    echo 'gateway:error,business msg must have client_id';
                    return false;
                }
                $connection = $this->connections_client[$data['client_id']];
                if (!$connection){
                    return false;
                }
                if ($this->log_address){
                    while (true){
                        $response_data = explode("\r\n\r\n", $message);
                        if(count($response_data)!==2){
                            break;
                        }
                        if (empty($connection->msg[$data['msg_id']])){
                            break;
                        }
                        $status_data = explode("\r\n",$response_data[0]);
                        if (count($status_data)<1){
                            break;
                        }
                        $status_data_one = explode(" ",$status_data[0]);
                        if (count($status_data_one)<3){
                            break;
                        }
                        $status = $status_data_one[1];
                        $request_data = Http::decode($connection->msg[$data['msg_id']]['request'],$connection);
                        $method = $request_data['server']['REQUEST_URI'];
                        $parse_url = parse_url($method);
                        $path = pathinfo($parse_url['path']);
                        if (isset($path['extension'])) {
                            break;
                        }

                        $request_buffer = $connection->msg[$data['msg_id']]['request'];
                        $msg_time = $connection->msg[$data['msg_id']]['time'];
                        $run_time = round(microtime(true) - $msg_time, 5);
                        $message = $response_data[0] . "\r\nRun-Time: {$run_time}\r\n\r\n" . $response_data[1];

                        $log = [
                            'ip' => $connection->getRemoteIp(),
                            'request_time' => $connection->msg[$data['msg_id']]['request_time'],
                            'run_time' => $run_time,
                            'status' => $status,
                            'method' => $method,
                            'request' => $request_buffer,
                            'response' => $message
                        ];
                        if (!$this->asy_log){
                            $this->connectLog();
                        }else{
                            $this->asy_log->send($log);
                        }


//                        $log = LogClient::encode($connection->getRemoteIp(),$connection->msg[$data['msg_id']]['request_time'],$run_time,$status,$method,$request_buffer,$message);
//                        $bool = LogClient::sendData($this->log_address,$log);
//                        if ($bool){
//                            $this->log_success_num++;
//                        }else{
//                            $this->log_error_num++;
//                        }
                        break;
                    }

                }
                if (isset($connection->msg[$data['msg_id']]['time_id'])){
                    Timer::del($connection->msg[$data['msg_id']]['time_id']);
                }
                if ($data['event']===$this->event_code['businessSendToClient']){
                    $this->connections_client[$data['client_id']]->send($message);
                }else{
                    $this->connections_client[$data['client_id']]->close($message);
                }
                break;
            case $this->event_code['ping']:
                break;
            default:
                echo "gateway:error,business msg do not know event";
        }

    }
    public function onListenClose($connection){
        if (isset($this->connections_business[$connection->id])){
            unset($this->connections_business[$connection->id]);
        }
    }
    public function onListenBufferFull($connection){
        $connection->pauseRecv();
    }
    public function onListenBufferDrain($connection){
        $connection->resumeRecv();
    }

    //注册服务
    public function registerAddress(){
        $address = $this->lanIp.':'.($this->startPort+$this->id);
        $this->connections_register = new AsyncTcpConnection("Text://{$this->register_address}");
        $data = json_encode([
            'event' => $this->event_code['gatewayConnectToRegister'],
            'secret_key' => $this->secretKey,
            'listen_address' => $address
        ],320);
        $this->connections_register->onConnect = function ($connection)use($data){
            $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
            $connection->send($data);
            $this->pingRegister();
        };
        $this->connections_register->onMessage = [$this,'onRegisterMessage'];
        $this->connections_register->onClose = [$this,'onRegisterClose'];
        $this->connections_register->connect();
    }
    public function onRegisterMessage($connection,$buffer){
        $data = @json_decode($buffer,true);
        if (empty($data['event'])){
            echo 'gateway:error,register msg must have event';
            return false;
        }
        switch ($data['event']){
            case $this->event_code['registerBroadcastBusinessID']:
                if (!is_array($data['address'])){
                    echo "gateway:error address must is array";
                    return false;
                }
                foreach ($this->business_ids as $k=>$v){
                    if (!in_array($v,$data['address'])){
                        foreach ($this->connections_business as $kk=>$vv){
                            if ($vv->business_id==$v){
                                $vv->close();
                            }
                        }
                    }
                }
                $this->business_ids = $data['address'];
                break;
            default:
                echo 'gateway:error,do not know event';
        }
    }
    //连接断开自动连接
    public function onRegisterClose(){
        Timer::add(1,[$this,'registerAddress'],null,false);
        if (isset($this->connections_register->ping_time_id)){
            Timer::del($this->connections_register->ping_time_id);
        }
    }
    //与注册中心的心跳
    public function pingRegister(){
        if ($this->connections_register){
            if (strpos($this->register_address,'127.0.0.1')===false){
                if (!$this->ping_time){
                    return false;
                }
                $data = json_encode([
                    'event' => $this->event_code['ping']
                ]);
                $this->connections_register->ping_time_id =  Timer::add($this->ping_time,function ()use($data){
                    $this->connections_register->send($data);
                });
            }
        }
    }

}