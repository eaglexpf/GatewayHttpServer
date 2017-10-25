<?php
/**
 * User: Roc.xu
 * Date: 2017/10/20
 * Time: 14:29
 */

namespace GatewayHttpServer;


use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

class Business extends Worker
{
    //注册中心地址
    public $register_address = '127.0.0.1:1230';
    //秘钥
    public $secretKey = '';
    //事件处理类
    public $eventHandler = 'GatewayHttpServer\lib\Events';
    //与注册中心的连接
    protected $register_con = null;
    //事件属性
    protected $event_code = [];
    //业务唯一标识
    protected $UID = '';
    //默认心跳时间
    public $ping_time = null;
    //发送缓冲区最大值
    public $maxBufferSize = 50*1024*1024;

    protected $gatewayConnections = [];
    protected $waitAddress = [];

    protected $connection_data = [];

    public $inner_to_ip = '127.0.0.1';
    public $inner_to_port = 80;

    public function run(){
        $this->onWorkerStart = [$this,'onWorkerStart'];
        parent::run();
    }

    public function onWorkerStart($worker){
        $this->event_code = require_once __DIR__.'/config/event_code.php';
        $this->UID = strtoupper(md5(uniqid(mt_rand(), true)));
        if (is_null($this->ping_time)){
            $this->ping_time = $this->event_code['ping_time'];
        }
        //注册服务
        $this->registerAddress();
        if (is_callable($this->eventHandler.'::onWorkerStart')){
            call_user_func($this->eventHandler.'::onWorkerStart',$worker);
        }
    }
    //注册服务
    public function registerAddress(){
        $this->register_con = new AsyncTcpConnection("Text://{$this->register_address}");
        $this->register_con->onConnect = [$this,'onRegisterConnect'];
        $this->register_con->onMessage = [$this,'onRegisterMessage'];
        $this->register_con->onClose = [$this,'onRegisterClose'];
        $this->register_con->connect();
    }
    public function onRegisterConnect($connection){
        $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
        $data = json_encode([
            'event' => $this->event_code['businessConnectToRegister'],
            'secret_key' => $this->secretKey,
            'business_uid' => $this->UID
        ],320);
        $connection->send($data);
        $this->pingRegister();
    }
    public function onRegisterMessage($connection,$buffer){
        $data = @json_decode($buffer,true);
        if (!isset($data['event'])){
            echo "Received bad data from Register\n";
            return;
        }
        switch ($data['event']){
            case $this->event_code['registerBroadcastGatewayAddress']://gateway地址广播
                if (!is_array($data['address'])){
                    echo "Received bad data from Register. Addresses empty\n";
                    return;
                }
                $this->checkGatewayConnections($data['address']);
                break;
            default:
                echo "Receive bad event:{$data['event']} from Register.\n";
        }
    }
    public function onRegisterClose($connection){
        Timer::add(1,[$this,'registerAddress'],null,false);
        if (isset($this->connection_data[$this->register_con->id]['ping_timer_id'])){
            Timer::del($this->connection_data[$this->register_con->id]['ping_timer_id']);
        }
        if (isset($this->connection_data[$this->register_con->id])){
            unset($this->connection_data[$this->register_con->id]);
        }
    }
    //与注册服务中心的心跳
    public function pingRegister(){
        if ($this->register_con){
            if (strpos($this->register_address,'127.0.0.1')===false){
                if (!$this->ping_time){
                    return false;
                }
                $data = json_encode([
                    'event' => $this->event_code['ping']
                ],320);
                $this->connection_data[$this->register_con->id]['ping_timer_id'] =  Timer::add($this->ping_time,function ()use($data){
                    $this->register_con->send($data);
                });
            }
        }
    }

    //检查地址列表
    public function checkGatewayConnections($address_list){
        foreach ($address_list as $address){
            if (!isset($this->waitAddress[$address])||!isset($this->gatewayConnections[$address])){
                if (isset($this->waitAddress[$address])){
                    continue;
                }
                $this->tryConnectToGateway($address);
            }
        }
    }

