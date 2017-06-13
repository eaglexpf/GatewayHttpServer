<?php

namespace GatewayHttpServer;

use GatewayHttpServer\Protocols\GatewayHttpProtocol;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;


class Gateway extends Worker{
	/**
	 * 本机ip地址
	 * 单机部署默认 127.0.0.1，如果是分布式部署，需要设置成本机 IP
	 *
	 * @var string
	 */
	public $lanIp = '127.0.0.1';
	/**
	 * 注册服务地址，用户注册gateway和business
	 *
	 * @var string
	 */
	public $registerAddress = '127.0.0.1:1234';
	/**
	 * 客户端心跳间隔
	 *
	 * @var int
	 */
	public $pingInterval = 0;
	/**
	 * 客户端心跳数据
	 *
	 * @var string
	 */
	public $pingData = '';
	/**
	 * 监听来自business链接的起始端口号，启动多少个进程就有多少个端口号
	 *
	 * @var int
	 */
	public $lanStartPort = 0;
	/**
	 * 秘钥(验证内部链接的合法性)
	 *
	 * @var string
	 */
	public $secretKey = '';
	//用户可自定义的回调函数
	protected $_onWorkerStart = null;
	protected $_onConnect = null;
	protected $_onClose = null;
	protected $_onWorkerStop = null;
	/**
	 * 本机客户端的监听端口
	 *
	 * @var string
	 */
	protected $lanPort = '';
	/**
	 * 保存客户端的所有connection对象
	 *
	 * @var array
	 */
	protected $_clientConnection = [];
	/**
	 * 保存所有business的connection对象
	 *
	 * @var array
	 */
	protected $_businessConnection = [];
	/**
	 * 到注册中心的链接
	 *
	 * @var null
	 */
	protected $_registerConnection = null;
	/**
	 * connection的id记录器
	 *
	 * @var int
	 */
	protected static $_connectionIdRecorder = 0;
	/**
	 * gateway监听的客户端端口
	 *
	 * @var int
	 */
	protected $_gatewayPort = 0;
	/**
	 * 用户保持内部长连接的心跳时间间隔
	 */
	const CONNECTION_PING_INTERVAL = 25;

	/**
	 * 构造函数
	 * @param string $socket_name
	 * @param array $context_option
	 */
	public function __construct($socket_name, $context_option=[]){
		parent::__construct($socket_name, $context_option);
		list(, , $this->_gatewayPort) = explode(':', $socket_name);
	}

	public function run(){
		//进程启动；设置用户回调并执行系统回调
		$this->_onWorkerStart = $this->onWorkerStart;
		$this->onWorkerStart = [$this,'onWorkerStart'];
		//建立连接：设置用户回调并执行系统回调
		$this->_onConnect = $this->onConnect;
		$this->onConnect = [$this,'onConnect'];
		//收到消息
		$this->onMessage = [$this,'onMessage'];
		//连接关闭：设置用户回调并执行系统回调
		$this->_onClose = $this->onClose;
		$this->onClose = [$this,'onClose'];
		//进程关闭：设置用户回调并执行系统回调
		$this->_onWorkerStop = $this->onWorkerStop;
		$this->onWorkerStop = [$this,'onWorkerStop'];
		parent::run(); // TODO: Change the autogenerated stub
	}

	/**
	 * 当gateway启动时触发回调函数
	 */
	public function onWorkerStart(){
		//分配一个内部监听business通讯端口
		$this->lanPort = $this->lanStartPort+$this->id;
		//为通讯协议类设置别名；可以让workerman使用
		if (!class_exists('\Protocols\GatewayHttpProtocol')){
			class_alias('GatewayHttpServer\Protocols\GatewayHttpProtocol','Protocols\GatewayHttpProtocol');
		}
		//初始化对business链接请求的监听
		$businessWorker = new Worker("GatewayHttpProtocol://{$this->lanIp}:{$this->lanPort}");
		//如果本地ip不是127.0.0.1；则需要添加gateway到business的心跳包
		if ($this->lanIp !== '127.0.0.1'){
			Timer::add(self::CONNECTION_PING_INTERVAL,[$this,'pingBusiness']);
		}
		$businessWorker->listen();
		//设置内部监听的相关回调
		$businessWorker->onMessage = [$this,'onBusinessMessage'];
		$businessWorker->onConnect = [$this,'onBusinessConnect'];
		$businessWorker->onClose = [$this,'onBusinessClose'];
		//注册gateway的内部通讯地址
		$this->registerAddress();
		//如果注册服务的IP地址不在本地服务器；添加心跳包
		if (strpos($this->registerAddress,'127.0.0.1')){
			Timer::add(self::CONNECTION_PING_INTERVAL,[$this,'pingRegister']);
		}
	}

