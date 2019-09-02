<?php
/*
 * Openssl
 * 生成/签名/验证
 * @author http://weibo.com/yakeing
 * @version 4.2
 * 注意 tempnam() 否有读写权限,临时文件保存 openssl.fnc 临时配置
 * extension_loaded('openssl') or die('php需要 openssl 扩展支持');
 * OPENSSL_ALGO_SHA256 等于 'sha256WithRSAEncryption'
 * ecdsa-with-SHA256  secp256r1  ECC证书格式
 * openssl_get_md_methods() 获取当前系统支持格式
 * 2016年1月1日起，权威证书机构CA颁发的可信证书仅支持SHA256哈希签名算法，不再支持SHA1
 */
namespace php_openssl;
class Openssl{
	public $days = 365; //X509证书有效天
	public $Passphrase = NULL; //密码
	public $CertSuffix = 'pem'; //输出CA证书后缀 pem pfx
	public $SSLcnf = array(); //配置
	public $SignatureAlg = OPENSSL_ALGO_SHA256; //加密方式
	private $TempList = array(); //临时文件列表

	//构造函数
	public function __construct(){
		$this->SSLcnf = array(
			// OPENSSL_CONF
			// "config" => '\usr\local\ssl\openssl.cnf', //指定 openssl.cnf 配置文件
			'encrypt_key' => true, //是否加密
			'digest_alg' => 'sha256', //加密方式
			'private_key_bits' => 2048, //私匙加密长度
			'private_key_type' => OPENSSL_KEYTYPE_RSA //私匙类型
		);
	}//END __construct

	//解析CSR证书信息(请求证书)
	public function CsrParse($csr){
		$RetCsr = openssl_pkey_get_details(openssl_csr_get_public_key($csr));
		$RetCsr['dn'] = openssl_csr_get_subject($csr, false);
		return$RetCsr;
	}//END CsrSubject

	//解析CRT证书内容(AC已签名证书)
	public function CrtParse($crt){
		return openssl_x509_parse($crt, false);
	}//END CsrSubject

	//获取公匙 (1)
	public function GetPubkey($privkey=false){
		$param = $this->GetPrivate($privkey);
		$details= openssl_pkey_get_details($param['key_id']); //获取细节
		return array('bits'=>$details['bits'], 'privkey'=>$param['privkey'], 'pubkey'=>$details['key']);
	}//END GetPubkey

	//获取请求证书 (2)
	public function GetCsr($dns=false, $privkey=false, $dn=false, $notext=true, $extensions=true){
		if(is_bool($dns)){
			$dns = true;
		}else if(!is_array($dns)){
				$dns = array($dns);
		}
		$this->OutOpensslCnf($dns, $extensions);
		$param = $this->GetPrivate($privkey, $this->SSLcnf);
		$out_O_CN = ($extensions) ? false:true;
		$CSR_id = $this->CsrDn($param['privkey'], $dn, $out_O_CN);
		openssl_csr_export($CSR_id, $csr, $notext);
		return array('csr'=>$csr, 'privkey'=>$param['privkey']);
	}//END GetCsr

	//CA签名证书(3)
	//CaCert($privkey, false, $dn, $CAserial) //根证书
	//CaCert($privkey, false, $dn, $CAserial, $CAkey, $CAcert) //中间证书
	//CaCert($privkey, $dns, $dn, $CAserial, $CAkey, $CAcert) //终端证书 (出错 颁发机构密匙标识)
	public function CaCert($privkey=false, $dns=false, $dn=false, $CAserial=0, $CAkey=false, $CAcert=NULL){
		$param = $this->GetPrivate($privkey);
		$out_O_CN = true;
		if($CAkey==false || $CAcert==NULL){ //生成CA 根证书
			$cnf = 'CA';
			$CAkey_id = $param['key_id'];
		}else{ //拿CA来签名证书
			if(is_array($dns)){
				$cnf = $dns;
				$out_O_CN = false;
			}else{
				$cnf = 'CA0';
			}
			$CAparam = $this->GetPrivate($CAkey);
			$CAkey_id = $CAparam['key_id'];
		}
		$this->OutOpensslCnf($cnf);
		$NweCsr_id = $this->CsrDn($param['key_id'], $dn, $out_O_CN);
		$sscert = openssl_csr_sign($NweCsr_id, $CAcert, $CAkey_id, $this->days, $this->SSLcnf, $CAserial);
		if($this->CertSuffix == 'pem'){ //PEM证书
			openssl_x509_export($sscert, $pem);
			return array('pem' => $pem, 'privkey' => $param['privkey']);
		}else{ //PFX证书
			$pkcs12args = array();
			openssl_pkcs12_export($sscert, $pfx, $CAkey_id, $this->Passphrase, $pkcs12args);
			return array('pfx' => $pfx, 'privkey' => $param['privkey']);
		}
	}//END CaCert

