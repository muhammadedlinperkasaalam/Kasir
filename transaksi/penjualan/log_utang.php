<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// --- 1. SETUP PAGINATION & FILTER ---
$tgl_awal  = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$tgl_akhir = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

$limit = 20; // Batasi 20 kartu per halaman agar ringan
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// --- 2. QUERY HITUNG TOTAL DATA (Untuk Pagination) ---
$sql_count = "SELECT COUNT(*) 
              FROM penjualan p
              WHERE p.tgl_penjualan BETWEEN ? AND ? 
              AND (p.pelunasan = 'N' OR p.keterangan LIKE '%|%')";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute([$tgl_awal, $tgl_akhir]);
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// --- 3. QUERY DATA UTAMA (OPTIMIZED) ---
// Menggunakan total_omzet (Kolom yg sudah kita buat sebelumnya)
// Hapus subquery SUM(...) yang bikin berat
$sql = "SELECT p.*, pl.nama_pelanggan, 
        p.total_omzet as grand_total 
        FROM penjualan p
        JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
        WHERE p.tgl_penjualan BETWEEN :awal AND :akhir
        AND (p.pelunasan = 'N' OR p.keterangan LIKE '%|%') 
        ORDER BY p.tgl_penjualan DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':awal', $tgl_awal);
$stmt->bindValue(':akhir', $tgl_akhir);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$data = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Log Pembayaran Utang</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .timeline-item { border-left: 2px solid #dee2e6; padding-left: 15px; margin-bottom: 10px; position: relative; }
        .timeline-item::before { content: ''; position: absolute; left: -6px; top: 5px; width: 10px; height: 10px; border-radius: 50%; background: #0d6efd; }
        .card-timeline { transition: transform 0.2s; }
        .card-timeline:hover { transform: translateY(-3px); }
    </style>
</head>
<body class="bg-light">
    
    <nav class="navbar navbar-dark bg-secondary mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="../../index.php"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
            <span class="navbar-text text-white fw-bold">Log & Riwayat Cicilan</span>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <h5 class="mb-0 text-primary"><i class="fas fa-list-alt me-2"></i>Riwayat Pembayaran Utang</h5>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Tgl Transaksi</span>
                            <input type="date" name="start" class="form-control" value="<?= $tgl_awal ?>">
                            <span class="input-group-text">-</span>
                            <input type="date" name="end" class="form-control" value="<?= $tgl_akhir ?>">
                            <button class="btn btn-primary" type="submit">Filter</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="card-body">
                
                <?php if(count($data) == 0): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-clipboard-check fa-3x mb-3 opacity-25"></i>
                        <p>Tidak ada riwayat utang pada periode ini.</p>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <?php foreach($data as $row): 
                        $grand_total = $row['grand_total']; // Ambil dari kolom tabel, bukan hitung ulang
                        $sisa = $grand_total - $row['uang_bayar'];
                        
                        $status_class = ($row['pelunasan'] == 'Y') ? 'border-success' : 'border-danger';
                        $badge_status = ($row['pelunasan'] == 'Y') ? '<span class="badge bg-success">LUNAS</span>' : '<span class="badge bg-danger">BELUM LUNAS</span>';
                        
                        // Parse History dari Text Keterangan
                        preg_match_all('/\|\s([0-9\/]+)\s([A-Z]+):([0-9\.,]+)/', $row['keterangan'], $cicilan_matches, PREG_SET_ORDER);
                        
                        $total_cicilan = 0;
                        $list_cicilan = [];
                        
                        foreach($cicilan_matches as $c) {
                            $nominal_bersih = preg_replace('/[^0-9]/', '', $c[3]);
                            $nominal = (float)$nominal_bersih;
                            $total_cicilan += $nominal;
                            $list_cicilan[] = ['tgl' => $c[1], 'metode' => $c[2], 'nominal' => $nominal];
                        }

                        // Hitung DP Awal (Total Bayar - Total Cicilan di Log)
                        $dp_awal = $row['uang_bayar'] - $total_cicilan;
                        if($dp_awal < 0) $dp_awal = 0; 

                        // Tebak Metode DP dari teks awal sebelum tanda "|"
                        $metode_dp = "CASH (DP)";
                        $first_part = explode('|', $row['keterangan'])[0]; 
                        if(stripos($first_part, 'transfer') !== false) $metode_dp = "TRANSFER (DP)";
                        if(stripos($first_part, 'qris') !== false) $metode_dp = "QRIS (DP)";
                        if(stripos($first_part, 'debit') !== false) $metode_dp = "DEBIT (DP)";
                    ?>

                    <div class="col-md-6 mb-4">
                        <div class="card h-100 shadow-sm card-timeline <?= $status_class ?>" style="border-top-width: 4px;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <h6 class="fw-bold text-primary">#<?= $row['no_penjualan'] ?></h6>
                                    <?= $badge_status ?>
                                </div>
                                <div class="row mb-3" style="font-size: 0.9rem;">
                                    <div class="col-6">
                                        <div class="text-muted small">Pelanggan</div>
                                        <div class="fw-bold"><?= $row['nama_pelanggan'] ?></div>
                                    </div>
                                    <div class="col-6 text-end">
                                        <div class="text-muted small">Total Tagihan</div>
                                        <div class="fw-bold">Rp <?= number_format($grand_total, 0, ',', '.') ?></div>
                                    </div>
                                </div>

                                <div class="bg-light p-3 rounded small">
                                    <h6 class="text-muted fw-bold border-bottom pb-2 mb-2" style="font-size:0.8rem">Riwayat Pembayaran:</h6>
                                    
                                    <?php if($dp_awal > 0): ?>
                                    <div class="timeline-item">
                                        <div class="d-flex justify-content-between">
                                            <span>
                                                <span class="text-muted"><?= date('d/m', strtotime($row['tgl_penjualan'])) ?></span> 
                                                <strong class="ms-1"><?= $metode_dp ?></strong>
                                            </span>
                                            <span class="text-success fw-bold">+ <?= number_format($dp_awal, 0, ',', '.') ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php foreach($list_cicilan as $cicil): ?>
                                    <div class="timeline-item">
                                        <div class="d-flex justify-content-between">
                                            <span>
                                                <span class="text-muted"><?= $cicil['tgl'] ?></span> 
                                                <strong class="ms-1"><?= $cicil['metode'] ?></strong>
                                            </span>
                                            <span class="text-success fw-bold">+ <?= number_format($cicil['nominal'], 0, ',', '.') ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="mt-3 d-flex justify-content-between align-items-center border-top pt-2">
                                    <small class="text-muted">Total Masuk: <strong>Rp <?= number_format($row['uang_bayar'], 0, ',', '.') ?></strong></small>
                                    <?php if($sisa > 0): ?>
                                        <div class="text-danger fw-bold">Sisa: Rp <?= number_format($sisa, 0, ',', '.') ?></div>
                                    <?php else: ?>
                                        <div class="text-success fw-bold"><i class="fas fa-check-double"></i> Lunas</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&page=<?= $page - 1 ?>">Previous</a>
                        </li>
                        <li class="page-item active"><span class="page-link">Hal <?= $page ?> dari <?= $total_pages ?></span></li>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&page=<?= $page + 1 ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>