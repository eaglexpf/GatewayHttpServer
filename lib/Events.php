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
    public static $event_code;
    public static $http_code;
    private static $api_config_file;
    private static $http_type;
    public static $config = [];
    //检测配置文件
    protected static function checkConfig(){
        $config = [
            'application' => 'backend',
            'statics' => 'statics'
        ];
        if (is_file(self::$api_config_file)){
            $file_config = require_once self::$api_config_file;
            if (is_array($file_config)){
                $config = array_merge($config,$file_config);
            }
        }
        self::$config = $config;
    }
    protected static function getFile($connection,$buffer,$type){
        $file = __DIR__.'/../../../../'.self::$config['statics'].$buffer['data']['server']['REQUEST_URI'];var_dump($file);
        $baseController = new Controller($connection,$buffer);
        if (!is_file($file)){
            return $baseController->sendStatics(404,'<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>');
        }
        $bool = true;
        foreach (self::$http_type as $k=>$v){
            if ($k==$type){
                Http::header("Content-Type: ".$v.';charset=utf-8');
                $bool = false;
            }
        }
        if ($bool){
            Http::header("Content-Type: text/".$type.';charset=utf-8');
        }
        $baseController->sendStatics(200,file_get_contents($file));
    }

    public static function onWorkerStart($worker){
        self::$event_code = $worker->event_code;
        self::$api_config_file = $worker->api_config_file;
        self::$http_type = require_once __DIR__.'/../config/http_type.php';
        self::$http_code = require_once __DIR__.'/../config/http_code.php';
        self::checkConfig();
    }
    public static function onConnect($connect_id){
        var_dump('this is connect;connect_id is:'.$connect_id);
    }
    public static function onMessage($connection,$buffer){
        try{
            $buffer['data'] = Http::decode($buffer['data'],$connection);
            //将请求地址切分为数组（数组为目录和文件）
            $array = explode("/",explode('?',  self::$config['application']."/controllers".$buffer['data']['server']['REQUEST_URI'])[0]);
            //判断请求地址是否有后缀；有后缀且后缀不是php的抓取静态文件返回
            if(strstr($array[count($array)-1], '.')){
                $file_data = explode('.',$array[count($array)-1]);
                if ($file_data[1]!=='php'){
                    self::getFile($connection,$buffer,$file_data[1]);
                    return;
                }
                $array[count($array)-1] = $file_data[0];
            }
            //没有请求路径时设置默认首页index/index
            if (empty($array[2])&&count($array)==3){
                $array[2] = "index";
                array_push($array, 'index');
            }
            //请求路径只有一个时；设置默认方法index
            if (count($array)==3){
                array_push($array, 'index');
            }
            /**
             * 第一种可能；请求地址包含文件名称和方法名称
             */
            //请求地址的绝对路径（去掉方法名称）
            $action = $array[count($array)-1];
            array_pop($array);
            $controller = implode("\\",$array);
            $file = __DIR__."/../../../../".implode("/",$array).".php";
            //文件不存在
            if (!is_file($file)) {
                /**
                 * 第二种可能：请求地址只包含文件名称（自动添加index方法）
                 */
                array_push($array, $action);
                $action = "index";
                $controller = implode("\\",$array);
                $file = __DIR__."/../../../../".implode("/",$array).".php";
                if (!is_file($file)) {
                    throw new \Exception("Class:$controller Not Found", 404);
                }
            }
            $controller_prefix_len = strlen(self::$config['application']."\\controllers\\");
            $buffer['data']["roc"] = [
                "controller" => substr($controller,$controller_prefix_len),
                "action" => $action
            ];
            //初始化文件
            $model = new $controller($connection,$buffer);
            //方法不存在
            if (!method_exists($model, $action)) {
                throw new \Exception("Action:$action Not Found",404);
            }
            $model->$action();
        }catch (\Exception $e){
            $errorCode = $e->getCode()?$e->getCode():500;
            $baseController = new Controller($connection,$buffer);
            Http::header('Content-Type:application/json; charset=UTF-8');
            $baseController->sendStatics($errorCode,json_encode(['file'=>$e->getFile(),'line'=>$e->getLine(),'code'=>$e->getCode(),'message'=>$e->getMessage()],320),false);
        }catch (\Error $e){
            $errorCode = $e->getCode()?$e->getCode():500;
            $baseController = new Controller($connection,$buffer);
            Http::header('Content-Type:application/json; charset=UTF-8');
            $baseController->sendStatics($errorCode,json_encode(['file'=>$e->getFile(),'line'=>$e->getLine(),'code'=>$e->getCode(),'message'=>$e->getMessage()],320),false);
        }
    }
    public static function onClose($connect_id){
        var_dump('this is close;connect_id is:'.$connect_id);
    }

}