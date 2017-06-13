<?php
namespace GatewayHttpServer\lib;
/**
 * User: Roc.xu
 * Date: 2017/5/22
 * Time: 10:03
 */
use GatewayHttpServer\lib\base\Controller;
use GatewayHttpServer\lib\connection\GatewayConnection;

require_once __DIR__.'/autoload.php';

class Events{
    public static $config;

    /**
     * 检测配置文件
     */
    protected static function checkConfig(){
        $roc_config = [
            'application' => 'backend',
            'statics' => 'statics'
        ];

        if (is_file(GatewayConnection::$configFile)){//判断文件是否存在
            $config = require_once GatewayConnection::$configFile;
            foreach ($config as $key=>$value){
                $roc_config[$key] = $value;
            }
        }
        self::$config = $roc_config;
    }

    protected static function getFile($connection_id,$message,$type){
        $file = self::$config['statics'].$message['server']['REQUEST_URI'];
        $baseController = new Controller($connection_id,$message);
        if (!is_file($file)){
            return $baseController->sendStatics($message['server']['REQUEST_URI'].":Not Found",false);
        }
        if (in_array($type,['jpg','png','gif'])){
            GatewayConnection::setHeader("Content-Type: image/".$type.';charset=utf-8');
        }else{
            GatewayConnection::setHeader("Content-Type: text/".$type.';charset=utf-8');
        }
        $baseController->sendStatics(file_get_contents($file),true);
    }

    /**
     * business进程启动
     * @param $worker
     */
    public static function onWorkerStart($worker){
        self::checkConfig();
    }

    /**
     * 用户对gateway的链接
     * @param $connection_id
     */
    public static function onConnect($connection_id){

    }

    /**
     * 用户对gateway的数据
     * @param $connection_id
     * @param $message
     * @return mixed
     */
    public static function onMessage($connection_id,$message){
        try{
            //将请求地址切分为数组（数组为目录和文件）
            $array = explode("/",explode('?',  self::$config['application']."/controllers".$message['server']['REQUEST_URI'])[0]);
            //判断请求地址是否有后缀；有后缀且后缀不是php的抓取静态文件返回
            if(strstr($array[count($array)-1], '.')){
                $file_data = explode('.',$array[count($array)-1]);
                if ($file_data[1]!=='php'){
                    return self::getFile($connection_id,$message,$file_data[1]);

                }
                $array[count($array)-1] = $file_data[0];
//                throw new \Exception($message['server']['REQUEST_URI'].":Not Found",404);
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
            $array[count($array)-1] = ucfirst($array[count($array)-1]);
            $controller = implode("\\",$array);
            $file = __DIR__."/../../../".implode("/",$array).".php";
            //文件不存在
            if (!is_file($file)) {
                /**
                 * 第二种可能：请求地址只包含文件名称（自动添加index方法）
                 */
                array_push($array, $action);
                $action = "index";
                $array[count($array)-1] = ucfirst($array[count($array)-1]);
                $controller = implode("\\",$array);
                $file = __DIR__."/../../../".implode("/",$array).".php";
                if (!is_file($file)) {
                    throw new \Exception("Class:$controller Not Found", 404);
                }
            }
            $message["roc"] = [
                "controller" => $controller,
                "action" => $action
            ];
            //初始化文件
            $model = new $controller($connection_id,$message);
            //方法不存在
            if (!method_exists($model, $action)) {
                throw new \Exception("Action:$action Not Found",404);
            }
            return $model->$action();
        }catch (\Exception $e){
            $errorCode = $e->getCode()?$e->getCode():500;
            $baseController = new Controller($connection_id,$message);
            $baseController->sendJson(["code"=>$errorCode,"message"=>$e->getMessage()]);
        }catch (\Error $e){
            $errorCode = $e->getCode()?$e->getCode():500;
            $baseController = new Controller($connection_id,$message);
            $baseController->sendJson(["code"=>$errorCode,"message"=>$e->getMessage()]);
        }
    }

    /**
     * 用户对gateway链接的关闭
     * @param $connection_id
     */
    public static function onClose($connection_id){
        
    }

    /**
     * business进程关闭
     * @param $worker
     */
    public static function onWorkerStop($worker){

    }
}