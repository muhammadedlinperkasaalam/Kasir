<?php
session_start();

// 1. Cek Login
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

require_once 'config/database.php';

// ==========================================
// SET ZONA WAKTU INDONESIA (PENTING)
// ==========================================
date_default_timezone_set('Asia/Jakarta');
$tgl_hari_ini = date('Y-m-d');

// ==========================================
// LOGIKA PHP: MENGHITUNG DATA DARI DATABASE (TETAP SAMA)
// ==========================================
$stmt_trx = $pdo->prepare("SELECT COUNT(*) FROM penjualan WHERE DATE(tgl_penjualan) = ?");
$stmt_trx->execute([$tgl_hari_ini]);
$total_trx = $stmt_trx->fetchColumn();

$sql_omset = "SELECT COALESCE(SUM(total_omzet), 0) FROM penjualan WHERE DATE(tgl_penjualan) = ?";
$stmt_omset = $pdo->prepare($sql_omset);
$stmt_omset->execute([$tgl_hari_ini]);
$omset_jual = $stmt_omset->fetchColumn();

$omset_lain = 0;
try {
    $stmt_lain = $pdo->prepare("SELECT COALESCE(SUM(nominal), 0) FROM pemasukan_lain WHERE tgl_pemasukan = ?");
    $stmt_lain->execute([$tgl_hari_ini]);
    $omset_lain = $stmt_lain->fetchColumn();
} catch (Exception $e) {}

$omset_depo = 0;
try {
    $cek_tabel = $pdo->query("SHOW TABLES LIKE 'log_deposit'");
    if($cek_tabel->rowCount() > 0) {
        $stmt_depo = $pdo->prepare("SELECT COALESCE(SUM(nominal), 0) FROM log_deposit WHERE DATE(tgl_deposit) = ?");
        $stmt_depo->execute([$tgl_hari_ini]);
        $omset_depo = $stmt_depo->fetchColumn();
    }
} catch (Exception $e) {}

$omset_hari_ini = $omset_jual + $omset_lain + $omset_depo;

$stmt_alert = $pdo->query("SELECT COUNT(*) FROM barang WHERE stok <= stok_limit AND aktif = 'Y'");
$total_alert = $stmt_alert->fetchColumn();

$sql_piutang = "SELECT COALESCE(SUM(total_omzet - uang_bayar), 0) FROM penjualan WHERE pelunasan = 'N'";
$stmt_piutang = $pdo->query($sql_piutang);
$total_piutang = $stmt_piutang->fetchColumn();

$total_produk = $pdo->query("SELECT COUNT(*) FROM barang WHERE aktif = 'Y'")->fetchColumn();

// Grafik Penjualan
$dates = [];
for ($i = 6; $i >= 0; $i--) {
    $d = new DateTime("-$i days");
    $dates[$d->format('Y-m-d')] = 0;
}
$tgl_obj_awal = new DateTime("-6 days");
$tgl_awal_chart = $tgl_obj_awal->format('Y-m-d');

$sql_chart = "SELECT DATE(tgl_penjualan) as tgl, SUM(total_omzet) as omzet FROM penjualan WHERE DATE(tgl_penjualan) >= ? GROUP BY DATE(tgl_penjualan)";     
$stmt_chart = $pdo->prepare($sql_chart);
$stmt_chart->execute([$tgl_awal_chart]);
while ($row = $stmt_chart->fetch(PDO::FETCH_ASSOC)) {
    if (isset($dates[$row['tgl']])) $dates[$row['tgl']] += (float)$row['omzet'];
}

try {
    $stmt_cl = $pdo->prepare("SELECT tgl_pemasukan as tgl, SUM(nominal) as omzet FROM pemasukan_lain WHERE tgl_pemasukan >= ? GROUP BY tgl_pemasukan");
    $stmt_cl->execute([$tgl_awal_chart]);
    while ($row = $stmt_cl->fetch(PDO::FETCH_ASSOC)) {
        if (isset($dates[$row['tgl']])) $dates[$row['tgl']] += (float)$row['omzet'];
    }
} catch (Exception $e) {}

