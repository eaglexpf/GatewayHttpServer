<?php
/**
 * User: Roc.xu
 * Date: 2017/12/19
 * Time: 13:47
 */

namespace GatewayHttpServer\Protocols;


class BusinessProtocol
{
    public static function input($buffer){
        // 接收到的数据还不够8字节，无法得知包的长度，返回0继续等待数据
        if(strlen($buffer)<8)
        {
            return 0;
        }
        // 利用unpack函数将首部4字节转换成数字，首部4字节即为整个数据包长度
        $unpack_data = unpack('Njson_length/Nbuffer_length', $buffer);
        return $unpack_data['json_length']+$unpack_data['buffer_length']+8;
    }
    public static function decode($buffer){
        $length = unpack("Njson_length/Nbuffer_length",$buffer);
        $data = @json_decode(substr($buffer,8,$length['json_length']),true);
        $buffer = substr($buffer,8+$length['json_length'],$length['buffer_length']);
        if (is_array($data)){
            $data['data'] = $buffer?$buffer:'';
        }
        return $data;
    }
    public static function encode($data){
        $buffer = isset($data['data'])?$data['data']:'';
        if (isset($data['data'])){
            unset($data['data']);
        }

        $json = json_encode($data);
        $buffer = pack('NN',strlen($json),strlen($buffer)).$json.$buffer;
//        var_dump($buffer);
        return $buffer;
    }
}