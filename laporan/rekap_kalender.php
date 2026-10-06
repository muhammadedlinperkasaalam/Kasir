<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- NAVIGASI LOGIKA ---
$mode  = 'tahun';
$tahun = isset($_GET['tahun']) ? $_GET['tahun'] : null;
$bulan = isset($_GET['bulan']) ? $_GET['bulan'] : null;

if ($tahun && !$bulan) { $mode = 'bulan'; }
if ($tahun && $bulan)  { $mode = 'kalender'; }

// --- HELPER FUNCTION ---
function bulanIndo($m) {
    $bln = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    return $bln[(int)$m];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rekap Profitabilitas | POS System</title>
    
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1a56db;
            --bg-color: #f8f9fa;
            --border-color: #e5e7eb;
            --text-dark: #111827;
            --text-muted: #6b7280;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-color);
            overflow: hidden; 
        }
        
        /* Layout Grid System Murni */
        .wrapper { height: 100vh; width: 100vw; display: flex; }
        .main-content { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: var(--bg-color); flex-grow: 1; }
        .header-top { height: 70px; background-color: #fff; border-bottom: 1px solid var(--border-color); flex-shrink: 0; z-index: 1020; display: flex; align-items: center; justify-content: space-between; }
        
        .content-scrollable { flex-grow: 1; overflow-y: auto; padding: 1.5rem; }
        
        /* Animasi Card */
        .card-hover { transition: all 0.2s ease-in-out; border-radius: 16px; border: 1px solid var(--border-color); }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 10px 25px rgba(0,0,0,0.05); cursor: pointer; border-color: var(--primary-color); }
        
        /* Kalender CSS Grid */
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        .day-header { background: #f3f4f6; color: var(--text-muted); padding: 10px 5px; text-align: center; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; border-radius: 8px; border: 1px solid var(--border-color); }
        
        .day-cell { 
            background: #fff; border: 1px solid var(--border-color); min-height: 110px; padding: 10px; 
            border-radius: 12px; position: relative; display: flex; flex-direction: column; justify-content: flex-start;
            transition: 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.01);
        }
        .day-cell:hover { border-color: var(--primary-color); box-shadow: 0 4px 12px rgba(26, 86, 219, 0.1); z-index: 2;}
        
        .date-num { font-weight: 800; font-size: 1.1rem; line-height: 1; margin-bottom: 8px; }
        
        .stat-line { font-size: 0.7rem; display: flex; justify-content: space-between; align-items: center; padding: 3px 0; border-bottom: 1px dashed #f1f5f9;}
        .stat-line:last-child { border-bottom: none; }
        
        /* Status Warna */
        .is-weekend { background-color: #fff1f2; border-color: #ffe4e6; }
        .is-today { border: 2px solid var(--primary-color); background-color: #eff6ff; }
        
        /* Responsif Kalender di HP */
        @media (max-width: 768px) {
            .calendar-grid { grid-template-columns: 1fr; gap: 10px;} 
            .day-header { display: none; }
            .day-cell { min-height: auto; flex-direction: row; align-items: center; flex-wrap: wrap; padding: 12px 16px;}
            .date-num { margin-bottom: 0; margin-right: 15px; font-size: 1.25rem; min-width: 30px;}
            .day-cell .mt-auto { margin-top: 0 !important; flex-grow: 1; }
            .stat-line { font-size: 0.8rem; padding: 4px 0;}
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php 
        $base_dir = '../'; 
        include '../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="header-top px-3 px-md-4 shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Laporan Profitabilitas</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Rekapitulasi Omzet & Laba Bersih</small>
                </div>
            </div>
            
            <div class="d-none d-sm-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                <img src="../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                <div class="d-flex flex-column ms-2 me-3 justify-content-center">
                    <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                    <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                </div>
            </div>
        </header>

        <div class="content-scrollable">
            <div class="container-fluid p-0 pb-4">

                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb bg-white px-4 py-3 rounded-pill shadow-sm border" style="font-size: 0.85rem;">
                        <li class="breadcrumb-item"><a href="rekap_kalender.php" class="text-decoration-none fw-bold text-muted"><i class="fas fa-home me-1"></i>Pilih Tahun</a></li>
                        <?php if($tahun): ?>
                            <li class="breadcrumb-item"><a href="?tahun=<?= $tahun ?>" class="text-decoration-none fw-bold text-muted"><?= $tahun ?></a></li>
                        <?php endif; ?>
                        <?php if($bulan): ?>
                            <li class="breadcrumb-item active fw-bolder text-primary" aria-current="page"><?= bulanIndo($bulan) ?></li>
                        <?php endif; ?>
                    </ol>
                </nav>

                <?php if ($mode == 'tahun'): ?>
                <?php
                    $sql = "SELECT YEAR(tgl_penjualan) as thn, SUM(total_omzet) as omzet FROM penjualan GROUP BY YEAR(tgl_penjualan) ORDER BY thn DESC";
                    $years = $pdo->query($sql)->fetchAll();
                ?>
                <div class="mb-4 d-flex align-items-center">
                    <div class="bg-primary rounded-circle me-3" style="width: 12px; height: 12px;"></div>
                    <h5 class="fw-bolder mb-0 text-dark">Data Tahunan</h5>
                </div>
                
                <div class="row g-3 g-md-4">
                    <?php foreach($years as $y): ?>
                    <div class="col-6 col-md-4 col-xl-3">
                        <a href="?tahun=<?= $y['thn'] ?>" class="text-decoration-none">
                            <div class="card card-hover bg-white h-100 text-center p-4">
                                <div class="text-primary mb-2"><i class="far fa-calendar-alt fa-3x opacity-25"></i></div>
                                <h2 class="fw-bolder text-dark mb-1"><?= $y['thn'] ?></h2>
                                <div class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill mt-2">
                                    Omzet: Rp <?= number_format($y['omzet'], 0, ',', '.') ?>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($years)): ?>
                        <div class="col-12"><div class="alert alert-info border-0 shadow-sm"><i class="fas fa-info-circle me-2"></i>Belum ada data transaksi yang tercatat.</div></div>
                    <?php endif; ?>
                </div>
                
                
                <?php elseif ($mode == 'bulan'): ?>
                <?php
                    $start_year = "$tahun-01-01";
                    $end_year   = "$tahun-12-31";
                    
                    $sql = "SELECT MONTH(tgl_penjualan) as bln, SUM(total_omzet) as omzet 
                            FROM penjualan WHERE tgl_penjualan BETWEEN ? AND ? GROUP BY MONTH(tgl_penjualan)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$start_year, $end_year]);
                    $months = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                ?>
                <div class="mb-4 d-flex align-items-center">
                    <div class="bg-primary rounded-circle me-3" style="width: 12px; height: 12px;"></div>
                    <h5 class="fw-bolder mb-0 text-dark">Rincian Bulan di Tahun <?= $tahun ?></h5>
                </div>

                <div class="row g-3">
                    <?php for($i=1; $i<=12; $i++): 
                        $omzet = $months[$i] ?? 0;
                        $bg    = $omzet > 0 ? 'bg-white shadow-sm' : 'bg-light border-dashed opacity-75';
                        $text  = $omzet > 0 ? 'text-dark' : 'text-muted';
                    ?>
                    <div class="col-6 col-md-4 col-xl-3">
                        <a href="<?= $omzet > 0 ? "?tahun=$tahun&bulan=".sprintf("%02d", $i) : '#' ?>" class="text-decoration-none" <?= $omzet==0 ? 'style="cursor:default;"' : '' ?>>
                            <div class="card card-hover <?= $bg ?> h-100 p-3 p-md-4">
                                <h5 class="fw-bolder <?= $text ?> mb-2"><?= bulanIndo($i) ?></h5>
                                <?php if($omzet > 0): ?>
                                    <div class="fw-bold text-success small">Rp <?= number_format($omzet, 0,',','.') ?></div>
                                <?php else: ?>
                                    <div class="small text-muted fst-italic">Tidak ada data</div>
                                <?php endif; ?>
                            </div>
                        </a>
                    </div>
                    <?php endfor; ?>
                </div>


                <?php elseif ($mode == 'kalender'): ?>
                <?php
                    $tgl_awal  = "$tahun-$bulan-01";
                    $tgl_akhir = date("Y-m-t", strtotime($tgl_awal));

                    // 1. Query Data Transaksi
                    $sqlData = "SELECT DATE(p.tgl_penjualan) as tgl, SUM(p.total_omzet) as omzet,
                                SUM((pi.harga_jual - IFNULL(b.harga_beli, 0)) * pi.jumlah - pi.diskon) as laba_kotor
                                FROM penjualan p
                                JOIN penjualan_item pi ON p.no_penjualan = pi.no_penjualan
                                LEFT JOIN barang b ON pi.kode_barang = b.kode_barang
                                WHERE p.tgl_penjualan BETWEEN ? AND ?
                                GROUP BY DATE(p.tgl_penjualan)";
                    $stmt = $pdo->prepare($sqlData);
                    $stmt->execute([$tgl_awal, $tgl_akhir]);
                    
                    $dailyData = [];
                    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $dailyData[$row['tgl']] = $row; }

                    // 2. Query Pengeluaran
                    $sqlOps = "SELECT tanggal, SUM(jumlah) as biaya FROM pengeluaran_non_stok 
                               WHERE tanggal BETWEEN ? AND ? GROUP BY tanggal";
                    $stmtOps = $pdo->prepare($sqlOps);
                    $stmtOps->execute([$tgl_awal, $tgl_akhir]);
                    
                    while($row = $stmtOps->fetch(PDO::FETCH_ASSOC)) {
                        if(!isset($dailyData[$row['tanggal']])) { $dailyData[$row['tanggal']] = ['omzet'=>0, 'laba_kotor'=>0]; }
                        $dailyData[$row['tanggal']]['biaya'] = $row['biaya'];
                    }

                    $numDays = date('t', strtotime($tgl_awal));
                    $firstDayName = date('N', strtotime($tgl_awal)); 
                    
                    $sum_omzet = 0; $sum_bersih = 0;
                ?>

                <div class="card bg-white border-0 shadow-sm mb-4 rounded-4 overflow-hidden">
                    <div class="row g-0">
                        <div class="col-md-4 bg-primary text-white p-4 d-flex flex-column justify-content-center">
                            <span class="text-uppercase fw-bold opacity-75" style="letter-spacing: 1px; font-size: 0.8rem;">Laporan Bulan</span>
                            <h2 class="fw-bolder mb-0"><?= bulanIndo($bulan) ?> <?= $tahun ?></h2>
                        </div>
                        <div class="col-md-8 p-4 d-flex flex-column justify-content-center">
                            <div class="d-flex flex-wrap gap-2">
                                <div class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2"><i class="fas fa-circle text-primary me-1" style="font-size:0.5rem;"></i> Omzet Kotor</div>
                                <div class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2"><i class="fas fa-circle text-success me-1" style="font-size:0.5rem;"></i> Laba Bersih (Net)</div>
                                <div class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2"><i class="fas fa-circle text-danger me-1" style="font-size:0.5rem;"></i> Minus / Rugi</div>
                                <div class="badge bg-warning bg-opacity-10 text-dark border border-warning border-opacity-50 px-3 py-2"><i class="fas fa-wallet text-warning me-1"></i> Ada Pengeluaran</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="calendar-grid mb-2 d-none d-md-grid">
                    <div class="day-header">Senin</div>
                    <div class="day-header">Selasa</div>
                    <div class="day-header">Rabu</div>
                    <div class="day-header">Kamis</div>
                    <div class="day-header">Jumat</div>
                    <div class="day-header text-danger bg-danger bg-opacity-10 border-danger border-opacity-25">Sabtu</div>
                    <div class="day-header text-danger bg-danger bg-opacity-10 border-danger border-opacity-25">Minggu</div>
                </div>

                <div class="calendar-grid">
                    <?php
                    // Ruang kosong awal bulan
                    for ($k = 1; $k < $firstDayName; $k++) echo '<div class="day-cell bg-light opacity-25 d-none d-md-flex border-0 shadow-none"></div>';

                    // Loop Tanggal
                    for ($d = 1; $d <= $numDays; $d++) {
                        $tgl_curr = sprintf("%s-%s-%02d", $tahun, $bulan, $d);
                        $is_today = ($tgl_curr == date('Y-m-d')) ? 'is-today shadow' : '';
                        $day_num  = date('N', strtotime($tgl_curr));
                        $is_weekend = ($day_num >= 6) ? 'is-weekend' : '';

                        $data  = $dailyData[$tgl_curr] ?? [];
                        $omzet = $data['omzet'] ?? 0;
                        $laba_kotor = $data['laba_kotor'] ?? 0;
                        $biaya = $data['biaya'] ?? 0;
                        $laba_bersih = $laba_kotor - $biaya;

                        $sum_omzet += $omzet;
                        $sum_bersih += $laba_bersih;
                        
                        $warna_net = ($laba_bersih >= 0) ? 'text-success' : 'text-danger';
                        ?>
                        
                        <div class="day-cell <?= $is_today ?> <?= $is_weekend ?>">
                            <div class="d-flex justify-content-between w-100">
                                <div>
                                    <span class="date-num <?= ($day_num==7)?'text-danger':'text-dark' ?>"><?= $d ?></span>
                                    <?php if($tgl_curr == date('Y-m-d')): ?><span class="badge bg-primary ms-1" style="font-size:0.5rem; vertical-align:top;">HARI INI</span><?php endif; ?>
                                </div>
                                <?php if($biaya > 0): ?>
                                    <span class="badge bg-warning text-dark border border-warning" style="font-size:0.6rem; height:fit-content;" title="Ada Biaya Operasional">Ops: -<?= number_format($biaya/1000) ?>k</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if($omzet > 0 || $biaya > 0): ?>
                                <div class="mt-auto w-100 pt-2">
                                    <div class="stat-line text-muted">
                                        <span>Omzet</span> 
                                        <span class="fw-bolder text-primary" style="letter-spacing:-0.5px;"><?= number_format($omzet,0,',','.') ?></span>
                                    </div>
                                    <div class="stat-line pt-1 mt-1 border-top" style="border-color: var(--border-color) !important;">
                                        <span class="fw-bold">Net</span> 
                                        <span class="fw-bolder <?= $warna_net ?> py-1 px-2 rounded bg-opacity-10 <?= ($laba_bersih >= 0) ? 'bg-success' : 'bg-danger' ?>" style="letter-spacing:-0.5px;">
                                            <?= number_format($laba_bersih,0,',','.') ?>
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mt-auto w-100 text-center opacity-25">
                                    <i class="fas fa-minus"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php
                    }
                    ?>
                </div>

                <div class="row g-3 mt-4 mb-2">
                    <div class="col-md-6">
                        <div class="card border-0 bg-primary text-white shadow-sm p-4 h-100 d-flex flex-row align-items-center" style="border-radius: 16px;">
                            <div class="bg-white bg-opacity-25 rounded-circle d-flex justify-content-center align-items-center me-4" style="width: 60px; height: 60px;">
                                <i class="fas fa-cash-register fa-2x"></i>
                            </div>
                            <div>
                                <small class="text-uppercase fw-bold opacity-75 d-block mb-1" style="letter-spacing: 1px; font-size:0.75rem;">Total Pendapatan Kotor</small>
                                <h3 class="fw-bolder mb-0">Rp <?= number_format($sum_omzet, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <?php $bg_total = ($sum_bersih >= 0) ? 'bg-success' : 'bg-danger'; ?>
                        <div class="card border-0 <?= $bg_total ?> text-white shadow-sm p-4 h-100 d-flex flex-row align-items-center" style="border-radius: 16px;">
                            <div class="bg-white bg-opacity-25 rounded-circle d-flex justify-content-center align-items-center me-4" style="width: 60px; height: 60px;">
                                <i class="fas fa-hand-holding-usd fa-2x"></i>
                            </div>
                            <div>
                                <small class="text-uppercase fw-bold opacity-75 d-block mb-1" style="letter-spacing: 1px; font-size:0.75rem;">Total Laba Bersih Akhir</small>
                                <h3 class="fw-bolder mb-0">Rp <?= number_format($sum_bersih, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>