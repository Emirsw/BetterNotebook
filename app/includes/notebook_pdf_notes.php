<?php
// Expected provider failures must survive hosting-generated 5xx error documents.
class BNPDFServiceException extends RuntimeException {}
function bnPDFNoteSteps($steps): array {
    if(!is_array($steps)||count($steps)<1||count($steps)>6)bnFail('Çözüm 1–6 adım içermelidir.',422);
    $result=[];
    foreach(array_values($steps) as $i=>$step){
        if(!is_array($step)||!is_string($step['title']??null)||!is_string($step['body']??null)||trim($step['title'])===''||trim($step['body'])==='')bnFail('Çözüm adımları okunamadı.',422);
        if(mb_strlen($step['title'])>120||mb_strlen($step['body'])>4000)bnFail('Açıklama çok uzun.',422);
        $region=$step['region']??null;$confidence=$step['confidence']??0;
        if(!is_numeric($confidence)||$confidence<0||$confidence>1)$confidence=0;
        if($region!==null){if(!bnPDFRect($region)||$confidence<0.65)$region=null;else $region=array_map('floatval',array_values($region));}
        $sourceRegion=$step['source_region']??null;if($sourceRegion!==null&&(!bnPDFRect($sourceRegion)||$confidence<0.65))$sourceRegion=null;
        $position=$step['position']??null;if($position!==null&&!bnPDFRect($position))bnFail('Not konumu geçersiz.',422);
        $result[]=['id'=>'step-'.($i+1),'title'=>trim($step['title']),'body'=>trim($step['body']),'region'=>$region,'source_region'=>$sourceRegion===null?null:array_map('floatval',array_values($sourceRegion)),'confidence'=>(float)$confidence,'position'=>$position===null?null:array_map('floatval',array_values($position))];
    }return $result;
}
function bnPDFRect($box): bool {
    if(!is_array($box)||count($box)!==4)return false;$v=array_values($box);
    foreach($v as $n)if(!is_int($n)&&!is_float($n))return false;
    return min($v)>=0&&$v[2]>0&&$v[3]>0&&$v[0]+$v[2]<=1000&&$v[1]+$v[3]<=1000;
}
function bnPDFNoteJSON(string $text): array {
    $text=trim($text);$text=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$text);
    try{$data=json_decode($text,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new BNPDFServiceException('AI, işaretleme için geçerli bir yanıt üretmedi. Tekrar deneyebilirsin.');}
    if(is_array($data['steps']??null))foreach($data['steps'] as &$step){if(is_array($step))unset($step['position']);}unset($step);
    return bnPDFNoteSteps($data['steps']??null);
}
function bnPDFNoteWrite(PDO $db,int $user,int $note,string $source,int $page,string $stamp,array $steps,int $expected): array {
    $db->beginTransaction();try{
        // The note lock also serializes first inserts for the same page.
        $q=$db->prepare('SELECT id FROM notes WHERE id=? AND user_id=? AND trashed_at IS NULL FOR UPDATE');$q->execute([$note,$user]);if(!$q->fetchColumn())bnFail('Not bulunamadı.',404);
        $q=$db->prepare('SELECT revision,source_stamp FROM notebook_pdf_annotations WHERE user_id=? AND note_id=? AND source_id=? AND page_no=? FOR UPDATE');$q->execute([$user,$note,$source,$page]);$old=$q->fetch(PDO::FETCH_ASSOC);
        $revision=$old&&$old['source_stamp']===$stamp?(int)$old['revision']:0;
        if($revision!==$expected)bnFail('PDF notları başka bir işlemde değişti. Katmanı yenileyip tekrar dene.',409);
        $revision++;
        $q=$db->prepare('INSERT INTO notebook_pdf_annotations(user_id,note_id,source_id,page_no,source_stamp,steps_json,revision) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_stamp=VALUES(source_stamp),steps_json=VALUES(steps_json),revision=VALUES(revision),updated_at=CURRENT_TIMESTAMP');
        $q->execute([$user,$note,$source,$page,$stamp,json_encode($steps,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$revision]);$db->commit();
        return ['page'=>$page,'steps'=>$steps,'revision'=>$revision,'sourceStamp'=>$stamp];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if($_SERVER['REQUEST_METHOD']!=='POST')bnFail('Bu işlem POST gerektirir.',405);
$noteId=(int)($input['note_id']??0);$note=bnOwned($db,$user,$noteId);$sourceId=(string)($input['source_id']??'');$source=null;
foreach(bnSources($db,$user,$note) as $candidate)if($candidate['id']===$sourceId)$source=$candidate;
if(!$source)bnFail('Kaynak bu nota ait değil.',403);
$path=bnFilePath($source['path']);if(bnMimeType($path)!=='application/pdf')bnFail('Bu araç PDF sayfaları içindir.',422);
$stamp=hash('sha256',$source['path'].'|'.filesize($path).'|'.filemtime($path));
if($action==='annotation_get'){
    $q=$db->prepare('SELECT page_no,steps_json,revision FROM notebook_pdf_annotations WHERE user_id=? AND note_id=? AND source_id=? AND source_stamp=? ORDER BY page_no');$q->execute([$user,$noteId,$sourceId,$stamp]);$pages=[];
    foreach($q as $row)$pages[]=['page'=>(int)$row['page_no'],'steps'=>json_decode($row['steps_json'],true),'revision'=>(int)$row['revision'],'sourceStamp'=>$stamp];
    $out=['pages'=>$pages,'sourceStamp'=>$stamp];
}else{
    // Multipart uploads avoid carrying the entire PNG as a large JSON/base64 field.
    if(isset($_FILES['page_image'])){
        foreach(['page','revision'] as $field){$value=$input[$field]??'';if(!is_string($value)||!preg_match('/^\d{1,6}$/D',$value))bnFail('Geçersiz sayfa veya katman sürümü.',422);$input[$field]=(int)$value;}
        $file=$_FILES['page_image'];
        if(!is_array($file)||is_array($file['error']??null)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)bnFail('Sayfa görseli yüklenemedi. PHP yükleme sınırını kontrol et.',413);
        if(($file['size']??0)>3*1024*1024||!is_uploaded_file($file['tmp_name']??''))bnFail('Sayfa görseli çok büyük veya geçersiz.',413);
        $binary=file_get_contents($file['tmp_name']);if($binary===false||strlen($binary)>3*1024*1024)bnFail('Sayfa görseli okunamadı.',422);
        $input['image']=base64_encode($binary);
    }
    $page=$input['page']??0;if(!is_int($page)||$page<1||$page>100000)bnFail('Geçersiz sayfa.',422);
    $revision=$input['revision']??0;if(!is_int($revision)||$revision<0)bnFail('Geçersiz katman sürümü.',422);
    if(($input['source_stamp']??'')!==$stamp)bnFail('Kaynak değişti. PDF’yi yeniden aç.',409);
    if($action==='annotation_save')$out=['document'=>bnPDFNoteWrite($db,$user,$noteId,$sourceId,$page,$stamp,bnPDFNoteSteps($input['steps']??null),$revision)];
    elseif($action==='annotation_generate'){
        require_once __DIR__.'/notebook_capture.php';require_once __DIR__.'/notebook_prompts.php';
        $capture=bnCaptureAttachment(['source_id'=>$sourceId,'page'=>$page,'data'=>$input['image']??null],[$source]);
        $q=$db->prepare('SELECT revision FROM notebook_pdf_annotations WHERE user_id=? AND note_id=? AND source_id=? AND page_no=? AND source_stamp=?');$q->execute([$user,$noteId,$sourceId,$page,$stamp]);if((int)($q->fetchColumn()?:0)!==$revision)bnFail('PDF notları başka bir işlemde değişti. Katmanı yenileyip tekrar dene.',409);
        $account=getCurrentUser();$savedKey=bnAccountGeminiKey($account);if(!$savedKey)bnFail('AI bağlantısını Ayarlar sayfasından yapılandır.',422);
        $prompt="Bu görsel, kullanıcının PDF belgesinin ".$page.". sayfasıdır. Sayfayı öğrenci için açıklamalı bir çalışma sayfasına dönüştür. Kaynaktaki soruları veya konuyu, eldeki verilerle adım adım açıkla. Eksik şekil/değerleri uydurma. Aynı görsel koordinat sisteminde, her adımın doğrudan ilgili olduğu devre elemanlarını/denklem satırını bir odak dikdörtgeniyle eşleştir. Ekran görüntüsü için ayrıca source_region ver: devre sorularında tüm devreyi, kaynakları, bütün kolları, eleman değerlerini ve düğüm etiketlerini kapsasın. Sadece bir kolu ya da yarım devreyi kesme. Denklem/metin için tam denklem satırını veya ilgili paragrafı kapsasın. source_region, region kutusundan geniş olabilir; region yalnızca vurgu içindir. Her adımda yalnızca 1 ana bölge seç; ilgisiz tüm sayfayı işaretleme. Görselde güvenilir konum belirleyemiyorsan region null olsun.\nSADECE JSON üret; kod çiti veya giriş yazısı yok. Şema: {\"steps\":[{\"title\":\"Kısa adım başlığı\",\"body\":\"Kısa öğretici açıklama, formül, değerleri yerine koyma ve sonuç\",\"region\":[x,y,width,height],\"source_region\":[x,y,width,height],\"confidence\":0.9}]}. 1–6 adım. region ve source_region değerleri tam görselin sol üst köşesi (0,0), sağ alt köşesi (1000,1000) olan normalize koordinatlardır; PDF noktası veya piksel değildir. Her kutu sayfa içinde kalmalı. confidence 0–1 arası konum güvenidir. title en fazla 120 karakter, body tercihen 2–5 kısa satır, en fazla 1500 karakter. body düz metin ve geçerli LaTeX içersin; HTML/Markdown başlık/liste kullanma. Satır içi $...$, ayrı denklem $$...$$. Bir adım tek başına anlaşılır olsun. Notun yerleşeceği boşluğu veya position alanını üretme; bunu uygulama belirler. Not başlığı: ".$note['title'];
        session_write_close();require_once __DIR__.'/gemini_helper.php';$ai=new GeminiAI($savedKey,$account['gemini_model']);
        $result=$ai->generateResponse($prompt,bnTeachingInstruction(bnLanguage($db,$user))."\nBu işlemde yanıt biçimi yalnızca belirtilen JSON şemasıdır. Öğretici çözüm ilkelerini toplam 1–6 steps adımına dağıt; her adımda altı bölümlük şablonu yeniden tekrarlama. Gereken sembolik formülü, sayısal yerine koymayı ve ara sonuçları koru; uzun giriş ve sonuç paragrafları ekleme. Her body en fazla 1500 karakter olsun.",[$capture['attachment']],'json');
        if(empty($result['success']))throw new BNPDFServiceException(str_replace($savedKey,'[redacted]',$result['error']??'AI açıklaması hazırlanamadı.'));
        $steps=bnPDFNoteJSON($result['text']??'');$out=['document'=>bnPDFNoteWrite($db,$user,$noteId,$sourceId,$page,$stamp,$steps,$revision)];
    }else bnFail('Bilinmeyen katman işlemi.',404);
}
