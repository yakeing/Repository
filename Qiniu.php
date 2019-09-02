<?php
/**
 * qiniu_class.php
 * PHP 七牛云存储cURL传输类
 *
 * 客服端POST上传使用multipart/form-data模式
 *      http://upload.qiniu.com
 *      token= 令牌
 *      key= 文件名-非填
 *      accept = 返回格式-非填（application/json -- text/plain）
 *      x:name = Value自定义返回变量名-非填
 *      crc32= CRC32 校验码-非填
 *
 *  注意：文件名称可以签名固定
 ****/
namespace php_qiniu;
class Qiniu{

    public $bucket;
    public $key;
    public $secret;
    public $prefix = '';//自定义文件前缀
    public $suffix = '';//自定义文件后缀

    private $SDK_VER = "6.1.9"; //SDK版本6.1.9
    private $host = 'http://rs.qiniu.com'; //单一操作地址前端 + rs.qbox.me
    private $rsf_host = 'http://rsf.qbox.me'; //bucket操作地址前端
    private $up_host = 'http://upload.qiniu.com'; //上传地址前端
    private $expires = 3600; // 令牌有效期一小时
    private $Referer = 'http://upload.qiniu.com'; //伪装来源

    //构造函数
    function __construct($bucket, $key, $secret) {
        $this->bucket = $bucket;
        $this->key = $key;
        $this->secret = $secret;
        if (version_compare(PHP_VERSION, '5') == -1) { //判断版本
            register_shutdown_function(array( & $this, '__destruct'));
        }
    }

    /* ***
     * UP文件上传接口（客服端文件推荐使用表单提交，顶端有说明）
    *
     * $file 文件名 或 URL
     * $body 内容
     * $Crc32 上传文件=1
     * $Params 文件属性arr
     * $op 头部arr
     */
    function uploadFile($file, $body= NULL,$op=NULL) {
        $url = $this->up_host;
        $fields = $files = array();
        $mimeBoundary = md5(microtime());
        if(empty($body)){
            $suffix = empty($this->suffix) ? $this->filenameExtension($file) : $this->suffix;
            $name = $this->prefix.time().'.'.$suffix;
            $fields['key'] = $name;
            $fields['file'] = '@'.$name;
            $content = file_get_contents( $file );
        }else{
            $content = $body;
            $fields['key'] = $name = $file;
        }
        /*
                if(!empty($Crc32)){
                    $fields['crc32'] = sprintf('%u', $Crc32);;
                }
                if (is_array($Params)) {
                    foreach ($Params as $k=>$v) {
                        $fields[$k] = $v;
                    }
                }
        $MimeType = ''
        $files[] = array('file', $name, $content, $MimeType);
        */
        $fields['token'] = $this->upToken($name, $op);
        $files[] = array('file', $name, $content, 'application/octet-stream');

        $FormBody = $this->uploadMultipartForm($fields, $files, $mimeBoundary);
        $httpHeader['Content-Type'] = 'multipart/form-data; boundary='.$mimeBoundary;
     return $this->http($url, $httpHeader, $FormBody);
    }

    /* ***
     * list列举资源
     *
     * $bucket 指定空间
     * $limit 条目数-范围为1-1000-默1000
     * $prefix 指定前缀-默空
     * $delimiter 指定目录分隔符-列出所有公共前缀-默空
     * $marker 上一次列举返回的位置标记-作为本次列举的起点信息-默空
     *
     */
    function listFile($bucket = null, $limit = null, $prefix = null, $delimiter = null, $marker = null) {
        $urls = '/list?bucket=';
        $urls .= empty($bucket) ? $this->bucket : $prefix;
        $urls .= empty($prefix) ? '' : '&prefix='.$prefix;
        $urls .= empty($marker) ? '' : '&marker='.$marker;
        $urls .= empty($limit) ? '' : '&limit='.$limit;
        $urls .= empty($delimiter) ? '' : '&delimiter='.$delimiter;
        $httpHeader = array();
        $httpHeader['Authorization'] = 'QBox '.$this->signRequest($urls, false);
     return $this->http($this->rsf_host.$urls, $httpHeader, false);
    }

    /* ***
     * batch批量操作
     *
     * $barr = array( array($behavior, $name, $new_name), arr...);
     *
     * $behavior 文件操作 S=属性 D=删除 C=复制 M=移动
     * $name 文件名
     * $new_name 新的文件名 只对 C=复制 M=移动 起作用
     */
    function batchFile($barr) {
        $urls = '/batch';
        $httpHeader = $urlarr = array();
        foreach ($barr as $arr) {
            $new_name = isset($arr[2]) ? $arr[2] : false;
            $urlarr[] = $this->setUrl($arr[0], $arr[1], $new_name);
        }
        $body = 'op=' . implode('&op=', $urlarr);
        $httpHeader['Content-Type'] = 'application/x-www-form-urlencoded';
        $httpHeader['Authorization'] = 'QBox '.$this->signRequest($urls, $body);
     return $this->http($this->host.$urls, $httpHeader, $body);
    }

