<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

$id = $_GET['id'];

// Cek apakah barang sudah pernah dipakai transaksi
// Jika sudah, JANGAN dihapus, tapi set status aktif = N (Soft Delete)
// Untuk keamanan data laporan.

$cek = $pdo->prepare("SELECT COUNT(*) FROM penjualan_item WHERE kode_barang = ?");
$cek->execute([$id]);
$used = $cek->fetchColumn();

if ($used > 0) {
    echo "<script>
            alert('Gagal Hapus! Barang ini sudah ada di riwayat transaksi. Silakan set status Non-Aktif saja.');
            window.location='index.php';
          </script>";
} else {
    // Jika belum pernah dipakai transaksi, boleh hapus permanen
    $stmt = $pdo->prepare("DELETE FROM barang WHERE kode_barang = ?");
    $stmt->execute([$id]);
    header("Location: index.php");
}
?>