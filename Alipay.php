<?php
/***
  * 支付宝接口
  * PHP SDK for openhome.alipay.com
  * 版本 OpenAPI1.0
  * author http://weibo.com/yakeing
  * alipay_class.php
  *
  * extension_loaded('openssl') or die('php需要openssl扩展支持');
 **/
namespace php_alipay;
class Alipay {

    private $version = '1.0'; //版本
    private $sign_type = 'RSA'; //签名类型
    private $referer = 'https://api.apple.com';//来源页面
    private $url = 'https://openapi.alipay.com/gateway.do'; //地址
    private $useragent = 'Apple Mac Pro(ME253CH/A)';

    public $charset = 'utf-8'; //编码 utf-8,gbk,gb2312
    public $debug= FALSE; //调试 TRUE FALSE

    /**
      * 构造函数
    */
    function __construct($config) {
        $this->app_id = $config['app_id'];
        $this->public_file = $config['public_file'];
        $this->private_file = $config['private_file'];
        $this->alipay_public_file = $config['alipay_public_file'];
    } //END __construct



  /**
   * 检验服务器签名 (主)
   *
   *$params  接收到服务器参数
   * @return string
   */
    function checkSignature($params) {
        $postsign = $params ['sign'];
        if(!empty($postsign)){
            $parameters =array(
                'biz_content' => $params['biz_content'],
                'sign_type' => $params['sign_type'],
                'service' => $params['service'],
                'charset' => $params['charset']
            );
            $paramstring = $this->getSignContent($parameters); //串联成字符

            // 验签 支付宝公钥验签
            $result = $this->getSignature($paramstring, $this->alipay_public_file, $postsign);

            // 提取 公钥
            $content = file_get_contents($this->public_file);
            $content = str_replace("-----BEGIN PUBLIC KEY-----", "", $content);
            $content = str_replace("-----END PUBLIC KEY-----", "", $content);
            $content = trim($content); // \n \r

            if ($result) { // 验签合法
                $response_xml = '<success>true</success><biz_content>'.$content.'</biz_content>';
            } else {
                $response_xml = '<success>false</success><error_code>VERIFY_FAILED</error_code><biz_content>'.$content.'</biz_content>';
            }
            $sign = $this->getSignature($response_xml, $this->private_file); // 私钥 签名
            $response = '<?xml version="1.0" encoding="'.$params['charset'].'"?><alipay><response>'.$response_xml.'</response><sign>'.$sign.'</sign><sign_type>RSA</sign_type></alipay>';
            return $response;
        }else{
            return false;
        }
    } //END checkSignature


  /**
   * 数组转字符串
   *
   * 类似 http_build_query 功能
   *  "@" != substr($v, 0, 1)
   * $params 数组
   * @return string
   */
   private function getSignContent($params) {
        $string = array();
        ksort($params);
        foreach($params as $k => $v) {
            array_push($string, $k.'='.$v);
        }
        unset($k, $v);
        return implode('&', $string);
    } //END getSignContent


  /**
   *rsa 生成签名/验签
   *
   * $data 原数据
   * $key_file 公钥/秘钥文件
   * $postsign 签名false /  验签true
   * @return string
   */
   private function getSignature($data, $key_file, $postsign=false) {
        if(is_file($key_file)){
            $pkey = file_get_contents($key_file);
            if(empty($postsign)){ // 秘钥签名
                $res = openssl_get_privatekey($pkey); //生成Resource类型的密钥,别名openssl_pkey_get_private()
                openssl_sign($data, $sign, $res); //生成签名
                $sig = base64_encode($sign);
            }else{ // 公钥验签
                $res = openssl_get_publickey($pkey); // 转换为openssl格式公钥
                $sig = (bool) openssl_verify($data, base64_decode($postsign), $res); // 调用openssl内置方法验签，返回bool值
            }
            openssl_free_key($res); // 释放资源
            return  $sig;
        }else{
            return false;
        }
    } //END getSignature


/*/ ------------------------------------------------------------
$encrypt_data = '';
openssl_public_encrypt($data, $encrypt_data, $pubkey);
$encrypt_data = base64_encode($encrypt_data);
echo $encrypt_data;
echo '<br/>';

// 私钥解密
$encrypt_data = base64_decode($encrypt_data);
openssl_private_decrypt($encrypt_data, $decrypt_data, $prikey);
var_dump($decrypt_data);
// ------------------------------------------------------------*/

