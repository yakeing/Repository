<?php
/**
  *  PDO DB类(MYSQL)
  *
  * @author http://weibo.com/yakeing
  * @version 1.1
  *  ----- db_config -----
  *	array(
  *		"db_host" => SAE_MYSQL_HOST_M;  //服务器地址
  *		"db_port" => SAE_MYSQL_PORT;  //服务器端口
  *		"db_user" => SAE_MYSQL_USER;  //数据库用户名
  *		"db_pass" => SAE_MYSQL_PASS;  //数据库密码
  *		"db_database" => SAE_MYSQL_DB;  //数据库名称
  *		"db_charset" => "utf8", //数据库编码
  *		"db_sustained" => false, //开启持久连接
  *		"db_logswitch" => true, //开启日志
  *		"db_logpath" => "saestor://log/" //日志路径
  *	);
  *
  *  PDO::ATTR_ERrmODE 错误处理方式
  *	PDO::ERRMODE_SILENT		不显示错误信息，只设置错误码
  *	PDO::ERRMODE_WARNING		显示警告错 E_WARNING
  *	PDO::ERRMODE_EXCEPTION	抛出异常 exceptions
  *
  *  PDO::ATTR_CASE 字段名称大小写处理(不同数据库)
  *	PDO::CASE_LOWER		强制列名小写
  *	PDO::CASE_NATURAL	保留数据库驱动返回的列名
  *	PDO::CASE_UPPER		强制列名大写
  *
  *  PDO::ATTR_ORACLE_NULLS 转换 NULL 和空字符串
  *	PDO::NULL_NATURAL		不转换
  *	PDO::NULL_EmpTY_STRING	将空字符串转换成 NULL
  *	PDO::NULL_TO_STRING		将 NULL 转换成空字符串
  *
  *  输出数据方式
  *	PDO::FETCH_ASSOC	关联数组形式
  *	PDO::FETCH_NUM		数字索引数组形式
  *	PDO::FETCH_BOTH		两者都有 默认
  *	PDO::FETCH_OBJ		对象形式
  *
  *  预处理方式
  *	PDO::ATTR_EMULATE_PREPARES
  *		true(PDO模拟)
  *		false(数据库服务器 本地完成)
  *
  *  $db->setAttribute(方式, 值); //可以使用
  */
namespace php_pdodb;
class PdoDb{
	private $db;
	private $handle;
	private $logswitch;
	private $logpath;
	private $query;
	private $gpc;
	private $log_msg;
	private $out_error = true;

	//构造函数
	function __construct($db_config){
		$this->gpc =get_magic_quotes_gpc();
		if(!empty($db_config["logswitch"])){
			$this->logswitch = true;
			$this->logpath = $db_config["logpath"];
		}
		$this->Connect($db_config["db_host"].":".$db_config["db_port"],$db_config["db_user"],$db_config["db_pass"],$db_config["db_database"],$db_config["db_charset"],$db_config["db_sustained"]);
	}

	//数据库连接
	private function Connect($dbhost, $dbuser, $dbpw, $dbname, $charset, $sustained){
		$susta = array(
			PDO::ATTR_CASE => PDO::CASE_NATURAL,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
			);
		if(!empty($sustained)){
			$susta[PDO1::ATTR_PERSISTENT] = true;
		}
		try{
			$this->db = new PDO("mysql:host=".$dbhost.";dbname=".$dbname.";charset=".$charset, $dbuser, $dbpw, $susta);
		}catch(PDOException $e){
			$this->Halt('数据库连接失败'.$e->getMessage());
		}
		if(version_compare(PHP_VERSION, '5.3.6', '>')){
			$this->db->exec("SET NAMES ".$charset);
		}
	}

	//查询
	public function Query($sql, $command, $action='查询', $data=''){
		$this->log_msg .= "$action $command $sql \n";
		if($command == 'prepare'){ //预处理
			$handle = $this->db->prepare($sql);
			$handle->execute($data);
		}else if($command == 'exec'){ //insert update delete
			$handle = $this->db->exec($sql);
		}else{ //select
			$handle = $this->db->Query($sql);
		}
		if($this->db->errorCode() !== '00000') $this->Halt("Query Error ".$this->db->errorCode(), $sql);
		return $handle;
	}