    /* ***
     * one单个操作
     *
     * $behavior 文件操作 S=属性 D=删除 C=复制 M=移动
     * $name 文件名
     * $new_name 新的文件名 只对 C=复制 M=移动 起作用
     */
    function singleFile($behavior, $name, $new_name = false) {
        $urls = $this->setUrl($behavior, $name, $new_name);
        $httpHeader = array();
        $httpHeader['Content-Type'] = 'application/x-www-form-urlencoded';
        $httpHeader['Authorization'] = 'QBox '.$this->signRequest($urls, false);
     return $this->http($this->host.$urls, $httpHeader, false);
    }

        //组装主体 => $body
    function uploadMultipartForm($fields, $files, $mimeBoundary) {
        $data = array();
        foreach ($fields as $name => $val) {
            array_push($data, '--' . $mimeBoundary);
            array_push($data, "Content-Disposition: form-data; name=\"$name\"");
            array_push($data, '');
            array_push($data, $val);
        }
        foreach ($files as $file) {
            array_push($data, '--' . $mimeBoundary);
            list($name, $fileName, $fileBody, $mimeType) = $file;
            $mimeType = empty($mimeType) ? 'application/octet-stream' : $mimeType;
            $find = array("\\", "\"");
            $replace = array("\\\\", "\\\"");
            $fileName = str_replace($find, $replace, $fileName);
            array_push($data, "Content-Disposition: form-data; name=\"$name\"; filename=\"$fileName\"");
            array_push($data, "Content-Type: $mimeType");
            array_push($data, '');
            array_push($data, $fileBody);
        }
        array_push($data, '--' . $mimeBoundary . '--');
        array_push($data, '');
        $body = implode("\r\n", $data);
    return $body;
    }

     //获取文件后缀名 ==> xxx
    function filenameExtension( $url ) {
        $array = explode( '?', basename( $url ) );
        $filename = $array[0];
      return strtolower(pathinfo( $filename , PATHINFO_EXTENSION ));
     }

    //设置地址 ==> /xxx/64xxx
    function setUrl($behavior, $name, $new_name) {
        $url = $this->bucket.':'.$name;
        $new_url = ($new_name == false) ? '' : $this->bucket.':'.$new_name;
        strtoupper($behavior); // 转大写字母
        switch($behavior){
            case 'S': $urls = '/stat/'.$this->urlSafeEncode($url);
            break;
            case 'D': $urls = '/delete/'.$this->urlSafeEncode($url);
            break;
            case 'M': $urls= '/move/'.$this->urlSafeEncode($url).'/'.$this->urlSafeEncode($new_url);
            break;
            case 'C': $urls = '/copy/'.$this->urlSafeEncode($url).'/'.$this->urlSafeEncode($new_url);
            break;
            default: $urls = '/stat/'.$this->urlSafeEncode($url);
        }
        return $urls;
    }

    //设置文件头 ==> array('xxx', 'xxx')
    function setHeader($httpHeader) {
        $header = array();
        if(is_array($httpHeader)){
            foreach($httpHeader as $key => $parsedUrlValue) {
                $header[] = $key.': '.$parsedUrlValue;
            }
             return $header;
        }
    }

    //操作认证签名 ==> token
    function signRequest($urls, $body){
        $data = $urls."\n";
        if (isset($body)) {
            $data .= $body;
        }
        return $this->sign($data);
    }

    // 上传认证签名 ==> token
    function upToken($name=NULL, $op=NULL, $deadline=NULL) {
        $thetime = time();
        $items = array();
        $items['scope'] = empty($name) ? $this->bucket : $this->bucket.':'.$name;
        $items['deadline'] = empty($deadline) ? $this->expires+$thetime : $deadline+$thetime;

        if(is_array($op)){
            /*  off作废
            $involved = array('callbackUrl', 'callbackBody', 'returnUrl', 'returnBody', 'asyncOps',
            'endUser', 'exclusive', 'detectMime', 'fsizeLimit', 'saveKey', 'persistentOps',
            'persistentPipeline', 'persistentNotifyUrl', 'fopTimeout', 'mimeLimit');
            */
            $involved = array('insertOnly', 'saveKey', 'endUser', 'returnUrl', 'returnBody', 'callbackUrl',
            'callbackHost', 'callbackBody', 'callbackBodyType', 'callbackFetchKey', 'persistentOps',
            'persistentNotifyUrl', 'persistentPipeline', 'fsizeLimit', 'detectMime', 'mimeLimit');
            foreach ($involved as $arr) {
                if(isset($op[$arr])) {
                    $items[$arr] = $op[$arr];
                }
            }
        }
        $itjson = json_encode($items);
    return $this->signWithData($itjson);
    }

