<?php
/** Owner-scoped media registry. Existing note files are registered without moving them. */
function bnMediaRows(PDO $db,int $user): array {
    $q=$db->prepare('SELECT * FROM notebook_media WHERE user_id=? ORDER BY id DESC');$q->execute([$user]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function bnMediaSync(PDO $db,int $user): array {
    $known=[];foreach(bnMediaRows($db,$user) as $m){$known[$m['file_path']]=true;if($m['canonical_path'])$known[$m['canonical_path']]=true;}
    $q=$db->prepare("SELECT file_path,COALESCE(NULLIF(file_name,''),file_path) AS name,file_type AS kind FROM notes WHERE user_id=? AND trashed_at IS NULL AND file_path IS NOT NULL AND file_path<>'' ORDER BY id");$q->execute([$user]);$discovered=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$db->prepare("SELECT s.file_path,s.name,'' AS kind FROM notebook_sources s JOIN notes n ON n.id=s.note_id AND n.user_id=s.user_id WHERE s.user_id=? AND n.trashed_at IS NULL ORDER BY s.id");$q->execute([$user]);$discovered=array_merge($discovered,$q->fetchAll(PDO::FETCH_ASSOC));
    $insert=$db->prepare('INSERT IGNORE INTO notebook_media(user_id,path_key,file_path,name,size_bytes,kind) SELECT ?,?,?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM notebook_media WHERE user_id=? AND (file_path=? OR canonical_path=?))');
    foreach($discovered as $s){$path=$s['file_path'];if(isset($known[$path]))continue;$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$kind=in_array($ext,['png','jpg','jpeg','webp'],true)||$s['kind']==='image'?'image':($ext==='pdf'?'pdf':'other');
        $size=0;try{$size=filesize(bnFilePath($path));}catch(RuntimeException $ignored){http_response_code(200);}
        $insert->execute([$user,hash('sha256',$path),$path,mb_substr(basename(str_replace('\\','/',$s['name'])),0,255),$size,$kind,$user,$path,$path]);$known[$path]=true;
    }
    return array_values(array_filter(bnMediaRows($db,$user), fn($item) => $item['trashed_at'] === null));
}
function bnMediaPath(array $media): string {
    if(!empty($media['canonical_path'])){try{return bnFilePath($media['canonical_path']);}catch(RuntimeException $ignored){http_response_code(200);}}
    return bnFilePath($media['file_path']);
}
function bnMediaLibrary(PDO $db,int $user): array {
    $rows=bnMediaSync($db,$user);$links=[];
    $q=$db->prepare("SELECT id,title,file_path FROM notes WHERE user_id=? AND trashed_at IS NULL AND file_path IS NOT NULL AND file_path<>''");$q->execute([$user]);$associated=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$db->prepare('SELECT n.id,n.title,s.file_path FROM notebook_sources s JOIN notes n ON n.id=s.note_id AND n.user_id=s.user_id WHERE s.user_id=? AND n.trashed_at IS NULL');$q->execute([$user]);$associated=array_merge($associated,$q->fetchAll(PDO::FETCH_ASSOC));
    foreach($associated as $r)$links[$r['file_path']][(int)$r['id']]=['id'=>(int)$r['id'],'title'=>$r['title']];
    $items=[];foreach($rows as $m){$available=true;$size=(int)$m['size_bytes'];try{$path=bnMediaPath($m);$size=filesize($path);}catch(RuntimeException $ignored){$available=false;http_response_code(200);}
        $notes=$links[$m['file_path']]??[];if($m['canonical_path'])$notes+=($links[$m['canonical_path']]??[]);
        $items[]=['id'=>(int)$m['id'],'name'=>$m['name'],'size'=>$size,'kind'=>$m['kind'],'available'=>$available,'notes'=>array_values($notes),'createdAt'=>$m['created_at'],'url'=>'/api/notebook?action=media_file&media_id='.(int)$m['id']];
    }return $items;
}
function bnMediaUpload(PDO $db,int $user,array $files): array {
    $names=(array)($files['name']??[]);$temps=(array)($files['tmp_name']??[]);$sizes=(array)($files['size']??[]);$errors=(array)($files['error']??[]);
    if(!$names||count($names)>20)bnFail('Tek seferde 1–20 dosya seç.',422);
    $valid=[];foreach($names as $i=>$name){if(($errors[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)bnFail('Dosya yüklenemedi. Sunucunun yükleme sınırını kontrol et.',413);
        if(($sizes[$i]??0)<1||$sizes[$i]>50*1024*1024)bnFail('Her dosya en fazla 50 MB olabilir.',413);
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=bnMimeType($temps[$i]);$allowed=['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
        if(!isset($allowed[$ext])||$allowed[$ext]!==$mime)bnFail('Yalnızca geçerli PDF, PNG, JPG veya WEBP dosyaları yüklenebilir.',422);
        $valid[]=['name'=>mb_substr(basename(str_replace('\\','/',$name)),0,255),'temp'=>$temps[$i],'size'=>$sizes[$i],'ext'=>$ext,'kind'=>$ext==='pdf'?'pdf':'image'];
    }
    $moved=[];$ids=[];$db->beginTransaction();try{
        foreach($valid as $f){$relative='uploads/notes/bn_media_'.$user.'_'.bin2hex(random_bytes(14)).'.'.$f['ext'];$absolute=__DIR__.'/../'.$relative;$dir=dirname($absolute);
            if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))bnFail('Yükleme klasörü oluşturulamadı.',500);
            if(!move_uploaded_file($f['temp'],$absolute))bnFail('Dosya kaydedilemedi. Disk alanını ve yükleme klasörünü kontrol et.',500);$moved[]=$absolute;
            $q=$db->prepare('INSERT INTO notebook_media(user_id,path_key,file_path,canonical_path,name,size_bytes,kind) VALUES(?,?,?,?,?,?,?)');$q->execute([$user,hash('sha256',$relative),$relative,$relative,$f['name'],$f['size'],$f['kind']]);$ids[]=(int)$db->lastInsertId();
        }$db->commit();return $ids;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();foreach($moved as $p)@unlink($p);throw $e;}
}
function bnMediaAttach(PDO $db,int $user,int $noteId,array $ids): array {
    foreach($ids as $id)if(!is_int($id)&&!(is_string($id)&&ctype_digit($id)))bnFail('Geçersiz dosya seçimi.',422);
    $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids);if(!$ids||count($ids)>20||min($ids)<1)bnFail('Eklemek için 1–20 dosya seç.',422);
    $newFiles=[];$db->beginTransaction();try{
        $q=$db->prepare('SELECT * FROM notes WHERE id=? AND user_id=? AND trashed_at IS NULL FOR UPDATE');$q->execute([$noteId,$user]);$note=$q->fetch(PDO::FETCH_ASSOC);if(!$note)bnFail('Not bulunamadı.',404);
        $sources=bnSources($db,$user,$note);$existing=array_column($sources,'path');$added=0;
        foreach($ids as $id){$q=$db->prepare('SELECT * FROM notebook_media WHERE id=? AND user_id=? AND trashed_at IS NULL FOR UPDATE');$q->execute([$id,$user]);$m=$q->fetch(PDO::FETCH_ASSOC);if(!$m)bnFail('Dosya bu hesaba ait değil.',403);
            if(!in_array($m['kind'],['pdf','image'],true))bnFail('Bu dosya türü kaynak olarak eklenemiyor.',422);
            $source=bnMediaPath($m);
            if(in_array($m['file_path'],$existing,true)||($m['canonical_path']&&in_array($m['canonical_path'],$existing,true)))continue;
            // One stable library copy protects reused legacy files from the old note's delete handler.
            $relative=$m['canonical_path'];if(!$relative){$ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));$relative='uploads/notes/bn_media_'.$user.'_'.bin2hex(random_bytes(14)).'.'.$ext;$absolute=__DIR__.'/../'.$relative;
                if(!@copy($source,$absolute))bnFail('Dosya kütüphaneye alınamadı. Disk alanını kontrol et.',500);$newFiles[]=$absolute;
                $q=$db->prepare('UPDATE notebook_media SET canonical_path=? WHERE id=? AND user_id=?');$q->execute([$relative,$id,$user]);
            }
            $q=$db->prepare('INSERT INTO notebook_sources(note_id,user_id,name,file_path,size_bytes) VALUES(?,?,?,?,?)');$q->execute([$noteId,$user,$m['name'],$relative,filesize($source)]);$existing[]=$relative;$added++;
        }
        if($added){$q=$db->prepare('UPDATE notes SET updated_at=NOW() WHERE id=? AND user_id=?');$q->execute([$noteId,$user]);}
        $db->commit();return ['note'=>bnNoteDTO($db,$user,$noteId),'added'=>$added];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();foreach($newFiles as $p)@unlink($p);throw $e;}
}
if(in_array($action,['media_attach','media_upload'],true)&&($_SERVER['REQUEST_METHOD']??'GET')!=='POST')bnFail('Bu işlem POST gerektirir.',405);
if($action==='media')$out=['items'=>bnMediaLibrary($db,$user)];
elseif($action==='media_upload'){$ids=bnMediaUpload($db,$user,$_FILES['files']??[]);$out=['ids'=>$ids,'items'=>bnMediaLibrary($db,$user)];}
elseif($action==='media_attach'){$ids=$input['media_ids']??null;if(!is_array($ids))bnFail('Geçersiz dosya seçimi.',422);$out=bnMediaAttach($db,$user,(int)($input['note_id']??0),$ids);}
elseif($action==='media_file'){
    $q=$db->prepare('SELECT * FROM notebook_media WHERE id=? AND user_id=? AND trashed_at IS NULL');$q->execute([(int)($_GET['media_id']??0),$user]);$m=$q->fetch(PDO::FETCH_ASSOC);if(!$m)bnFail('Dosya bulunamadı.',404);$path=bnMediaPath($m);$mime=bnMimeType($path);
    session_write_close();header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));header("Content-Disposition: inline; filename=\"document\"; filename*=UTF-8''".rawurlencode($m['name']));readfile($path);exit;
}