	//获取一条记录
	public function GetOne($sql){
		$handle = $this->Query($sql, 'query', '获取一条记录');
		if(!$handle) return false;
		return $handle->fetch(PDO::FETCH_ASSOC);
	}

	//获取多条记录
	public function GetArray($sql){
		$handle = $this->Query($sql, 'query', '获取多条记录');
		if(!$handle) return false;
		return $handle->fetchAll(PDO::FETCH_ASSOC);
	}

	//插入
	public function Insert($table,$dataArray){
		if(!is_array($dataArray) || count($dataArray) < 1){
			$this->Halt('没有要插入的数据');
			return false;
		}
		$str = '';
		$insertData  = array();
		$ankeys = array_rand($dataArray, 1);
		if(is_array($dataArray[$ankeys])){
			$count = count($dataArray)-1;
			for($i=0; $i <= $count; $i++){
				$insertQuery = array();
				foreach($dataArray[$i] as $k=>$v){
					$insertQuery[] = ':'.$k.$i;
					$insertData[$k.$i] = $v;
				}
				$str .= "(".implode(",", $insertQuery)."),";
			}
			$keys = implode(",", array_keys($dataArray[0]));
			$value = rtrim($str, ",");
		}else{
			$insertQuery = array();
			foreach($dataArray as $k=>$v){
				$insertQuery[] = ':'.$k;
				$insertData[$k] = $v;
			}
			$keys = implode(",", array_keys($dataArray));
			$value = "(".implode(",", $insertQuery).")";
		}
		$sql = "INSERT INTO `$table` ($keys) VALUES $value";
		$handle =  $this->Query($sql, 'prepare', '插入数据', $insertData);
		if(!$handle) return false;
		return $handle->rowCount();
	}

	//更新
	public function Update($table,$dataArray,$condition=""){
		if( !is_array($dataArray) || count($dataArray)<1){
			$this->Halt('没有要更新的数据');
			return false;
		}
		$i = 0;
		$data = array();
		while(list($key,$val) = each($dataArray)){
			$data[0][] = "`$key` = :v$i";
			$data[1]['v'.$i] = $val;
			++$i;
		}
		$value = implode(",", $data[0]);
		$sql = "UPDATE `$table` SET $value WHERE 1=1 AND $condition";
		$handle = $this->Query($sql, 'prepare', '插入数据', $data[1]);
		if(!$handle) return false;
		return $handle->rowCount();
	}

	//删除
	public function Delete($table,$condition=""){
		if(empty($condition) ){
			$this->Halt('没有设置删除的条件');
			return false;
		}
		$sql = "DELETE FROM `$table` WHERE 1=1 AND $condition";
		return $this->Query($sql, 'exec', '删除数据');
	}

	//重设
	public function Truncate($table){
		$sql = "TRUNCATE TABLE `$table`";
		if(is_bool($this->Query($sql, 'exec', '清空数据'))) return false;
		return true;
	}

	//获取记录条数
	public function NumRows($table,$condition="2=2"){
		$sql = "SELECT COUNT(*) FROM `$table` WHERE 1=1 AND $condition";
		$handle = $this->Query($sql, 'query', '获取记录数');
		if(is_bool($handle)) return 0;
		return (int)$handle->fetchColumn();
	}

	//获取最后插入的id
	public function InsertId(){
		$this->log_msg .= "最后插入的id为 $id \n";
		return $this->db->lastInsertId();
	}

	//关闭数据库连接
	//默认自动关闭长链接时可人为关闭
	public function Close(){
		$this->db = null;
	}

	//错误提示
	private function Halt($msg, $sql=''){
		$this->log_msg .= $msg.$sql."\n";
		if($this->out_error){
			$msg .= $sql."\n";
		}
		die($msg);
	}

	//写日志
	private function WriteLog(){
		if($this->logswitch){
			$person = date("Y-m-d H:i:s").' '.$this->log_msg;
			$file = $this->logpath.date("Ymd").'.txt';
			if(is_file($file)){
				$person .= file_get_contents($file);
			}
				file_put_contents($file, $person);
		}
	}

	//析构函数
	function __destruct(){
		$this->WriteLog($this->log_msg);
	}

}