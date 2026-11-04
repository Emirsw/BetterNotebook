<?php
$bnRequestPath=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
if(preg_match('~(?:^|/)\.[^/]*|^/(?:config|includes|sql|scripts|uploads)(?:/|$)|\.(?:sql|zip|log|ini|yml)$~i',$bnRequestPath)){
    http_response_code(404);echo 'Not found';return true;
}
$bnRoutes=['/files'=>'files/index.php','/files/'=>'files/index.php','/'=>'index.php','/index'=>'index.php','/notes'=>'notes.php','/pomodoro'=>'pomodoro.php','/settings'=>'settings.php','/login'=>'login.php','/logout'=>'logout.php','/pdf-viewer'=>'pdf-viewer.php','/api/notebook'=>'api/notebook.php'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&str_ends_with($bnRequestPath,'.php')){
    $bnClean=substr($bnRequestPath,0,-4);
    if(isset($bnRoutes[$bnClean])){if($bnClean==='/index')$bnClean='/';$bnQuery=$_SERVER['QUERY_STRING']??'';header('Location: '.$bnClean.($bnQuery!==''?'?'.$bnQuery:''),true,302);return true;}
}
if(isset($bnRoutes[$bnRequestPath])){
    $bnTarget=rtrim($_SERVER['DOCUMENT_ROOT'],'/\\').DIRECTORY_SEPARATOR.$bnRoutes[$bnRequestPath];
    $_SERVER['SCRIPT_FILENAME']=$bnTarget;$_SERVER['SCRIPT_NAME']='/'.$bnRoutes[$bnRequestPath];
    require $bnTarget;return true;
}
return false;