	/**
	 * 客户端与gateway建立连接时初始化数据
	 *
	 * @param $connection
	 */
	public function onConnect($connection){
		$connection->id = self::generateConnectionId($connection->id);
		//设置该链接的内部通讯数据包报头
		$connection->gatewayHeader = [
			//服务地址
			'local_ip' => ip2long($this->lanIp),
			'local_port' => $this->lanPort,
			//客户端地址
			'client_ip' => ip2long($connection->getRemoteIp()),
			'client_port' => $connection->getRemotePort(),
			//监听客户端的端口
			'gateway_port' => $this->_gatewayPort,
			//客户端的链接id
			'connection_id' => $connection->id,
		];
		$connection->session = '';
		//保存客户端链接的connection对象
		$this->_clientConnection[$connection->id] = $connection;
		//如果有用户自定义的onConnect回调；则执行
		if ($this->_onConnect){
			call_user_func($this->_onConnect,$connection);
		}
		//通知business
		$this->sendToBusiness(GatewayHttpProtocol::CMD_GATEWAY_ON_CONNECT,$connection);
	}

	/**
	 * 客户端发来消息
	 *
	 * @param $connection
	 * @param $data
	 */
	public function onMessage($connection,$data){
		//转发给business
		$connection->start_time = microtime(true);
		$this->sendToBusiness(GatewayHttpProtocol::CMD_GATEWAY_ON_MESSAGE,$connection,$data);
	}
	
	/**
	 * 客户端与gateway链接关闭
	 *
	 * @param $connection
	 */
	public function onClose($connection){
		//通知business
		$this->sendToBusiness(GatewayHttpProtocol::CMD_GATEWAY_ON_CLOSE,$connection);
		//删除缓存中的客户端连接
		unset($this->_clientConnection[$connection->id]);
		//触发用户自定义的onClose
		if ($this->_onClose){
			call_user_func($this->_onClose,$connection);
		}
	}

	/**
	 * gateway进程关闭
	 */
	public function onWorkerStop(){
		//触发用户回调
		if ($this->_onWorkerStop){
			call_user_func($this->_onWorkerStop,$this);
		}
	}

	/**
	 * 设置客户端connection的id
	 *
	 * @return int
	 */
	protected function generateConnectionId($id){
		return md5($this->id.$id.time().rand(0,999999));
//		$max_unsigned_int = 4294967295;
//		if (self::$_connectionIdRecorder >= $max_unsigned_int){
//			self::$_connectionIdRecorder = 0;
//		}
//		$id = ++ self::$_connectionIdRecorder;
//		return $id;
	}

	/******************************
	 *
	 * 与business服务的交互
	 *
	 *******************************/

	/**
	 * business与gateway建立连接时
	 *
	 * @param $connection
	 */
	public function onBusinessConnect($connection){
		//设置该链接是否通过秘钥的验证（如果有秘钥，则初始化为未通过false；如果没有秘钥，则初始化为通过true）
		$connection->authorized = $this->secretKey?false:true;
	}