    public function tryConnectToGateway($address){
        if (empty($address)){
            return false;
        }
        if (isset($this->gatewayConnections[$address])){
            return false;
        }
        $this->waitAddress[$address] = 0;
        $gateway_con = new AsyncTcpConnection("Text://{$address}");
        $gateway_con->maxSendBufferSize = $this->maxBufferSize;
        $data = json_encode([
            'event' => $this->event_code['businessConnectToGateway'],
            'secret_key' => $this->secretKey,
            'business_uid' => $this->UID
        ]);
        $data = pack('L',strlen($data)).$data;
        $gateway_con->onConnect = function ($connection)use($data,$address){
            $connection->id = strtoupper(md5(uniqid(mt_rand(), true)));
            $connection->send($data);
            $this->connection_data[$connection->id]['gateway_address'] = $address;
            $this->gatewayConnections[$address] = $connection;
            if (isset($this->waitAddress[$address])){
                unset($this->waitAddress[$address]);
            }
            $this->pingGateway($connection);
        };
        $gateway_con->onMessage = [$this,'onGatewayMessage'];
        $gateway_con->onClose = [$this,'onGatewayClose'];
        $gateway_con->onError = function ($connection,$code,$msg)use($address){
            if (isset($this->waitAddress[$address])){
                unset($this->waitAddress[$address]);
            }
        };
        $gateway_con->connect();
    }
    public function onGatewayMessage($connection,$buffer){
        if (strlen($buffer)<4){
            return false;
        }
        $length = unpack("L",substr($buffer,0,4));
        $data = @json_decode(substr($buffer,4,$length[1]),true);
        if (isset($data['length'])){
            $data['data'] = substr($buffer,4+$length[1]);
        }
        if (!isset($data['event'])){
            echo 'no event';
            return false;
        }
        switch ($data['event']){
            case $this->event_code['clientConnect']:
                if (is_callable($this->eventHandler.'::onConnect')){
                    call_user_func($this->eventHandler.'::onConnect',$data['client_id']);
                }
                break;
            case $this->event_code['clientMessage']:
                $data['data'] = base64_decode($data['data']);
                if (is_callable($this->eventHandler.'::onMessage')){
                    call_user_func($this->eventHandler.'::onMessage',$connection,$data);
                }
                break;
            case $this->event_code['clientClose']:
                if (is_callable($this->eventHandler.'::onClose')){
                    call_user_func($this->eventHandler.'::onClose',$data['client_id']);
                }
                break;
        }
    }
    //与gateway的链接关闭时
    public function onGatewayClose($connection){
        $gateway_address = isset($this->connection_data[$connection->id]['gateway_address'])?$this->connection_data[$connection->id]['gateway_address']:'';
        if (empty($gateway_address)){
            return false;
        }
        if (isset($this->waitAddress[$gateway_address])){
            $this->waitAddress[$gateway_address]++;
        }else{
            $this->waitAddress[$gateway_address] = 0;
        }
        if (isset($this->gatewayConnections[$gateway_address])){
            unset($this->gatewayConnections[$gateway_address]);
        }
        //存在心跳；删除心跳
        if (isset($this->connection_data[$connection->id]['ping_time_id'])){
            Timer::del($this->connection_data[$connection->id]['ping_time_id']);
        }
        //每隔一秒尝试重连一次；总共尝试3600次
        if ($this->waitAddress[$gateway_address]<3600){
            Timer::add(1,function ()use($gateway_address){
                $this->tryConnectToGateway($gateway_address);
            },null,false);
        }
        if (isset($this->connection_data[$connection->id])){
            unset($this->connection_data[$connection->id]);
        }
    }
    //与gateway的心跳
    public function pingGateway($connection){
        $gateway_address = isset($this->connection_data[$connection->id]['gateway_address'])?$this->connection_data[$connection->id]['gateway_address']:'';
        if (strpos($gateway_address,'127.0.0.1')===false){
            if (!$this->ping_time){
                return false;
            }
//            $data = json_encode([
//                'event' => $this->event_code['businessConnectToGateway'],
//                'secret_key' => $this->secretKey,
//                'business_uid' => $this->UID
//            ]);
//            $data = pack('L',strlen($data)).$data;
            $data = json_encode([
                'event' => $this->event_code['ping'],
                'msg' => 'this is ping'
            ]);
            $data = pack('L',strlen($data)).$data;
            $this->connection_data[$connection->id]['ping_time_id'] =  Timer::add($this->ping_time,function ()use($connection,$data){
                $connection->send($data);
            });
        }
    }


}