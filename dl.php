<?php
 function start($url,$saveFile,$timeout = 10) {
		$dataFile = $saveFile . '.download.cfg';
		$saveTemp = $saveFile . '';
		
		//header:{'url','length','name','supportRange'}
		if(is_array($url)){
			$fileHeader = $url;
		}else{
			$fileHeader = url_header($url);
		}
		$url = $fileHeader['url'];
		if(!$url){
			return array('code'=>false,'data'=>'url error!');
		}
		//默认下载方式if not support range
		if(!$fileHeader['supportRange'] || 
			$fileHeader['length'] == 0 ){
			@unlink($saveTemp);@unlink($saveFile);
			$result = fileDownloadFopen($url,$saveFile,$fileHeader['length']);
			if($result['code']) {
				return $result;
			}else{
				@unlink($saveTemp);@unlink($saveFile);
				$result = fileDownloadCurl($url,$saveFile,false,0,$fileHeader['length']);
				@unlink($saveTemp);@unlink($saveFile);
				return $result;
			}
		}

		$existsLength  = is_file($saveTemp) ? filesize($saveTemp) : 0;
		$contentLength = intval($fileHeader['length']);
		if( file_exists($saveTemp) &&
			time() - filemtime($saveTemp) < 3) {//has Changed in 3s,is downloading 
			return array('code'=>false,'data'=>'downloading');
		}
		
		$existsData = array();
		if(is_file($dataFile)){
			$tempData = file_get_contents($dataFile);
			$existsData = json_decode($tempData, 1);
		}
		// exist and is the same file;
		if( file_exists($saveFile) && $contentLength == filesize($saveFile)){
			@unlink($saveTemp);
			@unlink($dataFile);
			return array('code'=>true,'data'=>'exist');
		}

		// check file is expire
		if ($existsData['length'] != $contentLength) {
			$existsData = array('length' => $contentLength);
		}
		if($existsLength > $contentLength){
			@unlink($saveTemp);
		}
		// write exists data
		file_put_contents($dataFile, json_encode($existsData));
		$result = fileDownloadCurl($url,$saveFile,true,$existsLength,$contentLength);
		if($result['code']){
			@unlink($dataFile);
		}
		return $result;
	}

	// fopen then download
function fileDownloadFopen($url, $fileName,$headerSize=0){
		@ini_set('user_agent','Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.94 Safari/537.36');

		$fileTemp = $fileName.'';
		@set_time_limit(0);
		@unlink($fileTemp);
		if ($fp = @fopen ($url, "rb")){
			if(!$downloadFp = @fopen($fileTemp, "wb")){
				return array('code'=>false,'data'=>'open_downloading_error');
			}
			while(!feof($fp)){
				if(!file_exists($fileTemp)){//删除目标文件；则终止下载
					fclose($downloadFp);
					return array('code'=>false,'data'=>'stoped');
				}
				//对于部分fp不结束的通过文件大小判断
				clearstatcache();
				if( $headerSize>0 &&
					$headerSize==get_filesize(iconv_system($fileTemp))
					){
					break;
				}
				fwrite($downloadFp, fread($fp, 1024 * 8 ), 1024 * 8);
			}
			//下载完成，重命名临时文件到目标文件
			fclose($downloadFp);
			fclose($fp);
			
			$filesize = get_filesize(iconv_system($fileTemp));
			if($headerSize != 0 && $filesize != $headerSize){
			    return array('code'=>false,'data'=>'file size error');
			}
			checkGzip($fileTemp);
			if(!@rename($fileTemp,$fileName)){
				usleep(round(rand(0,1000)*50));//0.01~10ms
				@unlink($fileName);
				$res = @rename($fileTemp,$fileName);
				if(!$res){
					return array('code'=>false,'data'=>'rename error![open]');
				}
			}
			return array('code'=>true,'data'=>'success');
		}else{
			return array('code'=>false,'data'=>'url_open_error');
		}
	}

	// curl 方式下载
	// 断点续传 http://www.linuxidc.com/Linux/2014-10/107508.htm