	/**
	 * business向gateway发来数据时
	 *
	 * @param $connection
	 * @param $data
	 * @return mixed
	 */
	public function onBusinessMessage($connection,$data){
		$cmd = $data['cmd'];//var_dump($data);

		//验证未通过false且cmd不是验证；验证通过true则第一个判断是false，if永不执行
		if (empty($connection->authorized) && $cmd !== GatewayHttpProtocol::CMD_BUSINESS_TO_GATEWAY){
			echo "Error:secretKey\n";
			return $connection->close();
		}

		if (is_array($data['header'])){
			foreach ($data['header'] as $k=>$value){
				Http::header($value);
			}
		}


		switch ($cmd){
			//验证未通过的状态下，business向gateway请求验证
			case GatewayHttpProtocol::CMD_BUSINESS_TO_GATEWAY:
				$worker_info = json_decode($data['body'],true);
				if ($worker_info['secret_key'] !== $this->secretKey){
					echo "Error:secretKey";
					return $connection->close();
				}
				//设置business连接的key
				$connection->key = $connection->getRemoteIp().':'.$worker_info['business_key'];
				//将business连接缓存到本地business连接缓存中
				$this->_businessConnection[$connection->key] = $connection;
				//将该链接设置为验证通过
				$connection->authorized = true;
				break;
			//business返回的数据处理结果
			case GatewayHttpProtocol::CMD_BUSINESS_MSG:
				$time = round(microtime(true)-$this->_clientConnection[$data['connection_id']]->start_time,5);
				$this->_clientConnection[$data['connection_id']]->send($data['body'].$time);
				break;
			//business返回的数据处理结果
			case GatewayHttpProtocol::CMD_BUSINESS_MSG_CLOSE:
				$time = round(microtime(true)-$this->_clientConnection[$data['connection_id']]->start_time,5);
				$this->_clientConnection[$data['connection_id']]->close($data['body'].$time);
				break;
			default:
				echo "error";
		}
//		return ;
	}

	/**
	 * business的链接断开时
	 *
	 * @param $connection
	 */
	public function onBusinessClose($connection){
		//如果该链接存在key，则表示该链接已经验证过，并在缓存中存在
		if (isset($connection->key)){
			//将该连接从缓存中删除
			unset($this->_businessConnection[$connection->key]);
		}
	}

	/**
	 * 向business长连接发送心跳包；保持长连接
	 */
	public function pingBusiness(){
		$data = '';
		foreach ($this->_businessConnection as $connection){
			$connection->send($data);
		}
	}

	/**
	 * 将client_id与随机business链接绑定
	 *
	 * @param $client_connection
	 * @return mixed
	 */
	public function routerBind($client_connection){
		$client_connection->business_address = array_rand($this->_businessConnection);
		return $this->_businessConnection[$client_connection->business_address];
	}

	/**
	 * 向business发送数据
	 *
	 * @param $cmd
	 * @param $connection
	 * @param string $body
	 * @return bool
	 */
	protected function sendToBusiness($cmd,$connection,$body=''){
		$gateway_data = $connection->gatewayHeader;
		$gateway_data['cmd'] = $cmd;
		$gateway_data['body'] = $body;
		$gateway_data['ext_data'] = $connection->session;
		$gateway_data['connection_id'] = $connection->id;
		if ($this->_businessConnection){
			//调用路由函数，选择一个business把请求转发给他
			$business_connection = $this->routerBind($connection);
			if (false === $business_connection->send($gateway_data)){
				$this->log('sendToBusiness fail. May be the send buffer are overflow');
				return false;
			}
		}else{
			$this->log('sendToBusiness fail. The connections between Gateway and BusinessWorker are not ready');
			$connection->close();
			return false;
		}
		return true;
	}

	/******************************
	 *
	 * 与注册中心的交互
	 *
	 *******************************/

	/**
	 * 注册register的链接，发送本地地址
	 */
	public function registerAddress(){
		$address = $this->lanIp.':'.$this->lanPort;//本地服务的地址（ip地址加端口）
		$this->_registerConnection = new AsyncTcpConnection("text://{$this->registerAddress}");
		$this->_registerConnection->send('{"event":"gateway_connect","address":"'.$address.'","secret_key":"'.$this->secretKey.'"}');
		$this->_registerConnection->onClose = [$this,'registerClose'];
		$this->_registerConnection->connect();
	}

	/**
	 * gateway到register的长连接关闭时重新注册长连接
	 */
	public function registerClose(){
		Timer::add(1,[$this,'registerAddress'],null,false);
	}

	/**
	 * 向register长连接发送心跳包
	 */
	public function pingRegister(){
		if ($this->_registerConnection){
			$this->_registerConnection->send($this->pingData);
		}
	}

}