	//获取私匙
	private function GetPrivate($privkey=false, $PkeySSLcnf=NULL){
		if($privkey == false){
			$key_id = openssl_pkey_new($this->SSLcnf);
		}else{
			$key_id = openssl_pkey_get_private($privkey, $this->Passphrase);
		}
		openssl_pkey_export($key_id, $PrivkeyStr, $this->Passphrase, $PkeySSLcnf);
		return array('key_id'=>$key_id, 'privkey'=>$PrivkeyStr);
	} //NED GetPrivate

	//生成 openssl 配置文件
	private function OutOpensslCnf($cnf, $extensions=true){
		if($cnf == false) return false;
		$this->SSLcnf['digest_alg'] = 'sha256'; //加密方式
		$CAextended = array(
			//'authorityKeyIdentifier=keyid,issuer',
			//'authorityKeyIdentifier=keyid:always,issuer:always', //颁发机构密匙标识
			//'issuerAltName=issuer:copy', //颁发者备用名称(不要了)
			'extendedKeyUsage = critical,TLS Web Server Authentication, TLS Web Client Authentication', //增强型密钥用法
			'authorityInfoAccess = @ocsp_root', //颁发机构信息访问
			'crlDistributionPoints = @root_section', //CRL分发点
			'certificatePolicies = @PCs', //证书策略
			'[ root_section ]',
			'URI.1 = https://a.com/cacrl.crl',
			'[ ocsp_root ]',
			'OCSP;URI.0 = http://a.com/ocsp',
			'caIssuers;URI.1 = http://a.com/cacert.pem',
			'[ PCs ]',
			'policyIdentifier = X509v3 Any Policy',
			'CPS.1 = http://a.com/dpc',
		);
		if(is_array($cnf)){ //终端证书
			$this->SSLcnf['x509_extensions'] = 'v3_req';
			$ReqArr = array(
				'[ req ]',
				'distinguished_name = req_distinguished_name',
				'req_extensions = v3_req',
				'[ req_distinguished_name ]',
				'[ v3_req ]',
				'subjectAltName = @alt_names',
			);
			$extensionsArr = array(
				'basicConstraints = CA:FALSE', //基本约束
				'keyUsage = nonRepudiation, digitalSignature, keyEncipherment', //密匙用法
				'subjectKeyIdentifier = hash', //使用者密匙标识
				);
			$SanArr = array('[ alt_names ]');
			$i =0;
			foreach($cnf as $domain){
				++$i;
				$SanArr[] = 'DNS.'.$i.' = '.$domain;
			}
			if($extensions){
				$ConfArr = array_merge($ReqArr, $extensionsArr, $CAextended, $SanArr);
			}else{
				$ConfArr = array_merge($ReqArr, $SanArr);
			}
		}else{//服务器证书(根/中间)
			$this->SSLcnf['x509_extensions'] = 'v3_ca';
			$pathlen = '';
			$betweenCA = array();
			if($cnf == 'CA'){ //根CA配置
				$this->SSLcnf['digest_alg'] = 'SHA1'; //加密方式
			}else{//中间CA配置
				$pathlen = ', pathlen:0';
				$betweenCA = $CAextended;
			}
			$ConfArr = array(
				'[ req ]',
				'distinguished_name = req_distinguished_name',
				'[ req_distinguished_name ]',
				'[ v3_ca ]',
				'basicConstraints = critical, CA:true'.$pathlen, //基本约束
				'keyUsage = cRLSign, keyCertSign', //密匙用法
				'subjectKeyIdentifier = hash' //使用者密匙标识
			);
			$ConfArr = array_merge($ConfArr, $betweenCA);
		}
		$Conf = implode("\n", $ConfArr);
		//echo $Conf;
		$this->SSLcnf['config'] = $this->PutConfFile($Conf); //写入临时文件
	} //END OutOpensslCnf

