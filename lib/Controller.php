<?php
/**
 * User: Roc.xu
 * Date: 2018/4/26
 * Time: 17:11
 */

namespace GatewayHttpServer\lib;


use Workerman\Protocols\Http;
use Workerman\Protocols\HttpCache;

class Controller{
    protected static $connection;
    protected static $request;
    protected static $gateway_response;
    protected static $event_code;
    public function __construct($connection,$data,$request)
    {
        self::$connection = $connection;
        self::$gateway_response = $data;
        self::$request = $request;
        self::$event_code = Events::$event_code;
    }
    public static function send($buffer='',$bool = false){
        $request = Http::encode($buffer,self::$connection);
        if ($bool){
            $event = self::$event_code['businessSendToClientClose'];
        }else{
            $event = self::$event_code['businessSendToClient'];
        }
        $data = [
            'event' => $event,
            'client_id' => self::$gateway_response['client_id'],
            'msg_id' => self::$gateway_response['msg_id'],
            'data' => $request
        ];
        return self::$connection->send($data);
    }
    public static function sendJson($data,$bool=false,$http_status=200){
        Http::header("Content-Type:application/json; charset=UTF-8",true,$http_status);
        return self::send(json_encode($data,320),$bool);
    }
    public static function sendBuffer($buffer='',$bool=false,$http_status=200){
        Http::header("HTTP/1.1 {$http_status} ".HttpCache::$codes[$http_status]);
        return self::send($buffer,$bool);
    }
    public static function get_gateway_request(){
        return self::$gateway_response;
    }
    public static function getRequest(){
        return self::$request;
    }
    public static function get($key=false,$power=true,$value=null){
        if (!$key){
            return self::$request['get'];
        }
        if ($power){
            if (isset(self::$request['get'][$key])&&!empty(self::$request['get'][$key])){
                return self::$request['get'][$key];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("GET:缺少参数$key",400);
            }
        }else{
            if (isset(self::$request['get'][$key])){
                return self::$request['get'][$key];
            }else{
                return $value;
            }
        }
    }
    public static function post($key=false,$power=true,$value=null){
        if (!$key){
            return self::$request['post'];
        }
        if ($power){
            if (isset(self::$request['post'][$key])&&!empty(self::$request['post'][$key])){
                return self::$request['post'][$key];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("POST:缺少参数$key",400);
            }
        }else{
            if (isset(self::$request['post'][$key])){
                return self::$request['post'][$key];
            }else{
                return $value;
            }
        }
    }
    public static function getRowData($key=false,$power=true,$value=null){
        if (!$key){
            return $GLOBALS['HTTP_RAW_REQUEST_DATA'];
        }
        $data = @json_decode($GLOBALS['HTTP_RAW_REQUEST_DATA'],true);
        if ($power){
            if (isset($data[$key])&&!empty($data[$key])){
                return $data[$key];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("HTTP_RAW_REQUEST_DATA:缺少参数$key",400);
            }
        }else{
            if (isset($data[$key])){
                return $data[$key];
            }else{
                return $value;
            }
        }
    }
    public static function files(){
        return self::$request['files'];
    }
    public static function request($key=false,$power=true,$value=null){
        if (!$key){
            return self::$request;
        }
        if (isset(self::$request['get'][$key])){
            if ($power){
                if (!empty(self::$request['get'][$key])){
                    return self::$request['get'][$key];
                }else{
                    throw new \Exception("缺少参数$key",400);
                }
            }
            return self::$request['get'][$key];
        }elseif (isset(self::$request['post'][$key])){
            if ($power){
                if (!empty(self::$request['post'][$key])){
                    return self::$request['post'][$key];
                }else{
                    throw new \Exception("缺少参数$key",400);
                }
            }
            return self::$request['post'][$key];
        }elseif($power){
            if (is_null($value)){
                throw new \Exception("缺少参数$key",400);
            }
            return $value;
        }else{
            return $value;
        }
    }
    public static function header($key=false,$power=true,$value=null){
        if (!$key){
            return self::$request['server'];
        }
        if ($power){
            if (isset(self::$request['server'][strtoupper($key)])&&!empty(self::$request['server'][strtoupper($key)])){
                return self::$request['server'][strtoupper($key)];
            }elseif(isset(self::$request['server']['HTTP_'.strtoupper($key)])&&!empty(self::$request['server']['HTTP_'.strtoupper($key)])){
                return self::$request['server']['HTTP_'.strtoupper($key)];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("HEADER:缺少参数$key",400);
            }
        }else{
            if (isset(self::$request['server'][strtoupper($key)])){
                return self::$request['server'][strtoupper($key)];
            }else if(isset(self::$request['server']['HTTP_'.strtoupper($key)])){
                return self::$request['server']['HTTP_'.strtoupper($key)];
            }else{
                return $value;
            }
        }
    }
}