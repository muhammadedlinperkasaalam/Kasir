<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
error_reporting(E_ALL);
function paq_clean_output(): string { $out=''; while(ob_get_level()>0){ $out.=(string)ob_get_clean(); } return trim($out); }
function paq_json(array $arr, int $code=200): void { paq_clean_output(); if(!headers_sent()) header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($arr, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE); exit; }
register_shutdown_function(function(){
    $err=error_get_last();
    if($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)){
        paq_clean_output();
        if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Queue analyzer gagal karena error server: '.strip_tags((string)$err['message']),'detail'=>basename((string)$err['file']).':'.(int)$err['line']], JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
    }
});
require_once __DIR__.'/_init.php';
require_once __DIR__.'/app/BackgroundQueue.php';
try{
    if($_SERVER['REQUEST_METHOD'] !== 'POST') paq_json(['status'=>'error','message'=>'Method tidak valid.'],405);
    $res = PA_BackgroundQueue::createBatch($pdo);
    $res['status']='success';
    paq_json($res);
}catch(Throwable $e){
    paq_json(['status'=>'error','message'=>$e->getMessage()],400);
}
