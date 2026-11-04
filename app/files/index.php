<?php
require_once __DIR__.'/../config/config.php';
if(empty($_SESSION['user_id'])||!getCurrentUser()){header('Location: /login',true,302);exit;}
$notebookView='files';
require __DIR__.'/../includes/notebook_app.php';
