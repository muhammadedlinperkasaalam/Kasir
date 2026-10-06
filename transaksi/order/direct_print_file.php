<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'Akses ditolak.']); exit; }
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

function jp($status, $message, $extra = []) { echo json_encode(array_merge(['status'=>$status,'message'=>$message], $extra)); exit; }
function root_path($rel = '') { return realpath(__DIR__ . '/../..') . ($rel ? DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel), DIRECTORY_SEPARATOR) : ''); }
function clean_rel_path($path) { return trim(str_replace('\\', '/', (string)$path)); }
function order_file_disk_path($path_file) {
    $path = clean_rel_path($path_file);
    if ($path === '') return '';
    if (strpos($path, 'uploads/') === 0) return root_path($path);
    return root_path('uploads/orders/' . ltrim($path, '/'));
}
function run_cmd($cmd) { $out=[]; $code=0; @exec($cmd . ' 2>&1', $out, $code); return [$code, implode("\n", $out)]; }
function read_cfg($file, $default = []) { if (is_file($file)) { $tmp = include $file; if (is_array($tmp)) return array_merge($default, $tmp); } return $default; }
function find_bin($cfg, $keys, $fallbacks = []) { foreach ((array)$keys as $key) { if (!empty($cfg[$key]) && is_file($cfg[$key])) return $cfg[$key]; } foreach ($fallbacks as $path) { if (is_file($path)) return $path; } return ''; }
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
function convert_office_to_pdf($src, $cacheDir, $cfg) {
    $soffice = find_bin($cfg, ['soffice_bin','libreoffice_bin'], [
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        '/usr/bin/soffice','/usr/bin/libreoffice'
    ]);
    if (!$soffice) return [false, 'LibreOffice/soffice belum disetting.'];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $outPdf = $cacheDir . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    if (is_file($outPdf) && filemtime($outPdf) >= filemtime($src)) return [$outPdf, ''];
    $cmd = escapeshellarg($soffice) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($cacheDir) . ' ' . escapeshellarg($src);
    [$code, $out] = run_cmd($cmd);
    if ($code !== 0 || !is_file($outPdf)) return [false, 'Konversi PDF gagal: ' . $out];
    return [$outPdf, ''];
}
function convert_image_to_pdf($src, $cacheDir, $cfg) {
    $magick = find_bin($cfg, ['imagemagick_bin','magick_bin'], [
        'C:\\Program Files\\ImageMagick-7.1.1-Q16-HDRI\\magick.exe',
        'C:\\Program Files\\ImageMagick-7.1.1-Q16\\magick.exe',
        '/usr/bin/magick','/usr/bin/convert'
    ]);
    if (!$magick) return [$src, 'ImageMagick belum disetting; gambar akan dicoba print langsung.'];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $outPdf = $cacheDir . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '_img.pdf';
    if (is_file($outPdf) && filemtime($outPdf) >= filemtime($src)) return [$outPdf, ''];
    $cmd = escapeshellarg($magick) . ' ' . escapeshellarg($src) . ' -auto-orient ' . escapeshellarg($outPdf);
    [$code, $out] = run_cmd($cmd);
    if ($code !== 0 || !is_file($outPdf)) return [$src, 'Konversi gambar ke PDF gagal; dicoba print langsung.'];
    return [$outPdf, ''];
}
function create_pdf_subset($srcPdf, $pageList, $cacheDir, $cfg) {
    if (!$pageList) return [$srcPdf, ''];
    $gs = find_bin($cfg, ['ghostscript_bin','gs_bin'], [
        'C:\\Program Files\\gs\\gs10.05.1\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\gs10.04.0\\bin\\gswin64c.exe',
        '/usr/bin/gs'
    ]);
    if (!$gs) return [$srcPdf, 'Ghostscript belum disetting; halaman khusus belum bisa dipotong otomatis.'];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $outPdf = $cacheDir . DIRECTORY_SEPARATOR . pathinfo($srcPdf, PATHINFO_FILENAME) . '_pages_' . preg_replace('/[^0-9,-]/','_', $pageList) . '.pdf';
    if (is_file($outPdf) && filemtime($outPdf) >= filemtime($srcPdf)) return [$outPdf, ''];
    $cmd = escapeshellarg($gs) . ' -q -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -sPageList=' . escapeshellarg($pageList) . ' -sOutputFile=' . escapeshellarg($outPdf) . ' ' . escapeshellarg($srcPdf);
    [$code, $out] = run_cmd($cmd);
    if ($code !== 0 || !is_file($outPdf)) return [$srcPdf, 'Subset halaman gagal; file penuh diprint.'];
    return [$outPdf, ''];
}
function paper_setting($ukuran) {
    $u = strtoupper(trim((string)$ukuran));
    if (strpos($u, 'A3') !== false) return 'paper=A3';
    if (strpos($u, 'A4') !== false) return 'paper=A4';
    if (strpos($u, 'LETTER') !== false) return 'paper=Letter';
    if (strpos($u, 'LEGAL') !== false) return 'paper=Legal';
    return ''; // F4/folio tergantung driver printer, jadi tidak dipaksa.
}
function finishing_setting($finishing) {
    $f = strtolower((string)$finishing);
    if (strpos($f, '2') !== false || strpos($f, 'bolak') !== false || strpos($f, 'duplex') !== false) return 'duplexlong';
    return 'simplex';
}
function update_print_status($pdo, $file) {
    $no = $file['no_penjualan'];
    $id = (int)$file['id'];
    $target = max(1, (int)($file['qty_cetak'] ?? 1));
    $curr = (int)($file['printed_count'] ?? 0) + 1;
    $st = ($curr >= $target) ? 'Sudah' : 'Belum';
    $pdo->prepare("UPDATE order_files SET printed_count = ?, status_baca = ? WHERE id = ?")->execute([$curr, $st, $id]);

    $t = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status_task='Selesai' THEN 1 ELSE 0 END) selesai FROM order_tasks WHERE no_penjualan=?"); $t->execute([$no]); $dt = $t->fetch(PDO::FETCH_ASSOC);
    $f = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status_baca='Sudah' THEN 1 ELSE 0 END) selesai FROM order_files WHERE no_penjualan=?"); $f->execute([$no]); $df = $f->fetch(PDO::FETCH_ASSOC);
    $tot = (int)($dt['total'] ?? 0) + (int)($df['total'] ?? 0);
    $done = (int)($dt['selesai'] ?? 0) + (int)($df['selesai'] ?? 0);
    $pct = ($tot > 0) ? round(($done / $tot) * 100) : 0;
    $newStatus = ($pct >= 100) ? 'Selesai' : 'Proses';
    $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan=?"); $cek->execute([$no]);
    if ($cek->rowCount() > 0) $pdo->prepare("UPDATE order_pekerjaan SET status_order=? WHERE no_penjualan=?")->execute([$newStatus, $no]);
    else $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan,status_order) VALUES (?,?)")->execute([$no, $newStatus]);
    return ['persen'=>$pct,'current'=>$curr,'target'=>$target,'is_done'=>($st==='Sudah'),'new_order_status'=>$newStatus];
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
if ($id <= 0) jp('error', 'ID file tidak valid.');

