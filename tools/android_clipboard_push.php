<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Belum login']); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/android_clipboard_lib.php';
try {
    android_clipboard_ensure_table($pdo);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    $nama = android_clipboard_clean_name($data['nama_pelanggan'] ?? '');
    $nota = trim((string)($data['no_penjualan'] ?? ''));
    if ($nama === '') throw new Exception('Nama pelanggan kosong');
    $lower = strtolower($nama);
    if ($lower === 'umum' || strpos($lower, 'pelanggan umum') !== false || strpos($lower, 'cash') !== false) {
        echo json_encode(['status'=>'ignored','message'=>'Pelanggan umum tidak dikirim']); exit;
    }
    $stmt = $pdo->prepare("INSERT INTO android_clipboard_queue (nama_pelanggan, no_penjualan, source_user_id, status) VALUES (?,?,?,'pending')");
    $stmt->execute([$nama, $nota, (int)($_SESSION['user_id'] ?? 0)]);
    echo json_encode(['status'=>'success','id'=>(int)$pdo->lastInsertId(),'nama_pelanggan'=>$nama]);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
