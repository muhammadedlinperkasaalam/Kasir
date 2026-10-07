<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
error_reporting(E_ALL);
function paq_clean_output(): string { $out=''; while(ob_get_level()>0){ $out.=(string)ob_get_clean(); } return trim($out); }
function paq_json(array $arr, int $code=200): void { paq_clean_output(); if(!headers_sent()) header('Content-Type: application/json; charset=utf-8'); http_response_code($code); echo json_encode($arr, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE); exit; }
require_once __DIR__.'/_init.php';
require_once __DIR__.'/app/BackgroundQueue.php';
try{
    $batchToken=trim((string)($_REQUEST['batch_token'] ?? ''));
    if($batchToken === '' || !preg_match('/^[a-f0-9]{16,64}$/i',$batchToken)) throw new RuntimeException('Token queue tidak valid.');
    $include = !empty($_REQUEST['include_result']);
    $res=PA_BackgroundQueue::status($pdo,$batchToken,$include);
    paq_json($res);
}catch(Throwable $e){
    paq_json(['status'=>'error','message'=>$e->getMessage()],400);
}
