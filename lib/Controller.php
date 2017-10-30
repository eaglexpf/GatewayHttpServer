<?php
/**
 * User: Roc.xu
 * Date: 2017/10/26
 * Time: 15:46
 */

namespace GatewayHttpServer\lib;


use Workerman\Protocols\Http;

class Controller
{
    protected static $connection;
    protected static $message;
    protected static $event_code;
    public function __construct($connection,$buffer)
    {
        self::$message = $buffer;
        self::$connection = $connection;
        self::$event_code = Events::$event_code;
    }
    public static function send($buffer,$bool = false){
        $response = Http::encode($buffer,self::$connection);
        if ($bool){
            $event = self::$event_code['businessSendToClient'];
        }else{
            $event = self::$event_code['businessSendToClient'];
        }
        $data = [
            'event' => $event,
            'client_id' => self::$message['client_id'],
            'msg_id' => self::$message['msg_id'],
            'data' => $response
        ];

        return self::$connection->send($data);
    }
    public static function sendStatics($code=200,$buffer='',$bool=false){
        Http::header("HTTP/1.1 {$code} ".Events::$http_code[$code]);
        return self::send($buffer,$bool);
    }
    public static function sendJson($data,$bool=false){
        Http::header("Content-Type:application/json; charset=UTF-8");
        return self::send(json_encode($data,320),$bool);
    }
    public static function sendView($view,$param=[]){
        $controller = explode("controllers",self::$message['data']["roc"]["controller"]);
        $file = __DIR__."/../../../../".Events::$config['application']."/views/".str_replace("\\","/",strtolower($controller[0]))."/$view.php";
        if (!is_file($file)){
            return self::sendStatics(404,'<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>',false);
        }
        foreach($param as $k=>$v){
            $$k = $v;
        }
        ini_set('display_errors', 'off');
        ob_start();
        try {
            include $file;
        } catch (\Exception $e) {
            // Jump_exit?
            if ($e->getMessage() != 'jump_exit') {
                echo $e;
            }
        }
        $content = ob_get_clean();
        ini_set('display_errors', 'on');
        if (strtolower(self::$message['data']['server']['HTTP_CONNECTION']) === "keep-alive") {
            return self::send($content);
        } else {
            return self::send($content,false);
        }
    }
    public static function get($key=false,$power=false,$value=null){
        if (!$key){
            return self::$message['data']['get'];
        }
        if ($power){
            if (isset(self::$message['data']['get'][$key])&&!empty(self::$message['data']['get'][$key])){
                return self::$message['data']['get'][$key];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("GET:缺少参数$key",400);
            }
        }else{
            if (isset(self::$message['data']['get'][$key])){
                return self::$message['data']['get'][$key];
            }else{
                return $value;
            }
        }
    }

}