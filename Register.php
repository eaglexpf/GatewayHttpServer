<?php
/**
 * User: Roc.xu
 * Date: 2017/5/17
 * Time: 14:50
 */

namespace GatewayHttpServer;


use Workerman\Lib\Timer;
use Workerman\Worker;

class Register extends Worker{
    public $name = 'register';
    public $reloadable = false;
    //秘钥
    public $secretKey = '';
    //缓存所有的gateway地址
    protected $_gateway_connections = [];
    //缓存所有的business地址
    protected $_business_connections = [];

    public function run(){
        $this->onConnect = [$this,'onConnect'];
        $this->onMessage = [$this,'onMessage'];
        $this->onClose = [$this,'onClose'];
        $this->protocol = '\Workerman\Protocols\Text';
        parent::run();
    }

    /**
     * 建立连接时设置定时器，10秒钟内没有验证则关闭连接
     *
     * @param $connection
     */
    public function onConnect($connection){
        $connection->timeout_timerid = Timer::add(10,function ()use($connection){
            echo "auth timeout\n";
            $connection->close();
        },null,false);
    }

    /**
     * 接收消息时
     *
     * @param $connection
     * @param $buffer
     * @return mixed
     */
    public function onMessage($connection,$buffer){
        //删除定时器
        Timer::del($connection->timeout_timerid);
        $data = @json_decode($buffer,true);//注册服务强制使用Text协议；数据格式为json
        //注册服务接收到的数据必须为json格式；必须有event属性；没有的则视为非法连接
        if (empty($data['event'])){
            $error = "Bad request for Gegister service. If you are a client please connect Gateway. Request info(IP:".$connection->getRemoteIp().", Request Buffer:$buffer)\n";
            echo $error;
            return $connection->close($error);
        }
        $event = $data['event'];
        $secret_key = isset($data['secret_key'])?$data['secret_key']:'';
        switch ($event){
            //gateway链接
            case 'gateway_connect':
                //gateway连接数据必须要有address属性
                if (empty($data['address'])){
                    echo "address not found\d";
                    return $connection->close();
                }
                //验证gateway的秘钥是否符合在注册服务中心注册的秘钥
                if ($secret_key !== $this->secretKey){
                    echo "Register: Key does not match $secret_key !== {$this->secretKey}\n";
                    return $connection->close();
                }
                //储存gateway服务的地址
                $this->_gateway_connections[$connection->id] = $data['address'];
                //将所有的gateway地址广播给所有的business服务
                $this->sendToBusiness();
                break;
            //business链接
            case 'business_connect':
                //验证business的秘钥是否符合在注册服务中心注册的秘钥
                if ($secret_key !== $this->secretKey){
                    echo "Register: Key does not match $secret_key !== {$this->secretKey}\n";
                    return $connection->close();
                }
                //储存business服务的地址
                $this->_business_connections[$connection->id] = $connection;
                //将所有的gateway地址发送给新连接上来的business服务
                $this->sendToBusiness($connection);
                break;
            case 'ping':
                break;
            default:
                echo "unknown event:$event IP: ".$connection->getRemoteIp()." Buffer:$buffer\n";
                $connection->close();
        }
    }

    /**
     * 连接关闭时
     *
     * @param $connection
     */
    public function onClose($connection){
        //gateway进程连接断开（认为此gateway服务下线）
        if (isset($this->_gateway_connections[$connection->id])){
            unset($this->_gateway_connections[$connection->id]);//删除缓存的中gateway地址
            $this->sendToBusiness();//将所有的gateway地址广播给所有的business服务
        }
        //business进程连接断开
        if (isset($this->_business_connections[$connection->id])){
            unset($this->_business_connections[$connection->id]);//删除缓存中的business地址
        }
    }

    /**
     * 向business广播gateway内部通讯地址
     *
     * @param null $connection
     */
    public function sendToBusiness($connection=null){
        //格式化数据发送给business
        $data = [
            'event' => 'gateway_address',
            'address' => array_unique(array_values($this->_gateway_connections))
        ];
        $buffer = json_encode($data);
        //如果有单独的连接进程，则只通知该进程
        if ($connection){
            $connection->send($buffer);
            return;
        }
        //通知所有的business进程
        foreach ($this->_business_connections as $con){
            $con->send($buffer);
        }
    }
}