<?php
/** Append once, using the latest saved note content, and keep summary history. */
function bnApplySummary(PDO $db,int $user,int $noteId,?int $summaryId=null,?array $generated=null): array {
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT content FROM notes WHERE id=? AND user_id=? AND trashed_at IS NULL FOR UPDATE');$q->execute([$noteId,$user]);$note=$q->fetch(PDO::FETCH_ASSOC);if(!$note)bnFail('Not bulunamadı.',404);
        if($generated){$q=$db->prepare('INSERT INTO notebook_summaries(note_id,user_id,scope,sources_json,content) VALUES(?,?,?,?,?)');$q->execute([$noteId,$user,mb_substr((string)$generated['scope'],0,100),json_encode($generated['sources'],JSON_UNESCAPED_UNICODE),$generated['text']]);$summaryId=(int)$db->lastInsertId();}
        $q=$db->prepare('SELECT id,scope,sources_json,content,created_at,applied_at FROM notebook_summaries WHERE id=? AND note_id=? AND user_id=? FOR UPDATE');$q->execute([$summaryId,$noteId,$user]);$summary=$q->fetch(PDO::FETCH_ASSOC);if(!$summary)bnFail('Özet bulunamadı.',404);
        $alreadyApplied=!empty($summary['applied_at']);$section='## '.(bnLanguage($db,$user)==='en'?'AI Summary':'AI Özeti')."\n\n".$summary['content'];
        if(!$alreadyApplied){$body=(string)$note['content'];$combined=$body===''?$section:$body."\n\n---\n\n".$section;if(strlen($combined)>4*1024*1024)bnFail('Not metni çok uzun.',413);
            $q=$db->prepare('UPDATE notes SET content=?,updated_at=NOW() WHERE id=? AND user_id=?');$q->execute([$combined,$noteId,$user]);
            $q=$db->prepare('UPDATE notebook_summaries SET applied_at=NOW() WHERE id=? AND user_id=?');$q->execute([$summaryId,$user]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['summary'=>['id'=>(int)$summary['id'],'scope'=>$summary['scope'],'text'=>$summary['content'],'sources'=>json_decode($summary['sources_json'],true)?:[],'createdAt'=>$summary['created_at'],'applied'=>true,'section'=>$section],'note'=>bnNoteDTO($db,$user,$noteId),'alreadyApplied'=>$alreadyApplied];
}