	//证书 DN 信息
	private function CsrDn($key_id, $NewDn='', $out_O_CN=false){

		$dn = array(
			'countryName' => 'CN', //国家
			'stateOrProvinceName' => 'Guangdong', //省
			'localityName' => 'Guangzhou', //市
			'organizationName' => 'Network Technology Co Ltd', //公司(注册人)
			'organizationalUnitName' => 'Network Technology Team', //部门(组织)
			'commonName' => 'apptb.com', //域名(通用名)
		//	'emailAddress' => 'yakeing@gmail.com' //邮箱
		);
		if(is_array($NewDn)){
			$dn = array_merge($dn, $NewDn);
		}
		if($out_O_CN){ //AC只要 公司 通用名
			$dn = array('organizationName' => $dn['organizationName'],
				'commonName' => $dn['commonName']);
		}
		return openssl_csr_new($dn, $key_id, $this->SSLcnf);
	}//END CsrDn


	//此处涉及到临时文件写入
	private function PutConfFile($Conf){
		$temp = tempnam(sys_get_temp_dir(), 'CNF');
		file_put_contents($temp, $Conf);
		$this->TempList[] = $temp;
		return $temp;
	} //END PutConfFile

	//--------------------以下加 / 解密类-------------------------------

	//私匙签名
	public function PrivkeySign($str, $privkey, $EncodedType='base64'){
		$priv_key_id = openssl_pkey_get_private($privkey, $this->Passphrase); //别名 openssl_get_privatekey
		if(openssl_sign($str, $sign, $priv_key_id, $this->SignatureAlg)){
			return ($EncodedType == 'hex') ? bin2hex($sign) : base64_encode($sign);//2进制转16进制 / base64
		}else{
			return false;
		}
	} //NED PrivkeySign

	//公匙验证
	public function PubkeyVerify($str, $sign, $pubkey, $EncodedType='base64'){
		$sign = ($EncodedType == 'hex') ? hex2bin($sign) : base64_decode($sign);//16进制转2进制 / base64
		$pub_key_id = openssl_pkey_get_public($pubkey); //别名 openssl_get_publickey
		return (openssl_verify($str, $sign, $pub_key_id, $this->SignatureAlg) === 1);
	} //NED PubkeyVerify

	//私匙加密/解密
	public function PrivkeyEncryptDecrypt($str, $privkey, $mode){
		$priv_key_id = openssl_pkey_get_private($privkey, $this->Passphrase); //获取私钥KEY(有密码时)
		if($mode=='encrypt' && openssl_private_encrypt($str, $encrypted, $priv_key_id)){//私匙加密
			return base64_encode($encrypted);
		}elseif($mode=='decrypt' && openssl_private_decrypt(base64_decode($str), $decrypted, $priv_key_id)){//私匙解密
			return $decrypted;
		}else{
			return false;
		}
	} //END PrivkeyEncryptDecrypt

	//公匙加密/解密
	public function PubkeyEncryptDecrypt($str, $pubkey, $mode){
		if($mode=='encrypt' && openssl_public_encrypt($str, $encrypted, $pubkey)){ //公钥加密
			return base64_encode($encrypted);
		}elseif($mode=='decrypt' && openssl_public_decrypt(base64_decode($str), $deciphering, $pubkey)){ //公钥解密
			return $deciphering;
		}else{
			return false;
		}
	} //END PubkeyEncryptDecrypt

	//析构函数
	public function __destruct(){
		foreach($this->TempList as $temp){
			unlink ($temp); //删除临时文件
		}
	} //END __destruct
}