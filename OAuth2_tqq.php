<?php
/***
  * 腾讯微博
  * PHP SDK for t.qq.com
  * 版本 OAuth 2.0
 * @author http://weibo.com/yakeing
  * OAuth2_tqq.php
 **/
class TQQOAuth {

    public $url;
    public $http_code;
    public $access_token; //USER token
    public $app_secret; //APP secret
    public $app_key; //APP KEY
    public $http_info; //数据包含最后头部返回
    public $URL_VERSION; //HTTP协议版本
    public $ssl_verifypeer; //SSL安全模式
    public $format = 'json'; //定义返回格式json或xml
    public $debug = FALSE; //调试信息 测试模式 TRUE/FALSE
    public $timeout = 30; //设置curl允许执行的最长秒数
    public $connecttimeout = 30; //设置连接等待最长秒数
    public $useragent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 5_0 like Mac OS X) AppleWebKit/534.46 (KHTML, like Gecko) Version/5.1 Mobile/9A334 Safari/7534.48.3'; //设置用户代理[Sae T OAuth2 v0.1]
    public $host = 'https://open.t.qq.com/api/'; //地址前端

    public static $boundary = ''; //静态 多块的边界
    public static $Referer = 'open.t.qq.com'; //来源

/***
  * 构造函数
  */
function __construct($app_key, $app_secret) {
    $this->app_key = $app_key;
    $this->app_secret = $app_secret;
} //END __construct

    // 授权地址
    function authorizeURL() { return 'https://open.t.qq.com/cgi-bin/oauth2/authorize'; }
    function access_tokenURL() { return 'https://open.t.qq.com/cgi-bin/oauth2/access_token'; }

/***
  *
  * 获得授权URL
  * GET
  * header('Location: ' . $GO_URL);
 **/
function get_authorizeURL($uri, $type = 'code', $wicket = NULL, $state = NULL) {
   $params = array(
       'client_id' => $this->app_key,//App Key
       'redirect_uri' => $uri,//授权回调地址
       'response_type' => $type,//返回类型，支持code、token，默认值为code
       'state' => $state //用于保持请求和回调的状态
    );

 switch($wicket){
    case '1':
        $params['wap'] = '1'; //移动终端1.2
    break;
    case '2':
        $params['wap'] = '2'; //移动终端2.0
    break;
 }
     return $this->authorizeURL() . "?" . http_build_query($params);
} //END get_authorizeURL


/***
  * 使用授权后的Request Token换取Access Token
  *
  * 请求的类型:grant_type = 代码authorization_code、密码password、授权refresh_token
  * type == code 三个参数 arr(返回的code, 回调地址, 自定义随机数)
  * type != code 一个参数 arr(刷新accesstoken)
 **/
function get_access_token($type, $arr) {
 $url = $this->access_tokenURL();
 $params = array();
 $params['client_id'] = $this->app_key;


  if($type == 'code'){
    $params['client_secret'] = $this->app_secret;
    $params['forcelogin'] = false;
    if(!empty($arr[2])) $params['state'] = $arr[2];
    $params['grant_type'] = 'authorization_code';
    $params['code'] = $arr[0];
    $params['redirect_uri'] = $arr[1];
  }else{
    $params['grant_type'] = 'refresh_token';
    $params['refresh_token'] = $arr[0];
  }

  $get_params = $this->oAuthRequest($url, 'POST', $params);
  parse_str($get_params, $params_arr);
    return $params_arr;
} //END get_access_token


/***
  * 获取用户唯一ID
  *
 **/
function get_user_id($access, $get_arr){
    $user = Array();
  if(empty($access['openid'])){
    $user['id'] = NULL;
  }else{
    $user['id'] = $access['openid'];
    $user['access_token'] = $access['access_token'];
    // $user['expires'] = $this->timeu + $access['expires_in'];
    $user['expires'] = time() + $access['expires_in'];
    $user['refresh_token'] = $access['refresh_token'];
    $user['name'] = $access['name'];
    $user['nick'] = $access['nick'];
    $user['openid'] = $get_arr['openid'];
    $user['openkey'] = $get_arr['openkey'];
  }
    return $user;
} //END get_user_id


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
} //END set_remote_ip

/***
  * 统一发送接口
  *
  * $pic 上传图片地址
 **/
function get_oauth_call($url, $method, $params, $pic = null){
  $params['oauth_consumer_key'] = $this->app_key; //app key
  $params['access_token'] = $this->access_token; //授权token
  $params['format'] = $this->format; //返回类型json或xml
  $params['clientip'] = $this->remote_ip; //用户IP
  $params['oauth_version'] = '2.a'; //版本号
  $params['scope'] = 'all'; //请求权限范围

  strtoupper($method); //把字母转换大小

  if (empty($pic)){
    $multi = false;
  }else{
    $multi = true;
    $params['pic'] = '@'.$pic;
  }

  // OAuth的请求包装
  $response = $this->oAuthRequest($url, $method, $params, $multi);

 if ($this->format === 'json') {
    return json_decode($response, true);
 }else{
    return $response;
 }
} //END get_oauth_call


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

  curl_setopt($ci, CURLOPT_HTTP_VERSION, $this->URL_VERSION); //HTTP协议版本
  curl_setopt($ci, CURLOPT_USERAGENT, $this->useragent); //客户端User-Agent
  curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connecttimeout); //连接秒
  curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout); //运行秒
  curl_setopt($ci, CURLOPT_RETURNTRANSFER, TRUE); //信息以文件流的形式返回
  curl_setopt($ci, CURLOPT_ENCODING, ""); //压缩 1.identity、2.deflate, gzip
  curl_setopt($ci, CURLOPT_REFERER, "http://www.".self::$Referer."/"); //来源页面Referer
  curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, $this->ssl_verifypeer); //https安全
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
    $headers[] = "Authorization:OAuth2 ".$this->access_token;
  if ( !empty($this->remote_ip) && defined('SAE_ACCESSKEY') ) {
     $headers[] = "SaeRemoteIP: " . $this->remote_ip;
  } else {
     $headers[] = "API-RemoteIP: " . $this->remote_ip;
   }

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