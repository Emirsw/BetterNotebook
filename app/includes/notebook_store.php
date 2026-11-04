<?php
/** BetterNotebook: additive storage, compatible with existing notes and sessions. */
function bnFail(string $message, int $status=400): void { http_response_code($status); throw new RuntimeException($message); }
function bnOwned(PDO $db, int $user, int $id): array {
    $q=$db->prepare('SELECT n.*, c.course_name FROM notes n LEFT JOIN courses c ON c.id=n.course_id AND c.trashed_at IS NULL WHERE n.id=? AND n.user_id=? AND n.trashed_at IS NULL');
    $q->execute([$id,$user]); $n=$q->fetch(); if(!$n)bnFail('Not bulunamadı.',404); return $n;
}
function bnCourse(PDO $db,int $user,string $name): ?int {
    if($name==='')return null;
    $q=$db->prepare('SELECT id FROM courses WHERE user_id=? AND course_name=? AND trashed_at IS NULL ORDER BY id LIMIT 1');$q->execute([$user,$name]);
    $id=$q->fetchColumn();if(!$id)bnFail('Ders bu hesaba ait değil.',422);return (int)$id;
}
function bnSources(PDO $db,int $user,array $note): array {
    $sources=[];
    if(!empty($note['file_path'])){$legacy=realpath(__DIR__.'/../'.$note['file_path']);$sources[]=['id'=>'legacy-'.$note['id'],'name'=>$note['file_name']?:basename($note['file_path']),'path'=>$note['file_path'],'size'=>$legacy&&is_file($legacy)?filesize($legacy):0,'type'=>$note['file_type']?:'pdf'];}
    $q=$db->prepare('SELECT id,name,file_path,size_bytes FROM notebook_sources WHERE note_id=? AND user_id=? ORDER BY id');$q->execute([$note['id'],$user]);
    foreach($q as $r)$sources[]=['id'=>'source-'.$r['id'],'name'=>$r['name'],'path'=>$r['file_path'],'size'=>(int)$r['size_bytes'],'type'=>in_array(strtolower(pathinfo($r['file_path'],PATHINFO_EXTENSION)),['png','jpg','jpeg','webp'],true)?'image':'pdf'];
    foreach($sources as &$s){$s['url']='api/notebook?action=file&note_id='.(int)$note['id'].'&source_id='.rawurlencode($s['id']);}
    return $sources;
}
function bnFilePath(string $path): string {
    $root=realpath(__DIR__.'/../uploads/notes');$file=realpath(__DIR__.'/../'.$path);
    if(!$root||!$file||strpos($file,$root.DIRECTORY_SEPARATOR)!==0||!is_file($file))bnFail('Dosya bulunamadı.',404);
    return $file;
}
/** Identify known document signatures when shared hosting has no fileinfo. */
function bnMimeType(string $path): string {
    if(class_exists('finfo')&&defined('FILEINFO_MIME_TYPE')){
        try{$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(is_string($mime)&&$mime!==''&&$mime!=='application/octet-stream')return $mime;}
        catch(Throwable $ignored){}
    }
    $header=@file_get_contents($path,false,null,0,32);
    if(!is_string($header))bnFail('Dosya okunamadı.',404);
    if(strncmp($header,'%PDF-',5)===0)return 'application/pdf';
    if(strncmp($header,"\x89PNG\r\n\x1a\n",8)===0)return 'image/png';
    if(strncmp($header,"\xff\xd8\xff",3)===0)return 'image/jpeg';
    if(strlen($header)>=12&&substr($header,0,4)==='RIFF'&&substr($header,8,4)==='WEBP')return 'image/webp';
    return 'application/octet-stream';
}
function bnQAHistory($json): array {
    $rows=is_array($json)?$json:(json_decode($json??'[]',true)?:[]);$result=[];
    foreach($rows as $index=>$row){if(!is_array($row)||!isset($row['answer'])||!is_string($row['answer']))continue;
        if(empty($row['id'])||!is_string($row['id'])||!preg_match('/^answer-[a-z0-9-]{16,64}$/D',$row['id']))$row['id']='answer-'.substr(hash('sha256',$index."\0".($row['question']??'')."\0".$row['answer']."\0".($row['created_at']??'')),0,24);
        $row['addedToNote']=!empty($row['addedToNote']);$result[]=$row;
    }return $result;
}
function bnDTO(PDO $db,int $user,array $r): array {
    $sources=bnSources($db,$user,$r);foreach($sources as &$s)unset($s['path']);unset($s);
    $q=$db->prepare('SELECT id,scope,sources_json,content,created_at,applied_at FROM notebook_summaries WHERE user_id=? AND note_id=? ORDER BY id DESC LIMIT 1');$q->execute([$user,$r['id']]);$summary=$q->fetch();
    $content=(string)$r['content'];$text=html_entity_decode(strip_tags($content),ENT_QUOTES,'UTF-8');$text=preg_replace('/\s+/u',' ',$text);
    $type=$r['file_type']??'';$kind=$sources?($type==='image'?'Görsel':($type==='pptx'?'PPTX':'PDF')):'Not';
    return ['id'=>(int)$r['id'],'title'=>$r['title'],'body'=>$content,'content'=>$content,'course'=>$r['course_name']??'',
        'courseId'=>$r['course_id']?(int)$r['course_id']:null,'kind'=>$kind,'pages'=>0,'pinned'=>(bool)$r['is_pinned'],
        'custom'=>!(bool)($r['is_ai']??0),'sourceOnly'=>$sources&&trim($content)===''&&($r['tags']??'')!=='BetterNotebook:notebook',
        'summaryPlaceholder'=>!trim($text),'summary'=>mb_substr($text?:($sources?count($sources).' kaynak · Not al veya AI ile çalış.':'Düşüncelerini buraya yaz.'),0,150),
        'edited'=>formatTurkishDate($r['updated_at'],true),'updatedAt'=>$r['updated_at'],'attachments'=>$sources,
        'qaHistory'=>bnQAHistory($r['qa_history']??'[]'),
        'lastSummary'=>$summary?['id'=>(int)$summary['id'],'scope'=>$summary['scope'],'sources'=>json_decode($summary['sources_json'],true)?:[],'text'=>$summary['content'],'applied'=>!empty($summary['applied_at']),'createdAt'=>$summary['created_at']]:null];
}
function bnNoteDTO(PDO $db,int $user,int $id): array {return bnDTO($db,$user,bnOwned($db,$user,$id));}
function bnLanguage(PDO $db,int $user): string {$q=$db->prepare('SELECT ui_language FROM settings WHERE user_id=? ORDER BY id LIMIT 1');$q->execute([$user]);return $q->fetchColumn()==='en'?'en':'tr';}
function bnBootstrap(PDO $db,int $user,array $currentUser): array {
    $q=$db->prepare('SELECT n.*, c.course_name FROM notes n LEFT JOIN courses c ON c.id=n.course_id AND c.trashed_at IS NULL WHERE n.user_id=? AND n.trashed_at IS NULL ORDER BY n.is_pinned DESC,n.updated_at DESC,n.id DESC');$q->execute([$user]);$notes=[];
    foreach($q->fetchAll() as $r)$notes[]=bnDTO($db,$user,$r);
    $q=$db->prepare('SELECT id,course_name FROM courses WHERE user_id=? AND trashed_at IS NULL ORDER BY course_name');$q->execute([$user]);$courses=$q->fetchAll();
    $q=$db->prepare('SELECT a.note_id FROM notebook_activity a JOIN notes n ON n.id=a.note_id AND n.user_id=a.user_id WHERE a.user_id=? AND n.trashed_at IS NULL ORDER BY a.opened_at DESC,a.note_id DESC LIMIT 12');$q->execute([$user]);$recent=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $q=$db->prepare('SELECT preferences FROM notebook_preferences WHERE user_id=?');$q->execute([$user]);$prefs=json_decode($q->fetchColumn()?:'{}',true)?:[];
    $q=$db->prepare("SELECT s.*,COALESCE(c.course_name,d.course_name,'Serbest çalışma') AS course_name,d.client_id,d.duration_seconds,d.started_at,d.ended_at,d.status FROM study_sessions s LEFT JOIN courses c ON c.id=s.course_id LEFT JOIN notebook_study_sessions d ON d.legacy_id=s.id AND d.user_id=s.user_id WHERE s.user_id=? ORDER BY s.session_date DESC,s.id DESC LIMIT 5000");$q->execute([$user]);$sessions=[];
    foreach($q as $r){$end=(int)(strtotime($r['created_at'])*1000);$seconds=(int)($r['duration_seconds']??((int)$r['duration_minutes']*60));$sessions[]=[$r['client_id']??'legacy-'.$r['id'],$r['course_name']?:'Serbest çalışma',$seconds,(int)($r['started_at']??($end-$seconds*1000)),(int)($r['ended_at']??$end),$r['status']??'completed'];}
    return ['notes'=>$notes,'courses'=>array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['course_name']],$courses),'recentNoteIds'=>$recent,
        'language'=>bnLanguage($db,$user),'preferences'=>$prefs,'sessions'=>$sessions,'user'=>['id'=>$user,'name'=>$currentUser['full_name']?:$currentUser['username'],'username'=>$currentUser['username']],
        'settings'=>['model'=>$currentUser['gemini_model']?:'gemini-2.5-flash','keyConfigured'=>!empty($currentUser['gemini_api_key'])],
        'csrf'=>generateCSRFToken()];
}
