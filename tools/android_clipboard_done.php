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
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE android_clipboard_queue SET status='copied', copied_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
    }
    echo json_encode(['status'=>'success']);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