  /**
   *post 业务通信接口 (主)
   *
   *$method  接口名称
   *$data  业务参数
   * @return string
   */
    function requestMsg($method, $data) {
        $parameters = array(
                'app_id' => $this->app_id,
                'method' => trim($method),
                'charset' => strtolower($this->charset),
                'sign_type' => $this->sign_type,
                'timestamp' => date("Y-m-d H:i:s"),
                'version' => $this->version
        );
        if (!empty ($data)) $parameters['biz_content'] = json_encode($data);
        $paramstring = $this->getSignContent($parameters); //串联成字符
        $parameters['sign'] = $this->getSignature($paramstring,  $this->private_file); // 用私钥 签名
        if(empty($parameters['sign'])){
            return false;
        }else{
            return $this->http($this->url,  $this->private_file, $parameters);
        }
    } //END requestMsg


 /**
    * HTTP发送请求
    *
    * $url 地址
    * $postfields 表单 ARRAY
    * $cacert_url 证书地址
    * @return string API results
    */
     function http($url, $cacert_url, $postfields = null) {
         //a组装
         $options = array(
              CURLOPT_SSL_VERIFYHOST => 2, //严格认证
              CURLOPT_CAINFO => $cacert_url, //证书地址
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, //HTTP协议版本
              CURLOPT_SSL_VERIFYPEER => true, //https安全
            //  CURLOPT_USERAGENT => $this->useragent, //User-Agent客户端
              CURLOPT_CONNECTTIMEOUT => 30, //连接秒
              CURLOPT_TIMEOUT => 30, //运行秒
              CURLOPT_RETURNTRANSFER => true, //信息以文件流的形式返回
              CURLOPT_ENCODING => 'deflate, gzip', //压缩 1.identity、2.deflate, gzip
              CURLOPT_REFERER => $this->referer, //Referer来源页面
              CURLOPT_HEADER => FALSE, //header头部
              CURLINFO_HEADER_OUT => true //启用时追踪句柄的请求字符串
         );
        if (!empty($postfields)){
            $headers = array();
            $mimeBoundary = md5(uniqid(microtime()));
            $poststr = $this->uploadMultipartForm($postfields, $mimeBoundary);
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $mimeBoundary;

            //POST数据超过1024就分组发送 Expect: 100-continue 告诉对方服务器要分组
            $poststrLength = strlen($poststr);
            if(1024 < $poststrLength){
                $headers[] = 'Transfer-Encoding: chunked'; //不确定长度，分块技术
            }else{
                //$headers[] = 'Expect:'; //解决对方服务器无法识别100-continue错误
                //$headers[] = 'Content-Length: ' . $poststrLength; //固定长度
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $poststr;
         }
         //GET添加编码
         $divide = (strrpos($url, '?') != 0) ? '&' : '?';
     //$url = $url.$divide.'_input_charset='.strtolower($this->charset);
     $url = $url.$divide.'charset='.strtolower($this->charset);

         //HttpResponse发送
         //CURLOPT_URL =>$url,
         $ch = curl_init($url);
         curl_setopt_array($ch, $options);
         $response = curl_exec($ch);
         $ret = curl_errno($ch);

         if ($this->debug) {
            $data = array(
                'Error'  => 0,
                'OutputLength'  => $poststrLength,
                'PostFields' => $postfields,
                'ContentLength'  => strlen($response),
                'Data'  => $response
            );
            if ($ret !== 0) {
                $data['Error'] = curl_error($ch);
                curl_close($ch);
                return $data;
            }else{
                $data['Code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $data['ContentType'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $data['HeaderOut'] = curl_getinfo($ch, CURLINFO_HEADER_OUT);
                curl_close($ch);
                return $data;
            }
        }else{
            curl_close($ch);
            return json_decode($response, true);
        }
   } //END http


  /**
    *  POST模拟表单
    *
    * $fields 表单 array( array( k=> v ), ....)
    * $mimeBoundary 分界符
    * @return string
    */
    function uploadMultipartForm($fields, $mimeBoundary) {
        $data = array();
        foreach ($fields as $name => $val) {
            array_push($data, '--' . $mimeBoundary);
            array_push($data, 'Content-Disposition: form-data; name="' .$name. '"');
            array_push($data, '');
            array_push($data, $val);
        }
        array_push($data, '--' . $mimeBoundary . '--');
        array_push($data, '');
        return implode("\r\n", $data);
    } //END uploadMultipartForm

} //END class