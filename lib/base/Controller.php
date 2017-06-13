<?php
/**
 * User: Roc.xu
 * Date: 2017/5/22
 * Time: 10:45
 */

namespace GatewayHttpServer\lib\base;


use GatewayHttpServer\lib\connection\GatewayConnection;

class Controller{
    protected static $connection_id;
    protected static $message;

    /**
     * Controller 初始化.
     * @param $connection_id
     * @param $message
     */
    public function __construct($connection_id,$message){
        self::$connection_id = $connection_id;
        self::$message = $message;
    }

    /**
     * 数据不做处理直接返回
     * @param $data
     * @param bool $bool
     * @return mixed
     */
    public static function send($data,$bool=true){
        return GatewayConnection::sendToClient($data,$bool);
    }

    /**
     * 返回json数据
     * @param $data
     * @param bool $bool
     * @return mixed
     */
    public static function sendJson($data,$bool=true){
        GatewayConnection::setHeader("Content-Type:application/json; charset=UTF-8");
        GatewayConnection::setHeader("Access-Control-Allow-Origin:*");
        return GatewayConnection::sendToClient(json_encode($data,320),$bool);
    }

    /**
     * 返回静态文件
     * @param $data
     * @param bool $bool
     */
    public static function sendStatics($data,$bool=true){
        if ($bool){
            return self::send($data,$bool);
        }else{
            GatewayConnection::setHeader("HTTP/1.1 404 Not Found");
            $str = !empty($data)?$data:'404 Not Found';
            return self::send('<html><head><title>404 File not found</title></head><body><center><h3>'.$str.'</h3></center></body></html>',$bool);
        }
    }

    public static function sendView($view,$param=[]){
        foreach ($param as $key=>$value){
            $$key = $value;
        }
    }

    public function get($key,$power=true,$value=null){
        if ($power){
            if (isset(self::$message['get'][$key])){
                return self::$message['get'][$key];
            }
            throw new \Exception("GET：缺少参数$key",400);
        }else{
            if (isset(self::$message['get'][$key])){
                return self::$message['get'][$key];
            }else{
                return $value;
            }
        }
    }
}