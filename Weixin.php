<?php
/***
  * 微信 通讯API接口
  * PHP SDK for mp.weixin.qq.com
  * 版本 api 2.0
  * author http://weibo.com/yakeing
  * weixin_class.php
 **/
namespace php_weixin;
class Weixin{

    public $appid = ''; //地址前端
    public $host = 'https://api.weixin.qq.com'; //地址前端
    public $referer = '';//来源页面
    public $useragent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 9_0 like Mac OS X) AppleWebKit/600.1.3 (KHTML, like Gecko) Version/9.0 Mobile/13A4254v Safari/600.1.4';

    /**
        * 构造函数
    */
    function __construct($appid, $appsecret, $token, $encodingAesKey) {
        $this->appid = $appid;
        $this->appsecret = $appsecret;
        $this->token = $token;
        $this->encodingAesKey = $encodingAesKey;
        $this->times = time();
    } //END __construct


  /**
   * 检验服务器签名 (主)
   *
   * 注意：默认情况下服务器会GET方式发送三个参数进行检验
   * 注意：如果使用POST方式是无法接收任何信息
   *$signature  服务器签名
   * $timestamp  时间戳
   *$nonce  随机数
   * @return string
   */
    function checkSignature() {
        $signature = $_GET['signature'];
        $timestamp = $_GET['timestamp'];
        $nonce = $_GET['nonce'];
        $echostr = $_GET['echostr'];
        $tmpStr = $this->getSignature(array($this->token, $timestamp, $nonce));
        if($tmpStr == $signature && !empty($tmpStr)){
            return $echostr;
        }else{
            return false;
        }
    } //END checkSignature


  /**
   *  随机数
   *
   * @return string
   */
    function getNonce($number){
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $length = strlen($chars) - 1;
        $nonce = array();
        for ( $i = 0; $i < $number; $i++ ){
            array_push($nonce, $chars[ mt_rand(0, $length) ]);
        }
        return implode('', $nonce);
    } //END getNonce


  /**
   *  生成签名
   *
   * @return string
   */
    function getSignature($sig){
        if(is_array($sig)){
            sort($sig, SORT_STRING); //重建建名，建值作为字符串来字典排序
            return  sha1( implode( $sig ));
        }else{
            return false;
        }
    } //END getSignature


  /**
   * 获取密匙 (主)
   *
   * 授予类型 grant_type (access_token事件填写client_credential)
   * @return array
   */
    function getEventToken() {
        $url = $this->host.'/cgi-bin/token';
        $params = array('grant_type' => 'client_credential', 'appid' => $this->appid, 'secret' => $this->appsecret);
        return $this->http($url, 'GET', $params);
    } //END getEventToken


  /**
   * 获取微信服务器IP (主)
   *
   * $token  令牌
   * @return array/json
   */
    function getServerIp($token) {
        $url = $this->host.'/cgi-bin/getcallbackip';
        $params = array('access_token' => $token);
        $server_ip = $this->http($url, 'GET', $params);
            return $server_ip;
    } //END getServerIp


  /**
   * MXL 信息转换 (主)
   *
   * 判断类型 $postType = trim($postObj->$MsgType);
   *$Type 数组或json
   * @return array/json/object
   */
    function weixinMsg($XmlStr = FALSE,  $Type = 'array'){
		if(empty($XmlStr)) $XmlStr = $this->weixinRawData();
		$xml_parser = xml_parser_create();
		if(!xml_parse($xml_parser,$XmlStr,true)){// 解析XML最后一段数据是否正确
			xml_parser_free($xml_parser);
			return false;
		}else {// XML 转对象
			$postObj = simplexml_load_string($XmlStr, 'SimpleXMLElement', LIBXML_NOCDATA);
			if($Type == 'json'){
				$postType = json_encode($postObj, true);
			}else{ //array
				$postType = $this->objectToArray($postObj);
			}
			return $postType;
		}
    } //END weixinMsg


  /**
   * 接收原形数据
   *
   * $type 接收方式
   * @return XML
   */
    function weixinRawData($type = 'input') {
        if($type == 'input'){
            $postStr = file_get_contents('php://input');
        }else{
            $postStr = $GLOBALS['HTTP_RAW_POST_DATA'];
        }
          return $postStr;
    } //END weixinRawData


  /**
   *  把类转换成数组
   *
   *$obj  原形类
   * @return array
   */
    function objectToArray($obj){
        $_arr = is_object($obj) ? get_object_vars($obj) : $obj;
        if(is_array($_arr)){
          foreach ($_arr as $key => $val){
          $val = (is_array($val) || is_object($val)) ? $this->objectToArray($val) : $val;
          $arr[$key] = $val;
          }
           return $arr;
         }else{
          return false;
        }
    } //END objectToArray


  /**
   *  encodingAesKey加密接口(主)
   *
   *$XmlMsg  明文XML
   * @return array
   */
    function encodingAesKeyMsg($XmlMsg){
        $timeStamp = $this->times;
        $nonce = $this->getNonce(6);
        $random = $this->getNonce(16);
        $bsize = 32;
        $tmp = '';

         //pack 把数据装入一个二进制字符串
         //N =无符号长(通常是32位,大端字节顺序(Big-endian),网络字节序)
        $text = $random . pack('N', strlen($XmlMsg)) . $XmlMsg . $this->appid;

		//计算需要填充的位数
        $pad  = $bsize - (strlen($text) % $bsize);
       if($pad == 0) $pad =  $bsize;

        //获得补位所用的字符
        $pad_chr = chr($pad);
        for ($i = 0; $i < $pad; $i++) {
            $tmp .= $pad_chr;
        }
        $Xmlpad = $text. $tmp;

        $encrypt = $this->mcryptModule($Xmlpad, 'encode');//加密

        $sigarr = array($this->token, $timeStamp, $nonce, $encrypt);
        $signature = $this->getSignature($sigarr);

        $Msgarr = array(
            'Encrypt' => $encrypt,
            'MsgSignature' => $signature,
            'TimeStamp' => $timeStamp,
            'Nonce' => $nonce
            );

        $format = $this->assembleInfo( $Msgarr);
        return '<xml>'.implode("\r\n", $format).'</xml>';
    } //END encodingAesKeyMsg


  /**
   *  encodingAesKey解密接口(主)
   *
   *$encrypt  密文
   *$g  模拟数组
   * @return array
   */
    function decodeAesKeyMsg($encrypt, $g = FALSE){
		if(!is_array($g)){
			$g = array(
				'signature' => $_GET['signature'],
				'timestamp' => $_GET['timestamp'],
				'nonce' => $_GET['nonce'],
				'encrypt_type' => $_GET['encrypt_type'],
			   'msg_signature' => $_GET['msg_signature']
		   );
		}
		$sigarr = array($this->token, $g['timestamp'], $g['nonce'], $encrypt);
		$signature = $this->getSignature($sigarr);
		if ($signature != $g['msg_signature']) {
			return 'ErrorSignature';
		}else{
			$text = $this->mcryptModule($encrypt, 'decode');//解密

			//去除补位字符
			$pad = ord(substr($text, -1));
			if ($pad < 1 || $pad > 32) $pad = 0;
			$result = substr($text, 0, (strlen($text) - $pad));

			//去除16位随机字符串,网络字节序和AppId
			if (strlen($result) < 16){
				return  'ErrorText';
			}else{
				$content = substr($result, 16, strlen($result));
				$xml_len = unpack('N', substr($content, 0, 4));
				$xml_content = substr($content, 4, $xml_len[1]);
				$from_appid = substr($content, $xml_len[1] + 4);
				if ($from_appid != $this->appid){
					return  'ErrorAppid';
				}else{
					return $xml_content;
				}
			}
		}
    } //END decodeAesKeyMsg


	/**
	 * AES加解密模块
	* encodingAesKey
	* Big Endian 大端 网络字节序
	* Littile Endian 小端 主机字节序
    */
    function mcryptModule($body, $mod) {
		$this->key = base64_decode($this->encodingAesKey . '=');//编码AES

        $size = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
        $module = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');
        $iv = substr($this->key, 0, 16);
        mcrypt_generic_init($module, $this->key, $iv);
		if($mod == 'encode'){//加密
			$encrypted = mcrypt_generic($module, $body);
			$value = base64_encode($encrypted);
		}else{//解密 decode
			$ciphertext_dec = base64_decode($body);
			$value = mdecrypt_generic($module, $ciphertext_dec);
		}
		mcrypt_generic_deinit($module);
		mcrypt_module_close($module);
		return $value;
    } //END mcryptModule


  /**
   * 回复请求信息 (主)
   * 官方文档 http://mp.weixin.qq.com/wiki/14/89b871b5466b19b3efa4ada8e577d45e.html
   * $postmsg 参数数组
   * $postmsg['news'] = array(
   *                         array('Title'=> '标题', 'Description'=>描述,PicUrl=>图地址,Url=>地址)....

        'ToUserName 应用ID
        FromUserName 用户OPENID
        CreateTime 时间戳

        MsgType 下类型值

            text 文本消息
            'Content' = ''XXX;

            image 单图片
            'Image' = array( MediaId  )

            Voice 声音
            'Voice' = array( ediaId )

            Video 视频
            'Video' = array( Title, MediaId , Description)

            music 音乐 (ThumbMediaId 素材ID 缩略图 非必要)
            'Music' = array( Title, Description, MusicUrl, HQMusicUrl, ThumbMediaId )

            news 图文组 (最多10组 第一组图360*200，之后图200*200)
            'news' = array (array( Title - Description - PicUrl - Url  )...))
   * @return XML
   */
    function responseMsg($postMsg) {
        if($postMsg['MsgType'] == 'news' && array_key_exists('news', $postMsg)){  //图文组 程构数组
            $news = $postMsg['news'];
            unset($postMsg['news']);
            $postMsg['ArticleCount'] = count($news);
        }
        $postTpl = $this->assembleInfo($postMsg);

        if(!empty($news)){  //图文组 构造MXL
            array_push($postTpl, '<Articles>');
            foreach ($news as $value){
                array_push($postTpl, '<item>'.implode("\r\n", $this->assembleInfo($value)).'</item>');
            }
            array_push($postTpl, '</Articles>');
        }

        $xmlreply = implode("\r\n", $postTpl);
        return "<xml>\r\n".$xmlreply."\r\n</xml>";
    } //END responseMsg


  /**
   *  生成xml 回复信息
   * $pMsg 填充值
   * @return array
   */
    function assembleInfo($pMsg){
        $strarr = array();
        $Typearr = array_keys($pMsg);
        foreach($Typearr as $val){
            array_push($strarr, '<'.$val.'><![CDATA['.$pMsg[$val].']]></'.$val.'>');
        }
        return  $strarr;
    } //END assembleInfo


  /**
   *  客服帐号管理 (主)
   *
   *$kfarr = false 全部客户
   *$kfarr = NULL 删除客户
   *$kfarr = array(  增加客户
        kf_account 账号 (abc@微信号)不是原始ID
        nickname 昵称
        password 密码 用32位MD5加密后
   )
   * @return array
   */
    function customservice($kfarr = false) {
        if(is_array($kfarr)){
            $params = json_encode($kfarr);
            $method = 'POST_JSON';
            $url = '/customservice/kfaccount/add?access_token='.$this->access_token;
        }else{
            if($kfarr === NULL){
                $url = '/customservice/kfaccount/del';
            }else{
                $url = '/cgi-bin/customservice/getkflist';
            }
            $method = 'GET';
            $params = array('access_token' => $this->access_token);
        }
        return $this->http($this->host.$url, $method, $params);
    } //END customservice


  /**
   *  主动发消息给客服 (主) 赠送卡券给用户走这里
   * 注: 客户必须24小时内有与服务器通信才能发送信息
   *http://mp.weixin.qq.com/wiki/1/70a29afed17f56d537c833f89be979c9.html
   *$sms = array(
        touser => 用户ID
        msgtype =>  类型 文本 图片 语音 视频 音乐 图文组

        text => array( content => 内容)
        image => array( media_id => 素材ID)
        voice => array( media_id => 素材ID)
        video => array( media_id => 素材ID, thumb_media_id => 缩略图ID, title =>  标题, description => 描述)
        music => array( musicurl => 音乐URL, hqmusicurl => 高品质音乐URL, thumb_media_id => 缩略图ID, title =>  标题, description => 描述)
        news => array( articles => array(title=>标题, description=>描述, url=>点击后连接, picurl=>图片URL, .... ))
   第一个大图640*320，其余小图80*80
    )
   *wxcard卡券接口数据走这里
   *$sms = array(
            touse => 用户OPENID,
            wxcard  => array(
                    card_id => 卡券ID类,
                    card_ext => "{\"code\":\"\",\"openid\":\"\",\"timestamp\":\"1402057159\",\"signature\":\"017bb17407c8e0058a66d72dcc61632b70f511ad\"}"
            ));
   * @return array
   */
    function messageSend($sms) {
        if(is_array($sms)){
            $params = json_encode($sms);
            $method = 'POST_JSON';
            $url = $this->host.'/cgi-bin/message/custom/send?access_token='.$this->access_token;
            $params = array('access_token' => $this->access_token);
            return $this->http($url, $method, $params);
        }else{
            return false;
        }
    } //END messageSend



  /**
   *  门店接口 (主)
   *
   * $type = addpoi  创建门店 array( .....)
   * $type = getpoi  查询 门店固定poi_id
   * $type = getpoilist  门店列表 array(开始位置0, 返回数量) 最大允许返回50，默认为20
   * $type = updatepoi  修改门店服务信息 (门店poi_id 固定其余7 个字段可单独修改其中个别)
   * $type = delpoi  删除 门店固定poi_id
   * http://mp.weixin.qq.com/wiki/16/8f182af4d8dcea02c56506306bdb2f4c.html
   创建门店
                注:审核完官方会推送 poi_check_notify 事件获取 最终 poi_id

            门店列表 (返回的 total_count  门店总数量)
                    {"begin":0,"limit":10}  最大允许50，默认为20
   *  return @array
   */
    function setStorePoi($type=false, $dbr=false) {
        switch($type){
            case 'uploadimg': //a上传门店图片或卡券LOGO 限制1MB 格式JPG
                $method = 'POST';
                $params =array( array('buffer', $dbr[0], file_get_contents($dbr[1]), 'application/octet-stream'));
                $url = $this->host.'/cgi-bin/media/uploadimg?access_token='.$this->access_token;
            break;

            case 'addpoi': //创建门店
            case 'getpoi': //查询单个门店信息
            case 'getpoilist': //门店列表 (返回的 total_count  门店总数量)
            case 'updatepoi': //修改门店服务信息 (人工审核,不通过保持原样)
            case 'delpoi': //删除门店
                 $path = (string)$type;
                 $params = json_encode($dbr);
                 $method = 'POST_JSON';
                 $url = $this->host.'/cgi-bin/poi/'.$path.'?access_token='.$this->access_token;
            break;

            default: // GET获取门店分类类目表
                $method = 'GET';
                $url = $this->host.'/cgi-bin/api_getwxcategory';
                $params =array( 'access_token' => $this->access_token);
            break;
        }
        return $this->http($url, $method, $params);
    } //END setStorePoi




