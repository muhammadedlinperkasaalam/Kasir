<?php
// Halaman ringkas order untuk komputer Print Agent client.
// Tidak memakai login, tetapi wajib memakai token per job.
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rupiah($v): string { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }
function fmt_dt($v): string { return $v ? date('d/m/Y H:i', strtotime($v)) : '-'; }
function file_url_from_path($path_file): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = preg_replace('#/transaksi/order$#', '', $script);
    $path = str_replace('\\', '/', (string)$path_file);
    $path = ltrim($path, '/');
    if (strpos($path, 'uploads/') !== 0) $path = 'uploads/orders/' . $path;
    $parts = array_map('rawurlencode', explode('/', $path));
    return rtrim($scheme . '://' . $host . $root, '/') . '/' . implode('/', $parts);
}

$jobId = (int)($_GET['job_id'] ?? ($_GET['job'] ?? ($_GET['id'] ?? 0)));
$token = trim((string)($_GET['token'] ?? ''));
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$job = null;

// Mode utama: akses dengan token khusus dari Print Agent.
if ($jobId > 0 && $token !== '') {
    $stmt = $pdo->prepare("SELECT j.*, c.client_name, c.ip_address
                           FROM print_agent_jobs j
                           LEFT JOIN print_clients c ON c.id = j.client_id
                           WHERE j.id=? AND j.view_token=? LIMIT 1");
    $stmt->execute([$jobId, $token]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Fallback aman untuk komputer client: kalau token tidak ikut terbawa,
// tampilkan job terakhir milik Print Agent dari IP komputer ini.
if (!$job && $clientIp !== '') {
    if ($jobId > 0) {
        $stmt = $pdo->prepare("SELECT j.*, c.client_name, c.ip_address
                               FROM print_agent_jobs j
                               LEFT JOIN print_clients c ON c.id = j.client_id
                               WHERE j.id=? AND c.ip_address=?
                               ORDER BY j.id DESC LIMIT 1");
        $stmt->execute([$jobId, $clientIp]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$job) {
        $stmt = $pdo->prepare("SELECT j.*, c.client_name, c.ip_address
                               FROM print_agent_jobs j
                               LEFT JOIN print_clients c ON c.id = j.client_id
                               WHERE c.ip_address=?
                               ORDER BY j.id DESC LIMIT 1");
        $stmt->execute([$clientIp]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$job) {
    http_response_code(403);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Akses Order</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f4f6f8;padding:30px}.box{max-width:720px;margin:auto;background:#fff;border-radius:14px;padding:22px;box-shadow:0 8px 24px rgba(0,0,0,.08)}code{background:#f3f4f6;padding:2px 5px;border-radius:5px}</style>';
    echo '</head><body><div class="box"><h2>Akses detail order belum tersedia</h2>';
    echo '<p>Halaman ini harus dibuka dari link yang dikirim Print Agent saat menerima job, atau dari komputer client yang baru menerima job print.</p>';
    echo '<p>Jika baru memasang patch, jalankan ulang <b>start_agent.bat</b>, lalu kirim ulang print dari Manajemen Order.</p>';
    echo '<p>IP komputer ini: <code>' . e($clientIp ?: '-') . '</code></p>';
    echo '</div></body></html>';
    exit;
}

$nota = $job['no_penjualan'] ?? '';
$trx = null;
if ($nota !== '') {
    $st = $pdo->prepare("SELECT p.*, pl.nama_pelanggan, pl.no_telepon
                         FROM penjualan p
                         LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                         WHERE p.no_penjualan=? LIMIT 1");
    $st->execute([$nota]);
    $trx = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$files = [];
if ($nota !== '') {
    $fs = $pdo->prepare("SELECT * FROM order_files WHERE no_penjualan=? ORDER BY id ASC");
    $fs->execute([$nota]);
    $files = $fs->fetchAll(PDO::FETCH_ASSOC);
}

$tasks = [];
if ($nota !== '') {
    try {
        $ts = $pdo->prepare("SELECT * FROM order_tasks WHERE no_penjualan=? ORDER BY id ASC");
        $ts->execute([$nota]);
        $tasks = $ts->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $tasks = []; }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order Print <?= e($nota ?: ('Job '.$jobId)) ?></title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;color:#111827}.wrap{max-width:980px;margin:20px auto;background:#fff;border-radius:16px;padding:20px;box-shadow:0 8px 24px rgba(0,0,0,.08)}h1{font-size:22px;margin:0 0 4px}.muted{color:#6b7280}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:16px 0}.card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.label{font-size:12px;color:#6b7280;margin-bottom:4px}.val{font-weight:700}.badge{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700}.b-ok{background:#dcfce7;color:#166534}.b-warn{background:#fef3c7;color:#92400e}.b-info{background:#dbeafe;color:#1d4ed8}.table{width:100%;border-collapse:collapse;margin-top:10px}.table th,.table td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;font-size:14px}.table th{background:#f9fafb}.btn{display:inline-block;background:#111827;color:#fff;text-decoration:none;border-radius:8px;padding:6px 10px;font-size:13px}.section{margin-top:20px}.refresh{float:right;font-size:13px}@media(max-width:780px){.grid{grid-template-columns:1fr 1fr}.wrap{margin:10px;border-radius:0}}@media(max-width:480px){.grid{grid-template-columns:1fr}}
</style>
<script>setTimeout(()=>location.reload(),30000);</script>
</head>
<body>
<div class="wrap">
  <div class="refresh muted">Auto refresh 30 detik</div>
  <h1>Detail Order Print</h1>
  <div class="muted">Halaman client Print Agent tanpa login, akses khusus job ini.</div>

  <div class="grid">
    <div class="card"><div class="label">No Nota</div><div class="val"><?= e($nota ?: '-') ?></div></div>
    <div class="card"><div class="label">Customer</div><div class="val"><?= e($trx['nama_pelanggan'] ?? '-') ?></div></div>
    <div class="card"><div class="label">Client Print</div><div class="val"><?= e($job['client_name'] ?? '-') ?></div></div>
    <div class="card"><div class="label">Printer</div><div class="val"><?= e($job['printer_name'] ?? '-') ?></div></div>
  </div>

  <div class="grid">
    <div class="card"><div class="label">File Job</div><div class="val"><?= e($job['file_name'] ?? '-') ?></div></div>
    <div class="card"><div class="label">Status Job</div><div class="val"><span class="badge <?= ($job['status']==='done'?'b-ok':($job['status']==='failed'?'b-warn':'b-info')) ?>"><?= e(strtoupper($job['status'] ?? '-')) ?></span></div></div>
    <div class="card"><div class="label">Print Part</div><div class="val"><?= e($job['print_part'] ?? 'all') ?></div></div>
    <div class="card"><div class="label">Copies</div><div class="val"><?= e($job['copies'] ?? 1) ?>x</div></div>
  </div>

  <?php if(!empty($job['error_message'])): ?>
    <div class="card" style="border-color:#fecaca;background:#fef2f2;color:#991b1b"><b>Error:</b> <?= e($job['error_message']) ?></div>
  <?php endif; ?>

  <div class="section">
    <h3>Daftar File Order</h3>
    <table class="table">
      <thead><tr><th>File</th><th>Spesifikasi</th><th>Status</th><th>Terakhir Print</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php if(!$files): ?><tr><td colspan="5" class="muted">Tidak ada file order.</td></tr><?php endif; ?>
      <?php foreach($files as $f):
        $done = ($f['status_baca'] ?? '') === 'Sudah';
        $status = $done ? 'Selesai' : (($f['printed_count'] ?? 0) > 0 ? 'Diprint sebagian' : ($f['status_baca'] ?: 'Belum Cetak'));
      ?>
        <tr>
          <td><b><?= e($f['nama_file'] ?: basename(str_replace('\\','/',$f['path_file'] ?? ''))) ?></b></td>
          <td><?= e($f['ukuran_kertas'] ?? '-') ?> / <?= e($f['jenis_kertas'] ?? '-') ?><br><span class="muted"><?= e($f['finishing'] ?? '-') ?>, Hal: <?= e($f['halaman'] ?? 'All') ?></span></td>
          <td><span class="badge <?= $done?'b-ok':'b-info' ?>"><?= e($status) ?></span><br><span class="muted"><?= (int)($f['printed_count'] ?? 0) ?>/<?= max(1,(int)($f['qty_cetak'] ?? 1)) ?></span></td>
          <td><?= e($f['last_print_client_name'] ?? '-') ?><br><span class="muted"><?= e($f['last_print_printer_name'] ?? '') ?> <?= !empty($f['last_print_at']) ? ' - '.fmt_dt($f['last_print_at']) : '' ?></span></td>
          <td><a class="btn" href="<?= e(file_url_from_path($f['path_file'] ?? '')) ?>" target="_blank">Buka File</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if($tasks): ?>
  <div class="section">
    <h3>Task Pekerjaan</h3>
    <table class="table">
      <thead><tr><th>Task</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($tasks as $t): ?>
        <tr><td><?= e($t['nama_task'] ?? $t['task_name'] ?? '-') ?></td><td><?= e($t['status_task'] ?? '-') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
