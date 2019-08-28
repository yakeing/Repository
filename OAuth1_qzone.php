<?php
/***
  * 腾讯空间
  * PHP SDK for connect.qq.com
  * 版本 OAuth 1.0 a
 * @author http://weibo.com/yakeing
  * OAuth_qzone.php
 **/
class QZONEOAuth {

    public $signature;
    public $url;
    public $http_code;
    public $token_secret;
    public $access_token;
    public $client_secret; //APP secret
    public $client_id; //APP KEY
    public $http_info; //数据包含最后头部返回
    public $URL_VERSION; //HTTP协议版本
    public $ssl_verifypeer; //SSL安全模式
    public $format = 'json'; //定义返回格式json或xml
    public $debug = FALSE; //调试信息 测试模式 TRUE/FALSE
    public $timeout = 30; //设置curl允许执行的最长秒数
    public $connecttimeout = 30; //设置连接等待最长秒数
    public $useragent = 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)'; //设置用户代理[Sae T OAuth2 v0.1]
    public $host = 'http://openapi.qzone.qq.com/'; //地址前端
    public static $signature_method = 'HMAC-SHA1'; //签名方法，暂只支持HMAC-SHA1
    public static $version = '1.0'; //Oauth版本号，1.0
    public static $boundary = ''; //静态 多块的边界
    // public static $Referer = 'weiborobot.sinaapp.com'; //来源
    public $network_type = 'pc'; //授权网络类型 wap pc
/***
  * 构造函数
  */
function __construct($client_id, $client_secret) {
    $this->client_id = $client_id;
    $this->client_secret = $client_secret;
} //END __construct


    // PC授权地址
    function request_tokenURL() { return 'http://openapi.qzone.qq.com/oauth/qzoneoauth_request_token'; }
    function authorizeURL() { return 'http://openapi.qzone.qq.com/oauth/qzoneoauth_authorize'; }
    function access_tokenURL() { return 'http://openapi.qzone.qq.com/oauth/qzoneoauth_access_token'; }

    // WAP授权地址
    function request_wap_tokenURL() { return 'http://open.z.qq.com/moc/oauth_request_token'; }
    function authorize_wap_URL() { return 'http://open.z.qq.com/moc/oauth_authorize'; }
    function access_wap_tokenURL() { return 'http://open.z.qq.com/moc/oauth_access_token'; }

    // 默认 oauth 必要配置
    function oauth_arr_params() {
     return array(
       'oauth_consumer_key' => $this->client_id, //App Key
       'oauth_nonce' => rand(), //9位ini随机码
       'oauth_signature_method' => self::$signature_method, //签名方法，暂只支持HMAC-SHA1
       'oauth_timestamp' => time(), //时间戳
       'oauth_version' => self::$version //版本号，1.0
       );
    } //END oauth_arr_params

/***
  * 获取未授权的 Request Token
  *
  */
function get_request_token($callback= null){
 if($this->network_type == 'wap') $url = $this->request_wap_tokenURL();
   else $url = $this->request_tokenURL();
 $params = $this->oauth_arr_params();
 //$params['oauth_client_ip'] = $this->remote_ip;

  //签名值，密钥为：App Secret
  $params['oauth_signature'] = $this->get_signature($url, 'GET', $params);
  $get_params = $this->oAuthRequest($url, 'GET', $params);
  parse_str($get_params , $arr); //字符串转换数组
     return $arr;
} //END get_request_token


/***
  *
  * 获得授权URL
  *
  * @param string|array $token
  * @param bool $wicket
  *  1.wml版本
  *  2.xhtml版本
  * header('Location: ' . $url);
 **/
function get_authorizeURL($token, $callback=null, $wicket=0) {
 if($this->network_type == 'wap') $URL = $this->authorize_wap_URL();
   else $URL = $this->authorizeURL();
  $URL .= '?oauth_token='. $token .'&oauth_consumer_key='. $this->client_id .'&oauth_callback='.urlencode($callback);
  switch($wicket){
    case '1':
    case '2':
        $URL .= '&g_ut='.$wicket;
        break;
    default:
        $URL .= '';
  }
     return $URL;
} //END get_authorizeURL


