<?php
/**
 * User: Roc.xu
 * Date: 2018/3/12
 * Time: 13:58
 */

namespace GatewayHttpServer\Protocols;


class LogProtocol
{
    const PACKAGE_FIXED_LENGTH = 4;
    const MAX_UDP_PACKAGE_SIZE = 65507;
    const MAX_CHAR_VALUE = 255;
    const MAX_UNSIGNED_SHORT_VALUE = 65535;

    public static function input($buffer){
        if (strlen($buffer)<self::PACKAGE_FIXED_LENGTH){
            return 0;
        }
        $json_length = unpack("Njson_length",$buffer);
        if (strlen($buffer)<self::PACKAGE_FIXED_LENGTH+$json_length['json_length']){
            return 0;
        }
        $json = substr($buffer,self::PACKAGE_FIXED_LENGTH,$json_length['json_length']);
        $json_data = json_decode($json,true);
        $length = self::PACKAGE_FIXED_LENGTH+$json_length['json_length'];
        foreach ($json_data as $value){
            $length += $value;
        }
        return $length;
    }

    public static function encode($data){
        $json = json_encode([
            'ip_length' => strlen($data['ip']),
            'request_time_length' => strlen($data['request_time']),
            'run_time_length' => strlen($data['run_time']),
            'status_length' => strlen($data['status']),
            'method_length' => strlen($data['method']),
            'request_length' => strlen($data['request']),
            'response_length' => strlen($data['response'])
        ]);
        $fixed = pack("N",strlen($json));
        $buffer = $fixed.$json.$data['ip'].$data['request_time'].$data['run_time'].$data['status'].$data['method'].$data['request'].$data['response'];
        return $buffer;
    }

    public static function decode($buffer){
        $json_length = unpack("Njson_length",$buffer);
        $json = substr($buffer,self::PACKAGE_FIXED_LENGTH,$json_length['json_length']);
        $json_data = json_decode($json,true);
        $data = [
            'ip' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length'],$json_data['ip_length']),
            'request_time' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length']+$json_data['ip_length'],$json_data['request_time_length']),
            'run_time' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length']+$json_data['ip_length']+$json_data['request_time_length'],$json_data['run_time_length']),
            'status' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length']+$json_data['ip_length']+$json_data['request_time_length']+$json_data['run_time_length'],$json_data['status_length']),
            'method' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length']+$json_data['ip_length']+$json_data['request_time_length']+$json_data['run_time_length']+$json_data['status_length'],$json_data['method_length']),
            'request' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length']+$json_data['ip_length']+$json_data['request_time_length']+$json_data['run_time_length']+$json_data['status_length']+$json_data['method_length'],$json_data['request_length']),
            'response' => substr($buffer,self::PACKAGE_FIXED_LENGTH+$json_length['json_length']+$json_data['ip_length']+$json_data['request_time_length']+$json_data['run_time_length']+$json_data['status_length']+$json_data['method_length']+$json_data['request_length'],$json_data['response_length']),
        ];
        return $data;
    }
}