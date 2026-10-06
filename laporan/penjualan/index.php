<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// SET TIMEZONE
date_default_timezone_set('Asia/Jakarta');

// --- 1. SETTING FILTER ---
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$limit = 50; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// --- 2. HITUNG RINGKASAN (TOTAL KESELURUHAN) ---
// Menggunakan tabel 'penjualan_item'
$sql_summary = "SELECT 
                    COUNT(DISTINCT no_penjualan) as total_trx,
                    SUM((harga_jual * jumlah) - diskon) as grand_omzet,
                    SUM(((harga_jual * jumlah) - diskon) - (harga_beli_bersih * jumlah)) as grand_laba
                FROM penjualan_item
                WHERE tgl_penjualan BETWEEN :awal AND :akhir";

$stmt_sum = $pdo->prepare($sql_summary);
$stmt_sum->execute(['awal' => $tgl_awal, 'akhir' => $tgl_akhir]);
$summary = $stmt_sum->fetch(PDO::FETCH_ASSOC);

$sum_trx    = $summary['total_trx'] ?? 0;
$sum_omzet  = $summary['grand_omzet'] ?? 0;
$sum_laba   = $summary['grand_laba'] ?? 0;
$total_pages = ceil($sum_trx / $limit);
if($total_pages < 1) $total_pages = 1;

// --- 3. AMBIL DATA PER NOTA (GROUP BY no_penjualan) ---
// Kita kelompokkan berdasarkan no_penjualan
$sql_data = "SELECT 
                no_penjualan,
                tgl_penjualan,
                kode_pelanggan,
                keterangan,
                pelunasan,
                SUM((harga_jual * jumlah) - diskon) as hitung_omzet,
                SUM(((harga_jual * jumlah) - diskon) - (harga_beli_bersih * jumlah)) as hitung_laba
             FROM penjualan_item
             WHERE tgl_penjualan BETWEEN :awal AND :akhir 
             GROUP BY no_penjualan
             ORDER BY tgl_penjualan DESC, no_penjualan DESC 
             LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql_data);
