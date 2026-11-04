<?php
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/notebook_store.php';
$db=getDBConnection();$bootstrap=bnBootstrap($db,(int)$_SESSION['user_id'],$currentUser);
$bootstrap['view']=$notebookView??'notes';$bootstrap['noteId']=(int)($_GET['note']??0);
$bootstrap['version']=APP_VERSION;
// The final numeric ID keeps links unique; the title is descriptive and may change.
$noteQuery=rawurldecode($_SERVER['QUERY_STRING']??'');
if(!$bootstrap['noteId']&&preg_match('/^[a-z0-9-]+-([1-9][0-9]*)$/D',$noteQuery,$match))$bootstrap['noteId']=(int)$match[1];
if($bootstrap['view']==='notes'&&$bootstrap['noteId']){
    $linkedNote=null;foreach($bootstrap['notes'] as $candidate)if($candidate['id']===$bootstrap['noteId']){$linkedNote=$candidate;break;}
    if(!$linkedNote){http_response_code(404);echo 'Not found';return;}
    $slug=strtr($linkedNote['title'],['ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ğ'=>'g','Ğ'=>'g','ü'=>'u','Ü'=>'u','ö'=>'o','Ö'=>'o','ç'=>'c','Ç'=>'c']);
    $slug=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($slug)),'-');$slug=trim(substr($slug,0,90),'-')?:'note';
    $canonical='/notes?'.$slug.'-'.$linkedNote['id'];
    if(($_SERVER['REQUEST_URI']??'')!==$canonical&&!isset($_GET['legacy'])){header('Location: '.$canonical,true,302);return;}
}

?>
<!doctype html>
<html lang="<?= e($bootstrap['language']) ?>"><head><base href="/"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#09090b"><title>BetterNotebook</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg?v=1">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png?v=1">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/img/apple-touch-icon.png?v=1">
<link rel="stylesheet" href="assets/css/notebook.css?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/css/notebook.css'),0,16) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.22/dist/katex.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/marked@15.0.12/marked.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/dompurify@3.4.17/dist/purify.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.22/dist/katex.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.22/dist/contrib/auto-render.min.js"></script>
<script>window.BetterNotebookBootstrap=<?= json_encode($bootstrap,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<script defer src="assets/js/i18n.js?v=<?= filemtime(__DIR__.'/../assets/js/i18n.js') ?>"></script>
<script defer src="assets/vendor/notebook-editor.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/vendor/notebook-editor.js'),0,16) ?>"></script>
<script defer src="assets/js/notebook.js?v=<?= substr(hash_file('sha256',__DIR__.'/../assets/js/notebook.js'),0,16) ?>"></script>
</head><body><div id="bn-preview"><div data-app="workspace"></div></div></body></html>