/**
   *  卡券接口 (主)
   *
   * NO.1 上传 卡券LOGO 300*300 限制1MB 格式JPG
   * NO.2 设置卡券适用门店 location_id_list字段 (必须先创建门店并且审核通过获取poi_id )
   * NO.3 设置卡券背景颜色 color 字段
   * NO.4 创建卡券
   *http://mp.weixin.qq.com/wiki/9/4f455120b50741db79b54fde8896b489.html
   *
   *  跳转外链签名 getSignature  包含 公主号appscret、解码后的code、固定card_id
   *  return @array
   */
    function setStoreCard($type=false, $dbr=false) {
        if(empty($type)){ // GET获取卡券背景颜色目表
                $method = 'GET';
                $url = $this->host.'/card/getcolors';
                $params =array( 'access_token' => $this->access_token);
                //a 通用颜色表 http://mp.weixin.qq.com/wiki/static/assets/84fc4c00adfd751d05212d352f9b477a.png

        }else if($type == 'uploadimg'){  //a上传门店图片或卡券LOGO 限制1MB 格式JPG
                $method = 'POST';
                $params =array( array('buffer', $dbr[0], file_get_contents($dbr[1]), 'application/octet-stream'));
                $url = $this->host.'/cgi-bin/media/uploadimg?access_token='.$this->access_token;
        }else{
            switch($type){
                case 'testwhitelist': //设置测试用户白名单
                        $path = 'testwhitelist/set';
                        // openid=用户ID   username=微信号 ID与号填一种就可以，上限为10个
                        // {"openid": ["a1", "a2", ...],  "username": [ "x1","x2",...]} 查询
                break;

                case 'create': //创建卡券、会员卡
                        $path = 'create';
                        /* 卡券 = GROUPON 包括 团购券、代金券、折扣券、礼品券、优惠券
                            http://mp.weixin.qq.com/wiki/8/b7e310e7943f7763450eced91fa793b0.html
                         会员卡 = MEMBER_CARD
                        http://mp.weixin.qq.com/wiki/11/9116b6dacf7b7ee312e29a2d523434f7.html */
                break;

                case 'querycode': //查询Code码
                        $path = 'code/get';
                        // use_custom_code为 false 的 系统生成只需用 code 查询
                        // use_custom_code 为 true 的 商家自定义需要用 code 和 card_id  查询
                break;

                case 'qrcode': //二维码 扫码后添加卡券
                        $path = 'qrcode/create';
                        // use_custom_code为 false 的 系统生成只需用 code 查询
                        // use_custom_code 为 true 的 商家自定义需要用 code 和 card_id  查询
                break;

                case 'activatevip': //激活-绑定会员卡
                        $path = 'membercard/activate';
                        // 注: 会员卡期初就像白纸一样需要商家填入信息才能用
                        //注：卡券就像报纸一样客户拿到就能使用
                break;

                case 'updateuser': //更新会员信息
                        $path = 'membercard/updateuser';
                        // 会员卡交易后的每次信息变更需通过该接口通知微信
                break;

                case 'getcardlist': //获取用户已领取的卡券
                        $path = 'user/getcardlist';
                        // 查询的用户openid下的全部卡券 card_id不填写时全部
                        // {  "openid": "123",  "card_id": "abc"}
                break;

                case 'cardione': //查看卡券详情
                        $path = 'get';
                        // 查询的用户openid下的全部卡券 (一次最多50个)
                        // {"card_id": "abc"}
                break;

                case 'batchget': //批量查询卡列表
                        $path = 'batchget';
                        // 查询的用户openid下的全部卡券 (一次最多50个)
                        // {"offset": 开始位置0, count": 数量10, "status_list": [" 通过审核的CARD_STATUS_VERIFY_OK", "CARD_STATUS_DISPATCH"]}
                break;

                case 'update': //更改卡券信息 (修改后需要人工审核)
                        $path = 'update';
                        // 通用字段及特殊卡券（会员卡、飞机票、电影票、会议门票）中特定字段的信息
                break;

                case 'modifystock': //修改库存
                        $path = 'modifystock';
                        // 调用修改库存接口增减某张卡券的库存 只能填写一个 另一个不填或填0不改变
                        // {"card_id": "abc","increase_stock_value":  增加10,"reduce_stock_value":  减少0}
                break;

                case 'codeupdate': //更改Code编号
                        $path = 'code/update';
                        // 自定义Code的商户更改
                        // {"code": "123",  "card_id": "abc",  "new_code": "456"}
                break;

                case 'delete': //删除卡券
                        $path = 'delete';
                        // 删除卡券不能删除已被用户领取，保存在微信客户端中的卡券
                        // {"card_id": "abc"}
                break;

                case 'unavailable': //设置卡券失效
                        $path = 'code/unavailable';
                        // {"code": "123"} 非自定义卡券
                        // {"code": "123", "card_id": "abc"} 自定义code卡券
                break;
            }
             $params = json_encode($dbr);
             $method = 'POST_JSON';
             $url = $this->host.'/card/'.$path.'?access_token='.$this->access_token;
        }
        return $this->http($url, $method, $params);
    } //END setStoreCard



  /**
   * 模板消息 (主)
   *
   * http://mp.weixin.qq.com/debug/cgi-bin/readtmpl?t=tmplmsg/faq_tmpl
   * $ {{xxx.DATA}} 数据方式
        $arr = array(
                  'touser' => '用户OPENID
                  'template_id' => 模板ID
                  'url' => 连接地址
                  'topcolor' => 颜色#FF0000
                  'data' => array(
                         'xxxx' => array('value' => '秘密', 'color' => '#238383'),.......)
        );
   * @return array
   */
    function templateEvent($arr) {
         $params = json_encode($arr);
        $method = 'POST_JSON';
        $url = $this->host.'/cgi-bin/message/template/send?access_token='.$this->access_token;
        return $this->http($url, $method, $params);
    } //END templateEvent


  /**
   * 永久素材管理 (主)
   *
   * http://mp.weixin.qq.com/wiki/14/7e6c03263063f4813141c3e17dd4350a.html
  * $filearr = false 全部素材统计值
  * $filearr = media_id 素材ID 删除素材
  * $filearr = array( 1类型, 2名称, 3文件URL )  上传文件 (尽量使用本地文件)
  * $filearr = array( 1类型, 2从X开始, 3输出X个 ) 获取文件列表 (单次最多20个)
  * $type 类型
                图片（image）: 1M，支持JPG格式
                语音（voice）：2M，播放长度不超过60s，支持AMR\MP3格式
                缩略图（thumb）：64KB，支持JPG格式(无法获取列表, 被分配到 image 图片类)
                视频（video）：10MB，支持MP4格式
                            array( 1.video, 2.名称, 3.文件URL, 4.标题, 5.描述 )
                        上传视频素材时需要POST另一个description表单{"title":标题, "introduction":描述}
   * @return array
   */
    function manageMaterial($filearr = false) {
        $files = null;
         if(is_string($filearr)){
            $params = '{"media_id":"'.$filearr.'"}';
            $method = 'POST_JSON';
            $url = $this->host.'/cgi-bin/material/del_material?access_token='.$this->access_token;
         }else if(ctype_digit(strval($filearr[2]))){ //用 is_int 无法检测字符串的整数
            $params = '{"type":"'.$filearr[0].'","offset":'.$filearr[1].', "count":'.$filearr[2].'}';
            $method = 'POST_JSON';
            $url = $this->host.'/cgi-bin/material/batchget_material?access_token='.$this->access_token;
        }else if(file_exists($filearr[2])){
            $content = file_get_contents($filearr[2]);
            $files =array( array('media',$filearr[1], $content, 'application/octet-stream'));
            $params =array('description'=>  '{"title":"'.$filearr[3].'", "introduction":"'.$filearr[4].'"}');
            $method = 'POST';
           $url = $this->host.'/cgi-bin/material/add_material?access_token='.$this->access_token;
        }else{
            $method = 'GET';
            $params = array('access_token' => $this->access_token);
            $url = $this->host.'/cgi-bin/material/get_materialcount';
        }
            return $this->http($url, $method, $params, $files);
    } //END manageMaterial



      /**
   * 生成带参数的二维码 (主)
   *
   * $qrid = 1整数参数 (临时是32位非0整型 1至4294967295 ，永久1到10万）
      2字符串参数 (只能永久用 1到64个字符）
   * $expire = 有效期 (false = 永久)
   * a有效期单位为秒，临时最多为后的7天（即604800秒）
   * a永久二维码最多10万个 (永久的省着用官方不提供删除功能)
   * https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket= 二维码越换地址
   *  ticket 码使用 urlencode 编译一下
   *@return URL
   */
    function qrScene($qrid, $expire = 604800) {
        if($expire === false){//永久
            $str = '';
            if(ctype_digit(strval($qrid))){ //用 is_int 无法检测字符串的整数
                $qr_type = 'QR_LIMIT_SCENE';//参数值仅能整数
                $scene = '{"scene_id": '.$qrid32.'}';
            }else{
                $qr_type = 'QR_LIMIT_STR_SCENE'; //参数值可以是字符串
                $scene = '{"scene_str": "'.$qrid.'"}';
            }
        }else{//临时
            $str = '"expire_seconds":'.$expire.',';
            $qr_type = 'QR_SCENE';
            $scene = '{"scene_id": '.$qrid.'}';
        }
        $params = '{'.$str.'"action_name": "'.$qr_type.'", "action_info": {"scene":'.$scene.' }}';
        $method = 'POST_JSON';
        $url = $this->host.'/cgi-bin/qrcode/create?access_token='.$this->access_token;
        return $this->http($url, $method, $params);
    } //END qrScene



  /**
   * 自定义菜单 (主)
   *
   * false =查询菜单 默认
   * NULL =删除菜单
   * array =创建菜单
   *    array( 一级名 -> 类型 | 项目 | 值 )
   *    array( 一级名 -> array( 二级名 | 类型 | 项目 | 值 ))
   * a最多3个一级4个汉字，5个二级7个汉字
   * $arr array
   * @return array
   */
    function menuEvent($arr = false) {
        if(is_array($arr)){
            $params = $this->menuArrayToJson($arr);
            $method = 'POST_JSON';
            $url = '/cgi-bin/menu/create?access_token='.$this->access_token;
        }else{
            if($arr === NULL){
                $url = '/cgi-bin/menu/delete';
            }else{
                $url = '/cgi-bin/menu/get';
            }
            $method = 'GET';
            $params = array('access_token' => $this->access_token);
        }
        return $this->http($this->host.$url, $method, $params);
    } //END menuEvent


  /**
   * 菜单数组转json
   *
   * 注意：使用json_encode也出现了中文乱码,用函数urlencode()处理一下
   * @return json
   */
    function  menuArrayToJson($arr) {
        $json =array('{"button":[');
        $endarr = end($arr);
            foreach($arr as $key => $val){
                array_push($json, '{"name":"'.$key.'",');
                if(is_array($val)){
                    array_push($json, '"sub_button":[');
                    $endval = end($val);
                    foreach($val as $v){
                        $str = explode("|", $v);
                        array_push($json, '{"name":"'.$str[0].'","type":"'.$str[1].'","'.$str[2].'":"'.$str[3].'"}');
                        if($v !== $endval) array_push($json, ',');
                    }
                    array_push($json, ']}');
                }else{
                    $strs = explode("|", $val);
                    array_push($json, '"type":"'.$strs[0].'","'.$strs[1].'":"'.$strs[2].'"}');
                }
                if($val !== $endarr) array_push($json, ',');
            }
            array_push($json, ']}');
            return implode("",$json);
    } //END menuArrayToJson


  /**
   * 用户信息 (主)
   *
   *  openid=用户信息 默认
   * array(用户ID, 备注名)= 设置备注名
   * $arr array
   * @return array
   */
    function userEvent($openid) {
        if(is_array($openid)){
            $params = '{"openid":"'.$openid[0].'","remark":"'.$openid[1].'"}';//设置备注名
            $method = 'POST_JSON';
            $url = '/cgi-bin/user/info/updateremark?access_token='.$this->access_token;
        }else{
            $params = array('access_token' => $this->access_token, 'openid' => $openid, 'lang' => 'zh_CN');
            $method = 'GET';
            $url = '/cgi-bin/user/info';
    }
        return $this->http($this->host.$url, $method, $params);
    } //END userEvent


  /**
   * 分组管理 (主)
   *
   *$group  查询所有分组 默认
   * $group = array(Type, db)
   *db = (分组名) 创建分组
   *db = array(分组ID, 新名称) 修改分组名
   *db = (用户ID) 查询用户所在分组
   *db = array(用户ID, 分组ID) 移动单个用户到分组
   *db = array(批量用户ID, 分组ID) 批量移动用户到分组
   *db = (分组ID) 删除分组
   * @return array
   */
    function userGroupEvent($group = false) {
        if(is_array($group)){
            $method = 'POST_JSON';
            switch($group['Type']){

                case 'create': //创建分组
                        $params = '{"group":{"name":"'.$group['db'].'"}}';
                        $path = 'create';
                        break;

                case 'updateremark': //修改分组名
                        $params = '{"group":{"id":'.$group['db'][0].',"name":"'.$group['db'][1].'"}}';
                        $path = 'update';
                        break;

                case 'getid': //查询用户所在分组
                        $params = '{"openid":"'.$group['db'].'"}';
                        $path = 'getid';
                        break;

                case 'update': //移动单个用户到分组
                        $params = '{"openid":"'.$group['db'][0].'","to_groupid":'.$group['db'][1].'}';
                        $path = 'members/update';
                        break;

                case 'batchupdate': //批量移动用户到分组 "id1","id2","id3"......最多50个
                        $params = '{"openid_list":['.$group['db'][0].'],"to_groupid":'.$group['db'][1].'}';
                        $path = 'members/batchupdate';
                        break;

                case 'delete': //删除分组(删除后用户返回默认分组)
                        $params = '{"group":{"id":'.$group['db'].'}}';
                        $path = 'delete';
                        break;
            }
            $url = $this->host.'/cgi-bin/groups/'.$path.'?access_token='.$this->access_token;

        }else{ //查询所有分组
            $params = array('access_token' => $this->access_token);
            $method = 'GET';
            $url = $this->host.'/cgi-bin/groups/get';
        }
        return $this->http($url, $method, $params);
    } //END userGroupEvent


  /**
    * 发送HTTP请求
    *
    * $url 地址
    * $method 方法 GET   POST   POST_JSON 原形数据
    * $fields 表单 ARRAY
    * $files 文件 ARRAY
    * @return string API results
    */
    function http($url, $method, $fields, $files = null) {
        $headers = array();
        strtoupper($method);
        if($method ==  'GET') {
            $url = $url . '?' . http_build_query($fields);
        }else if($method ==  'POST_JSON'){
            $postfields = $fields;
            $headers[] = "Content-Type: application/json; charset=utf-8";
            $headers[] = "Content-Length: " . strlen($fields);
        } else {
            $mimeBoundary = md5(uniqid(microtime()));
            $postfields = $this->uploadMultipartForm($fields, $files, $mimeBoundary);
            $headers[] = "Content-Type: multipart/form-data; boundary=" . $mimeBoundary;
     }

         if (strrpos($url, 'https://') !== 0) { //CURL_HTTP_VERSION_NONE 系统自动判断类型
           $URL_VERSION = CURL_HTTP_VERSION_1_0;
           $ssl_verifypeer = FALSE;
         } else {
           $URL_VERSION = CURL_HTTP_VERSION_1_1;
           $ssl_verifypeer = TRUE;
         }

         //自定义头部
         $headers[] = "SaeRemoteIP: 203.205.147.177";

         //组装
         $options = array(
              CURLOPT_HTTP_VERSION => $URL_VERSION, //HTTP协议版本
              CURLOPT_USERAGENT => $this->useragent, //客户端User-Agent
              CURLOPT_CONNECTTIMEOUT => "30", //连接秒
              CURLOPT_TIMEOUT => "30", //运行秒
              CURLOPT_RETURNTRANSFER => TRUE, //信息以文件流的形式返回
              CURLOPT_ENCODING => "", //压缩 1.identity、2.deflate, gzip
              CURLOPT_REFERER => $this->referer, //来源页面Referer
              CURLOPT_SSL_VERIFYPEER => $ssl_verifypeer, //https安全
              CURLOPT_HEADER => FALSE, //头部
              CURLOPT_HTTPHEADER => $headers,
              CURLINFO_HEADER_OUT => TRUE
         );

         if($method == 'POST' || $method == 'POST_JSON'){
             $options[CURLOPT_POST] = TRUE;
             if (!empty($postfields)) $options[CURLOPT_POSTFIELDS] = $postfields;
         }
         $options[CURLOPT_URL] = $url;

         //发送
         $ch = curl_init();
         curl_setopt_array($ch, $options);
         $response = curl_exec($ch);
         $ret = curl_errno($ch);
        $debug = array(
            'Error'  => null,
            'Code'  => null,
            'Content-Type'  => null,
            'ContentLength'  => null,
            'fields'  => $fields,
            'files'  => $files,
            'Postfields' => $postfields,
            'db'  => null
        );
        if ($ret !== 0) {
            $debug['Error'] = curl_error($ch);
            curl_close($ch);
            return $debug;
        }else{
            $debug['Code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $debug['Content-Type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $debug['Header_out'] = curl_getinfo($ch, CURLINFO_HEADER_OUT);
            curl_close($ch);
            $responseArray = explode("\r\n\r\n", $response);
            $responseArraySize = sizeof($responseArray);
            $respBody = $responseArray[$responseArraySize-1];

            $debug['ContentLength'] = strlen($respBody);
            list($db, $err) = $this->getBody($debug, $respBody);
            $debug['db'] = $db;
            $debug['Error'] = $err;
            return $debug;
        }
   } //END http


  /**
    *  POST提交表单 模拟
    *
    * $fields 上传表单类 array( array( 项目名 => 项目值 )....)
    * $files 上传文件类 array( array( 项目名, 文件名, 文件流主体, 发送方式)....)
    * $mimeBoundary 分界符
    * @return string
    */
    function uploadMultipartForm($fields, $files, $mimeBoundary) {
        $data = array();
        if(!empty($fields)){
            foreach ($fields as $name => $val) {
                array_push($data, '--' . $mimeBoundary);
                array_push($data, 'Content-Disposition: form-data; name="'.$name.'"');
                array_push($data, '');
                array_push($data, $val);
            }
        }
        if(!empty($files)){
            foreach ($files as $file) {
                array_push($data, '--' . $mimeBoundary);
                list($name, $fileName, $fileBody, $mimeType) = $file;
                $mimeType = empty($mimeType) ? 'application/octet-stream' : $mimeType; //字节流
                $find = array('\\', '"');
                $replace = array('\\\\', '\\"');
                $fileName = str_replace($find, $replace, $fileName);
                array_push($data, 'Content-Disposition: form-data; name="'.$name.'"; filename="'.$fileName.'"');
                array_push($data, 'Content-Type: '.$mimeType);
                array_push($data, '');
                array_push($data, $fileBody);
            }
        }
        array_push($data, '--' . $mimeBoundary . '--');
        array_push($data, '');
        return implode("\r\n", $data);
    } //END uploadMultipartForm


    //解析主体内容信息 ==> array( drr, err)
    function getBody($resp, $respBody) {
        if ($resp['Code'] >= 200 && $resp['Code'] <= 299) {
            if ($resp['ContentLength'] !== 0) {
                $data = json_decode($respBody, true);
                if ($data === null) {
                        $err_msg = function_exists('json_last_error_msg') ? json_last_error_msg() : 'error with content:' . $respBody;
                        return array(null, $err_msg);
                }elseif ($resp['Code'] === 200) {
                    return array($data, null);
                }
            }
        }else{
            return array(null, $this->getError($resp, $respBody));
        }
    } //END getBody


    //解析错误信息
    function  getError($resp, $respBody) {
        if ($resp['Code'] > 299 && $resp['ContentLength'] !== 0) {
            if ($resp['Content-Type'] === 'application/json') {
                $ret = json_decode($respBody, true);
            }
        }
        return $ret['error'];
    } //END getError






      /** --------------------------(20130911)-----------------------
   * 处理数组中文json输出 [未用]
   *
   * 注意：使用json_encode也出现了中文乱码,用函数urlencode()处理一下
   * $arr 数组
   * @return json
   *
    function arrayWordJson($arr) {
         $arri =array();
        foreach($arr as $key => $val){
            if(is_array($val)){
                $this->arrayWordJson($val);
            }else{
                $arri[$key] = urlencode($val);
            }
        }
            print_r($arri);
            return urldecode(json_encode($arri));
    } //END arrayWordJson


    ** --------------------------------(20150614)------------------------------------------------------
   * 用户事件 [未使用]
   *
   * 类型 event 事件
   * http://mp.weixin.qq.com/wiki/9/981d772286d10d153a3dc4286c1ee5b5.html
   * @return array/json
   *
    function user_Event111($event) {

        switch($event){
            case 'subscribe': //关注
                $Type[] = '';
                break;

            case 'unsubscribe': //取消关注
                $Type[] = '';
                break;

            case 'subscribe': //未关注扫码
                $Type[] = 'EventKey';
                $Type[] = 'Ticket';
                break;

            case 'SCAN': //已关注扫码
                $Type[] = 'EventKey';
                $Type[] = 'Ticket';
                break;

            case 'LOCATION': //当前地理位置
                $Type[] = 'Latitude';
                $Type[] = 'Longitude';
                $Type[] = 'Precision';
                break;
    //--------------------------------------------------------------//

            case 'CLICK': //点击菜单
                $Type[] = 'EventKey';
                break;

            case 'VIEW': //URL跳转
                $Type[] = 'EventKey';
                break;

            case 'scancode_push': //扫码推事件
                $Type[] = 'EventKey';
                $Type[] = 'ScanCodeInfo';
                $Type[] = 'ScanType';
                $Type[] = 'ScanResult';
                break;

            case 'scancode_waitmsg': //扫码推事件且弹出“消息接收中”提示框
                $Type[] = 'EventKey';
                $Type[] = 'ScanCodeInfo';
                $Type[] = 'ScanType';
                $Type[] = 'ScanResult';
                break;

            case 'pic_sysphoto': //弹出系统拍照发图
                $Type[] = 'EventKey';
                $Type[] = 'Count';
                $Type[] = 'PicList';
                $Type[] = 'PicMd5Sum';
                break;

            case 'pic_photo_or_album': //弹出拍照或者相册发图
                $Type[] = 'EventKey';
                $Type[] = 'SendPicsInfo';
                $Type[] = 'Count';
                $Type[] = 'PicList';
                $Type[] = 'PicMd5Sum';
                break;

            case 'pic_weixin': //弹出微信相册发图器
                $Type[] = 'EventKey';
                $Type[] = 'SendPicsInfo';
                $Type[] = 'Count';
                $Type[] = 'PicList';
                $Type[] = 'PicMd5Sum';
                break;

            case 'location_select': //弹出地理位置选择器
                $Type[] = 'EventKey';
                $Type[] = 'SendLocationInfo';
                $Type[] = 'Location_X';
                $Type[] = 'Location_Y';
                $Type[] = 'Scale';
                $Type[] = 'Label';
                $Type[] = 'Poiname';
                break;
        }
        return $Type;
    } //END user_Event

    */

} //END class