$stmt->bindValue(':awal', $tgl_awal);
$stmt->bindValue(':akhir', $tgl_akhir);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$laporan = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan | Addinta Printing</title>
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
        
        /* Cards & Stats */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: white; transition: transform 0.2s; }
        .stat-card { padding: 20px; border-radius: 16px; position: relative; overflow: hidden; color: white; border: none; }
        .stat-icon { position: absolute; right: -10px; bottom: -15px; font-size: 5rem; opacity: 0.15; transform: rotate(-10deg); }
        
        /* Table Custom */
        .table-custom th { border-bottom: 2px solid #e9ecef; color: #6c757d; font-weight: 600; text-transform: uppercase; font-size: 0.80rem; padding: 12px 15px; background-color: #f8f9fa; }
        .table-custom td { vertical-align: middle; border-bottom: 1px solid #f1f3f5; padding: 12px 15px; font-size: 0.9rem; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        
        /* Badge Custom */
        .badge-pastel-success { background-color: #e6f4ea; color: #1e8e3e; }
        .badge-pastel-danger { background-color: #fce8e6; color: #d93025; }
        .badge-pastel-info { background-color: #e8f0fe; color: #1a73e8; }
        .badge-pastel-warning { background-color: #fef0cd; color: #b06000; }

        /* Pagination Custom */
        .pagination { flex-wrap: wrap; justify-content: center; margin-bottom: 0; }
        .page-item .page-link { border: none; color: #6c757d; font-weight: 500; margin: 0 3px; border-radius: 8px; }
        .page-item.active .page-link { background-color: #0d6efd; color: white; box-shadow: 0 2px 5px rgba(13,110,253,0.3); }
        
        @media print { 
            .no-print { display: none !important; } 
            .sidebar { display: none !important; }
            .main-content { padding: 0; width: 100%; }
            .card-custom { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <?php include '../../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-chart-bar text-primary me-2"></i> Laporan Penjualan</h4>
                <p class="text-muted small mb-0 mt-1">Rekapitulasi transaksi, omzet, dan laba bersih.</p>
            </div>
            
            <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0">
                    <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                        <i class="fas fa-calendar-alt text-muted me-2 small"></i>
                        <input type="date" name="tgl_awal" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium" value="<?= $tgl_awal ?>" onchange="this.form.submit()">
                        <span class="mx-2 text-muted small">s/d</span>
                        <input type="date" name="tgl_akhir" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium" value="<?= $tgl_akhir ?>" onchange="this.form.submit()">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1"><i class="fas fa-filter me-1"></i> Filter</button>
                    <a href="index.php" class="btn btn-light btn-sm rounded-pill text-muted px-3 border"><i class="fas fa-sync-alt"></i></a>
                    <div class="vr mx-2 opacity-25"></div>
                    <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak</button>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card bg-primary shadow-sm h-100">
                    <i class="fas fa-wallet stat-icon"></i>
                    <h6 class="text-white-50 text-uppercase fw-bold small mb-1">Total Omzet</h6>
                    <h3 class="fw-bold mb-0">Rp <?= number_format($sum_omzet, 0, ',', '.') ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-success shadow-sm h-100">
                    <i class="fas fa-chart-line stat-icon"></i>
                    <h6 class="text-white-50 text-uppercase fw-bold small mb-1">Total Laba Bersih</h6>
                    <h3 class="fw-bold mb-0">Rp <?= number_format($sum_laba, 0, ',', '.') ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-white border shadow-sm h-100" style="color: #1e293b;">
                    <i class="fas fa-receipt stat-icon text-muted" style="opacity: 0.05;"></i>
                    <h6 class="text-muted text-uppercase fw-bold small mb-1">Total Transaksi</h6>
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($sum_trx) ?> <span class="fs-6 text-muted fw-normal">Nota</span></h3>
                </div>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center rounded-top-4">
                <h6 class="mb-0 fw-bold text-dark">Data Transaksi (<?= date('d M Y', strtotime($tgl_awal)) ?> - <?= date('d M Y', strtotime($tgl_akhir)) ?>)</h6>
                <span class="badge bg-light text-dark border shadow-sm">Hal <?= $page ?> dari <?= $total_pages ?></span>
            </div>
            
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" width="8%">No Nota</th>
                            <th class="text-center">Tanggal</th>
                            <th>Pelanggan</th>
                            <th class="text-center">Metode</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Omzet</th>
                            <th class="text-end">Modal (HPP)</th>
                            <th class="text-end">Laba</th>
                            <th class="text-center no-print">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($laporan as $row): 
                            $omzet = $row['hitung_omzet'];
                            $laba  = $row['hitung_laba'];
                            $modal = $omzet - $laba; 
                            
                            $metode = 'CASH'; 
                            $bg = 'badge-pastel-success';
                            
                            if (!empty($row['keterangan'])) {
                                if (preg_match('/\{\{(.*?):/', $row['keterangan'], $m)) {
                                    $metode = strtoupper($m[1]); 
                                    if(in_array($metode, ['QRIS','TRANSFER','DEBIT'])) $bg = 'badge-pastel-info';
                                } elseif ($row['pelunasan'] == 'N') {
                                    $metode = 'UTANG'; $bg = 'badge-pastel-danger';
                                }
                            } else if ($row['pelunasan'] == 'N') {
                                 $metode = 'UTANG'; $bg = 'badge-pastel-danger';
                            }
                        ?>
                        <tr>
                            <td class="text-center fw-bold text-primary">#<?= $row['no_penjualan'] ?></td>
                            <td class="text-center text-muted"><?= date('d M y', strtotime($row['tgl_penjualan'])) ?></td>
                            <td class="fw-medium text-dark"><?= $row['kode_pelanggan'] ?></td>
                            <td class="text-center"><span class="badge <?= $bg ?> px-2 py-1"><?= $metode ?></span></td>
                            <td class="text-center">
                                <?= $row['pelunasan'] == 'Y' 
                                    ? '<span class="badge badge-pastel-success px-2 py-1"><i class="fas fa-check me-1"></i> LUNAS</span>' 
                                    : '<span class="badge badge-pastel-warning px-2 py-1"><i class="fas fa-clock me-1"></i> BON</span>' 
                                ?>
                            </td>
                            <td class="text-end fw-bold text-dark">Rp <?= number_format($omzet, 0, ',', '.') ?></td>
                            <td class="text-end text-muted small">Rp <?= number_format($modal, 0, ',', '.') ?></td>
                            <td class="text-end fw-bold <?= $laba > 0 ? 'text-success' : 'text-danger' ?>">Rp <?= number_format($laba, 0, ',', '.') ?></td>
                            <td class="text-center no-print">
                                <a href="../../transaksi/penjualan/cetak_nota.php?id=<?= $row['no_penjualan'] ?>" target="_blank" class="btn btn-sm btn-light border text-primary rounded-3 shadow-sm px-2 py-1" title="Cetak Nota">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($laporan)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 text-light"></i><br>
                                Data transaksi tidak ditemukan pada periode ini.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white no-print py-3 border-top-0 rounded-bottom-4">
                <nav>
                    <ul class="pagination">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link shadow-sm border bg-light" href="?tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                        </li>

                        <?php 
                        // Logika limit nomor pagination agar tidak terlalu panjang
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        if ($start > 1) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>';
                        
                        for ($i = $start; $i <= $end; $i++): 
                        ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link shadow-sm <?= ($i != $page) ? 'bg-light border' : '' ?>" href="?tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($end < $total_pages) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>'; ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link shadow-sm border bg-light" href="?tgl_awal=<?= $tgl_awal ?>&tgl_akhir=<?= $tgl_akhir ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            
        </div>
    </div>

</body>
</html>
