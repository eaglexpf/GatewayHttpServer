<?php
/**
 * User: Roc.xu
 * Date: 2017/10/19
 * Time: 13:31
 */

namespace GatewayHttpServer;


use Workerman\Worker;

class Register extends Worker
{
    //服务名称
    public $name = 'register';
    //秘钥
    public $secretKey = '';
    //不许reload
    public $reloadable = false;
    //网关服务数组
    protected $gateway_con = [];
    //网关服务监听business地址
    protected $gateway_listen_address = [];
    //业务服务数组
    protected $business_con = [];
    //业务服务处理消息数
    protected $business_msg_num = [];
    //消息事件集合
    protected $event_code = [];

    public function run(){
        $this->count = 1;
        $this->onWorkerStart = [$this,'onWorkerStart'];
        $this->onConnect = [$this,'onConnect'];
        $this->onMessage = [$this,'onMessage'];
        $this->onClose = [$this,'onClose'];
        $this->protocol = '\Workerman\Protocols\Text';
        parent::run();
    }

    //服务启动；注册消息事件
    public function onWorkerStart($worker){
        $this->event_code = require_once __DIR__.'/config/event_code.php';
    }

    //建立连接；设置连接验证属性（未通过）
    public function onConnect($connection){
        $connection->isTrueConnection = $this->secretKey?false:true;
    }

    //链接消息
    public function onMessage($connection,$buffer){
        $data = @json_decode($buffer,true);
        //数据是否包含event事件属性
        if (!isset($data['event'])){
            $error = "Bad request for Gegister service. If you are a client please connect Gateway. Request info(IP:".$connection->getRemoteIp().", Request Buffer:$buffer)\n";
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
            //网关服务验证
            case $this->event_code['gatewayConnectToRegister']:
                //不存在网关服务监听业务服务的监听地址
                if (empty($data['listen_address'])){
                    $error = "must have listen_address";
                    echo $error;
                    $connection->isTrueConnection = false;
                    return $connection->close($error);
                }
                //缓存网关服务的信息（监听业务服务的地址；网关服务的链接）
                $this->gateway_con[$connection->id] = $connection;
                $this->gateway_listen_address[$connection->id] = $data['listen_address'];
                $this->sendToBusinessFromGatewayAddress();
                break;
            //业务服务验证
            case $this->event_code['businessConnectToRegister']:
                //不存在业务服务唯一标识
                if (empty($data['business_uid'])){
                    $error = "must have business_uid";
                    echo $error;
                    $connection->isTrueConnection = false;
                    return $connection->close($error);
                }
                if (isset($this->business_msg_num[$data['business_uid']])){
                    $error = "repeat business_uid";
                    echo $error;
                    $connection->isTrueConnection = false;
                    return $connection->close($error);
                }
                $this->business_con[$connection->id] = $connection;
//                $this->business_msg_num[$data['business_uid']] = 0;
//                $connection->uid = $data['business_uid'];
                $this->sendToBusinessFromGatewayAddress($connection);
                break;
            //心跳
            case $this->event_code['ping']:
                break;
            default:
                $error = "未知的事件";
                echo $error;
                $connection->close($error);
        }
    }

    //链接关闭；释放链接在缓存中的信息
    public function onClose($connection){
        if (isset($this->gateway_con[$connection->id])){
            unset($this->gateway_con[$connection->id]);
        }
        if (isset($this->gateway_listen_address[$connection->id])){
            unset($this->gateway_listen_address[$connection->id]);
        }
        if (isset($this->business_con[$connection->id])){
            unset($this->business_con[$connection->id]);
        }
//        if (isset($connection->uid)&&isset($this->business_msg_num[$connection->uid])){
//            unset($this->business_msg_num[$connection->uid]);
//        }
    }

    //将网关服务的通讯地址广播给业务服务
    public function sendToBusinessFromGatewayAddress($connection = null){
        $data = [
            'event' => $this->event_code['registerBroadcastGatewayAddress'],
            'address' => array_unique(array_values($this->gateway_listen_address))
        ];
        $buffer = json_encode($data);
        if (!is_null($connection)){
            return $connection->send($buffer);
        }
        foreach ($this->business_con as $v){
            $v->send($buffer);
        }
    }

}