<?php
/**
 * User: Roc.xu
 * Date: 2017/10/24
 * Time: 13:52
 */

namespace GatewayHttpServer\lib;


class LoggerClient
{
    const PACKAGE_FIXED_LENGTH = 16;
    const MAX_UDP_PACKAGE_SIZE = 65507;
    const MAX_CHAR_VALUE = 255;
    const MAX_UNSIGNED_SHORT_VALUE = 65535;

    public static function encode($msg_id,$log_time,$run_time,$status,$url,$request,$response){
        $msg_id_length = strlen($msg_id);
        $log_time_length = strlen($log_time);
        $status_length = strlen($status);
        $url_length = strlen($url);
        $request_length = strlen($request);
        $response_length = strlen($response);
        if ($msg_id_length>self::MAX_CHAR_VALUE){
            $msg_id = substr($msg_id,0,self::MAX_CHAR_VALUE);
            $msg_id_length = strlen($msg_id);
        }
        if ($log_time_length>self::MAX_CHAR_VALUE){
            $log_time = substr($log_time,0,self::MAX_CHAR_VALUE);
            $log_time_length = strlen($log_time);
        }
        if ($status_length>self::MAX_CHAR_VALUE){
            $status = substr($log_time,0,self::MAX_CHAR_VALUE);
            $status_length = strlen($status);
        }
        if ($url_length>self::MAX_CHAR_VALUE){
            $url = substr($log_time,0,self::MAX_CHAR_VALUE);
            $url_length = strlen($url);
        }
        $request_max_length = self::MAX_UDP_PACKAGE_SIZE-self::PACKAGE_FIXED_LENGTH-$msg_id_length-$log_time_length-$status_length-$url_length;
        if ($request_length>$request_max_length){
            $request = substr($request,0,$request_max_length);
            $request_length = strlen($request);
        }
        $response_max_length = self::MAX_UDP_PACKAGE_SIZE-self::PACKAGE_FIXED_LENGTH-$msg_id_length-$log_time_length-$status_length-$url_length-$request_length;
        if ($response_length>$response_max_length){
            $response = substr($response,0,$response_max_length);
            $response_length = strlen($response);
        }
        $buffer = pack('CCfCCNN',$msg_id_length,$log_time_length,$run_time,$status_length,$url_length,$request_length,$response_length);
        $buffer .= $msg_id.$log_time.$status.$url.$request.$response;
        return $buffer;
    }

    public static function decode($buffer){
        $length = unpack("Cmsg_id_length/Clog_time_length/frun_time/Cstatus_length/Curl_length/Nrequest_length/Nresponse_length",$buffer);
        $data = [
            'msg_id' => substr($buffer,self::PACKAGE_FIXED_LENGTH,$length['msg_id_length']),
            'log_time' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$length['msg_id_length'],$length['log_time_length']),
            'run_time' => round($length['run_time'],5),
            'status' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$length['msg_id_length']+$length['log_time_length'],$length['status_length']),
            'url' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$length['msg_id_length']+$length['log_time_length']+$length['status_length'],$length['url_length']),
            'request' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$length['msg_id_length']+$length['log_time_length']+$length['status_length']+$length['url_length'],$length['request_length']),
            'response' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$length['msg_id_length']+$length['log_time_length']+$length['status_length']+$length['url_length']+$length['request_length'],$length['response_length'])
        ];
        return $data;
    }

    public static function sendData($address,$buffer){
        if (!$buffer){
            return false;
        }
        $socket = stream_socket_client($address);
        if (!$socket){
            return false;
        }
        return stream_socket_sendto($socket,$buffer)==strlen($buffer);
    }

}
//消息id；日志记录时间；日志运行时间；状态；参数；返回数据