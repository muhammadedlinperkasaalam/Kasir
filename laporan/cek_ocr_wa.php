<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$cfgFile = __DIR__ . '/../config/payment_ocr.php';
$cfg = file_exists($cfgFile) ? require $cfgFile : [];
$cfg = array_merge([
    'tesseract_bin' => 'tesseract',
    'tesseract_tessdata_dir' => '',
    'tesseract_lang' => 'eng',
    'wa_downloads_dir' => 'whatsapp/wa-engine/downloads',
], $cfg);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function norm($p){ return str_replace('\\', '/', (string)$p); }
function projectRoot(){ return realpath(__DIR__ . '/..'); }
function statusRow($label, $ok, $info, $tips=''){
    $badge = $ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">CEK</span>';
    echo '<tr><td class="fw-semibold">'.h($label).'</td><td>'.$badge.'</td><td><div>'.h($info).'</div>'.($tips?'<div class="text-muted small">'.h($tips).'</div>':'').'</td></tr>';
}
function runCmd($cmd){ return trim((string)@shell_exec($cmd)); }
function tesseractCmd($bin, $args, $tessdata=''){
    $cmd = escapeshellarg($bin) . ' ' . $args;
    $tessdata = trim((string)$tessdata);
    if ($tessdata !== '') {
        $tessdata = str_replace('\\', '/', $tessdata);
        if (stripos(PHP_OS, 'WIN') === 0) return 'set "TESSDATA_PREFIX=' . str_replace('"','',$tessdata) . '" && ' . $cmd;
        return 'TESSDATA_PREFIX=' . escapeshellarg($tessdata) . ' ' . $cmd;
    }
    return $cmd;
}

$root = projectRoot();
$tess = norm($cfg['tesseract_bin']);
$tessdata = norm($cfg['tesseract_tessdata_dir'] ?? '');
$waDir = $root . '/' . trim(norm($cfg['wa_downloads_dir']), '/');
$lang = $cfg['tesseract_lang'] ?? 'eng';
$version = function_exists('shell_exec') ? runCmd(tesseractCmd($tess, '--version', $tessdata) . ' 2>&1') : '';
$langs = function_exists('shell_exec') ? runCmd(tesseractCmd($tess, '--list-langs', $tessdata) . ' 2>&1') : '';
$phpIni = php_ini_loaded_file();
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cek OCR Bukti Pembayaran WA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f5f6fa}.wrap{max-width:1050px;margin:auto;padding:20px}.card{border:0;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.08)}pre{white-space:pre-wrap;background:#111827;color:#e5e7eb;border-radius:12px;padding:12px;font-size:12px}</style>
</head><body><div class="wrap">
<div class="d-flex justify-content-between align-items-center mb-3"><div><h3 class="mb-1">Cek OCR Bukti Pembayaran WA</h3><div class="text-muted">Gunakan halaman ini setelah install ulang Windows untuk cek Tesseract dan konfigurasi OCR.</div></div><a href="verifikasi_bukti_wa.php" class="btn btn-outline-primary">Kembali ke Verifikasi WA</a></div>
<div class="card"><div class="card-body">
<table class="table table-bordered align-middle">
<thead class="table-light"><tr><th style="width:230px">Komponen</th><th style="width:80px">Status</th><th>Detail</th></tr></thead><tbody>
<?php
statusRow('shell_exec PHP', function_exists('shell_exec'), function_exists('shell_exec') ? 'Aktif' : 'Tidak aktif', 'Kalau tidak aktif, cek disable_functions di php.ini. php.ini: ' . ($phpIni ?: '-'));
$tessExists = is_file($tess) || $tess === 'tesseract';
statusRow('Path Tesseract', $tessExists, $tess, 'Kalau belum ada, install Tesseract OCR lalu edit config/payment_ocr.php.');
statusRow('Tesseract bisa dipanggil', $version !== '' && stripos($version, 'not recognized') === false && stripos($version, 'bukan') === false && stripos($version, 'not found') === false, $version ?: 'Tidak ada output', 'Coba jalankan di CMD: "' . $tess . '" --version');
$tessdataOk = $tessdata === '' ? true : is_dir($tessdata);
statusRow('Folder tessdata', $tessdataOk, $tessdata ?: 'Kosong / mengikuti default Tesseract', 'Biasanya: C:/Program Files/Tesseract-OCR/tessdata');
$engOk = ($langs !== '' && stripos($langs, $lang) !== false) || ($tessdata && is_file($tessdata . '/' . $lang . '.traineddata'));
statusRow('Bahasa OCR: ' . $lang, $engOk, $langs ?: 'Tidak bisa membaca daftar bahasa', 'Pastikan ada file ' . $lang . '.traineddata di tessdata.');
statusRow('Folder download WA', is_dir($waDir), $waDir, 'Folder ini harus sesuai dengan lokasi file bukti WA yang didownload oleh gateway.');
statusRow('Folder temp writable', is_writable(sys_get_temp_dir()), sys_get_temp_dir(), 'Tesseract perlu menulis file sementara.');
?>
</tbody></table>
<h5>Konfigurasi aktif</h5>
<pre><?= h(print_r($cfg, true)) ?></pre>
<h5>Output Tesseract</h5>
<pre><?= h($version ?: 'Tidak ada output.') ?></pre>
<h5>Langkah cepat setelah install ulang Windows</h5>
<ol>
<li>Install <b>Tesseract OCR for Windows</b>.</li>
<li>Pastikan ada file <code>eng.traineddata</code> di folder <code>tessdata</code>.</li>
<li>Kalau status Path Tesseract belum OK, edit <code>config/payment_ocr.php</code>.</li>
<li>Buka ulang halaman Verifikasi Bukti WA, lalu klik <b>OCR / Baca Ulang</b>.</li>
</ol>
</div></div>
</div></body></html>
