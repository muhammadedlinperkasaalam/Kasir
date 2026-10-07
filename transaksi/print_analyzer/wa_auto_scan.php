<?php
require_once __DIR__.'/wa_auto_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function pa_wa_json($arr, int $code=200): void { http_response_code($code); echo json_encode($arr, JSON_INVALID_UTF8_IGNORE); exit; }
try{
    $limit=max(1,min(50,(int)($_GET['limit'] ?? $_POST['limit'] ?? 10)));
    $age=max(1,min(30,(int)($_GET['age_days'] ?? $_POST['age_days'] ?? 7)));
    $scan=PA_WhatsAppAutoAnalyzer::scanDownloads($pdo,$limit,$age);
    $processed=[];
    if(!empty($_GET['process']) || !empty($_POST['process'])){
        $steps=max(1,min(5,(int)($_GET['steps'] ?? $_POST['steps'] ?? 1)));
        for($i=0;$i<$steps;$i++) $processed[]=PA_WhatsAppAutoAnalyzer::processOne($pdo);
    }
    pa_wa_json(['status'=>'success','scan'=>$scan,'processed'=>$processed]);
}catch(Throwable $e){ pa_wa_json(['status'=>'error','message'=>$e->getMessage()],400); }
