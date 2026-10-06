<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

// PERBAIKAN PATH DATABASE (Naik 1 level)
require_once '../config/database.php';

// --- 1. FILTER PERIODE ---
$tgl_awal  = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$tgl_akhir = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

// --- 2. QUERY UANG MASUK (OMZET PENJUALAN) ---
// Mengambil total kotor penjualan
$sql_sales = "SELECT COALESCE(SUM(total_omzet), 0) as total_omzet
              FROM penjualan 
              WHERE tgl_penjualan BETWEEN ? AND ?";

$stmt_sales = $pdo->prepare($sql_sales);
$stmt_sales->execute([$tgl_awal, $tgl_akhir]);
$omzet = $stmt_sales->fetchColumn(); 

// --- 3. QUERY UANG KELUAR (OPERASIONAL & ASET) ---
// Mengambil data dari tabel pengeluaran_non_stok
// Ini otomatis mencakup Listrik, Gaji, dan BELANJA ASET (Komputer dll) yang baru diinput
$sql_biaya = "SELECT k.nama_kategori, SUM(p.jumlah) as total
              FROM pengeluaran_non_stok p
              LEFT JOIN kategori_pengeluaran k ON p.kode_kategori_pengeluaran = k.kode_kategori
              WHERE p.tanggal BETWEEN ? AND ?
              GROUP BY k.nama_kategori
              ORDER BY k.nama_kategori ASC";

$stmt_biaya = $pdo->prepare($sql_biaya);
$stmt_biaya->execute([$tgl_awal, $tgl_akhir]);
$list_biaya = $stmt_biaya->fetchAll(PDO::FETCH_ASSOC);

$total_pengeluaran_lain = 0;
foreach($list_biaya as $b) {
    $total_pengeluaran_lain += $b['total'];
}

// --- 4. QUERY BELANJA STOK (KULAKAN BARANG) ---
$sql_stok = "SELECT COALESCE(SUM(total), 0) as total_beli 
             FROM pembelian 
             WHERE tgl_pembelian BETWEEN ? AND ?";

$stmt_stok = $pdo->prepare($sql_stok);
$stmt_stok->execute([$tgl_awal, $tgl_akhir]);
$total_stok = $stmt_stok->fetchColumn();

