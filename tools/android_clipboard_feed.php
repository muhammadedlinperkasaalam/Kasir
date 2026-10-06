<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Belum login']); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/android_clipboard_lib.php';
try {
    android_clipboard_ensure_table($pdo);
    $after = (int)($_GET['after'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, nama_pelanggan, no_penjualan, created_at FROM android_clipboard_queue WHERE status='pending' AND id > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$after]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','item'=>$row ?: null]);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
