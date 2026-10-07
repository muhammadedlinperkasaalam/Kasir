<?php
require_once __DIR__.'/wa_auto_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function pa_wa_json($arr, int $code=200): void { http_response_code($code); echo json_encode($arr, JSON_INVALID_UTF8_IGNORE); exit; }
try{
    $steps=max(1,min(5,(int)($_GET['steps'] ?? $_POST['steps'] ?? 1)));
    $out=[];
    for($i=0;$i<$steps;$i++) $out[]=PA_WhatsAppAutoAnalyzer::processOne($pdo);
    pa_wa_json(['status'=>'success','processed'=>$out]);
}catch(Throwable $e){ pa_wa_json(['status'=>'error','message'=>$e->getMessage()],400); }