/***
  * 使用授权后的Request Token换取Access Token
  *
 **/
function get_access_token($oauth_token, $oauth_token_secret, $oauth_verifier){
 if($this->network_type == 'wap') $URL = $this->access_wap_tokenURL();
   else $url = $this->access_tokenURL();
 $params = $this->oauth_arr_params();
 $params['oauth_token'] = $oauth_token;//第2步返回的token
 $params['oauth_vericode'] = $oauth_verifier;//验证码

  //签名值，密钥为：App Secret和Request Token Secret
  $params['oauth_signature'] = $this->get_signature($url, 'GET', $params, $oauth_token_secret);
  $get_params = $this->oAuthRequest($url, 'GET', $params);
  parse_str($get_params , $arr); //字符串转换数组
     return $arr;
} //END get_access_token


/***
  * 获取用户唯一ID
  *
 **/
function get_user_id($access){
  $user = Array();
  $user['id'] = $access['openid'];
  $user['access_token'] = $access['oauth_token'];
  $user['access_token_secret'] = $access['oauth_token_secret'];
  $user['timestamp'] = $access['timestamp'];
  $user['oauth_signature'] = $access['oauth_signature'];
    return $user;
} //END get_user_id


/***
  * 统一发送接口
  *
  * $pic 上传图片地址
 **/
function get_oauth_call($url, $method, $params, $pic = null){
  $url = $this->host.$url;
  $signature = $this->oauth_arr_params();
  $signature['oauth_token'] = $this->access_token; //授权token
  $params['format'] = $this->format; //格式
  //$signature['clientip'] = $this->remote_ip; //用户IP

  strtoupper($method); //把字母转换大小
  $pairs = array_merge($signature ,$params); //合并数组

  //签名值，密钥为：App Secret和Access Token Secret
  $pairs['oauth_signature'] = $this->get_signature($url, $method, $pairs);
  if (empty($pic)){
    $multi = false;
  }else{
    $multi = true;
    $pairs['pic'] = '@'.$pic;
  }

  // OAuth的请求包装
  $response = $this->oAuthRequest($url, $method, $pairs, $multi);

 if ($this->format === 'json') {
    return json_decode($response, true);
 }else{
    return $response;
 }
} //END get_oauth_call


/***
  * @brief 使用HMAC-SHA1算法生成oauth_signature签名值
  *
  * $params 参数数组
  * $url 地址
  * $method 方式 GET/POST
  * $key 密钥
  *
  * @return 签名值
 **/
function get_signature($url, $method, $params, $key = null){
  $appsecret = $this->URLencode_RFC($this->client_secret);
   if (!empty($key)) $usersecret = $this->URLencode_RFC($key);
       else $usersecret = $this->URLencode_RFC($this->token_secret);
  $key = implode('&', array($appsecret, $usersecret));

  //ksort($params); //自然键名排序
  uksort($params, "strcmp"); //自定义键名排序

  $pairs = array();
  foreach($params as $k => $v) {
    $k = urlencode($k);
      if(is_array($v)) {
        natsort($v); //用"自然排序"算法对数组排序
        foreach($v as $d_v){
          $pairs[] = $k . '=' . $this->URLencode_RFC($d_v);
        }
      }else{
        $pairs[] = $k . '=' . $this->URLencode_RFC($v);
      }
   }
  $sign_parts = $this->URLencode_RFC(implode('&', $pairs));
  $base_string = implode('&', array( $method, $this->URLencode_RFC($url), $sign_parts ));
  //print_r($sign_parts); // 密串

    if (function_exists('hash_hmac')){
        $signature = base64_encode(hash_hmac("sha1", $base_string, $key, true));
    }else{
        $blocksize = 64;
        $hashfunc = 'sha1';
        if (strlen($key) > $blocksize){
            $key = pack('H*', $hashfunc($key));
        }
        $key = str_pad($key,$blocksize,chr(0x00));
        $ipad = str_repeat(chr(0x36),$blocksize);
        $opad = str_repeat(chr(0x5c),$blocksize);
        $hmac = pack('H*',$hashfunc(($key^$opad).pack('H*',$hashfunc(($key^$ipad).$base_string))));
        $signature = base64_encode($hmac);
    }
    return $signature;
} //END get_signature