$stmt = $pdo->prepare("SELECT * FROM order_files WHERE id = ?");
$stmt->execute([$id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) jp('error', 'File order tidak ditemukan.');
if (($file['status_baca'] ?? '') === 'Sudah') jp('success', 'File sudah selesai dicetak.', ['already_done'=>true]);

$src = order_file_disk_path($file['path_file']);
if (!$src || !is_file($src)) jp('error', 'File fisik tidak ditemukan: ' . ($file['path_file'] ?? ''));

$printCfg = read_cfg(__DIR__ . '/../../config/direct_print.php', []);
if (isset($printCfg['enabled']) && !$printCfg['enabled']) jp('error', 'Direct print belum diaktifkan di config/direct_print.php.');
$analyzerCfg = read_cfg(__DIR__ . '/../../config/print_analyzer.php', []);
$cfg = array_merge($analyzerCfg, $printCfg);
$sumatra = find_bin($cfg, ['sumatra_pdf','sumatra_pdf_alt'], [
    'C:\\Program Files\\SumatraPDF\\SumatraPDF.exe',
    'C:\\Program Files (x86)\\SumatraPDF\\SumatraPDF.exe'
]);
if (!$sumatra) jp('error', 'SumatraPDF belum ditemukan. Install SumatraPDF atau set path di config/direct_print.php.');

$cacheDir = root_path('uploads/orders/print_cache');
$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
$printDisk = $src;
$warnings = [];

if (in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx'], true)) {
    [$converted, $err] = convert_office_to_pdf($src, $cacheDir, $cfg);
    if (!$converted) jp('error', $err ?: 'File Office gagal dikonversi ke PDF.');
    $printDisk = $converted; $ext = 'pdf';
}
if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) {
    [$converted, $warn] = convert_image_to_pdf($src, $cacheDir, $cfg);
    if ($warn) $warnings[] = $warn;
    $printDisk = $converted; $ext = strtolower(pathinfo($printDisk, PATHINFO_EXTENSION));
}
if ($ext === 'pdf') {
    $pages = clean_page_list($file['halaman'] ?? '');
    [$subset, $warn] = create_pdf_subset($printDisk, $pages, $cacheDir, $cfg);
    if ($warn) $warnings[] = $warn;
    $printDisk = $subset;
} elseif (!in_array($ext, ['pdf'], true)) {
    jp('error', 'Format ini belum bisa direct print. Gunakan tombol Download cadangan.');
}

$qty = max(1, (int)($file['qty_cetak'] ?? 1));
$settings = [];
$settings[] = $qty . 'x';
$settings[] = finishing_setting($file['finishing'] ?? '1 sisi');
if (!empty($cfg['scale'])) $settings[] = $cfg['scale'];
if (!empty($cfg['orientation'])) $settings[] = $cfg['orientation'];
if (!empty($cfg['force_color'])) $settings[] = $cfg['force_color'];
$paper = paper_setting($file['ukuran_kertas'] ?? '');
if ($paper) $settings[] = $paper;
$settings = implode(',', array_filter($settings));

$printer = trim((string)($cfg['printer_name'] ?? ''));
$cmd = escapeshellarg($sumatra) . ' -silent -exit-on-print ';
$cmd .= $printer !== '' ? '-print-to ' . escapeshellarg($printer) . ' ' : '-print-to-default ';
$cmd .= '-print-settings ' . escapeshellarg($settings) . ' ' . escapeshellarg($printDisk);
[$code, $out] = run_cmd($cmd);
if ($code !== 0) jp('error', 'Gagal mengirim print ke printer. ' . trim($out), ['cmd_code'=>$code]);

$progress = [];
if (($cfg['mark_printed_after_success'] ?? true)) $progress = update_print_status($pdo, $file);
jp('success', 'Perintah print sudah dikirim ke printer.', ['print_settings'=>$settings, 'warnings'=>$warnings] + $progress);
