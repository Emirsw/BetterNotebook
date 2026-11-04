<?php
@ini_set('display_errors','0');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/notebook_store.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
class BNNoteServiceException extends RuntimeException {}
try {
    if(empty($_SESSION['user_id']))bnFail('Oturum açmanız gerekiyor.',401);
    $db=getDBConnection();$user=(int)$_SESSION['user_id'];
    $input=$_POST?:json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=[];
    $action=$input['action']??$_GET['action']??'bootstrap';
    if($_SERVER['REQUEST_METHOD']!=='GET'&&!validateCSRFToken($_SERVER['HTTP_X_CSRF_TOKEN']??$input['csrf']??''))bnFail('Güvenlik doğrulaması başarısız. Sayfayı yenileyin.',403);
    if(in_array($action,['annotation_generate','ai'],true)){$bnPDFRequestId=bin2hex(random_bytes(6));header('X-BetterNotebook-Request: '.$bnPDFRequestId);}
    $out=[];
    if(in_array($action,['annotation_get','annotation_generate','annotation_save'],true)){require __DIR__.'/../includes/notebook_pdf_notes.php';}
    elseif(in_array($action,['media','media_upload','media_attach','media_file'],true)){require __DIR__.'/../includes/notebook_media.php';}
    elseif($action==='bootstrap'){$out=bnBootstrap($db,$user,getCurrentUser());}
    elseif($action==='file'){
        $note=bnOwned($db,$user,(int)($_GET['note_id']??0));$selected=null;
        foreach(bnSources($db,$user,$note) as $source)if($source['id']===($_GET['source_id']??''))$selected=$source;
        if(!$selected)bnFail('Kaynak bulunamadı.',404);$path=bnFilePath($selected['path']);
        $mime=bnMimeType($path);
        session_write_close();header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));
        header("Content-Disposition: inline; filename=\"document.pdf\"; filename*=UTF-8''".rawurlencode($selected['name']));readfile($path);exit;
    }
    elseif($_SERVER['REQUEST_METHOD']!=='POST')bnFail('Bu işlem POST gerektirir.',405);
    elseif(in_array($action,['trash_list','trash_move','trash_restore','trash_empty'],true)){
        require_once __DIR__.'/../includes/notebook_trash.php';
        if ($action === 'trash_list') {
            $out = ['items' => bnTrashList($db, $user), 'total' => bnTrashCount($db, $user)];
        } elseif ($action === 'trash_empty') {
            if (($input['confirm'] ?? '') !== 'empty-trash') {
                bnFail('Çöp kutusunu boşaltmak için onay gerekiyor.', 422);
            }
            $out = ['deleted' => bnTrashEmpty($db, $user)];
        } else {
            $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            $restoring = $action === 'trash_restore';
            if (!$id || !bnTrashChange($db, $user, $kind, $id, $restoring)) {
                bnFail('Öğe bulunamadı veya geri yükleme süresi doldu.', 404);
            }
            $out = ['changed' => true];
        }
    }
    elseif($action==='create'){
        $title=mb_substr(trim($input['title']??'Yeni kişisel not'),0,200);if(!$title)$title='Yeni kişisel not';$course=bnCourse($db,$user,trim($input['course']??''));
        $q=$db->prepare('INSERT INTO notes(user_id,title,content,course_id,is_pinned,is_ai,tags) VALUES(?,?,?, ?,0,0,?)');$q->execute([$user,$title,'',$course,!empty($input['source_only'])?'BetterNotebook:document':'BetterNotebook:notebook']);$out=['note'=>bnNoteDTO($db,$user,(int)$db->lastInsertId())];
    }
    elseif($action==='save'){
        $id=(int)($input['note_id']??0);bnOwned($db,$user,$id);$title=mb_substr(trim($input['title']??''),0,200);if(!$title)bnFail('Not başlığı boş bırakılamaz.');
        $content=(string)($input['content']??'');if(strlen($content)>4*1024*1024)bnFail('Not metni çok uzun.',413);
        $course=bnCourse($db,$user,trim($input['course']??''));$q=$db->prepare('UPDATE notes SET title=?,content=?,course_id=COALESCE(?, (SELECT id FROM courses WHERE id=notes.course_id AND user_id=notes.user_id AND trashed_at IS NOT NULL)),updated_at=NOW() WHERE id=? AND user_id=? AND trashed_at IS NULL');$q->execute([$title,$content,$course,$id,$user]);$out=['note'=>bnNoteDTO($db,$user,$id)];
    }
    elseif($action==='pin'){
        $id=(int)($input['note_id']??0);bnOwned($db,$user,$id);$q=$db->prepare('UPDATE notes SET is_pinned=? WHERE id=? AND user_id=? AND trashed_at IS NULL');$q->execute([!empty($input['pinned'])?1:0,$id,$user]);$out=['note'=>bnNoteDTO($db,$user,$id)];
    }
    elseif($action==='open'){
        $id=(int)($input['note_id']??0);bnOwned($db,$user,$id);$q=$db->prepare('INSERT INTO notebook_activity(user_id,note_id,opened_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE opened_at=NOW()');$q->execute([$user,$id]);
    }
    elseif($action==='clear_qa'){
        $id=(int)($input['note_id']??0);bnOwned($db,$user,$id);$q=$db->prepare("UPDATE notes SET qa_history='[]' WHERE id=? AND user_id=?");$q->execute([$id,$user]);
    }
    elseif($action==='course'){
        $name=mb_substr(preg_replace('/\s+/u',' ',trim($input['name']??'')),0,150);if(!$name)bnFail('Bir ders adı yaz.');
        $q=$db->prepare('SELECT id FROM courses WHERE user_id=? AND course_name=? AND trashed_at IS NULL');$q->execute([$user,$name]);$id=$q->fetchColumn();
        if(!$id){$q=$db->prepare('INSERT INTO courses(user_id,course_code,course_name) VALUES(?,?,?)');$q->execute([$user,'BN-'.strtoupper(bin2hex(random_bytes(3))),$name]);$id=$db->lastInsertId();}$out=['course'=>['id'=>(int)$id,'name'=>$name]];
    }
    elseif($action==='preferences'){
        $prefs=$input['preferences']??[];$json=json_encode($prefs,JSON_UNESCAPED_UNICODE);if(strlen($json)>16000)bnFail('Tercih verisi çok uzun.');
        // Store UI and timer state only. No document text or credentials.
        $allowed=array_intersect_key($prefs,array_flip(['widths','order','minimized','noteId','readerReturnView','qaFont','timer']));
        $q=$db->prepare('INSERT INTO notebook_preferences(user_id,preferences) VALUES(?,?) ON DUPLICATE KEY UPDATE preferences=VALUES(preferences)');$q->execute([$user,json_encode($allowed,JSON_UNESCAPED_UNICODE)]);
    }
    elseif($action==='import_pdf'){require __DIR__.'/../includes/notebook_pdf_import.php';}
    elseif($action==='upload'){
        $id=(int)($input['note_id']??0);bnOwned($db,$user,$id);$f=$_FILES['files']??null;if(!$f)bnFail('PDF dosyası seç.');
        $names=(array)$f['name'];$sizes=(array)$f['size'];$errors=(array)$f['error'];$temps=(array)$f['tmp_name'];if(count($names)>20)bnFail('Tek seferde en fazla 20 PDF ekleyebilirsin.');
        foreach($names as $i=>$name){if(($errors[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)bnFail('Dosya yüklenemedi; PHP yükleme sınırını ve dosya boyutunu kontrol et.',413);
            if(($sizes[$i]??0)>50*1024*1024)bnFail('Bir PDF en fazla 50 MB olabilir.',413);
            $header=file_get_contents($temps[$i],false,null,0,1024);if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf'||strpos($header,'%PDF-')===false)bnFail('Yalnızca geçerli PDF dosyaları eklenebilir.');}
        $moved=[];$db->beginTransaction();try{
            foreach($names as $i=>$name){$relative='uploads/notes/bn_'.$user.'_'.bin2hex(random_bytes(14)).'.pdf';$path=__DIR__.'/../'.$relative;
                if(!move_uploaded_file($temps[$i],$path))bnFail('Dosya kaydedilemedi. Yükleme klasörü izinlerini kontrol et.',500);$moved[]=$path;
                $q=$db->prepare('INSERT INTO notebook_sources(note_id,user_id,name,file_path,size_bytes) VALUES(?,?,?,?,?)');$q->execute([$id,$user,mb_substr(basename(str_replace('\\','/',$name)),0,255),$relative,$sizes[$i]]);}
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();foreach($moved as $file)@unlink($file);throw $e;}$out=['note'=>bnNoteDTO($db,$user,$id)];
    }
    elseif($action==='models'){
        $account=getCurrentUser();$key=trim((string)($input['key']??''))?:(bnAccountGeminiKey($account)??'');
        if(!$key)bnFail('Modelleri görmek için API anahtarı ekle.',422);
        if(strlen($key)>512)bnFail('API anahtarı çok uzun.',422);
        session_write_close();require_once __DIR__.'/../includes/notebook_models.php';$out=['models'=>bnModelCatalog($key)];
    }
    elseif($action==='test_connection'){
        $account=getCurrentUser();$key=trim($input['key']??'')?:(bnAccountGeminiKey($account)??'');if(!$key)bnFail('Önce Gemini API anahtarını ekle.',422);
        if(!function_exists('curl_init'))bnFail('PHP cURL eklentisi etkin değil.',500);
        session_write_close();$ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['x-goog-api-key: '.$key],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $response=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode($response?:'{}',true);
        if($error)bnFail('Google bağlantısı kurulamadı. Sunucunun TLS ve ağ ayarlarını kontrol et.',502);
        if($code!==200)bnFail(str_replace($key,'[redacted]',$data['error']['message']??'Gemini anahtarı doğrulanamadı.'),422);
        $out=['message'=>'Google Gemini bağlantısı doğrulandı. Aktif kullanım limitlerini AI Studio’dan görebilirsin.'];
    }
    elseif($action==='language'){
        $language=$input['language']??'';if(!in_array($language,['tr','en'],true))bnFail('Geçersiz dil seçimi.',422);
        $q=$db->prepare('SELECT id FROM settings WHERE user_id=? LIMIT 1');$q->execute([$user]);
        if(!$q->fetchColumn()){$q=$db->prepare('INSERT INTO settings(user_id,ui_language) VALUES(?,?)');$q->execute([$user,$language]);}
        else{$q=$db->prepare('UPDATE settings SET ui_language=? WHERE user_id=?');$q->execute([$language,$user]);}
        setcookie('bn_language',$language,['expires'=>time()+31536000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);$out=['language'=>$language];
    }
    elseif($action==='settings'){
        $model=trim($input['model']??'');if(!preg_match('/^gemini-[a-z0-9.\-]{1,80}$/i',$model))bnFail('Geçerli bir Gemini modeli seç.');
        $q=$db->prepare('SELECT id FROM settings WHERE user_id=? ORDER BY id LIMIT 1');$q->execute([$user]);$settingId=$q->fetchColumn();
        if(!$settingId){$q=$db->prepare('INSERT INTO settings(user_id,gemini_model) VALUES(?,?)');$q->execute([$user,$model]);$settingId=$db->lastInsertId();}
        $key=trim((string)($input['key']??''));if(strlen($key)>512)bnFail('API anahtarı çok uzun.',422);if($key!==''){$q=$db->prepare('UPDATE settings SET gemini_api_key=?,gemini_model=? WHERE id=?');$q->execute([bnEncryptGeminiKey($key,$user),$model,$settingId]);}
        else{$q=$db->prepare('UPDATE settings SET gemini_model=? WHERE id=?');$q->execute([$model,$settingId]);}
        $out=['keyConfigured'=>!empty(getCurrentUser()['gemini_api_key'])];
    }
    elseif($action==='study'){
        $r=$input['session']??[];if(count($r)!==6)bnFail('Geçersiz oturum.');[$client,$course,$seconds,$start,$end,$status]=$r;
        $seconds=(int)$seconds;$start=(int)$start;$end=(int)$end;
        if(!is_string($client)||strlen($client)>80||$seconds<1||$seconds>21600||$end<$start||$end>$start+86400000||!in_array($status,['manual','partial','completed'],true))bnFail('Geçersiz çalışma süresi.');
        $q=$db->prepare('SELECT id FROM notebook_study_sessions WHERE user_id=? AND client_id=?');$q->execute([$user,$client]);if(!$q->fetchColumn()){
            $courseId=null;$q=$db->prepare('SELECT id FROM courses WHERE user_id=? AND course_name=? AND trashed_at IS NULL LIMIT 1');$q->execute([$user,$course]);$courseId=$q->fetchColumn()?:null;
            $db->beginTransaction();try{$q=$db->prepare('INSERT INTO study_sessions(user_id,course_id,duration_minutes,session_date,notes) VALUES(?,?,?,?,?)');$q->execute([$user,$courseId,(int)ceil($seconds/60),date('Y-m-d',(int)floor($end/1000)),'BetterNotebook · '.$status]);$legacy=(int)$db->lastInsertId();
                $q=$db->prepare('INSERT INTO notebook_study_sessions(user_id,legacy_id,client_id,course_name,duration_seconds,started_at,ended_at,status) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$user,$legacy,$client,mb_substr((string)$course,0,150),$seconds,$start,$end,$status]);$db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    }
    elseif($action==='add_answer'){
        require_once __DIR__.'/../includes/notebook_answer.php';
        $out=bnAddAnswerToNote($db,$user,(int)($input['note_id']??0),(string)($input['answer_id']??''));
    }
    elseif($action==='apply_summary'){
        require_once __DIR__.'/../includes/notebook_summary.php';
        $out=bnApplySummary($db,$user,(int)($input['note_id']??0),(int)($input['summary_id']??0));
    }
    elseif($action==='ai'){
        $id=(int)($input['note_id']??0);$note=bnOwned($db,$user,$id);$account=getCurrentUser();$savedKey=bnAccountGeminiKey($account);if(!$savedKey)bnFail('Gemini API anahtarını Ayarlar & AI sayfasından kaydet.',422);
        $kind=$input['kind']??'question';$scope=$input['scope']??'note';$wanted=array_values(array_unique(array_map('strval',$input['source_ids']??[])));$all=bnSources($db,$user,$note);$selected=[];
        foreach($wanted as $sourceId){$found=null;foreach($all as $s)if($s['id']===$sourceId)$found=$s;if(!$found)bnFail('Seçilen kaynak bu nota ait değil.',403);$selected[]=$found;}
        if($kind==='summary'&&$scope==='note')$selected=$all;
        require_once __DIR__.'/../includes/notebook_capture.php';$capture=bnCaptureAttachment($input['capture']??null,$all);if($capture&&$kind!=='question')bnFail('Ekran alıntısı yalnızca soru için kullanılabilir.',422);
        $attachments=$capture?[$capture['attachment']]:[];$bytes=$capture?strlen(base64_decode($capture['attachment']['data'])):0;foreach($selected as $s){$path=bnFilePath($s['path']);$bytes+=filesize($path);if($bytes>12*1024*1024)bnFail('Seçilen kaynaklar doğrudan AI isteği için çok büyük. Daha küçük bir PDF bölümü seç; uzun PDF kuyruğu bu ilk prototipte henüz açık değil.',413);$mime=bnMimeType($path);if(!in_array($mime,['application/pdf','image/png','image/jpeg','image/webp'],true))bnFail('Bu kaynak türü AI için desteklenmiyor.');$attachments[]=['mime_type'=>$mime,'data'=>base64_encode(file_get_contents($path))];}
        $question=trim($input['question']??'');$includeNote=($kind==='summary'&&$scope==='note')||($kind==='question'&&!$wanted&&!$capture);$content=$includeNote?$note['content']:'';
        if($kind==='question'&&$question==='')bnFail('Bir soru yaz.');if(!$attachments&&trim(strip_tags($content))==='')bnFail('Önce not yaz veya bir kaynak seç.');
        require_once __DIR__.'/../includes/notebook_prompts.php';
        $prompt='Not başlığı: '.$note['title']."\n".($includeNote?"NOT METNİ:\n".$content."\n\n":'').($kind==='summary'?bnSummaryTask():"KULLANICI SORUSU:\n".$question);
        if($capture)$prompt.="\nEKRAN ALINTISI: ".$capture['source']['name'].' · sayfa '.$capture['page'].'. PNG, kullanıcının bu sayfadan seçtiği alandır; tam belge gönderilmiş olduğunu varsayma.';
        // Release session lock: note saving and navigation remain available during the AI call.
        session_write_close();require_once __DIR__.'/../includes/gemini_helper.php';$ai=new GeminiAI($savedKey,$account['gemini_model']);
        $result=$ai->generateResponse($prompt,bnTeachingInstruction(bnLanguage($db,$user)),$attachments);
        if(empty($result['success']))throw new BNNoteServiceException(str_replace($savedKey,'[redacted]',$result['error']??'AI işlemi tamamlanamadı.'));$text=$result['text'];
        $publicSources=array_map(fn($s)=>['id'=>$s['id'],'name'=>$s['name']],$selected);if($capture)$publicSources[]=$capture['source'];
        if($kind==='summary'){require_once __DIR__.'/../includes/notebook_summary.php';$out=bnApplySummary($db,$user,$id,null,['scope'=>$scope,'sources'=>$publicSources,'text'=>$text]);}
        else{
            $db->beginTransaction();try{
                $q=$db->prepare('SELECT qa_history FROM notes WHERE id=? AND user_id=? AND trashed_at IS NULL FOR UPDATE');$q->execute([$id,$user]);$current=$q->fetch(PDO::FETCH_ASSOC);if(!$current)bnFail('Not bulunamadı.',404);
                $history=bnQAHistory($current['qa_history']);$answer=['id'=>'answer-'.bin2hex(random_bytes(16)),'question'=>$question,'answer'=>$text,'sources'=>$publicSources,'created_at'=>date('Y-m-d H:i:s'),'addedToNote'=>false];$history[]=$answer;$history=array_slice($history,-100);
                $q=$db->prepare('UPDATE notes SET qa_history=? WHERE id=? AND user_id=?');$q->execute([json_encode($history,JSON_UNESCAPED_UNICODE),$id,$user]);$db->commit();$out=['response'=>$text,'answer'=>$answer,'qaHistory'=>$history];
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }
    }
    else bnFail('Bilinmeyen işlem.',404);
    echo json_encode(['success'=>true]+$out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    $reference=$bnPDFRequestId??null;
    // A successful HTTP transport can still carry success:false. Avoid hosting
    // replacing an expected provider failure with its generic HTML 502 document.
    if($e instanceof BNPDFServiceException||$e instanceof BNNoteServiceException){http_response_code(200);}
    elseif(http_response_code()<400)http_response_code(500);
    error_log('BetterNotebook'.($reference?' AI ['.$reference.']':'').': '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'İşlem tamamlanamadı. Sunucu kaydını kontrol edin.','reference'=>$reference],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
