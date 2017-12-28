<?php
/**
 * User: Roc.xu
 * Date: 2017/12/19
 * Time: 13:58
 */

namespace GatewayHttpServer\Protocols;


use Workerman\Connection\TcpConnection;

class GatewayHttpProtocol
{
    public static $methods = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'];
    public static function input($recv_buffer, TcpConnection $connection){
        if (!strpos($recv_buffer, "\r\n\r\n")) {
            // Judge whether the package length exceeds the limit.
            if (strlen($recv_buffer) >= TcpConnection::$maxPackageSize) {
                $connection->close();
                return 0;
            }
            return 0;
        }

        list($header,) = explode("\r\n\r\n", $recv_buffer, 2);
        $method = substr($header, 0, strpos($header, ' '));

        if(in_array($method, static::$methods)) {
            return static::getRequestSize($header, $method);
        }else{
            $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }
    }
    protected static function getRequestSize($header, $method)
    {
        if($method === 'GET' || $method === 'OPTIONS' || $method === 'HEAD') {
            return strlen($header) + 4;
        }
        $match = array();
        if (preg_match("/\r\nContent-Length: ?(\d+)/i", $header, $match)) {
            $content_length = isset($match[1]) ? $match[1] : 0;
            return $content_length + strlen($header) + 4;
        }
        return 0;
    }
    public static function decode($buffer){
        return $buffer;
    }
    public static function encode($buffer){
        return $buffer;
    }
}