<?php
/**
 * 数据库链接线程池
 * 功能：用户信息操作
 * Created by PhpStorm.
 * User: marin
 * Date: 2017/5/25
 * Time: 13:48
 */
class DBServer {
    public static $instance;
    public $http;

    protected  $poolSize = 20;
    protected $busyPool = array();//忙碌池
    protected $idlePool = array();//空闲池
    private $application;
    private $config;//数据库配置
    private $SerConfig;//数据库线程池的配置
    private $isAsync;
    private $waitQueue = array();//等待队列
    private $waitQueueMax = 100;//等待队列最大值


    public function __construct()
    {
        define('APPLICATION_PATH',dirname(dirname(__DIR__)));
        define('MYPATH', dirname(APPLICATION_PATH));
        $this ->application = new Yaf_Application(APPLICATION_PATH.'/conf/application.ini');
        $this ->application->bootstrap();
        $configObjecter =Yaf_Registry::get('config');//获取注册表中寄存的项
        $this->config = $configObjecter->database->config->toArray();
        $this->SerConfig = $configObjecter->DbServer->toArray();

        $this ->poolSize = isset($this->SerConfig['pool_num']) ? $this->SerConfig['pool_num'] :20;//如果没有配置，默认给20个
        $this ->isAsync = isset($this->SerConfig['async'])?$this->SerConfig['async']:false;//如果没有，这默认不是异步mysql
        $this->multiprocess=isset($this->SerConfig['multiprocess'])?$this->SerConfig['multiprocess']:false;
        $this->http = new swoole_server("0.0.0.0", $this->SerConfig['port']);
        if($this->isAsync){//如果是异步
            $this ->http->set([
                'worker_num'=>1,//1个进程大概占用40M内存，值不易过大，否者CPU开销负载太高
                'max_request'=>0,//主要解决php内存泄漏溢出问题，这里显示设置未0
                'daemonize'=>false,
                'dispatch_mode'=>1,
                'log_file'=>$this->SerConfig['logfile']//服务运行日志，定义标准输出到应用目录下,服务器上需要做定时策略，定期清理日志(swoole不会切分文件)
            ]);
        }else{
            $this ->http->set([
                'worker_num' => 2,
                'task_worker_num' => $this->poolSize,
                'max_request' => 0,
                'daemonize' => false,
                'dispatch_mode' => 1,//todo 使用该模式可能会有问题还需考虑,先这样干起
                'log_file' => $this->SerConfig['logfile']
            ]);
        }

        if($this->isAsync){//异步
            $this->http->on('WorkerStart',array($this,'onStart'));
        }else{//同步
            $this->http->on('Task',array(&$this,'onTask'));
            $this->http->on('Finish',array(&$this,'onFinish'));
        }

        $this ->http->on('Receive' , array(&$this,'onReceive'));
        $this ->http->start();

    }

    public function onReceive(swoole_server $server, int $fd, int $from_id, string $sql){
        if($this->isAsync){//异步
            if(count($this->idlePool) ==0){//当前没有空闲的db可用
                if(count($this->waitQueue) < $this->waitQueueMax){
                    $this->waitQueue[] = array(
                        'fd'=>$fd,
                        'sql'=>$sql
                    );
                }else{
                    $this->http->send($fd,"request too many ,Please try again later");
                }
            }else{
                $this ->doQuery($fd,$sql);
            }
        }else{
            if($this->multiprocess){
                $result = $this ->http->task($sql);//投递任务到task worker池
            }else{
                $result = $this ->http->taskwait($sql);//异步阻塞
            }
            $data_resp=array('status' =>'ok','error'=>0,'errormsg'=>'','result'=>'');
            if($result !== false){//任务投递失败
                $data_resp['result']=$result;
                $this->http->send($fd,json_encode($data_resp));
            }else{
                $data_resp['error']=1;
                $data_resp['status']='error';
                //$data_resp['errormsg']=sprintf("MySQLi Error: %s\n", mysqli_error($mysqli));
                $data_resp['result']=array();
                $this->http->send($fd,json_encode($data_resp));
            }
        }
    }

    /**
     * @param $fd
     * @param $sql
     */
    public function doQuery($fd,$sql)
    {
        //从空闲池取db
        //$this->idlePool[array_rand($this->idlePool)];
        $db = array_pop($this->idlePool);
        $mysqli = $db['mysqli'];
        /**
         * @var mysqli $mysqli
         */
        $mysqli->query($sql,array(&$this,'doSQL'));
        $db['fd'] = $fd;
        //加入到忙碌工作池中
        $this->busyPool[$db['db_sock']] = $db;
    }

    public function doSQL()
    {

    }

    /**
     * @param swoole_server $serv
     */
    public function onStart(swoole_server $serv)
    {
        $connectConfig = [
        'host'=>$this->config['host'],
        'user'=>$this->config['user'],
        'password'=>$this->config['pwd'],
        'database'=>$this->config['name'],
        'charset'=>$this->config['charset']
    ];
        for ($i=0;$this->poolSize;$i++){
            $db = new swoole_mysql();
            $db->connect($connectConfig,function(swoole_mysql $db, bool $result){
                if($result==false)
                {
                    var_dump($result);
                    //todo 打印日志，mysql连接失败
                }
            });

            $db_sock = $db ->sock;
            $this->idlePool[] = [
                'mysqli'=>$db,
                'db_sock'=>$db_sock,
                'fd'=>0,
            ];
        }
    }
    public function onpipeMessage($serv, $src_worker_id, $data)
    {
        //echo "{$serv->worker_id} message from $src_worker_id: $data\n";

        //$this->idle_pool=json_decode($data,true);

    }
    /**
     * @param swoole_server $serv
     * @param int $task_id
     * @param int $src_worker_id
     * @param $data
     */
    public function onTask(swoole_server $serv, int $task_id, int $src_worker_id, $data)
    {

    }

    /**
     *
     * @return DBServer
     */
    public static function getInstance(){
        if(!self::$instance){
            self::$instance = new self();
        }
        return self::$instance;
    }
}
$dbserver= new DBServer();
//DbServer::getInstance();