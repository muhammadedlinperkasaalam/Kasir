<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- FILTER PERIODE ---
$tgl_awal  = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$tgl_akhir = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

// ==========================================
// 1. TOP PRODUK (Paling Laku)
// ==========================================
$sql_produk = "SELECT b.nama_barang, SUM(pi.jumlah) as total_qty
               FROM penjualan_item pi
               JOIN barang b ON pi.kode_barang = b.kode_barang
               JOIN penjualan p ON pi.no_penjualan = p.no_penjualan
               WHERE p.tgl_penjualan BETWEEN ? AND ?
               GROUP BY pi.kode_barang
               ORDER BY total_qty DESC LIMIT 10";
$stmt_produk = $pdo->prepare($sql_produk);
$stmt_produk->execute([$tgl_awal, $tgl_akhir]);
$top_produk = $stmt_produk->fetchAll();

// ==========================================
// 2. TOP CUSTOMER (Paling Sering Belanja)
// ==========================================
$sql_cust = "SELECT pl.nama_pelanggan, pl.no_telepon, COUNT(p.no_penjualan) as frekuensi, SUM(p.total_omzet) as total_belanja
             FROM penjualan p
             JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
             WHERE p.kode_pelanggan != 'UMUM' AND p.tgl_penjualan BETWEEN ? AND ?
             GROUP BY p.kode_pelanggan
             ORDER BY frekuensi DESC LIMIT 10";
$stmt_cust = $pdo->prepare($sql_cust);
$stmt_cust->execute([$tgl_awal, $tgl_akhir]);
$top_cust = $stmt_cust->fetchAll();

// ==========================================
// 3. PELANGGAN TIDUR (1 Bulan - 1 Tahun)
// ==========================================
$sql_tidur = "SELECT 
                pl.nama_pelanggan, 
                pl.no_telepon, 
                MAX(p.tgl_penjualan) as terakhir_belanja,
                DATEDIFF(CURDATE(), MAX(p.tgl_penjualan)) as hari_tidak_belanja
              FROM pelanggan pl
              JOIN penjualan p ON pl.kode_pelanggan = p.kode_pelanggan
              WHERE pl.kode_pelanggan != 'UMUM' 
                -- Triknya disini: Jangan baca data lebih dari 1 tahun yang lalu!
                AND p.tgl_penjualan >= CURDATE() - INTERVAL 365 DAY
              GROUP BY pl.kode_pelanggan
              -- Hindari DATEDIFF di HAVING, gunakan perbandingan tanggal langsung
              HAVING terakhir_belanja <= CURDATE() - INTERVAL 30 DAY
              ORDER BY terakhir_belanja ASC 
              LIMIT 10";
$stmt_tidur = $pdo->query($sql_tidur);
$cust_tidur = $stmt_tidur->fetchAll();

