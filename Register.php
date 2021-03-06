<?php
/**
 * User: Roc.xu
 * Date: 2018/3/1
 * Time: 14:00
 */

namespace GatewayHttpServer;


use GatewayHttpServer\lib\Db;
use Workerman\Lib\Timer;
use Workerman\WebServer;
use Workerman\Worker;

class Register extends Worker
{
    //服务名称
    public $name = 'register';
    //秘钥
    public $secretKey = '';
    //不允许reload
    public $reloadable = false;

    //gateway服务集合
    protected $service_gateway = [];
    //business服务集合
    protected $service_business = [];

    //事件集合
    protected $event_code = [];


    public function run()
    {
        $this->count = 1;
        $this->reloadable = false;
        $this->onWorkerStart = [$this,'onWorkerStart'];
        $this->onConnect = [$this,'onConnect'];
        $this->onMessage = [$this,'onMessage'];
        $this->onClose = [$this,'onClose'];
        $this->protocol = '\Workerman\Protocols\Text';
        parent::run(); // TODO: Change the autogenerated stub
    }

    //服务启动
    public function onWorkerStart($worker){
        $this->event_code = require_once __DIR__.'/config/event_code.php';
    }

    //建立连接
    public function onConnect($connection){
        $connection->isTrueConnection = $this->secretKey?false:true;
    }

    //收到消息
    public function onMessage($connection,$buffer){
        $data = @json_decode($buffer,true);
        if (empty($data['event'])){
            echo 'register:msg error,event is not null';
            return $connection->close("register:msg error,event is not null");
        }
        if (!$connection->isTrueConnection){
            if (empty($data['secret_key'])){
                return $connection->close('register:error,must have secret_key');
            }elseif ($data['secret_key']!==$this->secretKey){
                return $connection->close('register:error,the secret must ==');
            }else{
                $connection->isTrueConnection = true;
            }
        }
        switch ($data['event']){
            case $this->event_code['gatewayConnectToRegister']:
                if (empty($data['listen_address'])){
                    return $connection->close('register:error,must have listen_address');
                }
                $connection->listen_address = $data['listen_address'];
                $this->service_gateway[$connection->id] = $connection;
                $this->sendToGateway($connection);
                $this->sendToBusiness();
                break;
            case $this->event_code['businessConnectToRegister']:
                if (empty($data['business_id'])){
                    return $connection->close('register:error,must have business_id');
                }
                $connection->business_id = $data['business_id'];
                $this->service_business[$connection->id] = $connection;
                $this->sendToGateway();
                $this->sendToBusiness($connection);
                break;
            case $this->event_code['ping']:
                break;
            default:
                $connection->close("register:error,i do't know this event");
        }
    }

    //连接关闭
    public function onClose($connection){
        if (isset($this->service_gateway[$connection->id])){
            unset($this->service_gateway[$connection->id]);
        }
        if (isset($this->service_business[$connection->id])){
            unset($this->service_business[$connection->id]);
        }
    }

    //将business的唯一标识广播给gateway
    public function sendToGateway($connection = null){
        $ids = [];
        foreach ($this->service_business as $k=>$v){
            array_push($ids,$v->business_id);
        }
        $data = [
            'event' => $this->event_code['registerBroadcastBusinessID'],
            'address' => $ids
        ];
        $buffer = json_encode($data);
        if (!is_null($connection)){
            return $connection->send($buffer);
        }
        foreach ($this->service_gateway as $k=>$v){
            $v->send($buffer);
        }
    }
    //将gateway的监听地址广播给business
    public function sendToBusiness($connection = null){
        $address = [];
        foreach ($this->service_gateway as $k=>$v){
            array_push($address,$v->listen_address);
        }
        $data = [
            'event' => $this->event_code['registerBroadcastGatewayAddress'],
            'address' => $address
        ];
        $buffer = json_encode($data);
        if (!is_null($connection)){
            return $connection->send($buffer);
        }
        foreach ($this->service_business as $k=>$v){
            $v->send($buffer);
        }
    }

}