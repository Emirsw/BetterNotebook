<?php
// One PDF or extracted part is committed atomically with its note and retry key.
$client=trim($input['client_id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$client))bnFail('Geçersiz yükleme kimliği.',422);
$target=(int)($input['note_id']??0);if($target)bnOwned($db,$user,$target);
$prior=$db->prepare('SELECT note_id FROM notebook_import_items WHERE user_id=? AND client_id=?');$prior->execute([$user,$client]);$previous=$prior->fetchColumn();
if($previous){if($target&&$target!==(int)$previous)bnFail('Yükleme kimliği başka bir nota ait.',409);$out=['note'=>bnNoteDTO($db,$user,(int)$previous),'reused'=>true];}
else{
    $f=$_FILES['file']??null;
    if(!$f||is_array($f['name'])||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)bnFail('PDF yüklenemedi. Dosya boyutunu ve bağlantını kontrol et.',413);
    if(($f['size']??0)<1||$f['size']>50*1024*1024)bnFail('Her PDF parçası en fazla 50 MB olabilir. Daha az sayfalı parçalar seç.',413);
    $header=file_get_contents($f['tmp_name'],false,null,0,1024);
    if(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='pdf'||strpos($header,'%PDF-')===false)bnFail('Geçerli bir PDF dosyası seç.',422);
    $course=$target?null:bnCourse($db,$user,trim($input['course']??''));
    $title=mb_substr(trim($input['title']??pathinfo($f['name'],PATHINFO_FILENAME)),0,200);if($title==='')$title='PDF';
    $moved=null;$committed=false;$db->beginTransaction();
    try{
        if(!$target){$q=$db->prepare("INSERT INTO notes(user_id,title,content,course_id,is_pinned,is_ai,tags) VALUES(?,?,'',?,0,0,'BetterNotebook:document')");$q->execute([$user,$title,$course]);$target=(int)$db->lastInsertId();}
        $relative='uploads/notes/bn_'.$user.'_'.bin2hex(random_bytes(14)).'.pdf';$moved=__DIR__.'/../'.$relative;
        if(!move_uploaded_file($f['tmp_name'],$moved))bnFail('Dosya kaydedilemedi. Yükleme klasörü izinlerini kontrol et.',500);
        $q=$db->prepare('INSERT INTO notebook_sources(note_id,user_id,name,file_path,size_bytes) VALUES(?,?,?,?,?)');$q->execute([$target,$user,mb_substr(basename(str_replace('\\','/',$f['name'])),0,255),$relative,$f['size']]);
        $q=$db->prepare('INSERT INTO notebook_import_items(user_id,client_id,note_id) VALUES(?,?,?)');$q->execute([$user,$client,$target]);$db->commit();$committed=true;
        $out=['note'=>bnNoteDTO($db,$user,$target),'reused'=>false];
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();if(!$committed&&$moved&&is_file($moved))@unlink($moved);
        // Two retry requests can arrive together. Return the first committed result.
        $prior->execute([$user,$client]);$previous=$prior->fetchColumn();if($previous)$out=['note'=>bnNoteDTO($db,$user,(int)$previous),'reused'=>true];else throw $error;
    }
}
