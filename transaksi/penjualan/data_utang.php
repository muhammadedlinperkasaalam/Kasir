<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// Fitur Lunasi Utang
if (isset($_GET['lunasi'])) {
    $id_trx = $_GET['lunasi'];
    try {
        $pdo->beginTransaction();
        
        // Update Header
        $stmt = $pdo->prepare("UPDATE penjualan SET pelunasan = 'Y', keterangan = CONCAT(keterangan, ' (LUNAS)') WHERE no_penjualan = ?");
        $stmt->execute([$id_trx]);
        
        // Update Detail Item
        $stmt2 = $pdo->prepare("UPDATE penjualan_item SET pelunasan = 'Y' WHERE no_penjualan = ?");
        $stmt2->execute([$id_trx]);
        
        $pdo->commit();
        echo "<script>alert('Berhasil dilunasi!'); window.location='data_utang.php';</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Gagal: ".$e->getMessage()."');</script>";
    }
}

// Query Ambil Data Utang
// Kita harus hitung total belanja vs uang bayar (DP)
$sql = "SELECT p.*, pl.nama_pelanggan, pl.no_telepon,
        (SELECT SUM((pi.harga_jual * pi.jumlah) - pi.diskon) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) as total_belanja
        FROM penjualan p
        JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
        WHERE p.pelunasan = 'N'
        ORDER BY p.tgl_penjualan ASC";

$stmt = $pdo->query($sql);
$data_utang = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar Piutang Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="card shadow-sm border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-book-dead me-2"></i>Daftar Piutang (Utang) Pelanggan</h5>
                <a href="index.php" class="btn btn-light btn-sm text-danger">Kembali ke Kasir</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>No Transaksi</th>
                                <th>Tanggal</th>
                                <th>Pelanggan</th>
                                <th class="text-end">Total Tagihan</th>
                                <th class="text-end">Sudah Bayar (DP)</th>
                                <th class="text-end bg-warning bg-opacity-10">Sisa Utang</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grand_total_piutang = 0;
                            foreach($data_utang as $row): 
                                $sisa_utang = $row['total_belanja'] - $row['uang_bayar'];
                                $grand_total_piutang += $sisa_utang;
                            ?>
                            <tr>
                                <td><?= $row['no_penjualan'] ?></td>
                                <td><?= date('d-m-Y', strtotime($row['tgl_penjualan'])) ?></td>
                                <td>
                                    <strong><?= $row['nama_pelanggan'] ?></strong><br>
                                    <small class="text-muted"><?= $row['no_telepon'] ?></small>
                                </td>
                                <td class="text-end fw-bold">Rp <?= number_format($row['total_belanja'], 0, ',', '.') ?></td>
                                <td class="text-end text-success">Rp <?= number_format($row['uang_bayar'], 0, ',', '.') ?></td>
                                <td class="text-end fw-bold text-danger">Rp <?= number_format($sisa_utang, 0, ',', '.') ?></td>
                                <td class="text-center">
                                    <a href="?lunasi=<?= $row['no_penjualan'] ?>" 
                                       class="btn btn-success btn-sm"
                                       onclick="return confirm('Apakah pelanggan ini sudah melunasi utangnya sebesar Rp <?= number_format($sisa_utang,0,',','.') ?> ?')">
                                       <i class="fas fa-check"></i> Lunasi
                                    </a>
                                    <a href="cetak.php?id=<?= $row['no_penjualan'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Cetak Ulang">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if(empty($data_utang)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-smile fa-3x mb-2 text-success"></i><br>
                                    Tidak ada data utang yang belum lunas.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if(!empty($data_utang)): ?>
                        <tfoot>
                            <tr class="table-danger fw-bold">
                                <td colspan="5" class="text-end">TOTAL PIUTANG TOKO:</td>
                                <td class="text-end">Rp <?= number_format($grand_total_piutang, 0, ',', '.') ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>