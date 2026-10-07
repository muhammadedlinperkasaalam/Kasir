<?php
require_once __DIR__.'/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

pa_verify_csrf();
PA_KasirBridge::ensureTables($pdo);

$jobId = (int)($_POST['id'] ?? 0);
if ($jobId <= 0) {
    pa_flash('error', 'Data riwayat tidak valid.');
    header('Location: index.php');
    exit;
}

$st = $pdo->prepare('SELECT * FROM print_jobs WHERE id = ?');
$st->execute([$jobId]);
$job = $st->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    pa_flash('error', 'Riwayat Print Analyzer tidak ditemukan.');
    header('Location: index.php');
    exit;
}

function pa_delete_file_safe(string $path): void {
    $base = realpath(pa_storage_root());
    $real = realpath($path);
    if ($base && $real && str_starts_with($real, $base) && is_file($real)) {
        @unlink($real);
    }
}

function pa_delete_dir_safe(string $dir): void {
    $base = realpath(pa_storage_root());
    $real = realpath($dir);
    if (!$base || !$real || !str_starts_with($real, $base) || !is_dir($real)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($real);
}

try {
    // Hapus file fisik analyzer. Data transaksi kasir/nota yang sudah dibuat tidak ikut dihapus.
    if (!empty($job['stored_filename'])) {
        pa_delete_file_safe(pa_storage_root().DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.basename($job['stored_filename']));
    }
    pa_delete_dir_safe(pa_storage_root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId);

    // Hapus data halaman dan header analyzer.
    $pdo->prepare('DELETE FROM print_job_pages WHERE print_job_id = ?')->execute([$jobId]);
    $pdo->prepare('DELETE FROM print_jobs WHERE id = ?')->execute([$jobId]);

    pa_flash('success', 'Riwayat Print Analyzer berhasil dihapus. Transaksi kasir yang sudah dibuat tidak ikut dihapus.');
} catch (Throwable $e) {
    pa_flash('error', 'Gagal menghapus riwayat: '.$e->getMessage());
}

header('Location: index.php');
exit;
