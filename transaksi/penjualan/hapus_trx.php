<?php
session_start();
// Pastikan hanya admin/owner yang bisa akses
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
if ($_SESSION['level'] != 'admin' && $_SESSION['level'] != 'Owner') { 
    die("Akses Ditolak: Anda tidak memiliki izin untuk menghapus transaksi."); 
}

require_once '../../config/database.php';

if (isset($_GET['id'])) {
    $no_penjualan = $_GET['id'];

    try {
        // --- 1. HAPUS FILE FISIK DI FOLDER SERVER ---
        // Kita cari tahu dulu apakah ada file desain yang terkait dengan nota ini
        $stmt_files = $pdo->prepare("SELECT path_file FROM order_files WHERE no_penjualan = ?");
        $stmt_files->execute([$no_penjualan]);
        $files = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $f) {
            $file_path = '../../uploads/orders/' . $f['path_file'];
            // Jika filenya ada di folder, hapus filenya
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // --- 2. MULAI PENGHAPUSAN DATABASE (Menggunakan Transaction agar aman) ---
        $pdo->beginTransaction();

        // A. Hapus semua data yang berkaitan dengan Manajemen Order
        $pdo->prepare("DELETE FROM order_files WHERE no_penjualan = ?")->execute([$no_penjualan]);
        $pdo->prepare("DELETE FROM order_tasks WHERE no_penjualan = ?")->execute([$no_penjualan]);
        $pdo->prepare("DELETE FROM order_pekerjaan WHERE no_penjualan = ?")->execute([$no_penjualan]);

        // B. Hapus detail barang belanjaan di kasir
        $pdo->prepare("DELETE FROM penjualan_item WHERE no_penjualan = ?")->execute([$no_penjualan]);
        
        // C. Hapus mutasi Manajemen Cash yang terkait nota ini.
        // Ini akan mengembalikan stok pecahan karena stok cash dihitung ulang dari cash_movements.
        try {
            $likeNota = '%' . $no_penjualan . '%';
            $pdo->prepare("DELETE FROM cash_movements 
                           WHERE sumber IN ('transaksi_cash_diterima','kembalian_cash','bayar_utang_cash')
                           AND keterangan LIKE ?")
                ->execute([$likeNota]);
        } catch (Throwable $e) {
            // Jika tabel Manajemen Cash belum ada, proses hapus transaksi tetap lanjut.
        }
        
        // D. Hapus data arus kas
        $pdo->prepare("DELETE FROM arus_kas WHERE no_penjualan = ?")->execute([$no_penjualan]);

        // E. Hapus data nota utama
        $pdo->prepare("DELETE FROM penjualan WHERE no_penjualan = ?")->execute([$no_penjualan]);

        // Simpan semua perubahan
        $pdo->commit();

        // Redirect kembali ke riwayat dengan pesan sukses
        // Setelah semua query DELETE berhasil dieksekusi...
        
        // Redirect kembali ke riwayat dengan membawa "kunci rahasia" hapus=sukses
        header("Location: riwayat.php?hapus=sukses");
        exit;

    } catch (Exception $e) {
        // Jika terjadi error di tengah jalan, batalkan semua penghapusan database
        $pdo->rollBack();
        echo "<script>
            alert('Gagal menghapus transaksi: " . addslashes($e->getMessage()) . "');
            window.location.href = 'riwayat.php';
        </script>";
    }
} else {
    // Jika tidak ada ID yang dikirim
    header("Location: riwayat.php");
    exit;
}
?> 