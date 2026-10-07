<?php
require_once __DIR__.'/_init.php';
header('Content-Type: text/html; charset=utf-8');
function pa_cmd_ok($path): array {
    $path = (string)$path;
    $isAbsWin = (bool)preg_match('/^[A-Za-z]:[\\\/]/', $path);
    if ($isAbsWin) return [is_file($path), is_file($path) ? 'OK' : 'Tidak ditemukan: '.$path];
    $checker = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where' : 'which';
    $found = @shell_exec($checker . ' ' . escapeshellarg($path) . ' 2>NUL');
    return [trim((string)$found) !== '', trim((string)$found) !== '' ? 'OK: '.trim((string)$found) : 'Tidak ditemukan di PATH: '.$path];
}
$items = [
    'LibreOffice / soffice' => pa_setting('soffice_bin','soffice'),
    'Ghostscript' => pa_setting('ghostscript_bin','gs'),
    'ImageMagick / magick' => pa_setting('imagemagick_bin','magick'),
];
?>
<!doctype html><html><head><meta charset="utf-8"><title>Cek Kebutuhan Print Analyzer</title>
<style>body{font-family:Arial,sans-serif;padding:22px;background:#f7f7f7}.card{background:#fff;border-radius:10px;padding:18px;max-width:860px;margin:auto;box-shadow:0 3px 14px #0001}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #eee;padding:10px;text-align:left}.ok{color:green;font-weight:bold}.bad{color:#b00020;font-weight:bold}code{background:#eee;padding:2px 5px;border-radius:4px}</style>
</head><body><div class="card"><h2>Cek Kebutuhan Print Analyzer</h2>
<p>Halaman ini untuk komputer baru. Jika ada yang merah, sesuaikan path di <code>config/print_analyzer.php</code>.</p>
<table><tr><th>Kebutuhan</th><th>Path/Command</th><th>Status</th></tr>
<?php foreach($items as $label=>$path): [$ok,$msg]=pa_cmd_ok($path); ?>
<tr><td><?= e($label) ?></td><td><code><?= e($path) ?></code></td><td class="<?= $ok?'ok':'bad' ?>"><?= e($msg) ?></td></tr>
<?php endforeach; ?>
</table>
<p><b>Folder storage:</b> <code><?= e(pa_storage_root()) ?></code> — <?= is_writable(pa_storage_root()) ? '<span class="ok">Writable</span>' : '<span class="bad">Tidak bisa ditulis</span>' ?></p>
</div></body></html>
