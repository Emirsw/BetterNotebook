<?php
function bnPageLanguage(): string {
    if(!empty($_SESSION['user_id'])){require_once __DIR__.'/notebook_store.php';$db=getDBConnection();return bnLanguage($db,(int)$_SESSION['user_id']);}
    return ($_COOKIE['bn_language']??'tr')==='en'?'en':'tr';
}
function bnPageText(string $text,string $language): string {
    static $catalog=null;if($language!=='en')return $text;
    if($catalog===null)$catalog=json_decode(file_get_contents(__DIR__.'/../assets/i18n/en.json'),true)?:[];
    return $catalog[$text]??$text;
}
