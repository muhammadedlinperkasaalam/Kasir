<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit('Akses ditolak.'); }
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function root_path($rel = '') {
    return realpath(__DIR__ . '/../..') . ($rel ? DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR) : '');
}

function order_file_disk_path($path_file) {
    $path = trim(str_replace('\\', '/', (string)$path_file));
    if ($path === '') return '';
    if (strpos($path, 'uploads/') === 0) return root_path($path);
    return root_path('uploads/orders/' . ltrim($path, '/'));
}

function order_file_public_url($path_file) {
    $path = trim(str_replace('\\', '/', (string)$path_file));
    if ($path === '') return '';
    if (preg_match('~^(https?://|/)~i', $path)) return $path;
    if (strpos($path, 'uploads/') === 0) return '../../' . ltrim($path, '/');
    return '../../uploads/orders/' . ltrim($path, '/');
}

function read_print_config() {
    $cfg = [];
    $file = __DIR__ . '/../../config/print_analyzer.php';
    if (is_file($file)) {
        $tmp = include $file;
        if (is_array($tmp)) $cfg = $tmp;
    }
    return $cfg;
}

function find_bin($cfg, $keys, $fallbacks = []) {
    foreach ((array)$keys as $key) {
        if (!empty($cfg[$key]) && is_file($cfg[$key])) return $cfg[$key];
    }
    foreach ($fallbacks as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function clean_page_list($halaman) {
    $halaman = trim((string)$halaman);
    if ($halaman === '' || preg_match('/^(all|semua|semua halaman)$/i', $halaman)) return '';
    $parts = preg_split('/\s*,\s*/', $halaman);
    $valid = [];
    foreach ($parts as $part) {
        if (preg_match('/^\d+$/', $part)) $valid[] = $part;
        elseif (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m) && (int)$m[1] <= (int)$m[2]) $valid[] = $m[1] . '-' . $m[2];
    }
    return implode(',', $valid);
}

function run_cmd($cmd) {
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function convert_office_to_pdf($src, $cacheDir, $cfg) {
    $soffice = find_bin($cfg, ['soffice_bin', 'libreoffice_bin'], [
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
    ]);
    if (!$soffice) return [false, 'LibreOffice/soffice belum disetting.'];

    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $base = pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    $outPdf = $cacheDir . DIRECTORY_SEPARATOR . $base;
    if (is_file($outPdf) && filemtime($outPdf) >= filemtime($src)) return [$outPdf, ''];

    $cmd = escapeshellarg($soffice) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($cacheDir) . ' ' . escapeshellarg($src);
    [$code, $out] = run_cmd($cmd);
    if ($code !== 0 || !is_file($outPdf)) return [false, 'Konversi PDF gagal: ' . $out];
    return [$outPdf, ''];
}

function create_pdf_subset($srcPdf, $pageList, $cacheDir, $cfg) {
    if (!$pageList) return [$srcPdf, ''];
    $gs = find_bin($cfg, ['ghostscript_bin', 'gs_bin'], [
        'C:\\Program Files\\gs\\gs10.05.1\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.04.0\\bin\\gswin64c.exe',
        '/usr/bin/gs',
    ]);
    if (!$gs) return [$srcPdf, 'Ghostscript belum disetting; halaman khusus belum bisa dipotong otomatis.'];

    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $name = pathinfo($srcPdf, PATHINFO_FILENAME) . '_pages_' . preg_replace('/[^0-9,-]/', '_', $pageList) . '.pdf';
    $outPdf = $cacheDir . DIRECTORY_SEPARATOR . $name;
    if (is_file($outPdf) && filemtime($outPdf) >= filemtime($srcPdf)) return [$outPdf, ''];

    $cmd = escapeshellarg($gs)
        . ' -q -dBATCH -dNOPAUSE -sDEVICE=pdfwrite'
        . ' -sPageList=' . escapeshellarg($pageList)
        . ' -sOutputFile=' . escapeshellarg($outPdf)
        . ' ' . escapeshellarg($srcPdf);
    [$code, $out] = run_cmd($cmd);
    if ($code !== 0 || !is_file($outPdf)) return [$srcPdf, 'Subset halaman gagal; file penuh dibuka.'];
    return [$outPdf, ''];
}

function disk_to_public_url($diskPath) {
    $root = str_replace('\\', '/', root_path());
    $path = str_replace('\\', '/', $diskPath);
    if (strpos($path, $root) === 0) {
        return '../../' . ltrim(substr($path, strlen($root)), '/');
    }
    return '';
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT ofi.*, p.no_penjualan, pl.nama_pelanggan
                       FROM order_files ofi
                       LEFT JOIN penjualan p ON ofi.no_penjualan = p.no_penjualan
                       LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                       WHERE ofi.id = ?");
$stmt->execute([$id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) { http_response_code(404); exit('File order tidak ditemukan.'); }

$src = order_file_disk_path($file['path_file']);
$downloadUrl = order_file_public_url($file['path_file']);
if (!$src || !is_file($src)) { http_response_code(404); exit('File fisik tidak ditemukan: ' . h($file['path_file'])); }

$cfg = read_print_config();
$cacheDir = root_path('uploads/orders/print_cache');
$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
$printDisk = $src;
$warn = '';

if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
    [$converted, $err] = convert_office_to_pdf($src, $cacheDir, $cfg);
    if ($converted) { $printDisk = $converted; $ext = 'pdf'; }
    else { $warn = $err ?: 'File Office belum bisa dicetak langsung.'; }
}

if ($ext === 'pdf') {
    $pages = clean_page_list($file['halaman'] ?? '');
    [$subset, $subsetWarn] = create_pdf_subset($printDisk, $pages, $cacheDir, $cfg);
    $printDisk = $subset;
    if ($subsetWarn) $warn = trim($warn . ' ' . $subsetWarn);
}

$printUrl = disk_to_public_url($printDisk);
if (!$printUrl) $printUrl = $downloadUrl;
$isImage = in_array(strtolower(pathinfo($printDisk, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true);
$isPdf = strtolower(pathinfo($printDisk, PATHINFO_EXTENSION)) === 'pdf';
$qty = max(1, (int)($file['qty_cetak'] ?? 1));
$finishing = trim((string)($file['finishing'] ?? '1 sisi'));
$halaman = trim((string)($file['halaman'] ?? ''));
if ($halaman === '' || preg_match('/^(all)$/i', $halaman)) $halaman = 'semua halaman';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cetak File - <?= h($file['nama_file']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body{background:#f3f4f6;font-family:Arial,sans-serif;margin:0;}
    .toolbar{position:sticky;top:0;z-index:10;background:#111827;color:white;padding:10px 14px;box-shadow:0 2px 8px rgba(0,0,0,.2)}
    .toolbar small{color:#d1d5db}.doc-wrap{height:calc(100vh - 86px);background:#525659;display:flex;align-items:stretch;justify-content:center}.doc-frame{width:100%;height:100%;border:0;background:white}.img-wrap{min-height:calc(100vh - 86px);display:flex;align-items:center;justify-content:center;padding:20px;background:#525659}.img-wrap img{max-width:100%;max-height:calc(100vh - 130px);background:white}.warn{font-size:12px;color:#fde68a}
    @media print{.toolbar{display:none!important}body{background:white}.doc-wrap,.img-wrap{height:auto;min-height:auto;background:white;padding:0}.img-wrap img{max-width:100%;max-height:none}.doc-frame{height:100vh}}
</style>
</head>
<body>
<div class="toolbar">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="fw-bold">Cetak: <?= h($file['nama_file']) ?></div>
            <small>
                Nota <?= h($file['no_penjualan']) ?><?= !empty($file['nama_pelanggan']) ? ' • ' . h($file['nama_pelanggan']) : '' ?>
                • <?= h($file['jenis_kertas'] ?? '-') ?> <?= h($file['ukuran_kertas'] ?? '-') ?>
                • Qty <?= $qty ?>
                • <?= h($finishing ?: '-') ?>
                • Hal: <?= h($halaman) ?>
            </small>
            <?php if ($warn): ?><div class="warn">Catatan: <?= h($warn) ?></div><?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" onclick="window.print()">Cetak Lagi</button>
            <a class="btn btn-sm btn-outline-light" href="<?= h($downloadUrl) ?>" download>Download</a>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="window.close()">Tutup</button>
        </div>
    </div>
</div>
<?php if ($isPdf): ?>
<div class="doc-wrap"><iframe class="doc-frame" id="printFrame" src="<?= h($printUrl) ?>#toolbar=1&navpanes=0"></iframe></div>
<script>
setTimeout(function(){
    try { document.getElementById('printFrame').contentWindow.focus(); document.getElementById('printFrame').contentWindow.print(); }
    catch(e) { window.print(); }
}, 900);
</script>
<?php elseif ($isImage): ?>
<div class="img-wrap"><img id="printImg" src="<?= h($printUrl) ?>" alt="<?= h($file['nama_file']) ?>"></div>
<script>
document.getElementById('printImg').onload=function(){setTimeout(function(){window.print();},400)};
</script>
<?php else: ?>
<div class="container py-5 text-center">
    <div class="card shadow-sm mx-auto" style="max-width:520px"><div class="card-body p-4">
        <h5 class="fw-bold mb-2">File tidak bisa dicetak langsung dari browser</h5>
        <p class="text-muted">Silakan gunakan tombol download cadangan untuk membuka file ini di aplikasi aslinya.</p>
        <a class="btn btn-primary" href="<?= h($downloadUrl) ?>" download>Download File</a>
    </div></div>
</div>
<?php endif; ?>
</body>
</html>
