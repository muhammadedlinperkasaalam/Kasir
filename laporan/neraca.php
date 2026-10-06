<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- FILTER TANGGAL (POSISI PER TANGGAL) ---
$per_tanggal = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// ==========================================
// 1. HITUNG ASET LANCAR
// ==========================================

// A. KAS (ESTIMASI ARUS KAS)
// Rumus: Total Uang Masuk (Penjualan) - Total Uang Keluar (Belanja Stok + Biaya)

// 1. Total Uang Masuk (Dari penjualan)
$sql_masuk = "SELECT COALESCE(SUM(uang_bayar), 0) FROM penjualan WHERE tgl_penjualan <= ?";
$stmt_masuk = $pdo->prepare($sql_masuk);
$stmt_masuk->execute([$per_tanggal]);
$total_masuk = $stmt_masuk->fetchColumn();

// 2. Total Belanja Stok (Dari pembelian)
// PERBAIKAN: Menggunakan 'tgl_pembelian'
$sql_beli = "SELECT COALESCE(SUM(total), 0) FROM pembelian WHERE tgl_pembelian <= ?";
$stmt_beli = $pdo->prepare($sql_beli);
$stmt_beli->execute([$per_tanggal]);
$total_beli = $stmt_beli->fetchColumn();

// 3. Total Biaya Operasional (Dari pengeluaran_non_stok)
// Pastikan kolom tanggal di tabel ini benar (biasanya 'tanggal')
$sql_biaya = "SELECT COALESCE(SUM(jumlah), 0) FROM pengeluaran_non_stok WHERE tanggal <= ?";
$stmt_biaya = $pdo->prepare($sql_biaya);
$stmt_biaya->execute([$per_tanggal]);
$total_biaya = $stmt_biaya->fetchColumn();

// Hitung Saldo Kas
$saldo_kas = $total_masuk - ($total_beli + $total_biaya);


// B. PIUTANG USAHA (Uang di Pelanggan)
$sql_piutang = "SELECT 
                COALESCE(SUM(
                    (SELECT SUM((pi.harga_jual * pi.jumlah) - pi.diskon) 
                     FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) 
                    - p.uang_bayar
                ), 0)
                FROM penjualan p
                WHERE p.pelunasan = 'N' AND p.tgl_penjualan <= ?";
$stmt_piutang = $pdo->prepare($sql_piutang);
$stmt_piutang->execute([$per_tanggal]);
$saldo_piutang = $stmt_piutang->fetchColumn();


// C. PERSEDIAAN BARANG (Nilai Stok Aset)
// Mengambil nilai aset stok saat ini
$sql_stok = "SELECT COALESCE(SUM(stok * harga_beli), 0) FROM barang WHERE aktif = 'Y'";
$stmt_stok = $pdo->query($sql_stok);
$nilai_stok = $stmt_stok->fetchColumn();


// TOTAL AKTIVA (ASET)
$total_aktiva = $saldo_kas + $saldo_piutang + $nilai_stok;


// ==========================================
// 2. HITUNG PASIVA (MODAL & KEWAJIBAN)
// ==========================================

$modal_awal = 0; 
$utang_usaha = 0; 