/**
  * 设置用户授权
  *
  * 通过设置用户授权来获取服务器信任
  * 从而操作用户帐户
  *
  */
function set_remote_token($token) {
  if(is_array($token)){
    $this->access_token = $token[0];
    $this->token_secret = $token[1];
    return TRUE;
  }else{
    return FALSE;
  }
} //END set_remote_token


/**
  * 设置用户IP
  *
  * SDK默认将会通过$_SERVER['REMOTE_ADDR']获取用户IP，在请求微博API时将用户IP附加到Request Header中。
  * 但某些情况下$_SERVER['REMOTE_ADDR']取到的IP并非用户IP，而是一个固定的IP（例如使用SAE的Cron或TaskQueue服务时），
  * 此时就有可能会造成该固定IP达到微博API调用频率限额，导致API调用失败。此时可使用本方法设置用户IP，以避免此问题。
  *
  * @return bool IP为非法IP字符串时，返回false，否则返回true
  */
function set_remote_ip($ip = false){
  if ($ip == false) $ip = $_SERVER['REMOTE_ADDR'];
  if ( ip2long($ip) !== false ) {
    $this->remote_ip = $ip;
    return $ip;
  } else {
    return false;
  }
}


/**
  *  URL编码
  *
  * rawurlencode — 按照 RFC 1738 对 URL 进行编码
  * 返回string
  */
function URLencode_RFC($str){
     return str_replace('+',' ', str_replace('%7E', '~', rawurlencode($str)));
}


/**
 * 格式和签署 OAuth / API请求
 *
 * @return string
 * GET / POST / DELETE
 */
function oAuthRequest($url, $method, $parameters, $multi = false) {

  if (strrpos($url, 'http://') !== 0 && strrpos($url, 'https://') !== 0) {
    $url = "{$this->host}{$url}";
  }

  switch ($method) {
    case 'GET':
        $url = $url . '?' . http_build_query($parameters);
        return $this->http($url, 'GET');
    default:
        $headers = array();
        if (!$multi && (is_array($parameters) || is_object($parameters)) ) {
            $body = http_build_query($parameters);
        } else {
            $body = self::build_http_query_multi($parameters);
            $headers[] = "Content-Type: multipart/form-data; boundary=" . self::$boundary;
        }
     return $this->http($url, $method, $body, $headers);
  }
}


/**
 * 发送HTTP请求
 *
 * @return string API results
 * @ignore
 */
