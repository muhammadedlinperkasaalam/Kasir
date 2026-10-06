<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// SET TIMEZONE
date_default_timezone_set('Asia/Jakarta');

// --- 1. FILTER TANGGAL ---
// Default: Tahun ini (Januari s/d Sekarang)
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-01-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// ==========================================
// 2. AMBIL DATA DARI DATABASE (SUMBER DATA)
// ==========================================

// A. ASET: PERSEDIAAN BARANG (Dari Tabel Barang)
// Nilai Stok saat ini (Harga Beli x Stok)
$stmt_stok = $pdo->query("SELECT SUM(harga_beli * stok) FROM barang");
$val_persediaan = $stmt_stok->fetchColumn() ?? 0;

// B. ASET: PIUTANG USAHA (Dari Penjualan Belum Lunas)
$stmt_piutang = $pdo->prepare("SELECT SUM((pi.harga_jual * pi.jumlah) - pi.diskon) 
                               FROM penjualan_item pi
                               JOIN penjualan p ON pi.no_penjualan = p.no_penjualan
                               WHERE p.pelunasan = 'N' AND p.tgl_penjualan <= ?");
$stmt_piutang->execute([$tgl_akhir]);
$val_piutang = $stmt_piutang->fetchColumn() ?? 0;

// C & D. GABUNGAN: PENDAPATAN (Penjualan) DAN BEBAN (HPP)
// Menggabungkan 2 query menjadi 1 agar database tidak perlu membaca tabel yang sama dua kali.
$stmt_jual_hpp = $pdo->prepare("
    SELECT 
        SUM((pi.harga_jual * pi.jumlah) - pi.diskon) AS total_penjualan,
        SUM(pi.harga_beli_bersih * pi.jumlah) AS total_hpp
    FROM penjualan p
    JOIN penjualan_item pi ON p.no_penjualan = pi.no_penjualan
    WHERE p.tgl_penjualan BETWEEN ? AND ?
");
$stmt_jual_hpp->execute([$tgl_awal, $tgl_akhir]);
$row_jual_hpp = $stmt_jual_hpp->fetch(PDO::FETCH_ASSOC);

$val_penjualan = $row_jual_hpp['total_penjualan'] ?? 0;
$val_hpp = $row_jual_hpp['total_hpp'] ?? 0;

// E. BEBAN: OPERASIONAL (Listrik, Gaji, dll)
$stmt_beban = $pdo->prepare("SELECT SUM(jumlah) FROM pengeluaran_non_stok WHERE tanggal BETWEEN ? AND ?");
$stmt_beban->execute([$tgl_awal, $tgl_akhir]);
$val_beban = $stmt_beban->fetchColumn() ?? 0;

// F. ASET: KAS (Estimasi Sederhana)
$stmt_kas_in = $pdo->prepare("SELECT SUM(uang_bayar) FROM penjualan WHERE tgl_penjualan BETWEEN ? AND ?");
$stmt_kas_in->execute([$tgl_awal, $tgl_akhir]);
$kas_masuk = $stmt_kas_in->fetchColumn() ?? 0;
$val_kas = $kas_masuk - $val_beban; 

// G. EKUITAS (MODAL)
// Untuk menyeimbangkan Neraca: Aset - Kewajiban = Modal
$total_aset = $val_kas + $val_piutang + $val_persediaan;
$laba_bersih = $val_penjualan - $val_hpp - $val_beban;
// Modal Awal (Plug Figure agar Balance)
$val_modal = $total_aset - $laba_bersih; 

// ==========================================
// 3. SUSUN ARRAY AKUN (Chart of Accounts)
// ==========================================
$neraca_data = [
    ['kode'=>'1-1001', 'nama'=>'Kas & Bank',          'posisi'=>'D', 'tipe'=>'NERACA', 'nominal'=>$val_kas],
    ['kode'=>'1-1002', 'nama'=>'Piutang Usaha',       'posisi'=>'D', 'tipe'=>'NERACA', 'nominal'=>$val_piutang],
    ['kode'=>'1-2001', 'nama'=>'Persediaan Barang',   'posisi'=>'D', 'tipe'=>'NERACA', 'nominal'=>$val_persediaan],
    ['kode'=>'3-1001', 'nama'=>'Modal Pemilik',       'posisi'=>'K', 'tipe'=>'NERACA', 'nominal'=>$val_modal],
    ['kode'=>'4-1001', 'nama'=>'Pendapatan Penjualan','posisi'=>'K', 'tipe'=>'LABA',   'nominal'=>$val_penjualan],
    ['kode'=>'5-1001', 'nama'=>'Harga Pokok Penjualan (HPP)','posisi'=>'D', 'tipe'=>'LABA', 'nominal'=>$val_hpp],
    ['kode'=>'6-1001', 'nama'=>'Beban Operasional',   'posisi'=>'D', 'tipe'=>'LABA',   'nominal'=>$val_beban],
];

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neraca Lajur | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap'); /* For Numbers */
        
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Card & Table Custom */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: white; overflow: hidden; }
        
        .table-neraca th, .table-neraca td { vertical-align: middle; white-space: nowrap; padding: 12px 15px; border-color: #e2e8f0; }
        .table-neraca tbody tr:hover { background-color: #f8fafc; }
        
        /* Header Colors */
        .bg-head-main { background-color: #1e293b; color: white; font-weight: 600; font-size: 0.85rem; letter-spacing: 0.5px; border-bottom: none !important; }
        .bg-head-sub { background-color: #f1f5f9; color: #475569; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; }
        
        /* Number Formatting */
        .num { text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; }
        
        /* Column Highlights */
        .col-lr { background-color: rgba(13, 110, 253, 0.02); } /* Soft Blue for Laba Rugi */
        .col-nr { background-color: rgba(25, 135, 84, 0.02); } /* Soft Green for Neraca */

        /* Footer Rows */
        .row-total { background-color: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1 !important; }
        .row-laba { background-color: #e6f4ea; font-weight: 600; color: #1e8e3e; }
        .row-balance { background-color: #0f172a; color: white; font-weight: 800; }
        .row-balance td { border-color: #0f172a; }

        @media print { 
            .no-print { display: none !important; } 
            .sidebar { display: none !important; }
            body { font-size: 11px; -webkit-print-color-adjust: exact; print-color-adjust: exact; background: white; }
            .main-content { padding: 0; width: 100%; }
            .card-custom { box-shadow: none; border: none; }
            .bg-head-main { background-color: #333 !important; color: white !important; }
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-balance-scale text-primary me-2"></i> Neraca Lajur (Worksheet)</h4>
                <p class="text-muted small mb-0 mt-1">Kertas kerja akuntansi virtual otomatis dari sistem.</p>
            </div>
            
            <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0">
                    <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                        <i class="fas fa-calendar-alt text-muted me-2 small"></i>
                        <input type="date" name="tgl_awal" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_awal ?>" onchange="this.form.submit()">
                        <span class="mx-2 text-muted small">s/d</span>
                        <input type="date" name="tgl_akhir" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_akhir ?>" onchange="this.form.submit()">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1"><i class="fas fa-filter me-1"></i> Tampilkan</button>
                    <div class="vr mx-2 opacity-25"></div>
                    <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak Dokumen</button>
                </form>
            </div>
        </div>

        <div class="alert alert-info bg-info bg-opacity-10 border-0 d-flex align-items-center rounded-4 mb-4 no-print shadow-sm">
            <i class="fas fa-info-circle fa-lg text-info me-3"></i>
            <div class="small text-dark">
                <strong>Catatan Sistem:</strong> Laporan ini dibuat secara otomatis ("Virtual Accounting") berdasarkan data Penjualan, Stok, dan Pengeluaran. Nilai "Modal Pemilik" dihitung otomatis sebagai penyeimbang (Plug Figure) agar neraca selalu seimbang.
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">Kertas Kerja Periode: <?= date('d M Y', strtotime($tgl_awal)) ?> - <?= date('d M Y', strtotime($tgl_akhir)) ?></h6>
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered table-neraca mb-0">
                    <thead class="text-center align-middle">
                        <tr class="bg-head-main">
                            <th rowspan="2" class="border-end border-light">Kode Akun</th>
                            <th rowspan="2" class="border-end border-light">Nama Akun</th>
                            <th colspan="2" class="border-end border-light">Neraca Saldo</th>
                            <th colspan="2" class="border-end border-light">Laba Rugi</th>
                            <th colspan="2">Neraca (Posisi Keuangan)</th>
                        </tr>
                        <tr class="bg-head-sub">
                            <th>Debit</th>
                            <th class="border-end">Kredit</th>
                            <th class="col-lr">Debit</th>
                            <th class="col-lr border-end">Kredit</th>
                            <th class="col-nr">Debit</th>
                            <th class="col-nr">Kredit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Inisialisasi Total Bawah
                        $t_ns_d = 0; $t_ns_k = 0;
                        $t_lr_d = 0; $t_lr_k = 0;
                        $t_nr_d = 0; $t_nr_k = 0;

                        foreach($neraca_data as $row):
                            $d_ns = 0; $k_ns = 0;
                            $d_lr = 0; $k_lr = 0;
                            $d_nr = 0; $k_nr = 0;

                            // 1. Plot Neraca Saldo
                            if($row['posisi'] == 'D') { $d_ns = $row['nominal']; }
                            else { $k_ns = $row['nominal']; }

                            // 2. Plot Laba Rugi ATAU Neraca
                            if($row['tipe'] == 'LABA') {
                                $d_lr = $d_ns;
                                $k_lr = $k_ns;
                            } else {
                                $d_nr = $d_ns;
                                $k_nr = $k_ns;
                            }

                            // Akumulasi Total
                            $t_ns_d += $d_ns; $t_ns_k += $k_ns;
                            $t_lr_d += $d_lr; $t_lr_k += $k_lr;
                            $t_nr_d += $d_nr; $t_nr_k += $k_nr;
                        ?>
                        <tr>
                            <td class="text-center text-muted fw-medium font-monospace small"><?= $row['kode'] ?></td>
                            <td class="fw-medium text-dark"><?= $row['nama'] ?></td>
                            
                            <td class="num text-secondary"><?= $d_ns > 0 ? number_format($d_ns) : '-' ?></td>
                            <td class="num text-secondary border-end"><?= $k_ns > 0 ? number_format($k_ns) : '-' ?></td>
                            
                            <td class="num col-lr text-danger"><?= $d_lr > 0 ? number_format($d_lr) : '-' ?></td>
                            <td class="num col-lr border-end text-success"><?= $k_lr > 0 ? number_format($k_lr) : '-' ?></td>
                            
                            <td class="num col-nr text-primary"><?= $d_nr > 0 ? number_format($d_nr) : '-' ?></td>
                            <td class="num col-nr text-primary"><?= $k_nr > 0 ? number_format($k_nr) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    
                    <tfoot>
                        <tr class="row-total">
                            <td colspan="2" class="text-end text-uppercase pe-4">Total Saldo</td>
                            <td class="num"><?= number_format($t_ns_d) ?></td>
                            <td class="num border-end"><?= number_format($t_ns_k) ?></td>
                            
                            <td class="num col-lr"><?= number_format($t_lr_d) ?></td>
                            <td class="num col-lr border-end"><?= number_format($t_lr_k) ?></td>
                            
                            <td class="num col-nr"><?= number_format($t_nr_d) ?></td>
                            <td class="num col-nr"><?= number_format($t_nr_k) ?></td>
                        </tr>
                        
                        <?php
                            $laba_rugi = $t_lr_k - $t_lr_d; // Pendapatan - Beban
                            
                            // Penyeimbang Laba Rugi
                            $lr_d_plug = ($laba_rugi > 0) ? $laba_rugi : 0;
                            $lr_k_plug = ($laba_rugi < 0) ? abs($laba_rugi) : 0;

                            // Penyeimbang Neraca (Kebalikannya)
                            $nr_d_plug = ($laba_rugi < 0) ? abs($laba_rugi) : 0;
                            $nr_k_plug = ($laba_rugi > 0) ? $laba_rugi : 0;
                        ?>
                        
                        <tr class="row-laba">
                            <td colspan="2" class="text-end fst-italic pe-4 text-uppercase"><?= $laba_rugi >= 0 ? 'Laba Bersih' : 'Rugi Bersih' ?></td>
                            <td class="bg-white"></td>
                            <td class="border-end bg-white"></td> 
                            
                            <td class="num"><?= $lr_d_plug > 0 ? number_format($lr_d_plug) : '-' ?></td>
                            <td class="num border-end"><?= $lr_k_plug > 0 ? number_format($lr_k_plug) : '-' ?></td>
                            
                            <td class="num"><?= $nr_d_plug > 0 ? number_format($nr_d_plug) : '-' ?></td>
                            <td class="num"><?= $nr_k_plug > 0 ? number_format($nr_k_plug) : '-' ?></td>
                        </tr>

                        <tr class="row-balance">
                            <td colspan="2" class="text-end text-uppercase pe-4">Balance Akhir</td>
                            <td></td>
                            <td class="border-end"></td>
                            
                            <td class="num"><?= number_format($t_lr_d + $lr_d_plug) ?></td>
                            <td class="num border-end"><?= number_format($t_lr_k + $lr_k_plug) ?></td>
                            
                            <td class="num"><?= number_format($t_nr_d + $nr_d_plug) ?></td>
                            <td class="num"><?= number_format($t_nr_k + $nr_k_plug) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>
</body>
</html>