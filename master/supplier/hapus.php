<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

$id = $_GET['id'] ?? '';

if (!empty($id)) {
    try {
        // Cek dulu apakah supplier dipakai di tabel barang? (Opsional, fitur keamanan)
        // $cek = $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kode_supplier = ?");
        // ... (Logika cek relasi) ...

        $stmt = $pdo->prepare("DELETE FROM supplier WHERE kode_supplier = ?");
        $stmt->execute([$id]);
        
        $_SESSION['success'] = "Supplier berhasil dihapus.";
    } catch (PDOException $e) {
        // Biasanya error karena foreign key constraint
        $_SESSION['error'] = "Gagal hapus! Supplier ini mungkin sedang digunakan di data barang.";
    }
}

header("Location: index.php");
exit;
?>