function http($url, $method, $postfields = NULL, $headers = array()) {
  $this->http_info = array();
  $ci = curl_init();
  /* Curl settings */
  // CURL_HTTP_VERSION_NONE (默认值，让cURL自己判断使用哪个版本)
  // CURL_HTTP_VERSION_1_0 (强制使用 HTTP/1.0)80 http
  // CURL_HTTP_VERSION_1_1 (强制使用 HTTP/1.1) 443 https
  if (strrpos($url, 'https://') !== 0) {
    $this->URL_VERSION = CURL_HTTP_VERSION_1_0;
    $this->ssl_verifypeer = FALSE;
  } else {
    $this->URL_VERSION = CURL_HTTP_VERSION_1_1;
    $this->ssl_verifypeer = TRUE;
  }

  curl_setopt($ci, CURLOPT_HTTP_VERSION, $URL_VERSION); //HTTP协议版本
  curl_setopt($ci, CURLOPT_USERAGENT, $this->useragent); //客户端User-Agent
  curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connecttimeout); //连接秒
  curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout); //运行秒
  curl_setopt($ci, CURLOPT_RETURNTRANSFER, TRUE); //信息以文件流的形式返回
  curl_setopt($ci, CURLOPT_ENCODING, ""); //压缩 1.identity、2.deflate, gzip
  //curl_setopt($ci, CURLOPT_REFERER, "http://www.".self::$Referer."/"); //来源页面Referer[腾讯报错]
  curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, $this->ssl_verifypeer); //https安全连接
  curl_setopt($ci, CURLOPT_HEADERFUNCTION, array($this, 'getHeader')); //回调函数[cURL的资源句柄][输出的header数据]
  curl_setopt($ci, CURLOPT_HEADER, FALSE); //头部

  switch ($method) {
    case 'POST':
      curl_setopt($ci, CURLOPT_POST, TRUE);
      if (!empty($postfields)) {
          curl_setopt($ci, CURLOPT_POSTFIELDS, $postfields);
          $this->postdata = $postfields;
      }
      break;
      case 'DELETE':
        curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if (!empty($postfields)) {
          $url = "{$url}?{$postfields}";
        }
  }

  //模拟客户端发送文件头
  if ( isset($this->access_token) && $this->access_token )
     $headers[] = "Authorization: OAuth ".$this->access_token;

     $headers[] = "X-FORWARDED-FOR: " . $this->remote_ip;
     $headers[] = "CLIENT-IP: " . $this->remote_ip;

  curl_setopt($ci, CURLOPT_URL, $url );
  curl_setopt($ci, CURLOPT_HTTPHEADER, $headers );
  curl_setopt($ci, CURLINFO_HEADER_OUT, TRUE );

  $response = curl_exec($ci);
  $this->http_code = curl_getinfo($ci, CURLINFO_HTTP_CODE);
  $this->http_info = array_merge($this->http_info, curl_getinfo($ci));
  $this->url = $url;

  if ($this->debug) {
      echo "=====post data======\r\n";
      var_dump($postfields);

      echo "=====headers======\r\n";
      print_r($headers);

      echo '=====request info====='."\r\n";
      print_r( curl_getinfo($ci) );

      echo '=====response====='."\r\n";
      print_r( $response );
      curl_close ($ci);
  } else {
      curl_close ($ci);
      return $response;
  }
} //END http

/**
 * 获取头信息存储.
 *
 * @return int
 * @ignore
 */
function getHeader($ch, $header) {
  $i = strpos($header, ':');
  if (!empty($i)) {
    $key = str_replace('-', '_', strtolower(substr($header, 0, $i)));
    $value = trim(substr($header, $i + 2));
    $this->http_header[$key] = $value;
  }
    return strlen($header);
} //END getHeader

/**
 * @ignore
 */
public static function build_http_query_multi($params) {
  if (!$params) return '';
  uksort($params, 'strcmp');
  $pairs = array();
  self::$boundary = $boundary = uniqid('------------------');
  $MPboundary = '--'.$boundary;
  $endMPboundary = $MPboundary. '--';
  $multipartbody = '';

  foreach ($params as $parameter => $value) {
    if( in_array($parameter, array('pic', 'image')) && $value{0} == '@' ) {
        $url = ltrim( $value, '@' );
        $content = file_get_contents( $url );
        $array = explode( '?', basename( $url ) );

        $filename = $array[0];
        $mime = self::get_image_mime($filename);
        $multipartbody .= $MPboundary . "\r\n";
        $multipartbody .= 'Content-Disposition: form-data; name="' . $parameter . '"; filename="' . $filename . '"'. "\r\n";
        $multipartbody .= 'Content-Type: '.$mime."\r\n\r\n";
        $multipartbody .= $content. "\r\n";
    } else {
        $multipartbody .= $MPboundary . "\r\n";
        $multipartbody .= 'content-disposition: form-data; name="' . $parameter . '"'."\r\n\r\n";
        $multipartbody .= $value."\r\n";
    }
  }
  $multipartbody .= $endMPboundary. "\r\n";
    return $multipartbody;
} //END build_http_query_multi

 //判断图片类
 public static function get_image_mime( $file ) {
   $ext = strtolower(pathinfo( $file , PATHINFO_EXTENSION ));
      switch( $ext ) {
        case 'jpg':
        case 'jpeg':
            $mime = 'image/jpg';
            break;
        case 'png':
            $mime = 'image/png';
            break;
        case 'gif':
            $mime = 'image/gif';
            break;
        default:
            $mime = 'image/unknown';
            break;
      }
        return $mime;
 } //END get_image_mime

} //END class