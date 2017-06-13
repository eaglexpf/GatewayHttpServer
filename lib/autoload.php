<?php
/**
 * User: Roc.xu
 * Date: 2016/12/16
 * Time: 13:31
 */

namespace GatewayHttpServer\lib;


class autoload
{
    public static function loadByNamespace($name)
    {
        // 相对路径
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
        // 先尝试在当前目录寻找文件
        $class_file = __DIR__ . '/../' . $class_path.'.php';
        // 文件不存在，则在根目录寻找
        if(empty($class_file) || !is_file($class_file))
        {
            $class_file = __DIR__.'/../../../../'. "$class_path.php";
        }

        // 找到文件
        if(is_file($class_file))
        {
            // 加载
            require_once($class_file);
            if(class_exists($name, false))
            {
                return true;
            }
        }
        return false;
    }
}
// 设置类自动加载回调函数
spl_autoload_register('\GatewayHttpServer\lib\autoload::loadByNamespace');