// Modifikasi desain medali agar match dengan tema pastel
function getMedal($i) {
    if($i==0) return '<span class="badge badge-pastel-warning px-2 py-1"><i class="fas fa-medal me-1"></i>1</span>';
    if($i==1) return '<span class="badge bg-light text-secondary border px-2 py-1"><i class="fas fa-medal me-1"></i>2</span>';
    if($i==2) return '<span class="badge badge-pastel-danger px-2 py-1"><i class="fas fa-medal me-1"></i>3</span>';
    return '<span class="badge bg-light text-muted border px-2 py-1">'.($i+1).'</span>';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisa & Peringkat Toko | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }
        
        /* Sidebar Kesesuaian */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Card & Table Custom */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: white; overflow: hidden; height: 100%; display: flex; flex-direction: column; }
        .card-header-custom { padding: 15px 20px; font-weight: 700; border-bottom: 1px solid #f1f5f9; background: white; }
        
        .table-custom { margin-bottom: 0; }
        .table-custom th { border-bottom: 2px solid #e9ecef; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; padding: 12px 15px; background-color: #f8fafc; }
        .table-custom td { vertical-align: middle; border-bottom: 1px dashed #f1f3f5; padding: 12px 15px; font-size: 0.85rem; }
        .table-custom tr:last-child td { border-bottom: none; }
        .table-hover tbody tr:hover { background-color: #f8fafc; }
        
        /* Badge Custom Pastel */
        .badge-pastel-warning { background-color: #fef0cd; color: #b06000; }
        .badge-pastel-danger { background-color: #fce8e6; color: #d93025; }
        .badge-pastel-info { background-color: #e8f0fe; color: #1a73e8; }
        
        /* Custom WA Button */
        .btn-wa { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border-radius: 50%; transition: all 0.2s; }
        .btn-wa:hover { transform: scale(1.1); }

        @media print { 
            .no-print { display: none !important; } 
            .sidebar { display: none !important; }
            body { background: white; font-size: 12px; }
            .main-content { padding: 0; width: 100%; }
            .card-custom { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; }
            .col-lg-4 { width: 33.333% !important; float: left; padding: 0 10px; }
            .container-fluid { padding: 0 !important; }
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="container-fluid px-0">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
                <div>
                    <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-chart-pie text-primary me-2"></i> Analisa & Peringkat Toko</h4>
                    <p class="text-muted small mb-0 mt-1">Pantau produk terlaris, pelanggan setia, dan target *follow-up*.</p>
                </div>
                
                <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                    <form method="GET" class="d-flex align-items-center m-0">
                        <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                            <i class="fas fa-calendar-alt text-muted me-2 small"></i>
                            <input type="date" name="start" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_awal ?>" required onchange="this.form.submit()">
                            <span class="mx-2 text-muted small">s/d</span>
                            <input type="date" name="end" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_akhir ?>" required onchange="this.form.submit()">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1"><i class="fas fa-filter me-1"></i> Analisa</button>
                        <div class="vr mx-2 opacity-25"></div>
                        <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak</button>
                    </form>
                </div>
            </div>

            <div class="row g-4">
                
                <div class="col-lg-4 col-md-12">
                    <div class="card-custom">
                        <div class="card-header-custom text-dark d-flex align-items-center">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                <i class="fas fa-box-open"></i>
                            </div>
                            10 Produk Terlaris
                        </div>
                        <div class="card-body p-0 flex-grow-1">
                            <div class="table-responsive">
                                <table class="table table-custom table-hover">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="15%">#</th>
                                            <th>Nama Barang</th>
                                            <th class="text-end" width="25%">Terjual</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($top_produk as $idx => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= getMedal($idx) ?></td>
                                            <td>
                                                <div class="fw-bold text-dark text-truncate" style="max-width: 160px;"><?= $row['nama_barang'] ?></div>
                                            </td>
                                            <td class="text-end fw-bold text-success">
                                                <?= number_format($row['total_qty']) ?> <i class="fas fa-arrow-up small text-success opacity-50 ms-1"></i>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if(empty($top_produk)): ?>
                                            <tr><td colspan="3" class="text-center py-5 text-muted fst-italic">Belum ada data penjualan produk.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-12">
                    <div class="card-custom border-primary border-opacity-25">
                        <div class="card-header-custom text-dark d-flex align-items-center border-bottom">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                <i class="fas fa-crown"></i>
                            </div>
                            10 Pelanggan Setia
                        </div>
                        <div class="card-body p-0 flex-grow-1">
                            <div class="table-responsive">
                                <table class="table table-custom table-hover">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="15%">#</th>
                                            <th>Pelanggan</th>
                                            <th class="text-end" width="35%">Total Belanja</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($top_cust as $idx => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= getMedal($idx) ?></td>
                                            <td>
                                                <div class="fw-bold text-dark text-truncate" style="max-width: 130px;"><?= $row['nama_pelanggan'] ?></div>
                                                <span class="badge badge-pastel-info border border-info border-opacity-25 mt-1" style="font-size: 0.65rem;">
                                                    <i class="fas fa-receipt me-1"></i> <?= $row['frekuensi'] ?>x Trx
                                                </span>
                                            </td>
                                            <td class="text-end fw-bold text-primary">Rp <?= number_format($row['total_belanja'], 0, ',', '.') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if(empty($top_cust)): ?>
                                            <tr><td colspan="3" class="text-center py-5 text-muted fst-italic">Belum ada pelanggan tercatat.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-12">
                    <div class="card-custom">
                        <div class="card-header-custom text-dark d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div>
                                    <div class="fw-bold">Pelanggan Pasif</div>
                                    <small class="text-muted" style="font-size: 0.65rem; font-weight: normal;">(Absen > 30 Hari)</small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0 flex-grow-1">
                            <div class="table-responsive">
                                <table class="table table-custom table-hover">
                                    <thead>
                                        <tr>
                                            <th>Pelanggan</th>
                                            <th class="text-end">Terakhir</th>
                                            <th class="text-center no-print" width="15%">Sapa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($cust_tidur as $row): 
                                            // Bersihkan nomor WA
                                            $wa = preg_replace('/[^0-9]/', '', $row['no_telepon']);
                                            if(substr($wa, 0, 1) == '0') $wa = '62'.substr($wa, 1);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark text-truncate" style="max-width: 130px;"><?= $row['nama_pelanggan'] ?></div>
                                                <span class="text-danger mt-1 d-inline-block" style="font-size: 0.7rem; font-weight: 600;">
                                                    <i class="fas fa-exclamation-circle me-1"></i> <?= $row['hari_tidak_belanja'] ?> Hari Lalu
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="small fw-medium text-secondary"><?= date('d M', strtotime($row['terakhir_belanja'])) ?></div>
                                                <div class="text-muted" style="font-size: 0.65rem;"><?= date('Y', strtotime($row['terakhir_belanja'])) ?></div>
                                            </td>
                                            <td class="text-center no-print">
                                                <?php if(strlen($wa) > 10): ?>
                                                    <a href="https://wa.me/<?= $wa ?>?text=Halo Kak <?= urlencode($row['nama_pelanggan']) ?>, kami dari Addinta Printing kangen nih! Sudah lama tidak mampir. Barangkali ada kebutuhan cetak, kami siap bantu dengan penawaran menarik lho!" target="_blank" class="btn btn-success btn-wa shadow-sm" title="Sapa via WhatsApp">
                                                        <i class="fab fa-whatsapp"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border" style="font-size: 0.65rem;">No WA</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if(empty($cust_tidur)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-5">
                                                    <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px;">
                                                        <i class="fas fa-smile-beam fa-lg"></i>
                                                    </div>
                                                    <div class="text-muted small">Luar biasa! Tidak ada pelanggan pasif di rentang waktu ini.</div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            
            <div class="mt-4 text-center d-none d-print-block">
                <small class="text-muted">Laporan Analisa Toko | Dicetak pada: <?= date('d M Y, H:i') ?> WIB</small>
            </div>
        </div>
    </div>

</body>
</html>