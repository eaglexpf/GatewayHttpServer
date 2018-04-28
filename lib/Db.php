<?php
/**
 * User: Roc.xu
 * Date: 2018/4/26
 * Time: 17:12
 */

namespace GatewayHttpServer\lib;


use Workerman\MySQL\Connection;

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