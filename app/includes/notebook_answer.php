<?php
function bnAddAnswerToNote(PDO $db,int $user,int $noteId,string $answerId): array {
    if(!preg_match('/^answer-[a-z0-9-]{16,64}$/D',$answerId))bnFail('Geçersiz yanıt seçimi.',422);
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT content,qa_history FROM notes WHERE id=? AND user_id=? AND trashed_at IS NULL FOR UPDATE');$q->execute([$noteId,$user]);$note=$q->fetch(PDO::FETCH_ASSOC);if(!$note)bnFail('Not bulunamadı.',404);
        $history=bnQAHistory($note['qa_history']);$found=null;foreach($history as $index=>$row)if($row['id']===$answerId){$found=$index;break;}if($found===null)bnFail('Yanıt bulunamadı.',404);
        $already=!empty($history[$found]['addedToNote']);$section='## '.(bnLanguage($db,$user)==='en'?'AI Answer':'AI Yanıtı')."\n\n".$history[$found]['answer'];
        if(!$already){$body=(string)$note['content'];$combined=$body===''?$section:$body."\n\n---\n\n".$section;if(strlen($combined)>4*1024*1024)bnFail('Not metni çok uzun.',413);
            $history[$found]['addedToNote']=true;$history[$found]['added_at']=date('Y-m-d H:i:s');
            $q=$db->prepare('UPDATE notes SET content=?,qa_history=?,updated_at=NOW() WHERE id=? AND user_id=?');$q->execute([$combined,json_encode($history,JSON_UNESCAPED_UNICODE),$noteId,$user]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['note'=>bnNoteDTO($db,$user,$noteId),'qaHistory'=>$history,'alreadyApplied'=>$already,'alreadyAdded'=>$already,'appendSection'=>$section];
}
