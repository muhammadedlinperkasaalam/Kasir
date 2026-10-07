<?php
// Guard khusus AJAX Print Analyzer.
// Di komputer baru, warning/fatal PHP sering keluar sebagai HTML (<br><b>...).
// Guard ini menahan output HTML tersebut dan mengubahnya menjadi JSON agar JS tidak error "Unexpected token '<'".
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

function pa_clean_hidden_output(): string {
    $out = '';
    while (ob_get_level() > 0) {
        $out .= (string)ob_get_clean();
    }
    return trim($out);
}

function pa_json($arr, int $code=200): void {
    pa_clean_hidden_output();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($arr, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
    exit;
}

function pa_friendly_fatal_message(string $message): string {
    $msg = trim(strip_tags($message));
    if ($msg === '') return 'Terjadi error PHP saat analisis.';
    return $msg;
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $hidden = pa_clean_hidden_output();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Analisis gagal karena error server: ' . pa_friendly_fatal_message($err['message']),
            'detail' => basename((string)$err['file']) . ':' . (int)$err['line'] . ($hidden ? ' | Output: ' . pa_friendly_fatal_message($hidden) : ''),
            'hint' => 'Karena pindah komputer, cek config/print_analyzer.php: path Ghostscript, LibreOffice, ImageMagick, dan permission folder uploads/print_analyzer.',
        ], JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__.'/_init.php';
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function pa_uploaded_files_array_modal(string $field): array {
    if (empty($_FILES[$field])) return [];
    $f=$_FILES[$field];
    $out=[];
    if(!is_array($f['name'])){
        return [[
            'name'=>$f['name'] ?? '', 'type'=>$f['type'] ?? '', 'tmp_name'=>$f['tmp_name'] ?? '',
            'error'=>$f['error'] ?? UPLOAD_ERR_NO_FILE, 'size'=>$f['size'] ?? 0,
        ]];
    }
    foreach($f['name'] as $i=>$name){
        if($name==='' && (($f['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)) continue;
        $out[]=[
            'name'=>$name, 'type'=>$f['type'][$i] ?? '', 'tmp_name'=>$f['tmp_name'][$i] ?? '',
            'error'=>$f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size'=>$f['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function pa_wa_download_root_modal(): string {
    return pa_project_root() . DIRECTORY_SEPARATOR . 'whatsapp' . DIRECTORY_SEPARATOR . 'wa-engine' . DIRECTORY_SEPARATOR . 'downloads';
}
function pa_original_name_from_wa_modal(string $fileName): string {
    $base = basename($fileName);
    $parts = explode('_', $base);
    // Format engine: WA_nomor_timestamp_nama_asli.ext
    if (count($parts) >= 4 && strtoupper((string)$parts[0]) === 'WA') {
        return implode('_', array_slice($parts, 3));
    }
    return $base;
}
function pa_existing_wa_files_array_modal(): array {
    $raw = $_POST['wa_files'] ?? ($_POST['wa_file'] ?? []);
    if (is_string($raw) && $raw !== '') $raw = [$raw];
    if (!is_array($raw)) return [];
    $root = realpath(pa_wa_download_root_modal());
    if (!$root) return [];
    $out = [];
    foreach ($raw as $name) {
        $safe = basename((string)$name);
        if ($safe === '') continue;
        $path = realpath($root . DIRECTORY_SEPARATOR . $safe);
        if (!$path || strpos($path, $root) !== 0 || !is_file($path)) continue;
        $out[] = [
            'name' => pa_original_name_from_wa_modal($safe),
            'stored_source_name' => $safe,
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'source' => 'whatsapp',
        ];
    }
    return $out;
}
function pa_process_source_file_modal(PDO $pdo, array $source, array $profile, int $profileId, string $batchToken, PA_DocumentConverter $converter, PA_ColorAnalyzer $analyzer, PA_PriceCalculator $pricing, PDOStatement $insertPage): int {
    $orig = basename((string)($source['name'] ?? 'file'));
    $sourcePath = (string)($source['path'] ?? '');
    if ($sourcePath === '' || !is_file($sourcePath)) throw new RuntimeException('File sumber tidak ditemukan: '.$orig);
    if((int)($source['size'] ?? 0) > ((int)pa_setting('max_upload_mb',80)*1024*1024)) throw new RuntimeException('Ukuran file '.$orig.' melebihi batas.');

    $fallbackExt = !empty($source['stored_source_name']) ? strtolower(pathinfo((string)$source['stored_source_name'], PATHINFO_EXTENSION)) : '';
    [$ext, $mime] = pa_validate_file_type($orig, $sourcePath, $fallbackExt);

    $stored=bin2hex(random_bytes(16)).'.'.$ext;
    $uploadPath=pa_storage_root().'/uploads/'.$stored;
    if (!copy($sourcePath, $uploadPath)) throw new RuntimeException('File gagal disalin ke analyzer: '.$orig);

    $pdo->prepare("INSERT INTO print_jobs (user_id, price_profile_id, original_filename, stored_filename, mime_type, extension, status, batch_token) VALUES (?,?,?,?,?,?, 'processing', ?)")->execute([$_SESSION['user_id'],$profileId,$orig,$stored,$mime,$ext,$batchToken]);
    $jobId=(int)$pdo->lastInsertId();
    $jobDir=pa_storage_root().'/jobs/'.$jobId;
    $pages=$converter->convertToPages($uploadPath,$ext,$jobDir);
    foreach($pages as $i=>$page){
        $r=$analyzer->analyze($page);
        $cat=$r['category'];
        $insertPage->execute([$jobId,$i+1,basename($page),$r['colored_pixel_percentage'],$r['nonwhite_pixel_percentage'],$cat,$cat,$pricing->unitPrice($profile,$cat)]);
    }
    $pricing->refreshJob($pdo,$jobId);
    $pdo->prepare("UPDATE print_jobs SET status='done' WHERE id=?")->execute([$jobId]);
    return $jobId;
}

function pa_cat_label_modal(string $cat): string {
    return match($cat){
        'bw'=>'Hitam Putih', 'color_25'=>'Warna 25%', 'color_50'=>'Warna 50%', 'color_75'=>'Warna 75%', 'color_100'=>'Warna 100%', default=>$cat
    };
}
function pa_profile_barang_key_modal(string $cat): string {
    return match($cat){
        'bw'=>'kode_barang_bw', 'color_25'=>'kode_barang_color_25', 'color_50'=>'kode_barang_color_50', 'color_75'=>'kode_barang_color_75', default=>'kode_barang_color_100'
    };
}

try{
    if($_SERVER['REQUEST_METHOD'] !== 'POST') pa_json(['status'=>'error','message'=>'Method tidak valid.'],405);
    PA_KasirBridge::ensureTables($pdo);

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

    $st=$pdo->prepare('SELECT * FROM price_profiles WHERE id=? AND is_active=1');
    $st->execute([$profileId]);
    $profile=$st->fetch(PDO::FETCH_ASSOC);
    if(!$profile) throw new RuntimeException('Pilih profil harga terlebih dahulu.');
    if (($profile['print_mode'] ?? 'color') === 'grayscale') $printGrayscale = true;

    $cats=['bw','color_25','color_50','color_75','color_100'];
    $requiredCats = $printGrayscale ? ['bw'] : $cats;
    foreach($requiredCats as $cat){
        $kode=trim((string)($profile[pa_profile_barang_key_modal($cat)] ?? ''));
        if($kode==='') throw new RuntimeException('Profil harga belum lengkap. Mapping barang untuk '.pa_cat_label_modal($cat).' masih kosong.');
    }

    $uploadFiles=pa_uploaded_files_array_modal('documents');
    $waFiles=pa_existing_wa_files_array_modal();
    $sources=[];

    foreach($uploadFiles as $file){
        if(($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('File '.($file['name']??'').' gagal upload.');
        if(empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('File upload tidak valid: '.($file['name']??''));
        $sources[]=[
            'name'=>$file['name'] ?? 'file',
            'path'=>$file['tmp_name'],
            'size'=>$file['size'] ?? 0,
            'source'=>'upload',
        ];
    }
    foreach($waFiles as $wf){ $sources[]=$wf; }

    if(!$sources) throw new RuntimeException('Belum ada file yang dipilih.');
    if(count($sources)>20) throw new RuntimeException('Maksimal 20 file dalam sekali analisis.');

    $converter=new PA_DocumentConverter();
    $analyzer=new PA_ColorAnalyzer(PA_AnalyzerSettings::current($pdo));
    $pricing=new PA_PriceCalculator();
    $insertPage=$pdo->prepare('INSERT INTO print_job_pages (print_job_id,page_number,preview_filename,colored_pixel_percentage,nonwhite_pixel_percentage,detected_category,final_category,unit_price) VALUES (?,?,?,?,?,?,?,?)');

    $batchToken=bin2hex(random_bytes(16));

    foreach($sources as $source){
        $orig=basename((string)($source['name'] ?? 'file'));
        $sourcePath=(string)($source['path'] ?? '');
        if($sourcePath==='' || !is_file($sourcePath)) throw new RuntimeException('File sumber tidak ditemukan: '.$orig);
        if((int)($source['size'] ?? 0) > ((int)pa_setting('max_upload_mb',80)*1024*1024)) throw new RuntimeException('Ukuran file '.$orig.' melebihi batas.');

        $fallbackExt = !empty($source['stored_source_name']) ? strtolower(pathinfo((string)$source['stored_source_name'], PATHINFO_EXTENSION)) : '';
        [$ext, $mime] = pa_validate_file_type($orig, $sourcePath, $fallbackExt);

        $stored=bin2hex(random_bytes(16)).'.'.$ext;
        $uploadPath=pa_storage_root().'/uploads/'.$stored;
        if(!copy($sourcePath,$uploadPath)) throw new RuntimeException('File gagal disalin ke analyzer: '.$orig);

        $pdo->prepare("INSERT INTO print_jobs (user_id, price_profile_id, original_filename, stored_filename, mime_type, extension, status, batch_token) VALUES (?,?,?,?,?,?, 'processing', ?)")->execute([$_SESSION['user_id'],$profileId,$orig,$stored,$mime,$ext,$batchToken]);
        $jobId=(int)$pdo->lastInsertId();
        $jobDir=pa_storage_root().'/jobs/'.$jobId;
        $pages=$converter->convertToPages($uploadPath,$ext,$jobDir);
        $analysisRows=method_exists($analyzer,'analyzeMany') ? $analyzer->analyzeMany($pages) : [];
        foreach($pages as $i=>$page){
            $r=$analysisRows[$i] ?? $analyzer->analyze($page);
            $cat=$r['category'];
            $insertPage->execute([$jobId,$i+1,basename($page),$r['colored_pixel_percentage'],$r['nonwhite_pixel_percentage'],$cat,$cat,$pricing->unitPrice($profile,$cat)]);
        }
        $pricing->refreshJob($pdo,$jobId);
        $pdo->prepare("UPDATE print_jobs SET status='done' WHERE id=?")->execute([$jobId]);
    }

    $result=PA_ModalResultBuilder::build($pdo,$batchToken,$qtyCetak,$finishing,$halaman,$pagesPerSheet,$borderless,$orientation,$printGrayscale);
    $result['status']='success';
    $result['message']='Analisis selesai.';
    pa_json($result);
}catch(Throwable $e){
    $hidden = pa_clean_hidden_output();
    $message = $e->getMessage();
    if ($hidden !== '') {
        $message .= ' | Output PHP: ' . pa_friendly_fatal_message($hidden);
    }
    pa_json([
        'status'=>'error',
        'message'=>$message,
        'detail'=>basename($e->getFile()).':'.$e->getLine(),
        'hint'=>'Cek komputer baru: config/print_analyzer.php harus sesuai lokasi Ghostscript, LibreOffice, ImageMagick. Pastikan folder uploads/print_analyzer bisa ditulis.',
    ],400);
}
