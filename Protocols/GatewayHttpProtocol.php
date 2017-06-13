<?php
namespace GatewayHttpServer\Protocols;
/**
 * User: Roc.xu
 * Date: 2017/5/17
 * Time: 11:20
 */
class GatewayHttpProtocol{
    /**
     * business向gateway建立连接的操作
     */
    const CMD_BUSINESS_TO_GATEWAY = 101;
    /**
     * business返回数据并要求gateway将该数据返回客户端
     */
    const CMD_BUSINESS_MSG = 102;
    /**
     * business返回数据并要求gateway将该数据返回客户端后关闭对客户端的链接
     */
    const CMD_BUSINESS_MSG_CLOSE = 103;
    /**
     * gateway的客户端建立连接
     */
    const CMD_GATEWAY_ON_CONNECT = 201;
    /**
     * gateway接收到客户端发来的信息
     */
    const CMD_GATEWAY_ON_MESSAGE = 202;
    /**
     * gateway的客户端断开连接
     */
    const CMD_GATEWAY_ON_CLOSE = 203;
    /**
     * 包头长度
     */
    const HEAD_LEN = 4;
    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;

    public static $empty = array(
        'cmd'           => 0,
        'local_ip'      => 0,
        'local_port'    => 0,
        'client_ip'     => 0,
        'client_port'   => 0,
        'connection_id' => '',
        'gateway_port'  => 0,
        'header'        =>  [],
        'ext_data'      => '',
        'body'          => '',
    );

    /**
     * 返回包长度
     *
     * @param $buffer
     * @return int
     */
    public static function input($buffer){
        if (strlen($buffer)<self::HEAD_LEN){
            return 0;
        }
        $data = unpack("Npack_len",$buffer);
        return $data['pack_len'];
    }

    public static function encode($data){
        if (!empty($data['header'])&&is_array($data['header'])){
            foreach ($data['header'] as $k=>$value){
                if (strstr($value,'Content-Type')&&strstr($value,'image')){
                    $data['body'] = base64_encode($data['body']);
                }
            }
        }

        $buffer = json_encode($data);
        $data = pack("N",strlen($buffer)+self::HEAD_LEN).$buffer;//var_dump($data);
        return $data;
//        $flag = (int)is_scalar($data['body']);
//        if (!$flag) {
//            $data['body'] = serialize($data['body']);
//        }
//        $data['flag'] |= $flag;
//        $ext_len      = strlen($data['ext_data']);
//        $package_len  = self::HEAD_LEN + $ext_len + strlen($data['body']);
//        return pack("NCNnNnNCnN", $package_len,
//            $data['cmd'], $data['local_ip'],
//            $data['local_port'], $data['client_ip'],
//            $data['client_port'], $data['connection_id'],
//            $data['flag'], $data['gateway_port'],
//            $ext_len).$data['ext_data'].$data['body'];
    }
    public static function decode($buffer){
        $data = substr($buffer,4);//var_dump($data);
        $data = json_decode($data,true);
        if (!empty($data['header'])&&is_array($data['header'])){
            foreach ($data['header'] as $k=>$value){
                if (strstr($value,'Content-Type')&&strstr($value,'image')){
                    $data['body'] = base64_decode($data['body']);
                }
            }
        }

        return $data;
//        if ($data['ext_len'] > 0) {
//            $data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
//            if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
//                $data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
//            } else {
//                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
//            }
////            $data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
//        } else {
//            $data['ext_data'] = '';
//            if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
//                $data['body'] = substr($buffer, self::HEAD_LEN);
//            } else {
//                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
//            }
////            $data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
//        }
//        return $data;
    }
}