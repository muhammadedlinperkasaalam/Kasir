<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/android_clipboard_lib.php';
android_clipboard_ensure_table($pdo);
$nama = 'TEST CUSTOMER ' . date('H:i:s');
$stmt = $pdo->prepare("INSERT INTO android_clipboard_queue (nama_pelanggan, no_penjualan, source_user_id, status) VALUES (?,?,?,'pending')");
$stmt->execute([$nama, 'TEST', (int)$_SESSION['user_id']]);
header('Content-Type: text/html; charset=utf-8');
echo "Test dikirim ke Android Clipboard: <b>" . htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') . "</b><br>Silakan cek halaman android_clipboard.php di HP.";