try {
    $cek = $pdo->query("SHOW TABLES LIKE 'log_deposit'");
    if($cek->rowCount() > 0) {
        $stmt_cd = $pdo->prepare("SELECT DATE(tgl_deposit) as tgl, SUM(nominal) as omzet FROM log_deposit WHERE DATE(tgl_deposit) >= ? GROUP BY DATE(tgl_deposit)");
        $stmt_cd->execute([$tgl_awal_chart]);
        while ($row = $stmt_cd->fetch(PDO::FETCH_ASSOC)) {
            if (isset($dates[$row['tgl']])) $dates[$row['tgl']] += (float)$row['omzet'];
        }
    }
} catch (Exception $e) {}

$labels_tgl = [];
$data_omset = [];
foreach ($dates as $tgl => $val) {
    $labels_tgl[] = date('d/m', strtotime($tgl));
    $data_omset[] = $val;
}

$total_order_aktif = 0;
try {
    $cek_tabel_order = $pdo->query("SHOW TABLES LIKE 'order_pekerjaan'");
    if($cek_tabel_order->rowCount() > 0) {
        $total_order_aktif = $pdo->query("SELECT COUNT(*) FROM order_pekerjaan WHERE status_order IN ('Antri', 'Proses')")->fetchColumn();
    }
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard | POS System</title>
    
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script> 

    <style>
        body { background-color: #f8f9fa; font-family: 'Inter', 'Segoe UI', sans-serif; }
        
        /* Layout */
        .wrapper { display: flex; width: 100%; align-items: stretch; }
        .main-content { flex-grow: 1; min-width: 0; min-height: 100vh; display: flex; flex-direction: column; }
        
        /* Sidebar custom hover */
        .hover-bg-light:hover { background-color: #f8f9fa; }
        .nav-link.active { background-color: #eef2ff !important; color: #1d4ed8 !important; border-radius: 8px; }
        .nav-link:hover:not(.active) { background-color: #f3f4f6; border-radius: 8px; }
        
        /* Header */
        .header-top { background-color: transparent; padding: 1.25rem 2rem; border-bottom: 1px solid #e5e7eb; background: #fff; }
        
        /* Cards & Dashboard Items */
        .card-stat { border: 1px solid #f1f1f1; border-radius: 12px; transition: transform 0.2s; position: relative; overflow: hidden; background: #fff;}
        .card-stat:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        .icon-box { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        
        .action-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 15px 10px; text-align: center; color: #4b5563; text-decoration: none;
            transition: all 0.2s ease; height: 100%; display: flex; flex-direction: column;
            align-items: center; justify-content: center; position: relative;
        }
        .action-card:hover { border-color: #3b82f6; color: #1d4ed8; background-color: #eff6ff; }
        .action-card i { font-size: 1.5rem; margin-bottom: 8px; }
        .action-card span { font-weight: 600; font-size: 0.8rem; line-height: 1.2; }
        .badge-key { position: absolute; top: 8px; right: 8px; font-size: 0.6rem; background: #f3f4f6; color: #6b7280; padding: 2px 5px; border-radius: 4px; font-weight:bold;}

        /* Table */
        .table-stok td, .table-stok th { padding: 10px; font-size: 0.85rem; vertical-align: middle; }
        
        @media (max-width: 768px) {
            .wrapper { flex-direction: column; }
            .bg-white.border-end { width: 100% !important; height: auto !important; position: static !important; display: none !important; /* Manage responsive sidebar behavior */ }
            .header-top { padding: 1rem; }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <header class="header-top d-flex justify-content-between align-items-center sticky-top shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light me-3 d-md-none"><i class="fas fa-bars"></i></button>
                <div>
                    <h4 class="mb-0 fw-bold">Dashboard</h4>
                    <small class="text-muted">Ringkasan Sistem Toko</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center">
                <button class="btn btn-light rounded-circle text-muted p-2 me-3" style="width: 40px; height: 40px;">
                    <i class="fas fa-bell"></i>
                </button>
                
                <div class="d-none d-md-block bg-light rounded px-3 py-1 me-3 border text-center">
                    <small class="text-muted d-block" style="font-size: 0.65rem; font-weight:600;">Waktu</small>
                    <span id="realtimeClock" class="fw-bold" style="font-size: 0.85rem;">00:00:00 WIB</span>
                </div>
                
                <div class="d-flex align-items-center border px-3 py-1 bg-light rounded-pill">
                    <img src="assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle me-2" style="width: 32px; height: 32px;">
                    <div class="d-flex flex-column lh-1">
                        <span class="fw-bold fs-6 text-dark"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <small class="text-muted" style="font-size: 0.7rem;"><?= ucfirst($_SESSION['level'] ?? 'KASIR') ?></small>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid p-4">
            
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card card-stat shadow-sm h-100">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-box bg-success bg-opacity-10 text-success me-3">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div>
                                <small class="text-muted fw-bold text-uppercase" style="font-size:0.7rem;">Omset Hari Ini</small>
                                <h4 class="mb-0 fw-bold text-dark mt-1">Rp <?= number_format($omset_hari_ini ?? 0, 0, ',', '.') ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-stat shadow-sm h-100">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-box bg-danger bg-opacity-10 text-danger me-3">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div>
                                <small class="text-muted fw-bold text-uppercase" style="font-size:0.7rem;">Piutang</small>
                                <h4 class="mb-0 fw-bold text-dark mt-1">Rp <?= number_format($total_piutang ?? 0, 0, ',', '.') ?></h4>
                            </div>
                            <a href="transaksi/penjualan/daftar_piutang.php" class="stretched-link"></a>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-stat shadow-sm h-100">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-box bg-primary bg-opacity-10 text-primary me-3">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div>
                                <small class="text-muted fw-bold text-uppercase" style="font-size:0.7rem;">Transaksi Hari Ini</small>
                                <h4 class="mb-0 fw-bold text-dark mt-1"><?= number_format($total_trx) ?> <span class="fs-6 fw-normal text-muted">Trx</span></h4>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card card-stat shadow-sm h-100">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-box bg-info bg-opacity-10 text-info me-3">
                                <i class="fas fa-box-open"></i>
                            </div>
                            <div>
                                <small class="text-muted fw-bold text-uppercase" style="font-size:0.7rem;">Produk Aktif</small>
                                <h4 class="mb-0 fw-bold text-dark mt-1"><?= number_format($total_produk) ?> <span class="fs-6 fw-normal text-muted">Item</span></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white border-bottom py-3 d-flex align-items-center">
                            <div class="bg-primary text-white rounded p-1 me-2"><i class="fas fa-chart-area fa-sm"></i></div>
                            <h6 class="mb-0 fw-bold">Tren Penjualan (7 Hari Terakhir)</h6>
                        </div>
                        <div class="card-body py-3" style="height: 300px;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>

                    <h6 class="fw-bold text-muted mb-3"><i class="fas fa-bolt text-warning me-2"></i>Akses Cepat</h6>
                    <div class="row g-3 row-cols-3 row-cols-md-5">
                        <div class="col">
                            <a href="transaksi/penjualan/index.php" class="action-card shadow-sm border-primary">
                                <i class="fas fa-cash-register text-primary"></i>
                                <span>Kasir</span>
                                <div class="badge-key" id="hint_kasir">F1</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="laporan/setoran_harian.php" class="action-card shadow-sm border-success">
                                <i class="fas fa-file-invoice-dollar text-success"></i>
                                <span>Setor Harian</span>
                                <div class="badge-key" id="hint_setor">F2</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="master/barang/index.php" class="action-card shadow-sm border-warning">
                                <i class="fas fa-box text-warning"></i>
                                <span>Data Barang</span>
                                <div class="badge-key" id="hint_barang">F7</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="transaksi/pemasukan_lain/index.php" class="action-card shadow-sm border-info">
                                <i class="fas fa-hand-holding-usd text-info"></i>
                                <span>Pemasukan</span>
                                <div class="badge-key" id="hint_inlain">PgUp</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="transaksi/pengeluaran_non_stok/tambah.php" class="action-card shadow-sm border-secondary">
                                <i class="fas fa-wallet text-secondary"></i>
                                <span>Pengeluaran</span>
                                <div class="badge-key" id="hint_biaya">F6</div>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card shadow-sm border-0 rounded-4 h-100">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger text-white rounded p-1 me-2"><i class="fas fa-exclamation-triangle fa-sm"></i></div>
                                <h6 class="mb-0 fw-bold">Peringatan Stok</h6>
                            </div>
                            <?php if($total_alert > 0): ?>
                                <span class="badge bg-danger rounded-pill px-2 py-1"><?= $total_alert ?> Item</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0 align-middle table-stok">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th class="ps-3 border-bottom-0">Nama Barang</th>
                                            <th class="text-center border-bottom-0" width="20%">Sisa</th>
                                            <th width="30%" class="border-bottom-0">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sql_low = "SELECT * FROM barang WHERE stok <= stok_limit AND aktif = 'Y' ORDER BY stok ASC LIMIT 7";
                                        $stmt_low = $pdo->query($sql_low);
                                        $low_items = $stmt_low->fetchAll();

                                        foreach ($low_items as $row):
                                            $percent = ($row['stok_limit'] > 0) ? ($row['stok'] / $row['stok_limit']) * 100 : 0;
                                            $color = ($row['stok'] == 0) ? 'bg-secondary' : 'bg-danger';
                                        ?>
                                        <tr>
                                            <td class="ps-3">
                                                <div class="fw-bold text-dark text-truncate" style="max-width: 150px; font-size:0.85rem;"><?= $row['nama_barang'] ?></div>
                                                <small class="text-muted" style="font-size: 0.7rem;"><?= $row['kode_barang'] ?></small>
                                            </td>
                                            <td class="text-center fw-bold text-danger">
                                                <span class="bg-danger bg-opacity-10 text-danger px-2 py-1 rounded"><?= $row['stok'] ?></span>
                                            </td>
                                            <td class="pe-3">
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 6px; border-radius: 10px;">
                                                        <div class="progress-bar <?= $color ?>" style="width: <?= $percent ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if(count($low_items) == 0): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-5">
                                                <i class="fas fa-box-open fs-1 text-light mb-3"></i><br>
                                                <small>Semua stok aman terkendali.</small>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php if($total_alert > 7): ?>
                        <div class="card-footer bg-white border-top text-center py-2">
                            <a href="master/barang/index.php?stok=low" class="text-primary text-decoration-none fw-bold" style="font-size: 0.8rem;">Lihat Semua Data Stok &rarr;</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php
// AMBIL SHORTCUT DARI DATABASE
$map_nav = [];
$hint_data = [];
try {
    $stmt_sc = $pdo->query("SELECT kode_aksi, tombol FROM settings_shortcut WHERE kode_aksi LIKE 'nav_%'");
    if($stmt_sc) {
        while($row = $stmt_sc->fetch(PDO::FETCH_ASSOC)) {
            $map_nav[strtoupper($row['tombol'])] = $row['kode_aksi'];
            $hint_data[$row['kode_aksi']] = $row['tombol'];
        }
    }
} catch(Exception $e) {}
?>
<script>
    // --- JAM REALTIME ---
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('realtimeClock').textContent = `${hours}:${minutes}:${seconds} WIB`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // --- CHART PENJUALAN ---
    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels_tgl) ?>,
            datasets: [{
                label: 'Omset (Rp)',
                data: <?= json_encode($data_omset) ?>,
                borderColor: '#1d4ed8',
                backgroundColor: 'rgba(29, 78, 216, 0.08)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#1d4ed8',
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false, 
            plugins: { legend: { display: false } },
            scales: {
                y: { 
                    beginAtZero: true, 
                    grid: { color: '#f3f4f6', drawBorder: false },
                    ticks: { callback: function(value) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(value); } }
                },
                x: { grid: { display: false, drawBorder: false } }
            }
        }
    });

    // --- LOGIKA SHORTCUT ---
    const hints = <?= json_encode($hint_data ?? []) ?>;
    function setHint(id, key) {
        let el = document.getElementById(id);
        if(el && hints[key]) el.innerText = hints[key];
    }
    setHint('hint_kasir', 'nav_kasir');
    setHint('hint_setor', 'nav_setor');
    setHint('hint_barang', 'nav_barang');
    setHint('hint_inlain', 'nav_inlain');
    setHint('hint_biaya', 'nav_biaya');

    const hotkeys = <?= json_encode($map_nav) ?>;
    document.addEventListener('keydown', function(event) {
        const key = event.key.toUpperCase();
        if (hotkeys[key]) {
            event.preventDefault();
            const aksi = hotkeys[key];
            switch (aksi) {
                case 'nav_kasir': window.location.href = 'transaksi/penjualan/index.php'; break;
                case 'nav_setor': window.location.href = 'laporan/setoran_harian.php'; break;
                case 'nav_biaya': window.location.href = 'transaksi/pengeluaran_non_stok/tambah.php'; break;
                case 'nav_barang': window.location.href = 'master/barang/index.php'; break;
                case 'nav_inlain': window.location.href = 'transaksi/pemasukan_lain/index.php'; break;
            }
        }
    });
</script>

</body>
</html>
