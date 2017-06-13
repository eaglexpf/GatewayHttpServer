# RocWorker
# roc.xu

### composer require eaglexpf/rocworker @dev

## 代码示例
```php
require_once __DIR__."/vendor/autoload.php";
//配置文件地址
$config_file = __DIR__."/common/config/main.php";
//启动http进程
\Roc\Api::run("http",20001,'http_worker',4,$config_file);
//启动websocket进程
\Roc\Api::run("websocket",20002,'http_worker',4,$config_file);
```