     //上传 WD密匙token ==> key : 64xtxt : 64xxx
     function signWithData($data) {
        $data = $this->urlSafeEncode($data);
        return $this->sign($data).':'.$data;
    }

     //密匙token  ==> key : 64xxx
    function sign($data) {
        $sign = hash_hmac('sha1', $data, $this->secret, true);
        return $this->key.':'.$this->urlSafeEncode($sign);
    }

    // 编码URLSafeBase64Encode  ==> 64xxx..
    function urlSafeEncode($str) {
        $find = array('+', '/');
        $replace = array('-', '_');
        return str_replace($find, $replace, base64_encode($str));
    }

    //获取服务器信息 ==> QiniuPHP/6.1.9....PHP/xxx
    function getUserAgent() {
        $systemInfo = php_uname("s");
        $machineInfo = php_uname("m");
        return 'QiniuPHP/'.$this->SDK_VER.' '.'('.$systemInfo.'/'.$machineInfo.')'.' PHP/'.phpversion();
    }



    /* ***
     * to发送HTTP请求 ==> array
     *
     * $url 签名地址
     * $httpHeader 文件头数组
     * $body 文件主体
     */
    function http($url, $httpHeader, $body) {
        $options = array(
            CURLOPT_USERAGENT => $this->getUserAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_REFERER => $Referer, //伪装来源
            CURLOPT_URL => $url
        );

        $header = $this->setHeader($httpHeader);
        if (!empty($header)){
            $options[CURLOPT_HTTPHEADER] = $header;
        }

    if (!empty($body)) {
        $options[CURLOPT_POSTFIELDS] = $body;
    } else {
        $options[CURLOPT_POSTFIELDS] = null;
    }
    $arrdb = array(
            'Error'  => null,
            'Code'  => null,
            'Content-Type'  => null,
            'ContentLength'  => null,
            'X-Reqid'  => null,
            'X-Log'  => null,
            'db'  => null
            );
        //发送
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $ret = curl_errno($ch);
        if ($ret !== 0) {
            $arrdb['Error'] = curl_error($ch);
            curl_close($ch);
            return $arrdb;
        }else{
            $arrdb['Code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $arrdb['Content-Type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $responseArray = explode("\r\n\r\n", $result);
            $responseArraySize = sizeof($responseArray);
            $respHeader = $responseArray[$responseArraySize-2];
            $respBody = $responseArray[$responseArraySize-1];

            list($reqid, $xLog) = $this->getHeader($respHeader);
            $arrdb['ContentLength'] = strlen($respBody);
            $arrdb['X-Reqid'] = $reqid;
            $arrdb['X-Log'] = $xLog;
            list($db, $err) = $this->getBody($arrdb, $respBody);
            $arrdb['db'] = $db;
            $arrdb['Error'] = $err;

            return $arrdb;
        }
    }



    //解析主体内容信息 ==> array( drr, err)
    function getBody($resp, $respBody) {
        if ($resp['Code'] >= 200 && $resp['Code'] <= 299) {
            if ($resp['ContentLength'] !== 0) {
                $data = json_decode($respBody, true);
                if ($data === null) {
                        $err_msg = function_exists('json_last_error_msg') ? json_last_error_msg() : "error with content:" . $respBody;
                        return array(null, $err_msg);
                }elseif ($resp['Code'] === 200) {
                    return array($data, null);
                }
            }
        }else{
            return array(null, $this->getError($resp, $respBody));
        }
    }

    //解析头部 X-Reqid 和 X-Log 信息 ==> array( X-Reqid, X-Log)
    function getHeader($headerContent) {
        $headers = explode("\r\n", $headerContent);
        $reqid = null;
        $xLog = null;
        foreach($headers as $header) {
            $header = trim($header);
            if(strpos($header, 'X-Reqid') !== false) {
                list($k, $v) = explode(':', $header);
                $reqid = trim($v);
            } elseif(strpos($header, 'X-Log') !== false) {
                list($k, $v) = explode(':', $header);
                $xLog = trim($v);
            }
        }
        return array($reqid, $xLog);
    }

    //解析错误信息
    function  getError($resp, $respBody) {
        if ($resp['Code'] > 299 AND $resp['ContentLength'] !== 0) {
            if ($resp['Content-Type'] === 'application/json') {
                $ret = json_decode($respBody, true);
            }
        }
        return $ret['error'];
    }


    //析构函数
    function __destruct() {

    }
}