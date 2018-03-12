# GatewayHttpServer

GatewayHttpServer是基于workerman框架实现的一个http 网关服务开发框架（借鉴了同样基于workerman的gatewayworker框架）

基于workerman的高性能与易用性，GatewayHttpServer可轻松实现一个高性能且支持分布式部署的http API应用

### 安装方式（通过composer安装workerman及workerman/mysql完毕）
    composer require workerman/workerman
    composer require workerman/mysql
    composer require eaglexpf/gateway-http-server @dev

#### 1、分布式API网关服务

## 代码示例

#### 启动register注册服务（新建register.php）

    require_once __DIR__.'/vendor/autoload.php';
    //自定义监听端口号（只需更改你自定义的端口号即可）
    $register = new \GatewayHttpServer\Register('text://0.0.0.0:26001');
    //分布式部署时验证服务合法性秘钥
    $register->secretKey = '123456';

    \Workerman\Worker::runAll();

#### 启动gateway网关服务（新建gateway.php）

    require_once __DIR__.'/vendor/autoload.php';
    //自定义监听端口号（只需更改你自定义的端口号即可）
    $gateway = new \GatewayHttpServer\Gateway("tcp://0.0.0.0:26101");
    //服务名称
    $gateway->name = 'gateway';
    //启动服务进程数
    $gateway->count = 4;
    //分布式部署时验证服务合法性秘钥
    $gateway->secretKey = '123456';
    //内部通讯起始端口;监听business服务起始端口号（有几个进程就有几个端口号，自起始端口好顺序加1）
    $gateway->startPort = '26301';
    //register注册服务地址
    $gateway->register_address = '192.168.56.101:26001';
    //日志地址
    $gateway->log_address = '192.168.1.162:22001';
    //分布式部署时的外网ip（阿里云，腾讯云等）
    $gateway->lanIp = '192.168.56.101';
    //链接最大缓存
    $gateway->maxBufferSize = 50*1024*1024;

    \Workerman\Worker::runAll();

#### 启动business业务服务（新建business.php）

    require_once __DIR__.'/vendor/autoload.php';
    $business = new \GatewayHttpServer\Business();
    //服务名称
    $business->name = 'my_business';
    //启动服务进程数
    $business->count = 4;
    //register注册服务地址
    $business->register_address = '192.168.56.101:26001';
    //分布式部署时验证服务合法性秘钥
    $business->secretKey = '123456';
    //配置文件地址
    $business->config_dir = __DIR__.'/config.php';

    \Workerman\Worker::runAll();

#### 启动logger日志服务（新建logger.php）

    require_once __DIR__.'/vendor/autoload.php';
    //自定义监听端口号（只需更改你自定义的端口号即可）
    $logger = new \GatewayHttpServer\Logger('udp://0.0.0.0:26501');
    //服务名称
    $logger->name = "logger";
    //启动服务进程数
    $logger->count = 4;
    //定时存入数据库
    $log->udp_log_time = 10;
    //数据库配置
    $log->db = [
        'host'	=>	'192.168.1.162',
        'port'	=>	29001,
        'user'	=>	'root',
        'password'	=>	'123456',
        'dbname'	=>	'log',
        'charset'	=>	'utf8mb4',
    ];
    //http端口（查看日志信息）
    $log->http_port = 23001;

    \Workerman\Worker::runAll();




### 备注：
#### 1.API服务business业务配置文件说明：

    return [
        'backend' => 'backend',//项目目录
        'statics' => 'statics',//静态文件目录
        'db' => [//数据库配置（多数据库）
            'ceshi' => [//数据库配置名称
                'host'	=>	'127.0.0.1',//数据库地址
                'port'	=>	3306,//端口号
                'user'	=>	'root',//用户名
                'password'	=>	'123456',//密码
                'dbname'	=>	'gateway',//数据库
                'charset'	=>	'utf8mb4',
            ]
        ]
    ];
