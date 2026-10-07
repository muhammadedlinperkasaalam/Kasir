<?php
require_once __DIR__.'/_init.php';
PA_KasirBridge::ensureTables($pdo);
$title='Upload File Analyzer';
$profiles=$pdo->query("SELECT * FROM price_profiles WHERE is_active=1 ORDER BY paper_size,paper_type,name")->fetchAll(PDO::FETCH_ASSOC);

function pa_uploaded_files_array(string $field): array {
    if (empty($_FILES[$field])) return [];
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return [[
            'name'=>$f['name'], 'type'=>$f['type'] ?? '', 'tmp_name'=>$f['tmp_name'] ?? '',
            'error'=>$f['error'] ?? UPLOAD_ERR_NO_FILE, 'size'=>$f['size'] ?? 0,
        ]];
    }
    $out=[];
    foreach ($f['name'] as $i=>$name) {
        if ($name === '' && (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) continue;
        $out[]=[
            'name'=>$name, 'type'=>$f['type'][$i] ?? '', 'tmp_name'=>$f['tmp_name'][$i] ?? '',
            'error'=>$f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size'=>$f['size'][$i] ?? 0,
        ];
    }
    return $out;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    pa_verify_csrf();
    $createdJobs=[];
    $batchToken=bin2hex(random_bytes(16));
    try{
        pa_check_post_too_large();
        $profileId=(int)($_POST['price_profile_id']??0);
        $st=$pdo->prepare('SELECT * FROM price_profiles WHERE id=? AND is_active=1');
        $st->execute([$profileId]);
        $profile=$st->fetch(PDO::FETCH_ASSOC);
        if(!$profile) throw new RuntimeException('Pilih profil harga terlebih dahulu.');

        $files=pa_uploaded_files_array('documents');
        if(!$files) {
            // kompatibilitas jika browser/post lama masih memakai field document
            $files=pa_uploaded_files_array('document');
        }
        if(!$files) throw new RuntimeException('File belum dipilih.');
        if(count($files) > 20) throw new RuntimeException('Maksimal 20 file sekali upload.');

        $converter=new PA_DocumentConverter();
        $analyzer=new PA_ColorAnalyzer(PA_AnalyzerSettings::current($pdo));
        $pricing=new PA_PriceCalculator();
        $insertPage=$pdo->prepare('INSERT INTO print_job_pages (print_job_id,page_number,preview_filename,colored_pixel_percentage,nonwhite_pixel_percentage,detected_category,final_category,unit_price) VALUES (?,?,?,?,?,?,?,?)');

        foreach($files as $file){
            $jobId=0;
            try{
                if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException(pa_upload_error_message((int)($file['error']??UPLOAD_ERR_NO_FILE), (string)($file['name']??'file')));
                if((int)$file['size'] > ((int)pa_setting('max_upload_mb',80)*1024*1024)) throw new RuntimeException('Ukuran file '.($file['name']??'').' ('.pa_format_bytes((int)$file['size']).') melebihi batas aplikasi '.((int)pa_setting('max_upload_mb',80)).' MB. '.pa_upload_limit_text());
                $orig=basename((string)$file['name']);
                [$ext, $mime] = pa_validate_file_type($orig, (string)$file['tmp_name']);
                $stored=bin2hex(random_bytes(16)).'.'.$ext;
                $uploadPath=pa_storage_root().'/uploads/'.$stored;
                if(!move_uploaded_file((string)$file['tmp_name'],$uploadPath)) throw new RuntimeException('File gagal disimpan: '.$orig);

                $pdo->prepare("INSERT INTO print_jobs (user_id, price_profile_id, original_filename, stored_filename, mime_type, extension, status, batch_token) VALUES (?,?,?,?,?,?, 'processing', ?)")->execute([$_SESSION['user_id'],$profileId,$orig,$stored,$mime,$ext,$batchToken]);
                $jobId=(int)$pdo->lastInsertId();
                $createdJobs[]=$jobId;
                $jobDir=pa_storage_root().'/jobs/'.$jobId;
                $pages=$converter->convertToPages($uploadPath,$ext,$jobDir);
                $analysisRows=$analyzer->analyzeMany($pages);
                foreach($pages as $i=>$page){
                    $r=$analysisRows[$i] ?? $analyzer->analyze($page);
                    $cat=$r['category'];
                    $insertPage->execute([$jobId,$i+1,basename($page),$r['colored_pixel_percentage'],$r['nonwhite_pixel_percentage'],$cat,$cat,$pricing->unitPrice($profile,$cat)]);
                }
                $pricing->refreshJob($pdo,$jobId);
                $pdo->prepare("UPDATE print_jobs SET status='done' WHERE id=?")->execute([$jobId]);
            } catch(Throwable $e){
                if($jobId) $pdo->prepare("UPDATE print_jobs SET status='failed', error_message=? WHERE id=?")->execute([$e->getMessage(),$jobId]);
                throw $e;
            }
        }

        if(count($createdJobs) > 1){
            pa_flash('success',count($createdJobs).' file berhasil dianalisis. Periksa ringkasan gabungan, koreksi file bila perlu, lalu jadikan transaksi.');
            header('Location: batch.php?token='.$batchToken); exit;
        }
        pa_flash('success','File berhasil dianalisis. Koreksi halaman bila perlu, lalu jadikan transaksi.');
        header('Location: job.php?id='.$createdJobs[0]); exit;
    }catch(Throwable $e){
        pa_flash('error',$e->getMessage());
        header('Location: upload.php'); exit;
    }
}
require __DIR__.'/_header.php'; ?>
<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= e(pa_csrf()) ?>">
      <div class="mb-3">
        <label class="form-label fw-bold">File pelanggan</label>
        <input class="form-control" type="file" name="documents[]" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png" multiple required>
        <small class="text-muted">Bisa pilih lebih dari 1 file sekaligus. Batas per file: <?= (int)pa_setting('max_upload_mb',300) ?> MB. Batas efektif server: <?= e(pa_format_bytes(pa_effective_upload_limit_bytes())) ?>.</small>
      </div>
      <div class="mb-3">
        <label class="form-label fw-bold">Profil harga</label>
        <select class="form-select" name="price_profile_id" required>
          <option value="">Pilih profil</option>
          <?php foreach($profiles as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>"><?= e($pr['name']) ?> — BW <?= rupiah($pr['price_bw']) ?> / 100% <?= rupiah($pr['price_color_100']) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Satu upload multi-file memakai satu profil harga yang sama.</small>
      </div>
      <button class="btn btn-primary"><i class="fas fa-magic"></i> Analisis File</button>
    </form>
  </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>
