<?php
/**
 * User: Roc.xu
 * Date: 2017/10/19
 * Time: 14:33
 */

namespace GatewayHttpServer;


use GatewayHttpServer\lib\LoggerClient;
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
    public $register_address = '127.0.0.1:1230';
    //本机ip(用户内部监听)（使用阿里云或腾讯云产品）
    public $lanIp = '127.0.0.1';
    //链接缓存最大值
    public $maxBufferSize = 50*1024*1024;
    //本机端口(用户内部监听)
    public $lanPort = 0;
    //内部通讯起始端口(用户内部监听)
    public $startPort = 0;
    public $log_address = '';
    public $time_out = 10;
    //不可以reload
//    public $reloadable = false;
    //注册服务中心的链接
    protected $register_con = null;
    //客户端链接集合
    protected $clientConnections = [];

    protected $businessConnections = [];
    protected $businessUID = [];
    
    protected static $_connectionIdRecorder = 0;
    //事件属性集合
    public $event_code = [];
    public $ping_time = null;

    protected $connection_data = [];

    public function __construct($socket_name,$context_option=[])
    {
        $arr = explode(':',$socket_name);
        $protocol = array_shift($arr);
        if ($protocol=='http'){
            if (!class_exists('\Protocols\GatewayHttpProtocol')){
                class_alias('GatewayHttpServer\Protocols\GatewayHttpProtocol','Protocols\GatewayHttpProtocol');
            }
            $socket_name = str_replace($protocol,'GatewayHttpProtocol',$socket_name);
        }
        parent::__construct($socket_name,$context_option);
    }

    public function run(){
        if (empty($this->register_address)){
            $error = "must have register_address";
            echo $error;
            exit($error);
        }
        $this->onWorkerStart = [$this,'onWorkerStart'];
        $this->onConnect = [$this,'onClientConnect'];
        $this->onMessage = [$this,'onClientMessage'];
        $this->onClose = [$this,'onClientClose'];
        $this->onBufferFull = [$this,'onClientBufferFull'];
        $this->onBufferDrain = [$this,'onClientBufferDrain'];
        parent::run();
    }
    //对外服务启动
    public function onWorkerStart(){
        $this->reloadable = false;
        $this->event_code = require_once __DIR__.'/config/event_code.php';
        if (is_null($this->ping_time)){
            $this->ping_time = $this->event_code['ping_time'];
        }
        //设置内部监听端口
        $this->lanPort = $this->startPort+$this->id;
        //为通讯协议类设置别名；可以让workerman使用
        if (!class_exists('\Protocols\BusinessProtocol')){
            class_alias('GatewayHttpServer\Protocols\BusinessProtocol','Protocols\BusinessProtocol');
        }
        $innerTcpBusiness = new Worker("BusinessProtocol://0.0.0.0:{$this->lanPort}");
        $innerTcpBusiness->onConnect = [$this,'onListenConnect'];
        $innerTcpBusiness->onMessage = [$this,'onListenMessage'];
        $innerTcpBusiness->onClose = [$this,'onListenClose'];
        $innerTcpBusiness->onBufferFull = [$this,'onListenBufferFull'];
        $innerTcpBusiness->onBufferDrain = [$this,'onListenBufferDrain'];
        $innerTcpBusiness->listen();
        //注册服务
        $this->registerAddress();
        TcpConnection::$defaultMaxSendBufferSize = $this->maxBufferSize;
        TcpConnection::$maxPackageSize = $this->maxBufferSize;
    }
    //对外服务建立连接
    public function onClientConnect($connection){
        $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
//        $connection->maxSendBufferSize = $this->maxBufferSize;
        $this->clientConnections[$connection->id] = $connection;
        //通知业务服务
        $this->sendToBusiness($this->event_code['clientConnect'],$connection);
    }
    //对外服务接收消息
    public function onClientMessage($connection,$buffer){
//        var_dump($connection->id.'msg',$buffer);
        //通知业务服务
        $this->sendToBusiness($this->event_code['clientMessage'],$connection,$buffer);
    }
    //对外服务关闭连接
    public function onClientClose($connection){
//        var_dump($connection->id.'close');
        if (isset($this->clientConnections[$connection->id])){
            unset($this->clientConnections[$connection->id]);
        }
        //通知业务服务
        $this->sendToBusiness($this->event_code['clientClose'],$connection);
    }
    //当客户端链接的发送缓冲区溢出；停止客户端对应业务链接的接收数据
    public function onClientBufferFull($connection){
        if (isset($connection->business_uid)){
            //停止接收数据
            $this->businessUID[$connection->business_uid]->pauseRecv();
        }
    }
    //当客户端连接的发送缓冲区发送完毕；开始客户端对应业务链接接收数据
    public function onClientBufferDrain($connection){
        if (isset($connection->business_uid)){
            //恢复接收数据
            $this->businessUID[$connection->business_uid]->resumeRecv();
        }
    }
    //路由
    public function routerBind($connection){
        if (!isset($this->connection_data[$connection->id]['business_uid'])||!isset($this->businessUID[$this->connection_data[$connection->id]['business_uid']])){
            $this->connection_data[$connection->id]['business_uid'] = array_rand($this->businessUID);
        }
        return $this->businessUID[$this->connection_data[$connection->id]['business_uid']];
    }
    //向业务服务发送消息
    public function sendToBusiness($event,$connection,$buffer=''){
        if ($this->businessConnections){
            $business_connection = $this->routerBind($connection);
            $msg_id = strtoupper(md5(uniqid(mt_rand(), true)));
            //如果数据存在；设置定时器；时间超时后记录日志
//            if (!empty($this->log_address)){
//                if (!empty($buffer)){
//                    $http_data = Http::decode($buffer,$connection);
//                    $connection->time_out[$msg_id] = Timer::add($this->time_out,function ()use($msg_id,$http_data,$connection){
//                        $log = LoggerClient::encode($msg_id.'-'.$connection->id,time(),$this->time_out,503,$http_data['server']['REQUEST_URI'],$http_data['server']['HTTP_USER_AGENT'].'-'.$connection->getRemoteIp(),'请求超时');
//                        LoggerClient::sendData($this->log_address,$log);
//                    },null,false);
//                    $connection->msg_data[$msg_id] = $http_data;
//                }
//
//            }
            $data = [
                'event' => $event,
                'client_id' => $connection->id,
                'msg_id' => $msg_id,
                'data' => $buffer
            ];
//            var_dump($connection->id.'send',$data);
            $connection->msgTime[$msg_id] = microtime(true);
            $send_result = $business_connection->send($data);
            if (false === $send_result){
                $msg = "SendBufferToWorker fail. May be the send buffer are overflow. See http://wiki.workerman.net/Error2 for detail";
                self::log($msg);
                return false;
            }
        }else{
            $connection->close("no business");
        }
    }
    //业务服务建立连接
    public function onListenConnect($connection){
        $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
        $connection->isTrueConnection = $this->secretKey?false:true;
//        $connection->maxSendBufferSize = $this->maxBufferSize;
    }
    //业务服务发送消息
    public function onListenMessage($connection,$data){
//        $length = unpack('N',substr($buffer,0,4));
//        $json = substr($buffer,4,$length[1]);
//        $data = @json_decode($json,true);
        //数据是否包含event事件属性
        if (!isset($data['event'])){
            $error = "no event\n";
            echo $error;
            return $connection->close($error);
        }
        //链接没有经过验证
        if (!$connection->isTrueConnection){
            //不存在秘钥
            if (!isset($data['secret_key'])){
                $error = "First msg must is secret_key";
                echo $error;
                return $connection->close($error);
            }else if ($data['secret_key']!==$this->secretKey){//存在秘钥但秘钥不相等
                $error = "Register: Key does not match {$data['secret_key']} !== {$this->secretKey}\n";
                echo $error;
                return $connection->close($error);
            }else{//链接通过验证
                $connection->isTrueConnection = true;
            }
        }
        switch ($data['event']){
            //业务服务验证链接
            case $this->event_code['businessConnectToGateway']:
                //不存在业务服务唯一标识
                if (empty($data['business_uid'])){
                    $error = "must have business_uid";
                    $connection->isTrueConnection = false;
                    return $connection->close($error);
                }
                if (isset($this->businessUID[$data['business_uid']])){
                    $error = "repeat business_uid";
                    echo $error;
                    $connection->isTrueConnection = false;
                    return $connection->close($error);
                }
                $this->connection_data[$connection->id]['UID'] = $data['business_uid'];
                $this->businessConnections[$connection->id] = $connection;
                $this->businessUID[$this->connection_data[$connection->id]['UID']] = $connection;
                break;
            case $this->event_code['businessSendToClient']:
            case $this->event_code['businessSendToClientClose']:
                if (empty($data['client_id'])||empty($data['msg_id'])){
                    self::log("Gateway: must have client_id or msg_id");
                    return $connection->close();
                }
                $message = isset($data['data'])?$data['data']:'';
                if (!isset($this->clientConnections[$data['client_id']])){
                    self::log("this connection on close");
                    return $connection->close();
                }

                if (!empty($this->log_address)){
//                    if (isset($this->clientConnections[$data['client_id']]->time_out[$data['msg_id']])){
//                        Timer::del($this->clientConnections[$data['client_id']]->time_out[$data['msg_id']]);
//                    }
                    if (isset($this->clientConnections[$data['client_id']]->msg_data[$data['msg_id']])&&!empty($this->clientConnections[$data['client_id']]->msg_data[$data['msg_id']])) {
                        $request_data = $this->clientConnections[$data['client_id']]->msg_data[$data['msg_id']];
                        list($http_header, $http_body) = explode("\r\n\r\n", $message);
                        $http_header_data = explode("\r\n", $http_header);
                        $decode_buffer = explode(" ", $http_header_data[0]);
                        $msg_time = $this->clientConnections[$data['client_id']]->msgTime[$data['msg_id']];
                        $time = round(microtime(true) - $msg_time, 5);
                        $message = $http_header . "\r\nRun-Time: {$time}\r\n\r\n" . $http_body;
                        $http_body = json_encode($http_body, 320);
                        $log = LoggerClient::encode($data['msg_id'].'-'.$data['client_id'], time(), $time, $decode_buffer[1], $request_data['server']['REQUEST_URI'], $request_data['server']['HTTP_USER_AGENT'].'-'.$this->clientConnections[$data['client_id']]->getRemoteIp(), $http_body);
                        LoggerClient::sendData($this->log_address, $log);
                    }
                }
                if ($data['event']===$this->event_code['businessSendToClient']){
                    $this->clientConnections[$data['client_id']]->send($message);
                }else{
                    $this->clientConnections[$data['client_id']]->close($message);
                }
                break;
            case $this->event_code['ping']:
                break;
            default:
                $error = "未知的事件";
                echo $error;
                $connection->close($error);
        }
    }
    //业务服务关闭连接
    public function onListenClose($connection){
        if (isset($this->businessConnections[$connection->id])){
            unset($this->businessConnections[$connection->id]);
        }
        if (isset($this->connection_data[$connection->id]['UID'])&&isset($this->businessUID[$this->connection_data[$connection->id]['UID']])){
            unset($this->businessUID[$this->connection_data[$connection->id]['UID']]);
            unset($this->connection_data[$connection->id]);
        }
        //通知注册服务中心
    }
    //业务链接的发送缓冲区已满；停止业务链接对应的客户端链接接收数据
    public function onListenBufferFull($connection){
        foreach ($this->clientConnections as $con){
            if ($this->connection_data[$con->id]['business_uid']===$this->connection_data[$connection->id]['UID']){
                $con->pauseRecv();
            }
        }
    }
    //业务链接的发送缓冲区已清空；恢复业务链接对应的客户端链接接收数据
    public function onListenBufferDrain($connection){
        foreach ($this->clientConnections as $con){
            if ($this->connection_data[$con->id]['business_uid']===$this->connection_data[$connection->id]['UID']){
                $con->resumeRecv();
            }
        }
    }
    //注册服务
    public function registerAddress(){
        $address = $this->lanIp.':'.$this->lanPort;
        $this->register_con = new AsyncTcpConnection("Text://{$this->register_address}");
        $data = json_encode([
            'event' => $this->event_code['gatewayConnectToRegister'],
            'secret_key' => $this->secretKey,
            'listen_address' => $address
        ],320);
        $this->register_con->onConnect = function ($connection)use($data){
            $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
            $connection->send($data);
            $this->pingRegister();
        };
        $this->register_con->onClose = [$this,'onRegisterClose'];
        $this->register_con->connect();

    }
    //连接断开自动连接
    public function onRegisterClose(){
        Timer::add(1,[$this,'registerAddress'],null,false);
        if (isset($this->connection_data[$this->register_con->id]['ping_time_id'])){
            Timer::del($this->connection_data[$this->register_con->id]['ping_time_id']);
        }
        if (isset($this->connection_data[$this->register_con->id])){
            unset($this->connection_data[$this->register_con->id]);
        }

    }
    //与注册中心的心跳
    public function pingRegister(){
        if ($this->register_con){
            if (strpos($this->register_address,'127.0.0.1')===false){
                if (!$this->ping_time){
                    return false;
                }
                $data = json_encode([
                    'event' => $this->event_code['ping']
                ],320);
                $this->connection_data[$this->register_con->id]['ping_time_id'] =  Timer::add($this->ping_time,function ()use($data){
                    $this->register_con->send($data);
                });
            }
        }
    }
    //向注册服务中心发送消息
    public function sendToRegister($event,$connection,$data=null){

    }

}