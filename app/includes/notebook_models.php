<?php
/** Metadata only: listing models never calls generateContent. */
function bnTextModels(array $rows): array {
    $models=[];
    foreach($rows as $row){
        if(!is_array($row)||!is_string($row['name']??null)||!is_array($row['supportedGenerationMethods']??null))continue;
        $id=preg_replace('~^models/~','',(string)($row['name']??''));
        if(!preg_match('/^gemini-[a-z0-9.\-]{1,80}$/i',$id)||!in_array('generateContent',$row['supportedGenerationMethods']??[],true))continue;
        if(preg_match('/^gemini-(?:1\.|2\.0(?:-|$))/i',$id)||preg_match('/(?:embedding|tts|image|audio|live|robotics|computer-use|deep-research|customtools)/i',$id))continue;
        $models[$id]=['id'=>$id,'name'=>mb_substr(trim((string)($row['displayName']??''))?:$id,0,128),'outputTokenLimit'=>max(0,(int)($row['outputTokenLimit']??0))];
    }
    $models=array_values($models);usort($models,function($a,$b){$aliasA=str_ends_with($a['id'],'-latest');$aliasB=str_ends_with($b['id'],'-latest');return $aliasA!==$aliasB?($aliasA?1:-1):strnatcasecmp($b['id'],$a['id']);});
    return $models;
}
function bnModelCatalog(string $key): array {
    if(!function_exists('curl_init'))bnFail('Model listesi için PHP cURL eklentisi gerekli.',422);
    $rows=[];$token='';$seen=[];$deadline=microtime(true)+25;
    for($page=0;$page<5;$page++){
        $url='https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000'.($token!==''?'&pageToken='.rawurlencode($token):'');
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['x-goog-api-key: '.$key],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>max(1,(int)ceil($deadline-microtime(true))),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $response=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if($error||microtime(true)>$deadline)bnFail('Model listesi alınamadı. Sunucunun Google bağlantısını kontrol edip yeniden dene.',422);
        $data=json_decode($response?:'',true);
        if($code!==200)bnFail('Model listesi alınamadı. '.str_replace($key,'[redacted]',(string)($data['error']['message']??'API anahtarını ve Google erişimini kontrol et.')),422);
        if(!is_array($data)||!is_array($data['models']??null))bnFail('Google geçerli bir model listesi döndürmedi.',422);
        $rows=array_merge($rows,$data['models']);$token=(string)($data['nextPageToken']??'');
        if($token==='')return bnTextModels($rows);
        if(strlen($token)>4096||isset($seen[$token]))bnFail('Model listesi tamamlanamadı. Yeniden dene.',422);$seen[$token]=true;
    }
    bnFail('Model listesi tamamlanamadı. Yeniden dene.',422);
}
