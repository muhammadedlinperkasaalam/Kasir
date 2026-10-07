<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
error_reporting(E_ALL);

require_once __DIR__.'/_init.php';

function pa_reprice_clean_output(): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
}
function pa_json($arr, int $code=200): void {
    pa_reprice_clean_output();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($arr, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    if($_SERVER['REQUEST_METHOD'] !== 'POST') pa_json(['status'=>'error','message'=>'Method tidak valid.'],405);
    PA_KasirBridge::ensureTables($pdo);
    if (class_exists('PA_BackgroundQueue')) {
        try { PA_BackgroundQueue::ensureTables($pdo); } catch (Throwable $e) {}
    }

    $batchToken=trim((string)($_POST['batch_token'] ?? ''));
    $profileId=(int)($_POST['price_profile_id'] ?? 0);
    $qtyCetak=max(1,(int)($_POST['qty_cetak'] ?? 1));
    $pagesPerSheet=(int)($_POST['pages_per_sheet'] ?? 1);
    if(!in_array($pagesPerSheet,[1,2,4,6,9,16],true)) $pagesPerSheet=1;
    $finishing=trim((string)($_POST['finishing'] ?? ''));
    $halaman=trim((string)($_POST['halaman'] ?? ''));
    $borderless = !empty($_POST['borderless']) && (string)$_POST['borderless'] !== '0';
    $printGrayscale = !empty($_POST['print_grayscale']) && (string)$_POST['print_grayscale'] !== '0';
    $orientation = strtolower(trim((string)($_POST['orientation'] ?? 'auto')));
    if (!in_array($orientation, ['auto','portrait','landscape'], true)) $orientation = 'auto';

    if($batchToken === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $batchToken)) {
        throw new RuntimeException('Token hasil analyzer tidak valid.');
    }

    $st=$pdo->prepare('SELECT * FROM price_profiles WHERE id=? AND is_active=1 LIMIT 1');
    $st->execute([$profileId]);
    $profile=$st->fetch(PDO::FETCH_ASSOC);
    if(!$profile) throw new RuntimeException('Profil harga tidak ditemukan atau tidak aktif.');
    if (($profile['print_mode'] ?? 'color') === 'grayscale') $printGrayscale = true;

    // Jika batch berasal dari background queue, pastikan analyzer memang sudah selesai.
    // Ini mencegah error saat operator mengubah halaman/page sheet sebelum job print_jobs terbentuk.
    $batchStatus = null;
    try {
        $bst = $pdo->prepare('SELECT status FROM print_analyzer_batches WHERE batch_token=? LIMIT 1');
        $bst->execute([$batchToken]);
        $batchStatus = $bst->fetchColumn();
    } catch (Throwable $e) { $batchStatus = null; }

    if ($batchStatus !== false && $batchStatus !== null && $batchStatus !== 'done') {
        throw new RuntimeException('Analisis masih diproses. Tunggu progress selesai dulu, lalu ubah halaman/qty kembali.');
    }

    // Realtime ganti profile: jangan batasi user_id, karena batch cache/WA/background bisa dibuat oleh user_id 0/sistem.
    // Yang penting batch_token valid dan print_jobs-nya ada.
    $upd=$pdo->prepare('UPDATE print_jobs SET price_profile_id=? WHERE batch_token=?');
    $upd->execute([$profileId,$batchToken]);

    $chk=$pdo->prepare('SELECT COUNT(*) FROM print_jobs WHERE batch_token=?');
    $chk->execute([$batchToken]);
    if((int)$chk->fetchColumn() <= 0) {
        if ($batchStatus === 'done') {
            throw new RuntimeException('Data hasil analyzer belum terbentuk. Klik Analisis ulang sekali.');
        }
        throw new RuntimeException('Data batch analyzer tidak ditemukan. Klik Analisis ulang sekali.');
    }

    $result=PA_ModalResultBuilder::build($pdo,$batchToken,$qtyCetak,$finishing,$halaman,$pagesPerSheet,$borderless,$orientation,$printGrayscale);
    $result['status']='success';
    $result['message']='Harga dihitung ulang tanpa analisis file ulang.';
    $result['reprice_only']=1;
    pa_json($result);
}catch(Throwable $e){
    pa_json(['status'=>'error','message'=>$e->getMessage()],400);
}
