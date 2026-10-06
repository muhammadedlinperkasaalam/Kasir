<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// SET TIMEZONE
date_default_timezone_set('Asia/Jakarta');

$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// =========================================================================
// 1. BACA DATA DARI TABEL REKAP (SUPER RINGAN)
// =========================================================================

// A. Aset Stok tetap baca dari tabel barang (karena ini nilai real-time gudang)
$stmt_stok = $pdo->query("SELECT COALESCE(SUM(harga_beli * stok), 0) FROM barang");
$val_persediaan = (float) $stmt_stok->fetchColumn();

// B. Ambil Total Keseluruhan dari Tabel Rekap sampai tanggal yang dipilih
$stmt_rekap = $pdo->prepare("
    SELECT 
        COALESCE(SUM(total_omzet), 0) as omzet,
        COALESCE(SUM(total_hpp), 0) as hpp,
        COALESCE(SUM(total_beban), 0) as beban,
        COALESCE(SUM(uang_masuk), 0) as masuk
    FROM rekap_keuangan_harian 
    WHERE tanggal <= ?
");
$stmt_rekap->execute([$tgl_akhir]);
$rekap = $stmt_rekap->fetch(PDO::FETCH_ASSOC);

// =========================================================================
// 2. MAPPING HASIL (MATEMATIKA)
// =========================================================================

// Ambil data dari rekap
$uang_masuk  = (float) $rekap['masuk'];
$total_beban = (float) $rekap['beban'];
$total_omzet = (float) $rekap['omzet'];
$total_hpp   = (float) $rekap['hpp'];

// Hitung Saldo Akun (Sama seperti sebelumnya)
$val_kas       = $uang_masuk - $total_beban;
$val_piutang   = $total_omzet - $uang_masuk;
$val_penjualan = $total_omzet;
$val_hpp       = $total_hpp;
$val_beban     = $total_beban;

// Modal Penyeimbang
$val_modal = ($val_kas + $val_piutang + $val_persediaan + $val_hpp + $val_beban) - $val_penjualan;

// =========================================================================
// 3. DATA ARRAY UNTUK TAMPILAN
// =========================================================================
$akun = [
    ['kode'=>'1-1001', 'nama'=>'Kas Toko',            'posisi'=>'D', 'nominal'=>$val_kas],
    ['kode'=>'1-1002', 'nama'=>'Piutang Usaha',       'posisi'=>'D', 'nominal'=>$val_piutang],
    ['kode'=>'1-1003', 'nama'=>'Persediaan Barang',   'posisi'=>'D', 'nominal'=>$val_persediaan],
    ['kode'=>'3-1000', 'nama'=>'Modal Pemilik',       'posisi'=>'K', 'nominal'=>$val_modal],
    ['kode'=>'4-1000', 'nama'=>'Pendapatan Penjualan','posisi'=>'K', 'nominal'=>$val_penjualan],
    ['kode'=>'5-1000', 'nama'=>'Harga Pokok (HPP)',   'posisi'=>'D', 'nominal'=>$val_hpp],
    ['kode'=>'6-1000', 'nama'=>'Beban Operasional',   'posisi'=>'D', 'nominal'=>$val_beban],
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neraca Saldo | Addinta Printing</title>
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
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: white; overflow: hidden; max-width: 900px; margin: 0 auto; }
        .card-header-custom { padding: 18px 25px; border-bottom: 1px solid #f1f5f9; background: white; }
        
        .table-custom th { border-bottom: 2px solid #e9ecef; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; padding: 15px; background-color: #f8fafc; }
        .table-custom td { vertical-align: middle; border-bottom: 1px dashed #f1f3f5; padding: 12px 15px; font-size: 0.9rem; }
        .table-hover tbody tr:hover { background-color: #f8fafc; }
        
        /* Number Formatting */
        .num { text-align: right; font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; letter-spacing: 0.5px; }
        
        /* Footer Total */
        .row-total td { background-color: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1 !important; border-bottom: none; padding: 15px; }

        @media print { 
            .no-print { display: none !important; } 
            .sidebar { display: none !important; }
            body { font-size: 12px; -webkit-print-color-adjust: exact; print-color-adjust: exact; background: white; }
            .main-content { padding: 0; width: 100%; }
            .card-custom { box-shadow: none; border: none; max-width: 100%; }
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-list-alt text-primary me-2"></i> Neraca Saldo</h4>
                <p class="text-muted small mb-0 mt-1">Laporan posisi saldo setiap akun buku besar.</p>
            </div>
            
            <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0">
                    <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                        <i class="fas fa-calendar-day text-muted me-2 small"></i>
                        <span class="text-muted small fw-medium me-2">Per Tanggal:</span>
                        <input type="date" name="tgl_akhir" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-bold text-dark" value="<?= $tgl_akhir ?>" onchange="this.form.submit()">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1">Tampilkan</button>
                    <div class="vr mx-2 opacity-25"></div>
                    <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak</button>
                </form>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1 fw-bold text-dark text-uppercase" style="letter-spacing: 1px;">Neraca Saldo</h5>
                    <div class="text-muted small">Posisi per tanggal: <strong class="text-dark"><?= date('d F Y', strtotime($tgl_akhir)) ?></strong></div>
                </div>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" width="15%">Kode Akun</th>
                                <th>Nama Akun</th>
                                <th class="text-end" width="25%">Debit</th>
                                <th class="text-end" width="25%">Kredit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_d = 0; 
                            $total_k = 0;

                            foreach($akun as $row):
                                $d = ($row['posisi'] == 'D') ? $row['nominal'] : 0;
                                $k = ($row['posisi'] == 'K') ? $row['nominal'] : 0;
                                $total_d += $d;
                                $total_k += $k;
                            ?>
                            <tr>
                                <td class="text-center fw-medium font-monospace text-secondary small"><?= $row['kode'] ?></td>
                                <td class="fw-medium text-dark"><?= $row['nama'] ?></td>
                                <td class="num text-primary"><?= ($d != 0) ? number_format($d, 0, ',', '.') : '-' ?></td>
                                <td class="num text-success"><?= ($k != 0) ? number_format($k, 0, ',', '.') : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="row-total">
                                <td colspan="2" class="text-end text-uppercase pe-4">Total Keseimbangan</td>
                                <td class="num text-dark fs-6"><?= number_format($total_d, 0, ',', '.') ?></td>
                                <td class="num text-dark fs-6"><?= number_format($total_k, 0, ',', '.') ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="card-footer bg-white border-0 pb-4 pt-3 px-4 text-center">
                <?php 
                $selisih = abs($total_d - $total_k);
                if($selisih < 100): 
                ?>
                    <div class="d-inline-flex align-items-center bg-success bg-opacity-10 text-success px-4 py-2 rounded-pill fw-bold" style="border: 1px solid rgba(25, 135, 84, 0.2);">
                        <i class="fas fa-check-circle me-2 fa-lg"></i> STATUS: BALANCE (SEIMBANG)
                    </div>
                <?php else: ?>
                    <div class="d-inline-flex align-items-center bg-danger bg-opacity-10 text-danger px-4 py-2 rounded-pill fw-bold" style="border: 1px solid rgba(220, 53, 69, 0.2);">
                        <i class="fas fa-exclamation-triangle me-2 fa-lg"></i> TIDAK BALANCE (Selisih: Rp <?= number_format($selisih) ?>)
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4 mb-5 no-print opacity-50">
            <small class="text-muted fst-italic"><i class="fas fa-robot me-1"></i> Generated by Sistem Addinta Printing - <?= date('d M Y, H:i') ?></small>
        </div>

    </div>

</body>
</html>