<?php
/** Validate a transient PNG crop; its source must belong to this note/user. */
function bnCaptureAttachment($capture,array $sources): ?array {
    if($capture===null)return null;
    if(!is_array($capture))bnFail('Geçersiz ekran alıntısı.',422);
    $source=null;foreach($sources as $s)if($s['id']===($capture['source_id']??null))$source=$s;
    if(!$source)bnFail('Ekran alıntısının kaynağı bu nota ait değil.',403);
    $page=$capture['page']??null;if(!is_int($page)||$page<1||$page>100000)bnFail('Geçersiz sayfa numarası.',422);
    $data=$capture['data']??null;if(!is_string($data)||$data===''||strlen($data)>4200000)bnFail('Ekran alıntısı çok büyük.',413);
    $binary=base64_decode($data,true);if($binary===false||strlen($binary)>3*1024*1024||strncmp($binary,"\x89PNG\r\n\x1a\n",8)!==0)bnFail('Geçerli bir PNG ekran alıntısı gönder.',422);
    $size=@getimagesizefromstring($binary);
    if(!$size||$size[2]!==IMAGETYPE_PNG||$size[0]<1||$size[1]<1||max($size[0],$size[1])>1600||$size[0]*$size[1]>2560000)bnFail('Ekran alıntısının boyutları geçersiz.',422);
    return ['attachment'=>['mime_type'=>'image/png','data'=>base64_encode($binary)],'source'=>['id'=>$source['id'],'name'=>$source['name'],'page'=>$page],'page'=>$page];
}