// Laba Ditahan (Balancing Figure)
// Laba Ditahan = Total Aset - (Modal + Utang)
$laba_ditahan = $total_aktiva - ($modal_awal + $utang_usaha);
$total_pasiva = $modal_awal + $utang_usaha + $laba_ditahan;

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Neraca</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .report-paper {
            background: white;
            padding: 40px;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            max-width: 900px;
            margin: 20px auto;
            border-top: 5px solid #198754; 
        }
        .table-neraca th { background-color: #f8f9fa; text-transform: uppercase; font-size: 0.9rem; }
        .table-neraca td { padding: 8px 15px; border-bottom: 1px solid #eee; }
        .section-title { font-weight: bold; color: #198754; margin-top: 15px; text-transform: uppercase; border-bottom: 2px solid #198754; padding-bottom: 5px; margin-bottom: 10px; display: block;}
        .sub-total { font-weight: bold; background-color: #f1fcf5; }
        .grand-total { font-weight: bold; font-size: 1.2rem; background-color: #198754; color: white; }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .report-paper { box-shadow: none; border: none; margin: 0; padding: 0; width: 100%; max-width: 100%; }
            .grand-total { color: black !important; border: 2px solid black !important; background: none !important; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-secondary mb-4 no-print shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="../index.php"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
        <span class="navbar-text text-white fw-bold"><i class="fas fa-balance-scale me-2"></i>Laporan Neraca</span>
    </div>
</nav>

<div class="container pb-5">

    <div class="card shadow-sm mb-4 no-print">
        <div class="card-body py-2">
            <form method="GET" class="row align-items-center g-2">
                <div class="col-auto"><label class="fw-bold text-secondary">Posisi Per Tanggal:</label></div>
                <div class="col-auto">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= $per_tanggal ?>" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i> Tampilkan</button>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-dark ms-2"><i class="fas fa-print me-1"></i> Cetak</button>
                </div>
            </form>
        </div>
    </div>

    <div class="report-paper">
        
        <div class="text-center mb-5">
            <h3 class="fw-bold text-uppercase mb-1">Neraca Keuangan (Balance Sheet)</h3>
            <p class="text-muted mb-0">Posisi Per Tanggal: <?= date('d F Y', strtotime($per_tanggal)) ?></p>
        </div>

        <div class="row">
            
            <div class="col-md-6 border-end">
                <div class="section-title">I. AKTIVA (ASET)</div>
                <table class="w-100 table-neraca">
                    <tr>
                        <td colspan="2" class="fw-bold text-secondary pt-3">A. Aset Lancar</td>
                    </tr>
                    <tr>
                        <td>Kas & Setara Kas</td>
                        <td class="text-end">Rp <?= number_format($saldo_kas, 0, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <td>Piutang Usaha</td>
                        <td class="text-end">Rp <?= number_format($saldo_piutang, 0, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <td>Persediaan Barang (Stok)</td>
                        <td class="text-end">Rp <?= number_format($nilai_stok, 0, ',', '.') ?></td>
                    </tr>
                    <tr class="sub-total">
                        <td>Total Aset Lancar</td>
                        <td class="text-end">Rp <?= number_format($total_aktiva, 0, ',', '.') ?></td>
                    </tr>

                    <tr>
                        <td colspan="2" class="fw-bold text-secondary pt-4">B. Aset Tetap</td>
                    </tr>
                    <tr>
                        <td class="text-muted fst-italic">Inventaris Toko (Tanah/Bangunan)</td>
                        <td class="text-end text-muted">-</td>
                    </tr>
                    
                    <tr><td colspan="2" class="py-3"></td></tr>
                    
                    <tr class="grand-total">
                        <td class="py-3 ps-3">TOTAL AKTIVA</td>
                        <td class="text-end py-3 pe-3">Rp <?= number_format($total_aktiva, 0, ',', '.') ?></td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <div class="section-title">II. PASIVA (KEWAJIBAN & MODAL)</div>
                <table class="w-100 table-neraca">
                    <tr>
                        <td colspan="2" class="fw-bold text-secondary pt-3">A. Kewajiban (Utang)</td>
                    </tr>
                    <tr>
                        <td>Utang Usaha (Supplier)</td>
                        <td class="text-end">Rp <?= number_format($utang_usaha, 0, ',', '.') ?></td>
                    </tr>
                    <tr class="sub-total">
                        <td>Total Kewajiban</td>
                        <td class="text-end">Rp <?= number_format($utang_usaha, 0, ',', '.') ?></td>
                    </tr>

                    <tr>
                        <td colspan="2" class="fw-bold text-secondary pt-4">B. Ekuitas (Modal)</td>
                    </tr>
                    <tr>
                        <td>Modal Awal</td>
                        <td class="text-end">Rp <?= number_format($modal_awal, 0, ',', '.') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-success">Laba Ditahan (Retained Earnings)</td>
                        <td class="text-end fw-bold text-success">Rp <?= number_format($laba_ditahan, 0, ',', '.') ?></td>
                    </tr>
                    <tr class="sub-total">
                        <td>Total Modal</td>
                        <td class="text-end">Rp <?= number_format($modal_awal + $laba_ditahan, 0, ',', '.') ?></td>
                    </tr>

                    <tr><td colspan="2" class="py-3"></td></tr>

                    <tr class="grand-total">
                        <td class="py-3 ps-3">TOTAL PASIVA</td>
                        <td class="text-end py-3 pe-3">Rp <?= number_format($total_pasiva, 0, ',', '.') ?></td>
                    </tr>
                </table>
            </div>

        </div>

        <div class="mt-5 pt-4 text-center">
            <small class="text-muted d-block">Laporan ini dibuat otomatis berdasarkan data transaksi yang tercatat di sistem.</small>
            <small class="text-muted">Dicetak pada: <?= date('d/m/Y H:i') ?></small>
        </div>

    </div>

</div>

</body>
</html>