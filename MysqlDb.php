<?php
/**
  *  MYSQL DB类
  *
  * @author http://weibo.com/yakeing
  * @version 1.1
  *  ----- db_config -----
  *     array(
  *        "db_host" => SAE_MYSQL_HOST_M;  //服务器地址
  *        "db_port" => SAE_MYSQL_PORT;  //服务器端口
  *        "db_user" => SAE_MYSQL_USER;  //数据库用户名
  *        "db_pass" => SAE_MYSQL_PASS;  //数据库密码
  *        "db_database" => SAE_MYSQL_DB;  //数据库名称
  *        "db_charset" => "utf8", //数据库编码
  *        "db_sustained" => false, //开启持久连接
  *        "db_logswitch" => true, //开启日志
  *        "db_logpath" => "saestor://log/" //日志路径
  *        );
  */
namespace php_mysqldb;
class MysqlDb{
    private $link_id;
    private $handle;
    private $logswitch;
    private $logpath;
    private $query;
    private $gpc;
    private $log_msg;
    private $out_sql = true;

    //构造函数
    function __construct($db_config){
        $this->gpc =get_magic_quotes_gpc();
        if(!empty($db_config["logswitch"])){
            $this->logswitch = true;
            $this->logpath = $db_config["logpath"];
        }
        $this->Connect($db_config["db_host"].":".$db_config["db_port"], $db_config["db_user"], $db_config["db_pass"], $db_config["db_database"], $db_config["db_charset"], $db_config["db_sustained"]);
    }

    //数据库连接
    private function Connect($dbhost, $dbuser, $dbpw, $dbname, $charset, $pconnect){
        if(empty($pconnect)){
            $this->link_id = @mysql_connect($dbhost, $dbuser, $dbpw, true);
            if(!$this->link_id){
                $this->Halt('数据库连接失败');
            }
        }else{
            $this->link_id = @mysql_pconnect($dbhost, $dbuser, $dbpw);
            if(!$this->link_id){
                $this->Halt('数据库持久连接失败');
            }
        }
        if(!@mysql_select_db($dbname,$this->link_id)){
            $this->Halt('数据库选择表失败');
        }
        @mysql_query("SET NAMES ".$charset);
    }

    //查询
    public function Query($sql, $action='查询'){
        $this->log_msg .= $action." ".$sql."\n";
        $query = mysql_query($sql,$this->link_id);
        if(!$query) $this->Halt("Query Error ", $sql);
        return $query;
    }

    //获取一条记录
    // mysql_fetch_row 无键名
    public function GetOne($sql){
        $query = $this->Query($sql, '获取一条记录');
         if(!$query) return false;
        return mysql_fetch_array($query, MYSQL_ASSOC);
    }

    //获取多条记录
    // MYSQL_ASSOC 关联数组
    // MYSQL_NUM 数字数组
    // MYSQL_BOTH 默认,同时产生关联和数字数组
    public function GetArray($sql,$result_type = MYSQL_ASSOC){
        $query = $this->Query($sql, '获取多条记录');
        if(!$query) return false;
        $rt = array();
        while($row =& mysql_fetch_array($query,$result_type)){
            $rt[]=$row;
        }
        $this->FreeResult($query);
        return $rt;
    }

    //插入
    public function Insert($table,$dataArray){
        if( !is_array($dataArray) || count($dataArray) < 1){
            $this->Halt('没有要插入的数据');
            return false;
        }
        $str = '';
        $ankeys = array_rand($dataArray, 1);
        if(is_array($dataArray[$ankeys])){
            foreach($dataArray as $v){
                $str .= "('".implode("','", $v)."'),";
            }
            $keys = implode(",", array_keys($dataArray[$ankeys]));
            $value = rtrim($str, ",");
        }else{
            $keys = implode(",", array_keys($dataArray));
            $value = "('".implode("','", $dataArray)."')";
        }
        $sql = "INSERT INTO `$table` ($keys) VALUES $value";
        $query = $this->Query($sql, '插入数据');
         if(!$query) return false;
        $this->FreeResult($query);
        return mysql_affected_rows();
    }

    //更新
    public function Update($table,$dataArray,$condition=""){
        if( !is_array($dataArray) || count($dataArray)<=0){
            $this->Halt('没有要更新的数据');
            return false;
        }
        $value = array();
        while( list($key,$val) = each($dataArray)){
            $value[] = "`$key` = '$val'";
        }
        $value = implode(",", $value);
        $sql = "UPDATE `$table` SET $value WHERE 1=1 AND $condition";
        if(!$this->Query($sql, '更新数据')) return false;
        return mysql_affected_rows();
    }

    //删除
    public function Delete($table,$condition=""){
        if( empty($condition) ){
            $this->Halt('没有设置删除的条件');
            return false;
        }
        $sql = "DELETE FROM `$table` WHERE 1=1 AND $condition";
        if(!$this->Query($sql, '删除数据')) return false;
        return mysql_affected_rows();
    }

    //重设
    public function Truncate($table){
        $sql = "TRUNCATE TABLE `$table`";
        if(!$this->Query($sql, '清空数据')) return false;
        return true;
    }

    //获取记录条数
    public function NumRows($table, $condition='2=2'){
        $sql = "SELECT * FROM `$table` WHERE 1=1 AND $condition";
        $results = $this->Query($sql, '获取记录数');
        if(is_bool($results)) return 0;
        $num = mysql_num_rows($results);
        return $num;
    }

    //获取最后插入的id
    public function InsertId(){
        $id = mysql_insert_id($this->link_id);
        $this->log_msg .= '最后插入的id为'.$id;
        return $id;
    }

    //释放结果集
    private function FreeResult($query){
        if(is_resource($query) && get_resource_type($query) === 'mysql result'){
            return mysql_free_result($query);
        }
    }

    //关闭数据库连接
    private function Close(){
        return @mysql_close($this->link_id);
    }

    //错误提示
    private function Halt($msg, $sql=''){
        $this->log_msg .= $msg.$sql."\n";
        if($this->out_sql){
            $msg .= $sql."\n".mysql_error();
        }
        die($msg);
    }

    //SQL过滤
    private function filterSql($sql){
        if(empty($this->gpc)){
            $sql = addslashes($sql);
        }
        return $sql;
    }

    //写日志
    private function WriteLog(){
        if($this->logswitch){
            $person = date("Y-m-d H:i:s")." ".$this->log_msg;
            $file = $this->logpath.date("Ymd").'.txt';
            if(is_file($file)){
                $person .= file_get_contents($file);
            }
            file_put_contents($file, $person);
       }
    }

    //析构函数
    function __destruct(){
        $this->WriteLog();
        $this->Close();
    }

}