function fileDownloadCurl($url, $fileName,$supportRange=false,$existsLength=0,$length=0){
		$fileTemp = $fileName;
		@set_time_limit(0);
		if ($fp = @fopen ($fileTemp, "a")){
			$ch = curl_init($url);
			//断点续传
			if($supportRange){
				curl_setopt($ch, CURLOPT_RANGE, $existsLength."-");
			}
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_REFERER,get_url_link($url));
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.94 Safari/537.36');

			$res = curl_exec($ch);
			curl_close($ch);
			fclose($fp);

            $filesize = get_filesize(iconv_system($fileTemp));
			if($filesize < $length && $length!=0){
			    return array('code'=>false,'data'=>'downloading');
			}
			if($filesize > $length && $length!=0){
			    //远程下载大小不匹配；则返回正在下载中，客户端重新触发下载
			    return array('code'=>false,'data'=>'file size error');
			}
			
			if($res && filesize($fileTemp) != 0){
				checkGzip($fileTemp);
				if(!@rename($fileTemp,$fileName)){
					@unlink($fileName);
					$res = @rename($fileTemp,$fileName);
					if(!$res){
						return array('code'=>false,'data'=>'rename error![curl]');
					}
				}
				return array('code'=>true,'data'=>'success');
			}
			return array('code'=>false,'data'=>'curl exec error!');
		}else{
			return array('code'=>false,'data'=>'file create error');
		}
	}

	function checkGzip($file){
		$char = "\x1f\x8b";
		$str  = file_sub_str($file,0,2);
		if($char != $str) return;

		ob_start();   
		readgzfile($file);   
		$out = ob_get_clean();
		file_put_contents($file,$out);
	}
	function url_header($url){
	$header = get_headers_curl($url);//curl优先
	if(is_array($header)){
		$header['ACTION_BY'] = 'get_headers_curl';
	}else{
		$header = @get_headers($url,true);
	}
	if (!$header) return false; 

	//加入小写header值;兼容各种不统一的情况
	$header['———'] = '————————————';//分隔
	foreach ($header as $key => $value) {
		$header[strtolower($key)] = $value;
	}
	$checkArr = array(
		'content-length'		=> 0, 
		'location'				=> $url,//301调整
		'content-disposition'	=> '',
	);
	//处理多次跳转的情况
	foreach ($checkArr as $key=>$val) {
		if(isset($header[$key])){
			$checkArr[$key] = $header[$key];
			if(is_array($header[$key])  && count($header[$key])>0){
				$checkArr[$key] = $header[$key][count($header[$key])-1];
			}
		}
	}
	$name 	= $checkArr['content-disposition'];
	$length = $checkArr['content-length'];
	$fileUrl= $checkArr['location'];
	if($name){
		preg_match('/filename\s*=\s*"*(.*)"*?/',$name,$match);
		if(count($match) == 2){
			$name = $match[1];
		}else{
			$name = '';
		}
	}
	if(!$name){
		$name = get_path_this($fileUrl);
		if (strstr($name,'=')) $name = substr($name,strrpos($name,'=')+1);
		if (!$name) $name = 'file.data';
	}
	if(!empty($header['x-outfilename'])){
		$name = $header['x-outfilename'];
	}
	$name = rawurldecode(trim($name,'"'));
	$name = str_replace(array('/','\\'),'-',$name);//safe;
	$supportRange = isset($header["accept-ranges"])?true:false;
	if(!request_url_safe($fileUrl)){
		$fileUrl = "";
	}
	$result = array(
		'url' 		=> $fileUrl,
		'length' 	=> $length,
		'name' 		=> $name,
		'supportRange' => $supportRange && ($length!=0),
		'all'		=> $header,
	);
	if(!function_exists('curl_init')){
		$result['supportRange'] = false;
	}
	//pr($url,$result);
	return $result;
}
function get_headers_curl($url,$timeout=30,$depth=0,&$headers=array()){
	if(!function_exists('curl_init')){
		return false;
	}
	if ($depth >= 10) return false;
	$ch = curl_init(); 
	curl_setopt($ch, CURLOPT_URL,$url);
	curl_setopt($ch, CURLOPT_HEADER,true); 
	curl_setopt($ch, CURLOPT_NOBODY,true); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
	curl_setopt($ch, CURLOPT_TIMEOUT,$timeout);
	curl_setopt($ch, CURLOPT_REFERER,get_url_link($url));
	curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.94 Safari/537.36');

	$res = curl_exec($ch);
	$res = explode("\r\n", $res);

	$location = false;
	foreach ($res as $line) {
		list($key, $val) = explode(": ", $line, 2);
		$the_key = trim($key);
		if($the_key == 'Location' || $the_key == 'location'){
			$the_key = 'Location';
			$location = trim($val);
		}
		if( strlen($the_key) == 0 &&
			strlen(trim($val)) == 0  ){
			continue;
		}
		if( substr($the_key,0,4) == 'HTTP' &&
			strlen(trim($val)) == 0  ){
			$headers[] = $the_key;
			continue;
		}

		if(!isset($headers[$the_key])){
			$headers[$the_key] = trim($val);
		}else{
			if(is_string($headers[$the_key])){
				$temp = $headers[$the_key];
				$headers[$the_key] = array($temp);
			}
			$headers[$the_key][] = trim($val);
		}
	}
	if($location !== false){
		$depth++;
		get_headers_curl($location,$timeout,$depth,$headers);
	}
	return count($headers)==0?false:$headers;
} 
function request_url_safe($url){
	$link = trim(strtolower($url));
	$link = str_replace('\\','/',$link);
	while (strstr($link,'../')) {
		$link = str_replace('../', '/', $link);
	}
	if( substr($link,0,6) != "ftp://" &&
		substr($link,0,7) != "http://" &&
		substr($link,0,8) != "https://" ){
		return false;
	}
	return true;
}
function get_path_this($path){
	$path = str_replace('\\','/', rtrim($path,'/'));
	$pos = strrpos($path,'/');
	if($pos === false){
		return $path;
	}
	return substr($path,$pos+1);
}
function get_url_link($url){
	if(!$url) return "";
	$res = parse_url($url);
	$port = (empty($res["port"]) || $res["port"] == '80')?'':':'.$res["port"];
	return $res['scheme']."://".$res["host"].$port.$res['path'];
}
function iconv_system($str){
	//去除中文空格UTF8; windows下展示异常;过滤文件上传、新建文件等时的文件名
	//文件名已存在含有该字符时，没有办法操作.
	$char_empty = "\xc2\xa0";
	if(strpos($str,$char_empty) !== false){
		$str = str_replace($char_empty," ",$str);
	}

	global $config;
	$result = iconv_to($str,$config['appCharset'], $config['systemCharset']);
	$result = path_filter($result);
	return $result;
}
function iconv_to($str,$from,$to){
	if (strtolower($from) == strtolower($to)){
		return $str;
	}
	if (!function_exists('iconv')){
		return $str;
	}
	//尝试用mb转换；android环境部分问题解决
	if(function_exists('mb_convert_encoding')){
		$result = @mb_convert_encoding($str,$to,$from);
	}else{
		$result = @iconv($from, $to, $str);
	}
	if(strlen($result)==0){ 
		return $str;
	}
	return $result;
}
function path_filter($path){
	if(strtoupper(substr(PHP_OS, 0,3)) != 'WIN'){
		return $path;
	}
	$notAllow = array('*','?','"','<','>','|');//去除 : D:/
	return str_replace($notAllow,' ', $path);
}
function get_filesize($path){
	if(PHP_INT_SIZE >= 8 ){ //64bit
		return (float)(abs(sprintf("%u",@filesize($path))));
	}
	
	$fp = fopen($path,"r");
	if(!$fp) return $result;	
	if (fseek($fp, 0, SEEK_END) === 0) {
		$result = 0.0;
		$step = 0x7FFFFFFF;
		while ($step > 0) {
			if (fseek($fp, - $step, SEEK_CUR) === 0) {
				$result += floatval($step);
			} else {
				$step >>= 1;
			}
		}
	}else{
		static $iswin;
		if (!isset($iswin)) {
			$iswin = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN');
		}
		static $exec_works;
		if (!isset($exec_works)) {
			$exec_works = (function_exists('exec') && !ini_get('safe_mode') && @exec('echo EXEC') == 'EXEC');
		}
		if ($iswin && class_exists("COM")) {
			try {
				$fsobj = new COM('Scripting.FileSystemObject');
				$f = $fsobj->GetFile( realpath($path) );
				$size = $f->Size;
			} catch (Exception $e) {
				$size = null;
			}
			if (is_numeric($size)) {
				$result = $size;
			}
		}else if ($exec_works){
			$cmd = ($iswin) ? "for %F in (\"$path\") do @echo %~zF" : "stat -c%s \"$path\"";
			@exec($cmd, $output);
			if (is_array($output) && is_numeric($size = trim(implode("\n", $output)))) {
				$result = $size;
			}
		}else{
			$result = filesize($path);
		}
	}
	fclose($fp);
	return $result;
}
function file_sub_str($file,$start=0,$len=0){
	$size = filesize($file);
	if($start < 0 ){
		$start = $size + $start;
		$len = $size - $start;
	}
    $fp = fopen($file,'r');
    fseek($fp,$start);
    $res = fread($fp,$len);
    fclose($fp);
    return $res;
}
?>
