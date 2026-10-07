<?php
require_once __DIR__.'/_init.php';
header('Content-Type: application/json; charset=utf-8');
function pa_json($arr, int $code=200): void { http_response_code($code); echo json_encode($arr, JSON_INVALID_UTF8_IGNORE); exit; }
try{
    if($_SERVER['REQUEST_METHOD'] !== 'POST') pa_json(['status'=>'error','message'=>'Method tidak valid.'],405);
    PA_KasirBridge::ensureTables($pdo);

    $jobId=(int)($_POST['job_id'] ?? 0);
    $cat=(string)($_POST['category'] ?? '');
    $qtyCetak=max(1,(int)($_POST['qty_cetak'] ?? 1));
    $pagesPerSheet=(int)($_POST['pages_per_sheet'] ?? 1);
    if(!in_array($pagesPerSheet,[1,2,4,6,9,16],true)) $pagesPerSheet=1;
    $finishing=trim((string)($_POST['finishing'] ?? ''));
    $halaman=trim((string)($_POST['halaman'] ?? ''));
    $borderless = !empty($_POST['borderless']) && (string)$_POST['borderless'] !== '0';
    $printGrayscale = !empty($_POST['print_grayscale']) && (string)$_POST['print_grayscale'] !== '0';
    $orientation = strtolower(trim((string)($_POST['orientation'] ?? 'auto')));
    if (!in_array($orientation, ['auto','portrait','landscape'], true)) $orientation = 'auto';
    if(!array_key_exists($cat,pa_setting('categories',[]))) throw new RuntimeException('Kategori tidak valid.');

    // Mode baru: koreksi per sheet. Frontend mengirim semua page_ids yang ada di sheet tersebut.
    $pageIds=[];
    $rawPageIds=trim((string)($_POST['page_ids'] ?? ''));
    if($rawPageIds !== ''){
        foreach(explode(',', $rawPageIds) as $pid){
            $pid=(int)trim($pid);
            if($pid>0) $pageIds[]=$pid;
        }
        $pageIds=array_values(array_unique($pageIds));
    }

    // Fallback kompatibilitas lama: koreksi satu halaman.
    if(!$pageIds){
        $pageId=(int)($_POST['page_id'] ?? 0);
        if($pageId>0) $pageIds=[$pageId];
    }
    if($jobId<=0 || !$pageIds) throw new RuntimeException('Data halaman/sheet tidak valid.');

    $st=$pdo->prepare('SELECT j.batch_token, pp.* FROM print_jobs j JOIN price_profiles pp ON pp.id=j.price_profile_id WHERE j.id=? LIMIT 1');
    $st->execute([$jobId]);
    $profile=$st->fetch(PDO::FETCH_ASSOC);
    if(!$profile) throw new RuntimeException('Job analyzer tidak ditemukan.');
    if (($profile['print_mode'] ?? 'color') === 'grayscale') $printGrayscale = true;

    $in=implode(',', array_fill(0,count($pageIds),'?'));
    $pageStmt=$pdo->prepare("SELECT id, colored_pixel_percentage FROM print_job_pages WHERE print_job_id=? AND id IN ($in)");
    $pageStmt->execute(array_merge([$jobId], $pageIds));
    $pages=$pageStmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($pages)!==count($pageIds)) throw new RuntimeException('Sebagian halaman di sheet tidak ditemukan.');

    $pricing=new PA_PriceCalculator();
    $unit=$pricing->unitPrice($profile,$cat);

    // Jangan memakai transaksi manual di sini.
    // PA_AnalyzerSettings::learnFromCorrection() bisa melakukan operasi DDL/ensure table
    // pada beberapa instalasi MySQL/MariaDB, yang dapat mengakhiri transaksi secara implisit
    // dan memicu error: "There is no active transaction" saat commit/rollback.
    $upd=$pdo->prepare('UPDATE print_job_pages SET final_category=?, overridden=1, unit_price=? WHERE id=? AND print_job_id=?');
    foreach($pages as $p){
        $upd->execute([$cat,$unit,(int)$p['id'],$jobId]);
        PA_AnalyzerSettings::learnFromCorrection($pdo,(float)$p['colored_pixel_percentage'],$cat);
    }

    $pricing->refreshJob($pdo,$jobId);

    $result=PA_ModalResultBuilder::build($pdo,(string)$profile['batch_token'],$qtyCetak,$finishing,$halaman,$pagesPerSheet,$borderless,$orientation,$printGrayscale);
    $result['status']='success';
    $result['message']=count($pages)>1 ? 'Kategori sheet disimpan.' : 'Kategori halaman disimpan.';
    pa_json($result);
}catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    pa_json(['status'=>'error','message'=>$e->getMessage()],400);
}
