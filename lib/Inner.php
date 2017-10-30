<?php
namespace GatewayHttpServer\lib;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
 * User: Roc.xu
 * Date: 2017/9/13
 * Time: 17:18
 */
class Inner
{
    private static $inner_to_ip = '127.0.0.1';
    private static $inner_to_port = 80;
    private static $async = [];
    private static $event_code;
    public static function onWorkerStart($worker){
        self::$inner_to_ip = $worker->inner_to_ip;
        self::$inner_to_port = $worker->inner_to_port;
        self::$event_code = $worker->event_code;
    }

    public static function onConnect($connection_id){
        if (isset(self::$async[$connection_id])){
            return false;
        }
        self::$async[$connection_id] = new AsyncTcpConnection("tcp://".self::$inner_to_ip.":".self::$inner_to_port);
        self::$async[$connection_id]->onConnect = function ($connection)use($connection_id){
            $connection->id = $connection_id;
        };
        self::$async[$connection_id]->onClose = function ($connection){
            unset(self::$async[$connection->id]);
        };
        self::$async[$connection_id]->connect();
    }

    public static function onMessage($connection,$message){
//        $message['data'] = str_replace('192.168.56.101:26101','192.168.1.165',$message['data']);
        if (!isset(self::$async[$message['client_id']])){
            self::onConnect($message['client_id']);
        }
        self::$async[$message['client_id']]->send($message['data']);
        self::$async[$message['client_id']]->onMessage = function ($async,$http_buffer)use($connection,$message){

//            $buffer = base64_encode($http_buffer);
//            $data = json_encode([
//                'event' => self::$event_code['businessSendToClient'],
//                'client_id' => $message['client_id'],
//                'msg_id' => $message['msg_id'],
//                'length' => strlen($buffer)
//            ]);
//            $data = pack('N',strlen($data)).$data.$buffer;
            $data = [
                'event' => self::$event_code['businessSendToClient'],
                'client_id' => $message['client_id'],
                'msg_id' => $message['msg_id'],
                'data' => $http_buffer
            ];
            $connection->send($data);
        };

    }

    public static function onClose($connection_id){
        if (isset(self::$async[$connection_id])){
            unset(self::$async[$connection_id]);
        }

    }

    public static function onWorkerStop($worker){

    }

}