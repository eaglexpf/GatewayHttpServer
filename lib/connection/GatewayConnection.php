<?php
namespace GatewayHttpServer\lib\connection;
use GatewayHttpServer\Protocols\GatewayHttpProtocol;

/**
 * User: Roc.xu
 * Date: 2017/5/22
 * Time: 9:44
 */
class GatewayConnection{
    protected static $gateway_connection;
    protected static $local_ip;
    protected static $local_port;
    protected static $client_ip;
    protected static $client_port;
    protected static $connection_id;
    protected static $session;
    protected static $gateway_port;
    protected static $header = [];
    protected static $body;
    protected static $cmd;

    public static $configFile;

    /**
     * 数据初始化
     * @param $data
     */
    public static function setConstruct($data){
        self::$client_ip = $data['client_ip'];
        self::$client_port = $data['client_port'];
        self::$local_ip = $data['local_ip'];
        self::$local_port = $data['local_port'];
        self::$connection_id = $data['connection_id'];
        self::$cmd = $data['cmd'];
        self::$session = $data['ext_data'];
        self::$body = $data['body'];
        self::$header = [];
    }
    /**
     * 设置客户端的链接
     * @param $connection
     */
    public static function setGatewayConnection($connection){
        self::$gateway_connection = $connection;
    }

    /**
     * 设置header
     * @param $str
     */
    public static function setHeader($str){
        self::$header[] = $str;
    }

    /**
     * 向gateway客户端发送数据
     * @param $body
     * @param bool $bool
     * @return mixed
     */
    public static function sendToClient($body,$bool=true){
        $data = [
            'cmd'               => self::$cmd,
            'local_ip'         => self::$local_ip,
            'local_port'       => self::$local_port,
            'client_ip'         => self::$client_ip,
            'client_port'       => self::$client_port,
            'connection_id'     => self::$connection_id,
            'gateway_port'     => self::$gateway_port,
            'ext_data'         => self::$session,
            'header'            => self::$header,
            'body'              => self::$body,
        ];
        $data['body'] = $body;
        if ($bool){
            $data['cmd'] = GatewayHttpProtocol::CMD_BUSINESS_MSG;
        }else{
            $data['cmd'] = GatewayHttpProtocol::CMD_BUSINESS_MSG_CLOSE;
        }
        return self::$gateway_connection->send($data);
    }
}