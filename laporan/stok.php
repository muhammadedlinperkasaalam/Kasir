<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// Filter Tanggal
$tgl_awal  = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$tgl_akhir = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

// LOGIKA HITUNG STOK
// 1. Ambil semua barang
$sql_barang = "SELECT b.*, k.nama_kategori 
               FROM barang b 
               LEFT JOIN kategori k ON b.kode_kategori = k.kode_kategori 
               WHERE b.aktif = 'Y' 
               ORDER BY b.nama_barang ASC";
$stmt_barang = $pdo->query($sql_barang);
$barang = $stmt_barang->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Stok Barang | Addinta Printing</title>
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
        
        /* Cards & Table */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: white; }
        
        .table-custom th { border-bottom: 2px solid #e9ecef; color: #6c757d; font-weight: 600; text-transform: uppercase; font-size: 0.80rem; padding: 15px 12px; background-color: #f8f9fa; }
        .table-custom td { vertical-align: middle; border-bottom: 1px solid #f1f3f5; padding: 12px; font-size: 0.9rem; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        
        /* Custom Highlights */
        .bg-highlight-warning { background-color: rgba(255, 193, 7, 0.05) !important; }
        .bg-highlight-danger { background-color: rgba(220, 53, 69, 0.03) !important; }

        /* Badge Custom Pastel */
        .badge-pastel-success { background-color: #e6f4ea; color: #1e8e3e; }
        .badge-pastel-warning { background-color: #fef0cd; color: #b06000; }
        .badge-pastel-danger { background-color: #fce8e6; color: #d93025; }
        
        @media print { 
            .no-print { display: none !important; } 
            .sidebar { display: none !important; }
            .main-content { padding: 0; width: 100%; }
            .card-custom { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-boxes text-primary me-2"></i> Laporan Mutasi Stok</h4>
                <p class="text-muted small mb-0 mt-1">Pantau pergerakan barang keluar (terjual) dan sisa stok.</p>
            </div>
            
            <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0">
                    <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                        <i class="fas fa-calendar-alt text-muted me-2 small"></i>
                        <input type="date" name="start" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_awal ?>" onchange="this.form.submit()">
                        <span class="mx-2 text-muted small">s/d</span>
                        <input type="date" name="end" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_akhir ?>" onchange="this.form.submit()">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1"><i class="fas fa-filter me-1"></i> Filter</button>
                    <a href="stok.php" class="btn btn-light btn-sm rounded-pill text-muted px-3 border"><i class="fas fa-sync-alt"></i></a>
                    <div class="vr mx-2 opacity-25"></div>
                    <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak</button>
                </form>
            </div>
        </div>

        <div class="alert alert-primary bg-primary bg-opacity-10 border-0 d-flex align-items-center rounded-4 mb-4 no-print shadow-sm">
            <i class="fas fa-info-circle fa-lg text-primary me-3"></i>
            <div>
                <span class="fw-bold text-primary">Informasi:</span> Data <strong>Terjual (Periode Ini)</strong> dihitung berdasarkan struk / tanggal penjualan yang sesuai dengan filter di atas.
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center rounded-top-4">
                <h6 class="mb-0 fw-bold text-dark">Data Stok Barang (<?= date('d M Y', strtotime($tgl_awal)) ?> - <?= date('d M Y', strtotime($tgl_akhir)) ?>)</h6>
            </div>
            
            <div class="table-responsive">
                <table class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" width="5%">No</th>
                            <th width="15%">Kode Barang</th>
                            <th>Nama Barang</th>
                            <th width="15%">Kategori</th>
                            <th class="text-center text-dark" width="12%">Stok Saat Ini</th>
                            <th class="text-center text-dark" width="15%">Terjual (Periode Ini)</th>
                            <th class="text-center" width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach($barang as $row): 
                            $kode = $row['kode_barang'];

                            // A. HITUNG BARANG KELUAR (PENJUALAN)
                            $sql_jual = "SELECT SUM(jumlah) FROM penjualan_item 
                                         WHERE kode_barang = ? 
                                         AND tgl_penjualan BETWEEN ? AND ?";
                            
                            $stmt_jual = $pdo->prepare($sql_jual);
                            $stmt_jual->execute([$kode, $tgl_awal, $tgl_akhir]);
                            $terjual = $stmt_jual->fetchColumn();
                            if(!$terjual) $terjual = 0;

                            // Status Stok
                            $alert = "";
                            if($row['stok'] <= 0) {
                                $alert = '<span class="badge badge-pastel-danger px-2 py-1"><i class="fas fa-times-circle me-1"></i> Habis</span>';
                            } elseif ($row['stok'] <= $row['stok_limit']) {
                                $alert = '<span class="badge badge-pastel-warning px-2 py-1"><i class="fas fa-exclamation-triangle me-1"></i> Menipis</span>';
                            } else {
                                $alert = '<span class="badge badge-pastel-success px-2 py-1"><i class="fas fa-check-circle me-1"></i> Aman</span>';
                            }
                        ?>
                        <tr>
                            <td class="text-center text-muted"><?= $no++ ?></td>
                            <td class="fw-bold small text-muted font-monospace"><?= $row['kode_barang'] ?></td>
                            <td class="fw-medium text-dark"><?= $row['nama_barang'] ?></td>
                            <td><span class="badge bg-light text-secondary border"><?= $row['nama_kategori'] ?? 'Tanpa Kategori' ?></span></td>
                            
                            <td class="text-center fw-bold text-dark bg-highlight-warning">
                                <?= $row['stok'] ?> <span class="small fw-normal text-muted"><?= $row['satuan'] ?></span>
                            </td>

                            <td class="text-center fw-bold bg-highlight-danger">
                                <?php if($terjual > 0): ?>
                                    <span class="text-danger">- <?= $terjual ?> <span class="small fw-normal opacity-75"><?= $row['satuan'] ?></span></span>
                                <?php else: ?>
                                    <span class="text-muted opacity-50">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center"><?= $alert ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($barang)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-box-open fa-3x mb-3 text-light"></i><br>
                                Belum ada data barang.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>