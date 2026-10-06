<?php
session_start();

// 1. Cek Login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit;
}

require_once '../../config/database.php';

// 2. Cek Parameter ID
if (isset($_GET['id'])) {
    $id = $_GET['id'];

    try {
        // Cek dulu apakah pelanggan ini sudah pernah bertransaksi?
        // Jika sudah ada di tabel penjualan, JANGAN DIHAPUS (bisa error laporan nanti)
        $cek = $pdo->prepare("SELECT COUNT(*) FROM penjualan WHERE kode_pelanggan = ?");
        $cek->execute([$id]);
        $jumlah_transaksi = $cek->fetchColumn();

        if ($jumlah_transaksi > 0) {
            // Jika sudah ada transaksi, tolak penghapusan
            echo "<script>
                alert('GAGAL HAPUS: Pelanggan ini memiliki $jumlah_transaksi riwayat transaksi. Data tidak boleh dihapus demi keakuratan laporan.');
                window.location = 'index.php';
            </script>";
            exit;
        }

        // 3. PROSES HAPUS (Jika aman)
        // [PERBAIKAN DISINI]: kode_pelanggan (double g)
        $sql = "DELETE FROM pelanggan WHERE kode_pelanggan = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        // Redirect Sukses
        header("Location: index.php?msg=deleted");
        exit;

    } catch (PDOException $e) {
        // Tangkap Error Database
        echo "<script>
            alert('Gagal menghapus data: " . $e->getMessage() . "');
            window.location = 'index.php';
        </script>";
    }
} else {
    // Jika tidak ada ID
    header("Location: index.php");
    exit;
}
?>