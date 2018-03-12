<?php
namespace GatewayHttpServer\lib;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http;
use Workerman\Protocols\HttpCache;

/**
 * User: Roc.xu
 * Date: 2018/3/2
 * Time: 14:02
 */
class Events
{
    public static $event_code;
    public static $config = [];

    protected static $namespace;
    protected static $dir = __DIR__.'/../../../../';

    public static function onWorkerStart($worker){
        self::$event_code = $worker->event_code;
        if ($worker->config_dir){
            self::$config = require_once $worker->config_dir;
        }
        if (!isset(self::$config['backend'])){
            self::$config['backend'] = 'backend';
        }
        if (!isset(self::$config['static'])){
            self::$config['static'] = 'static';
        }
        self::$namespace = self::$config['backend'].'/controllers';

    }
    public static function onConnect($connection){

    }
    public static function onMessage($connection,$data){
        $controller = new Controller($connection,$data);
        try {
            $response = $controller->getResponse();
            $parse_url = parse_url($response['server']['REQUEST_URI']);
            $path = pathinfo($parse_url['path']);
            if (isset($path['extension'])) {
//                return $controller->send('静态资源');
                return $controller->send(MyError::NotFound());
            }
            if ($path['dirname'] !== '/') {
                $model = self::$namespace . $path['dirname'];
                $action = $path['filename'];
                $file = self::$dir . $model . '.php';
                if (!is_file($file)) {
                    $model = self::$namespace . $path['dirname'] . '/' . $path['filename'];
                    $action = 'index';
                }
            } elseif ($path['filename']) {
                $model = self::$namespace . '/' . $path['filename'];
                $action = 'index';
            } else {
                $model = self::$namespace . '/' . 'index';
                $action = 'index';
            }
            $file = self::$dir . $model . '.php';
            if (!is_file($file)) {
                return $controller->send(MyError::NotFound());
            }
            $model = '\\'.str_replace('/','\\',$model);
            $new_model = new $model($connection, $data);
            if (!method_exists($new_model, $action)) {
                return $controller->send(MyError::NotFound());
            }
            $new_model->$action();
        }catch (\Exception $e){
            $controller->send(MyError::ServerError($e->getCode(),$e->getMessage()));
        }catch (\Error $e){
            $controller->send(MyError::ServerError($e->getCode(),$e->getMessage()));
        }

    }
    public static function onClose($connection){

    }
    public static function onWorkerStop($worker){

    }

}
class MyError{
    public static function NotFound(){
        Http::header("Content-Type:application/json; charset=UTF-8",true,404);
        return json_encode([
            'code' => 404,
            'msg' => '404 not found'
        ]);
    }
    public static function ServerError($code,$msg){
        if (isset(HttpCache::$codes[$code])){
            Http::header("Content-Type:application/json; charset=UTF-8",true,$code);
        }else{
            Http::header("Content-Type:application/json; charset=UTF-8",true,503);
        }

        return json_encode([
            'code' => $code,
            'msg' => $msg
        ],320);
    }
}
class Controller{
    protected static $connection;
    protected static $response;
    protected static $gateway_response;
    protected static $event_code;
    public function __construct($connection,$data)
    {
        self::$connection = $connection;
        self::$gateway_response = $data;
        self::$response = Http::decode(empty($data['data'])?'':$data['data'],$connection);
        self::$event_code = Events::$event_code;
    }
    public static function send($buffer,$bool = false){
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
    public static function get_gateway_response(){
        return self::$gateway_response;
    }
    public static function getResponse(){
        return self::$response;
    }
    public static function get($key=false,$power=true,$value=null){
        if (!$key){
            return self::$response['get'];
        }
        if ($power){
            if (isset(self::$response['get'][$key])&&!empty(self::$response['get'][$key])){
                return self::$response['get'][$key];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("GET:缺少参数$key",400);
            }
        }else{
            if (isset(self::$response['get'][$key])){
                return self::$response['get'][$key];
            }else{
                return $value;
            }
        }
    }
    public static function post($key=false,$power=true,$value=null){
        if (!$key){
            return self::$response['post'];
        }
        if ($power){
            if (isset(self::$response['post'][$key])&&!empty(self::$response['post'][$key])){
                return self::$response['post'][$key];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("POST:缺少参数$key",400);
            }
        }else{
            if (isset(self::$response['post'][$key])){
                return self::$response['post'][$key];
            }else{
                return $value;
            }
        }
    }
    public static function files(){
        return self::$response['files'];
    }
    public static function request($key=false,$power=true,$value=null){
        if (!$key){
            return self::$response;
        }
        if (isset(self::$response['get'][$key])){
            if ($power){
                if (!empty(self::$response['get'][$key])){
                    return self::$response['get'][$key];
                }else{
                    throw new \Exception("缺少参数$key",400);
                }
            }
            return self::$response['get'][$key];
        }elseif (isset(self::$response['post'][$key])){
            if ($power){
                if (!empty(self::$response['post'][$key])){
                    return self::$response['post'][$key];
                }else{
                    throw new \Exception("缺少参数$key",400);
                }
            }
            return self::$response['post'][$key];
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
            return self::$response['server'];
        }
        if (isset(self::$response['server']['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])){
            throw new \Exception("正在验证HEADER是否符合标准",200);
        }
        if ($power){
            if (isset(self::$response['server'][strtoupper($key)])&&!empty(self::$message['server'][strtoupper($key)])){
                return self::$response['server'][strtoupper($key)];
            }elseif(isset(self::$response['server']['HTTP_'.strtoupper($key)])&&!empty(self::$message['server']['HTTP_'.strtoupper($key)])){
                return self::$response['server']['HTTP_'.strtoupper($key)];
            }else if (!is_null($value)){
                return $value;
            }else{
                throw new \Exception("HEADER:缺少参数$key",400);
            }
        }else{
            if (isset(self::$response['server'][strtoupper($key)])){
                return self::$response['server'][strtoupper($key)];
            }else if(isset(self::$response['server']['HTTP_'.strtoupper($key)])){
                return self::$response['server']['HTTP_'.strtoupper($key)];
            }else{
                return $value;
            }
        }
    }
}
class Db{
    protected static $instance = [];
    public static function instance($config_name){
        if (!isset(Events::$config['db'][$config_name])) {
            echo "$config_name not set\n";
            throw new \Exception("$config_name not set\n");
        }

        if (empty(self::$instance[$config_name])) {
            $config                       = Events::$config['db'][$config_name];
            self::$instance[$config_name] = new Connection($config['host'], $config['port'],
                $config['user'], $config['password'], $config['dbname']);
        }
        return self::$instance[$config_name];
    }
    public static function close($config_name){
        if (isset(self::$instance[$config_name])) {
            self::$instance[$config_name]->closeConnection();
            unset(self::$instance[$config_name]);
        }
    }
    public static function closeAll(){
        foreach (self::$instance as $connection) {
            $connection->closeConnection();
        }
        self::$instance = [];
    }
}