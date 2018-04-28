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
        $request = Http::decode(empty($data['data'])?'':$data['data'],$connection);
        $controller = new Controller($connection,$data,$request);
        try {
            $parse_url = parse_url($request['server']['REQUEST_URI']);
            $path = pathinfo($parse_url['path']);
            if (isset($path['extension'])) {
//                return $controller->send('静态资源');
                return $controller->send(MyError::NotFound());
            }
            if ($path['dirname'] !== '/') {
                $model = self::$namespace . $path['dirname'] . '/' . $path['filename'];
            } elseif ($path['filename']) {
                $model = self::$namespace . '/' . $path['filename'];
            } else {
                $model = self::$namespace . '/' . 'index';
            }
            $file = self::$dir . $model . '.php';
            if (!is_file($file)) {
                return $controller->send(MyError::NotFound());
            }
            $model = '\\'.str_replace('/','\\',$model);
            $new_model = new $model($connection, $data,$request);
            $action = strtolower($request['server']['REQUEST_METHOD']).'Action';
            if (!method_exists($new_model, $action)) {
                return $controller->send(MyError::NotAllowed());
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
    public static function NotAllowed(){
        Http::header("Content-Type:application/json; charset=UTF-8",true,405);
        return json_encode([
            'code' => 405,
            'msg' => '405 Method Not Allowed'
        ]);
    }
    public static function ServerError($code,$msg){
        if (HttpCache::$codes[$code]){
            Http::header("Content-Type:application/json; charset=UTF-8",true,$code);
        }else{
            Http::header("Content-Type:application/json; charset=UTF-8");
        }
        return json_encode([
            'code' => $code,
            'msg' => $msg
        ],320);
    }
}