// --- 5. HITUNG SISA KAS BERSIH (NET CASH) ---
// Rumus: Omzet - (Biaya Ops + Aset + Stok)
$sisa_kas = $omzet - ($total_pengeluaran_lain + $total_stok);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Arus Kas | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Report Paper Style */
        .report-paper {
            background: white;
            padding: 50px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            max-width: 850px;
            margin: 0 auto;
            border-top: 6px solid #0d6efd;
        }

        .report-header { border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 30px; }
        
        /* Table Report */
        .table-report { width: 100%; font-size: 0.95rem; color: #334155; }
        .table-report td { padding: 10px 0; border-bottom: 1px dashed #e2e8f0; vertical-align: middle; }
        .table-report tr:last-child td { border-bottom: none; }
        
        .section-title { font-weight: 700; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px; margin-top: 15px; }
        .indent { padding-left: 25px !important; }
        
        .sub-total { font-weight: 700; border-top: 2px solid #cbd5e1 !important; }
        .grand-total-row td { 
            font-weight: 800; 
            font-size: 1.25rem; 
            border-top: 2px solid #1e293b !important; 
            border-bottom: 2px solid #1e293b !important; 
            padding: 15px 0 !important; 
            margin-top: 20px;
        }

        .text-surplus { color: #16a34a; }
        .text-deficit { color: #dc2626; }
        
        /* Print Styles */
        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none !important; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .main-content { padding: 0; width: 100%; background: white; }
            .report-paper { box-shadow: none; border: none; margin: 0; padding: 20px 0; width: 100%; max-width: 100%; }
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-file-invoice-dollar text-primary me-2"></i> Laporan Arus Kas</h4>
                <p class="text-muted small mb-0 mt-1">Ringkasan pemasukan, pengeluaran, dan sisa kas bersih.</p>
            </div>
            
            <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0">
                    <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                        <i class="fas fa-calendar-alt text-muted me-2 small"></i>
                        <input type="date" name="start" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_awal ?>" required onchange="this.form.submit()">
                        <span class="mx-2 text-muted small">s/d</span>
                        <input type="date" name="end" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_akhir ?>" required onchange="this.form.submit()">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1"><i class="fas fa-filter me-1"></i> Filter</button>
                    <div class="vr mx-2 opacity-25"></div>
                    <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak Dokumen</button>
                </form>
            </div>
        </div>

        <div class="report-paper">
            
            <div class="report-header text-center">
                <h3 class="fw-bold text-uppercase mb-1" style="color: #0f172a;">LAPORAN ARUS KAS</h3>
                <p class="text-muted mb-0 small">Periode: <strong><?= date('d M Y', strtotime($tgl_awal)) ?></strong> s/d <strong><?= date('d M Y', strtotime($tgl_akhir)) ?></strong></p>
            </div>

            <table class="table-report">
                
                <tr>
                    <td colspan="2" class="section-title text-success"><i class="fas fa-arrow-down-circle me-2"></i>1. Pemasukan (Cash In)</td>
                </tr>
                <tr>
                    <td class="indent">Penjualan Toko (Total Omzet)</td>
                    <td class="text-end text-success fw-medium">Rp <?= number_format($omzet, 0, ',', '.') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold indent">Total Pemasukan</td>
                    <td class="text-end fw-bold sub-total text-success">Rp <?= number_format($omzet, 0, ',', '.') ?></td>
                </tr>
                
                <tr><td colspan="2" style="height: 15px; border:none;"></td></tr>

                <tr>
                    <td colspan="2" class="section-title text-danger"><i class="fas fa-arrow-up-circle me-2"></i>2. Pengeluaran Operasional & Aset</td>
                </tr>
                <?php if(count($list_biaya) > 0): ?>
                    <?php foreach($list_biaya as $row): ?>
                    <tr>
                        <td class="indent"><?= htmlspecialchars($row['nama_kategori'] ?? 'Lain-lain') ?></td>
                        <td class="text-end text-muted">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td class="indent text-muted fst-italic">Belum ada pengeluaran tercatat.</td>
                        <td class="text-end text-muted">Rp 0</td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="fw-bold indent">Total Pengeluaran Operasional</td>
                    <td class="text-end fw-bold sub-total text-danger">(Rp <?= number_format($total_pengeluaran_lain, 0, ',', '.') ?>)</td>
                </tr>

                <tr><td colspan="2" style="height: 15px; border:none;"></td></tr>

                <tr>
                    <td colspan="2" class="section-title text-warning" style="color: #b45309 !important;"><i class="fas fa-box-open me-2"></i>3. Belanja Stok (Restock)</td>
                </tr>
                <tr>
                    <td class="indent">Pembelian Barang Dagangan</td>
                    <td class="text-end text-muted">Rp <?= number_format($total_stok, 0, ',', '.') ?></td>
                </tr>
                <tr>
                    <td class="fw-bold indent">Total Belanja Stok</td>
                    <td class="text-end fw-bold sub-total text-danger">(Rp <?= number_format($total_stok, 0, ',', '.') ?>)</td>
                </tr>

                <tr><td colspan="2" style="height: 30px; border:none;"></td></tr>

                <tr class="grand-total-row <?= $sisa_kas >= 0 ? 'text-surplus' : 'text-deficit' ?>">
                    <td class="text-uppercase">
                        <i class="fas <?= $sisa_kas >= 0 ? 'fa-wallet' : 'fa-exclamation-triangle' ?> me-2"></i>
                        <?= $sisa_kas >= 0 ? 'Surplus Kas (Sisa Bersih)' : 'Defisit Kas (Minus)' ?>
                    </td>
                    <td class="text-end">
                        <?= $sisa_kas < 0 ? '-' : '' ?>Rp <?= number_format(abs($sisa_kas), 0, ',', '.') ?>
                    </td>
                </tr>

            </table>

            <div class="mt-5 pt-4 text-center">
                <div class="row">
                    <div class="col-6 offset-6">
                        <p class="mb-5 text-muted small">Diverifikasi & Diketahui Oleh,</p>
                        <p class="fw-bold text-dark text-decoration-underline mb-0" style="font-size: 1.1rem;"><?= $_SESSION['nama_lengkap'] ?? 'Pemilik Toko' ?></p>
                        <small class="text-muted">Manajemen Keuangan</small>
                    </div>
                </div>
                <div class="mt-5 text-start border-top pt-3 opacity-50">
                    <small class="text-muted"><i class="fas fa-print me-1"></i> Dicetak oleh sistem pada: <?= date('d M Y, H:i') ?> WIB</small>
                </div>
            </div>

        </div>
    </div>

</body>
</html>