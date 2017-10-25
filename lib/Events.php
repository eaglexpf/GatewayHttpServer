<?php
/**
 * User: Roc.xu
 * Date: 2017/10/25
 * Time: 11:50
 */

namespace GatewayHttpServer\lib;


use Workerman\Protocols\Http;

class Events
{
    private static $event_code;
    public static function onWorkerStart($worker){
        self::$event_code = require_once __DIR__.'/../config/event_code.php';
    }
    public static function onMessage($connection,$buffer){
        $buffer_data = Http::decode($buffer['data'],$connection);
//        var_dump($buffer_data);
        Http::header("Content-Type: application/json;charset=utf-8");
        $response = Http::encode(json_encode(['name'=>'roc','age'=>18]),$connection);
        $data = json_encode([
            'event' => self::$event_code['businessSendToClient'],
            'client_id' => $buffer['client_id'],
            'msg_id' => $buffer['msg_id'],
            'data' => base64_encode($response)
        ]);
        $data = pack('L',strlen($data)).$data;
        $connection->send($data);
    }

}