<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// 1. SETUP FILTER
$tgl_awal      = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d'); 
$tgl_akhir     = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
$filter_metode = isset($_GET['metode']) ? $_GET['metode'] : '';
$keyword       = isset($_GET['q']) ? trim($_GET['q']) : ''; 
$filter_shift  = isset($_GET['shift']) ? $_GET['shift'] : ''; 

// =========================================================================
// OPTIMASI QUERY (MENGHILANGKAN FULL TABLE SCAN REGEX)
// =========================================================================
$params = [];
$sql = "SELECT p.*, pl.nama_pelanggan, pl.no_telepon, pl.wa_lid, o.nama_user,
        CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END as nilai_transaksi_real
        FROM penjualan p
        LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
        LEFT JOIN operator o ON p.kode_user = o.kode_user
        WHERE 1=1 ";

if (!empty($keyword)) {
    $sql .= " AND (p.no_penjualan LIKE ? OR pl.nama_pelanggan LIKE ?) ";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
} else {
    $stmt_ak = $pdo->prepare("SELECT DISTINCT no_penjualan FROM arus_kas WHERE tanggal BETWEEN ? AND ? AND jenis = 'Pemasukan' AND no_penjualan IS NOT NULL");
    $stmt_ak->execute([$tgl_awal, $tgl_akhir]);
    $ids_ak = $stmt_ak->fetchAll(PDO::FETCH_COLUMN);

    $sql .= " AND ( p.tgl_penjualan BETWEEN ? AND ? OR (p.is_verif_qris = 'Y' AND p.tgl_verif_qris BETWEEN ? AND ?) ";
    array_push($params, $tgl_awal, $tgl_akhir, $tgl_awal, $tgl_akhir);
    
    if (count($ids_ak) > 0) {
        $in = implode(',', array_fill(0, count($ids_ak), '?'));
        $sql .= " OR p.no_penjualan IN ($in) ";
        $params = array_merge($params, $ids_ak);
    }
    $sql .= " ) ";
}

if (!empty($filter_shift)) {
    $sql .= " AND p.shift = ? ";
    $params[] = $filter_shift;
}

$sql .= " ORDER BY p.no_penjualan DESC"; 
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// TARIK DATA ARUS KAS SEKALIGUS
// =========================================================================
$no_penjualan_list = array_column($raw_data, 'no_penjualan');
$cicilan_map = [];

if (!empty($no_penjualan_list)) {
    $inQuery = implode(',', array_fill(0, count($no_penjualan_list), '?'));
    $sql_arus = "SELECT id, no_penjualan, tanggal, metode_pembayaran, jumlah_masuk, keterangan, created_at FROM arus_kas WHERE jenis = 'Pemasukan' AND no_penjualan IN ($inQuery)";
    $stmt_arus = $pdo->prepare($sql_arus);
    $stmt_arus->execute($no_penjualan_list);
    
    foreach ($stmt_arus->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cicilan_map[$c['no_penjualan']][] = $c;
    }
}

// --- PROSES DATA MEMORI ---
$final_data = [];
$total_omset_penjualan = 0; $total_uang_masuk_real = 0;
$sum_cash = 0; $sum_qris = 0; $sum_debit = 0; $sum_transfer = 0; 
$sum_sisa_piutang = 0; $sum_deposit = 0; 

function cleanNum($str) {
    $str = preg_replace('/[^0-9,.]/', '', $str);
    if (strpos($str, '.') !== false && strpos($str, ',') !== false) { $str = str_replace('.', '', $str); $str = str_replace(',', '.', $str); }
    elseif (strpos($str, '.') !== false) { if (substr_count($str, '.') > 1 || strlen(explode('.', $str)[1]) == 3) $str = str_replace('.', '', $str); }
    return (float)$str;
}

function getMetode($teks) {
    $t = strtolower(trim((string)$teks));
    if ($t === '' || $t === '-' || $t === 'null') return 'Cash';
    if (preg_match('/(debit|edc|kartu|card|gesek)/', $t)) return 'Debit';
    if (preg_match('/(qris|dana|gopay|ovo|shopee|linkaja|qr)/', $t)) return 'QRIS';
    if (preg_match('/(transfer|trf|tf|bca|bri|bni|mandiri|bsi|cimb|jago|seabank|bank|atm)/', $t)) return 'Transfer';
    if (preg_match('/(cash|tunai|kas)/', $t)) return 'Cash';
    if (preg_match('/(utang|piutang|tempo|belum)/', $t)) return 'Utang';
    return 'Cash';
}

function metodeLabelFromMap($map) {
    if (empty($map)) return 'Cash';
    arsort($map);
    $labels = [];
    foreach ($map as $m => $v) {
        if ((float)$v > 0) $labels[] = $m;
    }
    return empty($labels) ? 'Cash' : implode(' + ', $labels);
}

function metodeMatch($selected, $list) {
    if ($selected === '') return true;
    $selected = getMetode($selected);
    foreach ((array)$list as $m) {
        if (strcasecmp(getMetode($m), $selected) === 0) return true;
    }
    return false;
}

foreach ($raw_data as $row) {
    $grand_total = (float)$row['nilai_transaksi_real'];
    $tgl_trx_db  = $row['tgl_penjualan'];
    $is_nota_in_range = ($tgl_trx_db >= $tgl_awal && $tgl_trx_db <= $tgl_akhir);
    
    $cicilan_db = $cicilan_map[$row['no_penjualan']] ?? [];
    $total_sudah_dicicil = 0;
    $payments_in_range_by_method = [];
    $payments_in_range_total = 0;
    $sort_id = (int)filter_var($row['no_penjualan'], FILTER_SANITIZE_NUMBER_INT);
    
    // A. PROSES PEMBAYARAN DARI ARUS KAS
    // Catatan: banyak transaksi tunai/cash sudah dicatat di arus_kas.
    // Pada versi lama, saat filter Cash, baris asli tidak tampil karena dp_murni menjadi 0
    // setelah dikurangi arus_kas. Sekarang metode dari arus_kas tetap dipakai untuk filter dan display.
    foreach ($cicilan_db as $c) {
        $tgl_cicil     = $c['tanggal'];
        $metode_cicil  = getMetode($c['metode_pembayaran'] ?: 'Cash');
        $nominal_cicil = (float)$c['jumlah_masuk'];
        $total_sudah_dicicil += $nominal_cicil;

        if ($tgl_cicil >= $tgl_awal && $tgl_cicil <= $tgl_akhir) {
            if (!isset($payments_in_range_by_method[$metode_cicil])) $payments_in_range_by_method[$metode_cicil] = 0;
            $payments_in_range_by_method[$metode_cicil] += $nominal_cicil;
            $payments_in_range_total += $nominal_cicil;

            if ($metode_cicil == 'QRIS') $sum_qris += $nominal_cicil;
            elseif ($metode_cicil == 'Transfer') $sum_transfer += $nominal_cicil;
            elseif ($metode_cicil == 'Debit') $sum_debit += $nominal_cicil;
            else $sum_cash += $nominal_cicil;
            
            $total_uang_masuk_real += $nominal_cicil;
            
            if (!$is_nota_in_range) {
                $row_virtual = $row;
                $row_virtual['tgl_penjualan'] = $tgl_cicil;
                $row_virtual['jam']           = '00:00:00';
                $row_virtual['virtual_status'] = 'Cicilan';
                $row_virtual['virtual_nominal'] = $nominal_cicil;
                $row_virtual['virtual_metode'] = $metode_cicil;
                $row_virtual['virtual_arus_kas_id'] = (int)($c['id'] ?? 0);
                $row_virtual['virtual_tanggal_lama'] = $tgl_cicil;
                $row_virtual['virtual_created_at'] = $c['created_at'] ?? '';
                $row_virtual['sort_id'] = $sort_id;
                
                if (metodeMatch($filter_metode, [$metode_cicil])) {
                    $final_data[] = $row_virtual;
                }
            }
        }
    }

    // B. CEK DEPOSIT
    $nominal_deposit = (float)($row['nominal_deposit'] ?? 0);
    if ($nominal_deposit == 0 && stripos($row['keterangan'] ?? '', 'depo') !== false) {
        if (preg_match('/(?:Depo|Deposit).*?([0-9.,]+)/i', $row['keterangan'], $md)) {
            $nominal_deposit = (float)str_replace(['.', ','], ['', '.'], $md[1]);
        } elseif (strtolower(trim($row['keterangan'])) == 'deposit') {
            $nominal_deposit = $grand_total;
        }
    }
    if($is_nota_in_range) $sum_deposit += $nominal_deposit;

    // C. CEK AUTO-VERIF QRIS (PERBAIKAN LOGIKA TRUE/FALSE)
    $is_verif_qris = ($row['is_verif_qris'] === 'Y');
    $tgl_qris = $row['tgl_verif_qris'] ?: $tgl_trx_db;

    // Fallback baca teks lama (Jika belum migrasi)
    if (!$is_verif_qris && strpos($row['keterangan'] ?? '', '[Verif:QRIS') !== false) {
        if (preg_match('/\[Verif:QRIS(?::(.*?))?\]/i', $row['keterangan'], $vq)) {
            $is_verif_qris = true;
            $tgl_qris = !empty($vq[1]) ? trim($vq[1]) : $tgl_trx_db;
        }
    }

    if ($is_verif_qris) {
        $sisa_sebelum_verif = $grand_total - $nominal_deposit - $total_sudah_dicicil;
        if ($sisa_sebelum_verif < 0) $sisa_sebelum_verif = 0;
        
        $nominal_verif_qris = ($row['pelunasan'] == 'Y') ? $sisa_sebelum_verif : 0;

        if ($tgl_qris >= $tgl_awal && $tgl_qris <= $tgl_akhir) {
            $sum_qris += $nominal_verif_qris;
            $total_uang_masuk_real += $nominal_verif_qris;

            if (!$is_nota_in_range && $nominal_verif_qris > 0) {
                $row_virtual = $row;
                $row_virtual['tgl_penjualan'] = $tgl_qris;
                $row_virtual['jam']           = '00:00:00';
                $row_virtual['virtual_status'] = 'Auto-QRIS';
                $row_virtual['virtual_nominal'] = $nominal_verif_qris;
                $row_virtual['virtual_metode'] = 'QRIS';
                $row_virtual['is_verif_qris'] = 'Y'; // Kunci sebagai string Y
                $row_virtual['sort_id'] = $sort_id;
                
                if (metodeMatch($filter_metode, ['QRIS'])) {
                    $final_data[] = $row_virtual;
                }
            }
        }
    }

    // D. PROSES NOTA ASLI
    if ($is_nota_in_range) {
        $total_omset_penjualan += $grand_total; 
        
        // --- PERBAIKAN LOGIKA PELUNASAN ---
        // 1. Percayai status asli dari database (karena ini yang paling valid)
        if ($row['pelunasan'] == 'Y') {
            $row['pelunasan'] = 'Y';
        } else {
            // 2. Jika di DB masih 'N' (Utang), kita hitung ulang sisa tagihannya dengan melibatkan SEMUA pembayaran
            $uang_pelunasan_utang = (float)($row['uang_pelunasan_utang'] ?? 0);
            $sisa_tagihan = $grand_total - $nominal_deposit - (float)$row['uang_bayar'] - $total_sudah_dicicil - $uang_pelunasan_utang;
            
            if ($sisa_tagihan > 100) { 
                $sum_sisa_piutang += $sisa_tagihan;
                $row['pelunasan'] = 'N'; 
            } else {
                $row['pelunasan'] = 'Y'; // Auto-lunas jika ternyata perhitungannya <= 100 perak
            }
        }
        // ----------------------------------

        $dp_awal = 0;
        if (!$is_verif_qris) {
            $dp_awal = (float)$row['uang_bayar'] - $total_sudah_dicicil; 
            if ($dp_awal < 0) $dp_awal = 0;

            $metode_dp = getMetode($row['metode_pembayaran'] ?: 'Cash');

            if ($dp_awal > 0) {
                if ($metode_dp == 'QRIS') $sum_qris += $dp_awal;
                elseif ($metode_dp == 'Transfer') $sum_transfer += $dp_awal;
                elseif ($metode_dp == 'Debit') $sum_debit += $dp_awal;
                else $sum_cash += $dp_awal;
                
                $total_uang_masuk_real += $dp_awal;
            }

            // Untuk tampilan/filter: jika pembayaran sudah ada di arus_kas pada tanggal filter,
            // tampilkan metode dari arus_kas. Ini memperbaiki filter Cash yang sebelumnya kosong.
            $metode_filter_list = [];
            if ($dp_awal > 0) $metode_filter_list[] = $metode_dp;
            foreach (array_keys($payments_in_range_by_method) as $m) $metode_filter_list[] = $m;
            $metode_filter_list = array_values(array_unique($metode_filter_list));

            $row['dp_murni'] = ($dp_awal > 0) ? $dp_awal : $payments_in_range_total;
            $row['metode_bersih'] = ($dp_awal > 0) ? $metode_dp : metodeLabelFromMap($payments_in_range_by_method);
            $row['metode_filter_list'] = $metode_filter_list;
            $row['is_verif_qris'] = 'N'; // Pastikan yang bukan QRIS diset 'N'
        } else {
            $row['dp_murni'] = ($tgl_qris == $tgl_trx_db) ? $nominal_verif_qris : 0;
            $row['metode_bersih'] = 'QRIS';
            $row['metode_filter_list'] = ['QRIS'];
            $row['is_verif_qris'] = 'Y'; // Pastikan yang QRIS diset 'Y'
        }

        $row['virtual_status'] = 'Asli';
        $row['sort_id'] = $sort_id;
        
        if ($filter_metode == 'Utang') {
            if ($row['pelunasan'] == 'N') $final_data[] = $row;
        } elseif (!empty($filter_metode)) {
            $list_metode_row = $row['metode_filter_list'] ?? [$row['metode_bersih'] ?? 'Cash'];
            if (metodeMatch($filter_metode, $list_metode_row) && (float)($row['dp_murni'] ?? 0) > 0) $final_data[] = $row;
        } else {
            $final_data[] = $row; 
        }
    }
}

// OPTIMASI URUTKAN BERDASARKAN ID (SUPER CEPAT)
if (!empty($final_data)) {
    array_multisort(array_column($final_data, 'sort_id'), SORT_DESC, SORT_NUMERIC, $final_data);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Riwayat Transaksi | POS System</title>
    
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
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
        body { background-color: var(--bg-color); font-family: 'Inter', sans-serif; overflow-x: hidden; }
        
        /* Layout CSS */
        .wrapper { display: flex; width: 100vw; height: 100vh; overflow: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; height: 100vh; overflow-y: auto; background: var(--bg-color);}
        
        .header-top { height: 70px; background-color: #fff; padding: 0 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; z-index: 1020; position: sticky; top: 0;}
        
        /* Card Summary Modern */
        .card-summary { border: none; border-radius: 12px; color: white; transition: 0.2s; min-height: 90px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .card-summary:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .summary-label { font-size: 0.75rem; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 600;}
        .summary-val { font-weight: 800; font-size: 1.1rem; }
        .col-custom { flex: 1 0 14%; min-width: 140px; }
        
        /* Table Styles */
        .card-filter { border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
        .table-custom th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; color: var(--text-muted); background: #f9fafb; border-bottom: 2px solid var(--border-color) !important;}
        .table-custom td { font-size: 0.85rem; vertical-align: middle; color: var(--text-dark); }
        
        .bg-cicilan { background-color: #f0fdf4 !important; }
        .bg-qris-virtual { background-color: #eff6ff !important; }
        
        .modal-detail-row { display: flex; justify-content: space-between; border-bottom: 1px dashed var(--border-color); padding: 8px 0; font-size: 0.9rem; }
        .modal-detail-row.bold { font-weight: 800; border-bottom: 2px solid var(--border-color); color: var(--text-dark);}
        .info-struk { font-size: 0.85rem; color: var(--text-dark); background: #f9fafb; padding: 15px; border-radius: 12px; margin-bottom: 15px; border: 1px solid var(--border-color);}
        .col-aksi { min-width: 160px; white-space: nowrap; }

        @media (max-width: 768px) {
            .wrapper { flex-direction: column; height: auto; overflow: visible;}
            .bg-white.border-end { display: none !important; }
            .main-content { height: auto; overflow: visible;}
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php 
        $base_dir = '../../'; 
        include '../../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="bg-white border-bottom px-3 px-md-4 d-flex align-items-center justify-content-between" style="height: 70px; z-index: 1020; flex-shrink: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
            
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Riwayat Transaksi</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Addinta Printing</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="d-none d-md-flex flex-column align-items-end text-end border-end pe-3">
                    <small class="text-muted fw-bold" style="font-size: 0.65rem; text-transform: uppercase;">Waktu Realtime</small>
                    <span id="realtimeClock" class="fw-bold text-primary" style="font-size: 0.9rem;">00:00:00 WIB</span>
                </div>
                
                <div class="d-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="d-none d-sm-flex flex-column ms-2 me-3 justify-content-center">
                        <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'KASIR') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid p-4">
            
            <div class="d-flex justify-content-between align-items-end mb-3">
                <h6 class="fw-bolder text-dark mb-0"><i class="fas fa-chart-pie text-primary me-2"></i> Ringkasan Keuangan</h6>
                <a href="index.php" class="btn btn-sm btn-primary fw-bold rounded-3 shadow-sm"><i class="fas fa-cash-register me-1"></i> Buka Kasir</a>
            </div>
            
            <div class="row g-2 mb-4 d-flex flex-wrap">
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-primary p-3 h-100">
                        <div class="summary-label">Total Omset</div>
                        <div class="summary-val">Rp <?= number_format($total_omset_penjualan, 0, ',', '.') ?></div>
                        <small style="font-size: 10px; opacity: 0.7;">Penjualan Kotor</small>
                    </div>
                </div>
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-success p-3 h-100">
                        <div class="summary-label"><i class="fas fa-money-bill me-1"></i>Cash Masuk</div>
                        <div class="summary-val">Rp <?= number_format($sum_cash, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-info p-3 h-100">
                        <div class="summary-label"><i class="fas fa-qrcode me-1"></i>QRIS Masuk</div>
                        <div class="summary-val">Rp <?= number_format($sum_qris, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-warning text-dark p-3 h-100">
                        <div class="summary-label"><i class="far fa-credit-card me-1"></i>Debit Card</div>
                        <div class="summary-val">Rp <?= number_format($sum_debit, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-secondary p-3 h-100">
                        <div class="summary-label"><i class="fas fa-university me-1"></i>Transfer Bank</div>
                        <div class="summary-val">Rp <?= number_format($sum_transfer, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary p-3 h-100" style="background-color: #6610f2;">
                        <div class="summary-label"><i class="fas fa-wallet me-1"></i>Deposit Dipakai</div>
                        <div class="summary-val">Rp <?= number_format($sum_deposit, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-danger p-3 h-100">
                        <div class="summary-label"><i class="fas fa-book-dead me-1"></i>Sisa Piutang</div>
                        <div class="summary-val">Rp <?= number_format($sum_sisa_piutang, 0, ',', '.') ?></div>
                    </div>
                </div>
                 <div class="col-6 col-md-custom col-custom">
                    <div class="card card-summary bg-dark p-3 h-100">
                        <div class="summary-label text-warning"><i class="fas fa-coins me-1"></i>Total Uang Real</div>
                        <div class="summary-val text-warning">Rp <?= number_format($total_uang_masuk_real, 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <div class="card card-filter bg-white mb-5">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" name="q" class="form-control border-start-0 bg-light" placeholder="Cari Faktur / Nama..." value="<?= htmlspecialchars($keyword) ?>" style="box-shadow:none;">
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted">Tgl</span>
                                <input type="date" name="start" class="form-control" value="<?= $tgl_awal ?>" onchange="this.form.submit()">
                                <span class="input-group-text bg-light text-muted">-</span>
                                <input type="date" name="end" class="form-control" value="<?= $tgl_akhir ?>" onchange="this.form.submit()">
                            </div>
                        </div>

                        <div class="col-md-2">
                            <select name="shift" class="form-select form-select-sm text-muted" onchange="this.form.submit()">
                                <option value="">- Semua Shift -</option>
                                <option value="Pagi" <?= $filter_shift == 'Pagi' ? 'selected' : '' ?>>Pagi</option>
                                <option value="Siang" <?= $filter_shift == 'Siang' ? 'selected' : '' ?>>Siang</option>
                                <option value="Malam" <?= $filter_shift == 'Malam' ? 'selected' : '' ?>>Malam</option>
                                <option value="Full" <?= $filter_shift == 'Full' ? 'selected' : '' ?>>Full</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <select name="metode" class="form-select form-select-sm text-muted" onchange="this.form.submit()">
                                <option value="">- Semua Metode -</option>
                                <option value="Cash" <?= $filter_metode == 'Cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="QRIS" <?= $filter_metode == 'QRIS' ? 'selected' : '' ?>>QRIS</option>
                                <option value="Debit" <?= $filter_metode == 'Debit' ? 'selected' : '' ?>>Debit</option>
                                <option value="Transfer" <?= $filter_metode == 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                                <option value="Utang" <?= $filter_metode == 'Utang' ? 'selected' : '' ?>>Utang</option>
                            </select>
                        </div>

                        <div class="col-md-2 text-end">
                            <button type="submit" class="btn btn-primary btn-sm rounded-3 shadow-sm px-3"><i class="fas fa-filter"></i> Filter</button>
                            <a href="riwayat.php" class="btn btn-light border btn-sm rounded-3 text-secondary ms-1" title="Reset"><i class="fas fa-sync"></i></a>
                        </div>
                    </form>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom table-hover align-middle mb-0" id="tabelHistory">
                            <thead class="text-center">
                                <tr>
                                    <th class="ps-4">No Faktur</th>
                                    <th>Waktu</th>
                                    <th>Shift</th>
                                    <th class="text-start">Pelanggan</th>
                                    <th class="text-end">Total Belanja</th>
                                    <th class="text-end">Uang Masuk</th>
                                    <th>Status</th>
                                    <th class="col-aksi pe-4">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($final_data as $row): 
                                    $is_virtual = ($row['virtual_status'] ?? '') == 'Cicilan';
                                    $is_virtual_qris = ($row['virtual_status'] ?? '') == 'Auto-QRIS';
                                    $trx_total  = $row['nilai_transaksi_real'];
                                    
                                    if ($is_virtual || $is_virtual_qris) {
                                        $display_total = '<span class="text-muted small"><i class="fas fa-history text-secondary me-1"></i>Nota Lama</span>';
                                        $display_masuk = $row['virtual_nominal'];
                                        $display_metode = $row['virtual_metode'];
                                        
                                        if ($is_virtual_qris) {
                                            $bg_class = 'bg-qris-virtual';
                                            $ket_tambahan = '<span class="badge bg-info text-dark ms-2 rounded-pill"><i class="fas fa-qrcode"></i> Lunas QRIS CSV</span>';
                                        } else {
                                            $bg_class = 'bg-cicilan';
                                            $ket_tambahan = '<span class="badge bg-success ms-2 rounded-pill">Cicilan</span>';
                                        }
                                        $keterangan_bersih = preg_replace('/\[Verif:QRIS(?::(.*?))?\]/i', '', $row['keterangan'] ?? '');
                                        $keterangan_bersih = preg_replace('/\{\{\s*PAY.*?\}\}/i', '', $keterangan_bersih);
                                    } else {
                                        $display_total = number_format($trx_total, 0, ',', '.');
                                        $display_masuk = $row['dp_murni']; 
                                        
                                        $is_qris_terverifikasi = (isset($row['is_verif_qris']) && $row['is_verif_qris'] === 'Y');
                                        $display_metode = $is_qris_terverifikasi ? "Auto-QRIS" : $row['metode_bersih'];
                                        $bg_class = '';
                                        $ket_tambahan = '';
                                        
                                        $keterangan_bersih = preg_replace('/\[Verif:QRIS(?::(.*?))?\]/i', '', $row['keterangan'] ?? '');
                                        $keterangan_bersih = preg_replace('/\{\{\s*PAY.*?\}\}/i', '', $keterangan_bersih);
                                        
                                        if ($is_qris_terverifikasi) {
                                            $tgl_qris_badge = !empty($row['tgl_verif_qris']) ? $row['tgl_verif_qris'] : '';
                                            $ket_tambahan = '<span class="badge bg-info text-dark ms-2 rounded-pill" style="font-size:0.6rem" title="Diverifikasi CSV: '.$tgl_qris_badge.'"><i class="fas fa-qrcode"></i> Auto-QRIS</span>';
                                        }
                                    }
                                ?>
                                <tr class="<?= $bg_class ?>">
                                    <td class="fw-bold text-primary text-center ps-4">
                                        <?= $row['no_penjualan'] ?>
                                        <?php if($is_virtual || $is_virtual_qris): ?>
                                            <br><small class="text-muted" style="font-size:0.65rem">Asli: <?= date('d/m/y', strtotime($row['tgl_penjualan'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-muted">
                                        <?php if($is_virtual || $is_virtual_qris): ?>
                                            <span class="text-dark fw-500"><?= date('d/m/y', strtotime($row['tgl_penjualan'])) ?></span>
                                        <?php else: ?>
                                            <span class="text-dark fw-500"><?= date('d/m/y', strtotime($row['tgl_penjualan'])) ?></span>
                                            <br><small><?= substr($row['jam'], 0, 5) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-secondary border rounded-pill px-2"><?= $row['shift'] ?? '-' ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['nama_pelanggan']) ?> <?= $ket_tambahan ?></div>
                                        <small class="text-muted" style="font-size:0.7em"><?= substr(htmlspecialchars(trim($keterangan_bersih)), 0, 35) ?><?= strlen($keterangan_bersih) > 35 ? '...' : '' ?></small>
                                    </td>
                                    
                                    <td class="text-end fw-bold text-dark">
                                        <?= ($is_virtual || $is_virtual_qris) ? $display_total : 'Rp '.$display_total ?>
                                    </td>
                                    
                                    <td class="text-end">
                                        <?php if($display_masuk > 0): ?>
                                            <span class="fw-bold text-success">Rp <?= number_format($display_masuk, 0, ',', '.') ?></span>
                                            <br><small class="text-muted fst-italic" style="font-size:0.7rem;">(<?= $display_metode ?>)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-center">
                                        <?php if ($is_virtual): ?>
                                            <span class="badge bg-success rounded-pill px-2">Cicilan</span>
                                        <?php elseif($is_virtual_qris): ?>
                                            <span class="badge bg-info text-dark rounded-pill px-2">QRIS</span>
                                        <?php elseif($row['pelunasan'] == 'Y'): ?>
                                            <span class="badge bg-success rounded-pill px-2">Lunas</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill px-2">Utang</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center pe-4">
                                        <div class="d-flex justify-content-center gap-2">
                                            <button class="btn btn-light border btn-sm text-primary shadow-sm" onclick="showDetail('<?= $row['no_penjualan'] ?>')" title="Detail Nota"><i class="fas fa-eye"></i></button>
                                            <button class="btn btn-light border btn-sm text-dark shadow-sm" onclick='bukaModalEditCustomer(<?= json_encode($row["no_penjualan"] ?? "") ?>, <?= json_encode($row["nama_pelanggan"] ?? "") ?>, <?= json_encode($row["kode_pelanggan"] ?? "") ?>)' title="Edit Nama Customer"><i class="fas fa-user-edit"></i></button>
                                            <a href="cetak_direct.php?id=<?= $row['no_penjualan'] ?>" target="_blank" class="btn btn-light border btn-sm text-secondary shadow-sm" title="Print Struk"><i class="fas fa-print"></i></a>
                                            <button class="btn btn-success btn-sm shadow-sm" onclick="bukaModalKirimWa('<?= $row['no_penjualan'] ?>', '<?= htmlspecialchars($row['no_telepon'] ?? '') ?>', '<?= htmlspecialchars($row['wa_lid'] ?? '') ?>')" title="Kirim Ulang WA"><i class="fab fa-whatsapp"></i></button>
                                            <a href="../order/index.php?id=<?= $row['no_penjualan'] ?>" target="_blank" class="btn btn-warning btn-sm text-dark shadow-sm" title="Manajemen Order Produksi"><i class="fas fa-tasks"></i></a>
                                            <?php if($is_virtual): ?>
                                                <button class="btn btn-light border btn-sm text-info shadow-sm" onclick="bukaModalEditTglCicilan('<?= (int)($row['virtual_arus_kas_id'] ?? 0) ?>', '<?= $row['no_penjualan'] ?>', '<?= date('Y-m-d', strtotime($row['virtual_tanggal_lama'] ?? $row['tgl_penjualan'])) ?>', '<?= number_format((float)($row['virtual_nominal'] ?? 0), 0, ',', '.') ?>', '<?= htmlspecialchars($row['virtual_metode'] ?? '-', ENT_QUOTES) ?>')" title="Edit Tanggal Cicilan"><i class="fas fa-calendar-day"></i></button>
                                            <?php elseif(!$is_virtual && !$is_virtual_qris): ?>
                                                <button class="btn btn-light border btn-sm text-info shadow-sm" onclick="bukaModalEditTgl('<?= $row['no_penjualan'] ?>', '<?= date('Y-m-d', strtotime($row['tgl_penjualan'])) ?>')" title="Edit Tanggal"><i class="fas fa-calendar-alt"></i></button>
                                                <button class="btn btn-light border btn-sm text-success shadow-sm" onclick="bukaModalGantiMetode('<?= $row['no_penjualan'] ?>', '<?= htmlspecialchars($display_metode, ENT_QUOTES) ?>')" title="Ganti Metode Pembayaran"><i class="fas fa-money-bill-transfer"></i></button>
                                            <?php endif; ?>
                                            <?php if($_SESSION['level'] == 'admin' && !$is_virtual && !$is_virtual_qris): ?>
                                            <button class="btn btn-light border btn-sm text-danger shadow-sm" onclick="konfirmasiHapus('<?= $row['no_penjualan'] ?>')" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if(empty($final_data)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="fas fa-box-open fa-3x text-muted opacity-25 mb-3"></i>
                                        <h6 class="text-muted fw-bold">Tidak ada data transaksi.</h6>
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
</div>

<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-receipt text-primary me-2"></i>Detail: <span id="modalNoTrx" class="text-primary"></span></h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="info-struk shadow-sm">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted fw-bold">Pelanggan:</span><span class="fw-bold text-dark" id="detPelanggan">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted fw-bold">Kasir:</span><span class="fw-bold text-dark" id="detKasir">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted fw-bold">Waktu:</span><span class="fw-bold text-dark" id="detWaktu">-</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                        <span class="text-muted fw-bold">Metode:</span><span class="fw-bold text-primary text-end" id="detMetode">-</span>
                    </div>
                </div>
                
                <div class="bg-white rounded-3 border shadow-sm p-3 mb-3 overflow-auto" style="max-height: 200px;">
                    <table class="table table-sm table-borderless mb-0 align-middle">
                        <thead class="border-bottom text-muted" style="font-size:0.75rem;">
                            <tr><th>Item</th><th class="text-end">Harga</th><th class="text-center">Qty</th><th class="text-end">Subtotal</th></tr>
                        </thead>
                        <tbody id="modalBody" style="font-size:0.85rem;"></tbody>
                    </table>
                </div>

                <div id="modalSummary" class="bg-white rounded-3 border shadow-sm p-3">
                    <div class="modal-detail-row"><span>Total Barang</span><span class="fw-bold" id="sumTotalBarang">0</span></div>
                    <div class="modal-detail-row text-success" id="rowDiskon" style="display:none;"><span>Diskon Nota</span><span class="fw-bold" id="sumDiskon">0</span></div>
                    <div class="modal-detail-row text-primary" id="rowOngkir" style="display:none;"><span>Biaya Ongkir</span><span class="fw-bold" id="sumOngkir">0</span></div>
                    <div class="modal-detail-row bold fs-5 mt-2 text-primary"><span>GRAND TOTAL</span><span id="sumGrandTotal">0</span></div>
                    <div class="modal-detail-row mt-2 text-muted"><span>Uang Masuk (Sah)</span><span class="fw-bold text-dark" id="sumBayar">0</span></div>
                    <div class="modal-detail-row text-danger" id="rowHutang" style="display:none;"><span>Sisa Piutang</span><span class="fw-bold" id="sumHutang">0</span></div>
                    <div class="modal-detail-row text-success" id="rowKembali" style="display:none;"><span>Kembali</span><span class="fw-bold" id="sumKembali">0</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalKirimWa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fab fa-whatsapp text-success fs-5 me-2"></i>Kirim Ulang Nota WA</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" id="waNoPenjualan">
                <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
                    <div class="small text-muted fw-bold mb-1 text-uppercase">Nota Transaksi</div>
                    <h4 class="fw-bolder text-primary mb-0" id="waLabelNota">TRX-123</h4>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted">Nomor Tujuan (HP / LID)</label>
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="fab fa-whatsapp text-success"></i></span>
                        <input type="text" id="waInputNomor" class="form-control border-start-0 py-2 fw-bold" placeholder="08xxx / 12345@lid" style="box-shadow:none;">
                    </div>
                    <div class="form-text" style="font-size:0.75rem;"><i class="fas fa-info-circle me-1"></i> Sistem otomatis melacak nomor HP asli di database.</div>
                </div>
                <button type="button" class="btn btn-success w-100 fw-bold py-2 rounded-3 shadow-sm" id="btnProsesKirimWa" onclick="prosesKirimUlangWa()">
                    <i class="fas fa-paper-plane me-2"></i> KIRIM SEKARANG
                </button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="modalGantiMetode" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-money-bill-transfer text-success me-2"></i>Ganti Metode Pembayaran</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" id="metodeNoPenjualan">
                <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
                    <div class="small text-muted fw-bold mb-1 text-uppercase">Nota Transaksi</div>
                    <h4 class="fw-bolder text-primary mb-1" id="metodeLabelNota">TRX-123</h4>
                    <div class="small text-muted">Metode sekarang: <span class="fw-bold text-dark" id="metodeSekarangLabel">-</span></div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted">Metode Pembayaran Baru</label>
                    <select id="metodeInputBaru" class="form-select py-2 fw-bold shadow-sm rounded-3">
                        <option value="Cash">Cash</option>
                        <option value="QRIS">QRIS</option>
                        <option value="Debit">Debit</option>
                        <option value="Transfer">Transfer</option>
                    </select>
                    <div class="form-text" style="font-size:0.75rem;"><i class="fas fa-info-circle me-1"></i> Perubahan ini ikut memperbarui tabel penjualan dan arus kas untuk nota tersebut.</div>
                </div>
                <button type="button" class="btn btn-success w-100 fw-bold py-2 rounded-3 shadow-sm" id="btnProsesGantiMetode" onclick="prosesGantiMetode()">
                    <i class="fas fa-save me-2"></i> SIMPAN METODE PEMBAYARAN
                </button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="modalEditCustomer" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-user-edit text-dark me-2"></i>Edit Customer Nota</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" id="editCustomerNoPenjualan">
                <input type="hidden" id="editCustomerKodePelangganLama">
                <input type="hidden" id="editCustomerKodePelangganBaru">
                <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
                    <div class="small text-muted fw-bold mb-1 text-uppercase">Nota Transaksi</div>
                    <h4 class="fw-bolder text-primary mb-1" id="editCustomerLabelNota">TRX-123</h4>
                    <div class="small text-muted">Customer sekarang: <span class="fw-bold text-dark" id="editCustomerNamaLama">-</span></div>
                </div>

                <div class="alert alert-info border-0 rounded-3 py-2 px-3 small mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    Perubahan ini <b>hanya untuk nota ini saja</b>. Data master pelanggan tidak diubah.
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Ambil Nama dari Data Pelanggan</label>
                    <div class="input-group shadow-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="editCustomerSearch" class="form-control py-2 fw-bold border-start-0" placeholder="Cari nama / nomor HP / kode pelanggan">
                    </div>
                    <div class="form-text" style="font-size:0.75rem;">Ketik minimal 1 huruf, lalu pilih customer dari hasil pencarian.</div>
                </div>

                <div class="bg-white rounded-3 border shadow-sm mb-3" style="max-height: 260px; overflow:auto;">
                    <div id="editCustomerSearchResults" class="list-group list-group-flush small">
                        <div class="text-center text-muted py-4">Ketik nama customer untuk mencari data pelanggan.</div>
                    </div>
                </div>

                <div id="editCustomerSelectedBox" class="bg-white rounded-3 border p-3 shadow-sm mb-4" style="display:none;">
                    <div class="small text-muted fw-bold mb-1">Customer yang dipilih</div>
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-bold text-dark" id="editCustomerSelectedName">-</div>
                            <div class="small text-muted" id="editCustomerSelectedMeta">-</div>
                        </div>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check me-1"></i>Dipilih</span>
                    </div>
                </div>

                <button type="button" class="btn btn-dark w-100 fw-bold py-2 rounded-3 shadow-sm" id="btnProsesEditCustomer" onclick="prosesEditCustomer()">
                    <i class="fas fa-save me-2"></i> SIMPAN CUSTOMER UNTUK NOTA INI
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditTgl" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-calendar-alt text-primary me-2"></i>Edit Tanggal Transaksi</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" id="editTglNoPenjualan">
                <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
                    <div class="small text-muted fw-bold mb-1 text-uppercase">Nota Transaksi</div>
                    <h4 class="fw-bolder text-primary mb-0" id="editTglLabelNota">TRX-123</h4>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted">Tanggal Transaksi Baru</label>
                    <input type="date" id="editTglInputBaru" class="form-control py-2 fw-bold shadow-sm rounded-3">
                </div>
                <button type="button" class="btn btn-primary w-100 fw-bold py-2 rounded-3 shadow-sm" id="btnProsesEditTgl" onclick="prosesEditTgl()">
                    <i class="fas fa-save me-2"></i> SIMPAN PERUBAHAN TANGGAL
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditTglCicilan" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-calendar-day text-success me-2"></i>Edit Tanggal Pembayaran Cicilan</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" id="editCicilanArusKasId">
                <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
                    <div class="small text-muted fw-bold mb-1 text-uppercase">Pembayaran Cicilan Nota</div>
                    <h4 class="fw-bolder text-success mb-1" id="editCicilanLabelNota">TRX-123</h4>
                    <div class="small text-muted">Nominal: <span class="fw-bold text-dark" id="editCicilanNominal">0</span> • <span id="editCicilanMetode">-</span></div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted">Tanggal Pembayaran Cicilan Baru</label>
                    <input type="date" id="editCicilanTanggalBaru" class="form-control py-2 fw-bold shadow-sm rounded-3">
                    <div class="form-text" style="font-size:0.75rem;"><i class="fas fa-info-circle me-1"></i>Ini hanya mengubah tanggal pembayaran/cicilan di arus kas, bukan tanggal nota pembelian asal.</div>
                </div>
                <button type="button" class="btn btn-success w-100 fw-bold py-2 rounded-3 shadow-sm" id="btnProsesEditTglCicilan" onclick="prosesEditTglCicilan()">
                    <i class="fas fa-save me-2"></i> SIMPAN TANGGAL CICILAN
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // --- JAM REALTIME ---
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const clockEl = document.getElementById('realtimeClock');
        if(clockEl) clockEl.textContent = `${hours}:${minutes}:${seconds} WIB`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    function showDetail(no_penjualan) {
        document.getElementById('modalNoTrx').innerText = no_penjualan;
        const tbody = document.getElementById('modalBody');
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4"><div class="spinner-border text-primary spinner-border-sm"></div> Memuat...</td></tr>';
        new bootstrap.Modal(document.getElementById('modalDetail')).show();

        fetch(`get_detail.php?id=${no_penjualan}`)
            .then(response => response.json())
            .then(data => {
                let html = '';
                let subtotal_barang = 0;
                document.getElementById('detPelanggan').innerText = data.header.nama_pelanggan;
                document.getElementById('detKasir').innerText = data.header.nama_user;
                document.getElementById('detWaktu').innerText = data.header.tgl_penjualan + ' ' + data.header.jam;
                
                let ket_mentah = data.header.keterangan || '';
                
                let is_verif_qris = (data.header.is_verif_qris === 'Y') || (ket_mentah.match(/\[Verif:QRIS/i) !== null);
                let metode_tampil = data.header.metode_pembayaran || ket_mentah.replace(/\[Verif:QRIS:.*?\]/ig, '').replace(/\[Verif:QRIS\]/ig, '').trim();
                
                if (is_verif_qris) {
                    let tgl_verif = data.header.tgl_verif_qris ? data.header.tgl_verif_qris : '';
                    metode_tampil += ' <span class="badge bg-info text-dark rounded-pill"><i class="fas fa-qrcode"></i> Auto-QRIS ' + tgl_verif + '</span>';
                }
                if(!metode_tampil) metode_tampil = 'CASH';
                document.getElementById('detMetode').innerHTML = metode_tampil;
                
                data.items.forEach(item => {
                    let sub = (item.harga_jual * item.jumlah) - item.diskon;
                    subtotal_barang += sub;
                    html += `<tr><td><span class="fw-bold text-dark">${item.nama_barang}</span></td><td class="text-end text-muted">${parseInt(item.harga_jual).toLocaleString()}</td><td class="text-center fw-bold">${item.jumlah}</td><td class="text-end fw-bold text-dark">${sub.toLocaleString()}</td></tr>`;
                });
                tbody.innerHTML = html;

                let ongkir = data.header.ongkir || 0;
                let grand_total = data.header.total_omzet;
                let diskon_nota = (subtotal_barang + ongkir) - grand_total;
                if(diskon_nota < 0) diskon_nota = 0;
                
                let nominal_deposit = data.header.nominal_deposit || 0;
                let uang_bayar_cash = data.header.uang_bayar || 0;
                
                let verif_qris_nominal = 0;
                if (is_verif_qris && data.header.pelunasan === 'Y') {
                    verif_qris_nominal = grand_total - nominal_deposit - uang_bayar_cash;
                    if (verif_qris_nominal < 0) verif_qris_nominal = 0;
                }
                
                let total_uang_masuk_sah = uang_bayar_cash + nominal_deposit + verif_qris_nominal;
                let sisa_hutang = 0; let kembali = 0;

                if (data.header.pelunasan === 'N') {
                    sisa_hutang = grand_total - total_uang_masuk_sah;
                    if(sisa_hutang < 0) sisa_hutang = 0;
                } else { 
                    kembali = total_uang_masuk_sah - grand_total; 
                    if(kembali < 0) kembali = 0; 
                }

                document.getElementById('sumTotalBarang').innerText = subtotal_barang.toLocaleString();
                document.getElementById('sumGrandTotal').innerText = parseInt(grand_total).toLocaleString();
                document.getElementById('sumBayar').innerText = parseInt(total_uang_masuk_sah).toLocaleString();

                if(ongkir > 0) { document.getElementById('rowOngkir').style.display = 'flex'; document.getElementById('sumOngkir').innerText = parseInt(ongkir).toLocaleString(); }
                else document.getElementById('rowOngkir').style.display = 'none';

                if(diskon_nota > 0) { document.getElementById('rowDiskon').style.display = 'flex'; document.getElementById('sumDiskon').innerText = '-' + parseInt(diskon_nota).toLocaleString(); }
                else document.getElementById('rowDiskon').style.display = 'none';

                if(sisa_hutang > 0) { document.getElementById('rowHutang').style.display = 'flex'; document.getElementById('sumHutang').innerText = parseInt(sisa_hutang).toLocaleString(); document.getElementById('rowKembali').style.display = 'none'; }
                else { document.getElementById('rowHutang').style.display = 'none'; document.getElementById('rowKembali').style.display = 'flex'; document.getElementById('sumKembali').innerText = parseInt(kembali).toLocaleString(); }
            });
    }
    
    function bukaModalKirimWa(noNota, noTelp, waLid) {
        document.getElementById('waLabelNota').innerText = noNota;
        document.getElementById('waNoPenjualan').value = noNota;
        
        let noAsli = '';
        if (noTelp) {
            noAsli = noTelp.replace(/[^0-9]/g, '');
            if (!noAsli.startsWith('62') && !noAsli.startsWith('08') && waLid) {
                noAsli = waLid.replace(/[^0-9]/g, '');
            }
        } else if (waLid) {
            noAsli = waLid.replace(/[^0-9]/g, '');
        }

        document.getElementById('waInputNomor').value = noAsli;
        var modal = new bootstrap.Modal(document.getElementById('modalKirimWa'));
        modal.show();
    }

    function prosesKirimUlangWa() {
        let noFaktur = document.getElementById('waNoPenjualan').value;
        let noTujuan = document.getElementById('waInputNomor').value;
        
        if(!noTujuan || noTujuan === 'Mencari...') {
            Swal.fire('Warning', 'Nomor tujuan tidak boleh kosong', 'warning');
            return;
        }

        let btn = document.getElementById('btnProsesKirimWa');
        let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Mengirim...';
        btn.disabled = true;

        let payload = {
            no_penjualan: noFaktur,
            nomor_hp: noTujuan,
            utang_lama_input: 0, 
            total_bayar_input: 0, 
            depo_awal: 0,
            depo_pakai: 0
        };

        fetch('kirim_wa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = ori; btn.disabled = false;
            if(data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalKirimWa')).hide();
                Swal.fire('Sukses!', 'Nota berhasil dikirim ulang ke WhatsApp.', 'success');
            } else {
                Swal.fire('Gagal', data.message, 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = ori; btn.disabled = false;
            Swal.fire('Error', 'Gagal menghubungi server.', 'error');
        });
    }

    function konfirmasiHapus(id) {
        Swal.fire({
            title: 'Hapus Transaksi?', text: "Data " + id + " akan dihapus permanen!", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#3085d6', confirmButtonText: 'Ya, Hapus!', cancelButtonText: 'Batal'
        }).then((result) => { if (result.isConfirmed) window.location.href = 'hapus_trx.php?id=' + id; })
    }



    let editCustomerSearchTimer = null;

    function escapeHtmlRiwayat(str) {
        return (str || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function bukaModalEditCustomer(noNota, namaLama, kodePelanggan) {
        document.getElementById('editCustomerNoPenjualan').value = noNota || '';
        document.getElementById('editCustomerKodePelangganLama').value = kodePelanggan || '';
        document.getElementById('editCustomerKodePelangganBaru').value = kodePelanggan || '';
        document.getElementById('editCustomerLabelNota').innerText = noNota || '-';
        document.getElementById('editCustomerNamaLama').innerText = namaLama || '-';
        document.getElementById('editCustomerSearch').value = '';

        if (kodePelanggan) {
            setEditCustomerSelected(kodePelanggan, namaLama || '-', 'Customer saat ini');
        } else {
            clearEditCustomerSelected();
        }

        const resultBox = document.getElementById('editCustomerSearchResults');
        resultBox.innerHTML = '<div class="text-center text-muted py-4">Ketik nama customer untuk mencari data pelanggan.</div>';

        new bootstrap.Modal(document.getElementById('modalEditCustomer')).show();
        setTimeout(() => document.getElementById('editCustomerSearch').focus(), 300);
    }

    function clearEditCustomerSelected() {
        document.getElementById('editCustomerKodePelangganBaru').value = '';
        document.getElementById('editCustomerSelectedBox').style.display = 'none';
        document.getElementById('editCustomerSelectedName').innerText = '-';
        document.getElementById('editCustomerSelectedMeta').innerText = '-';
    }

    function setEditCustomerSelected(kode, nama, telp) {
        document.getElementById('editCustomerKodePelangganBaru').value = kode || '';
        document.getElementById('editCustomerSelectedName').innerText = nama || '-';
        document.getElementById('editCustomerSelectedMeta').innerText = [kode || '', telp || ''].filter(Boolean).join(' • ') || '-';
        document.getElementById('editCustomerSelectedBox').style.display = 'block';
    }

    function renderEditCustomerResults(rows) {
        const box = document.getElementById('editCustomerSearchResults');
        if (!rows || rows.length === 0) {
            box.innerHTML = '<div class="text-center text-muted py-4">Customer tidak ditemukan.</div>';
            return;
        }

        box.innerHTML = rows.map(row => {
            const kode = row.kode_pelanggan || '';
            const nama = row.nama_pelanggan || '';
            const telp = row.no_telepon || '';
            return `
                <button type="button" class="list-group-item list-group-item-action py-3" onclick='setEditCustomerSelected(${JSON.stringify(kode)}, ${JSON.stringify(nama)}, ${JSON.stringify(telp)})'>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-bold text-dark">${escapeHtmlRiwayat(nama)}</div>
                            <div class="small text-muted">${escapeHtmlRiwayat(kode)}${telp ? ' • ' + escapeHtmlRiwayat(telp) : ''}</div>
                        </div>
                        <span class="badge bg-light text-dark border rounded-pill">Pilih</span>
                    </div>
                </button>
            `;
        }).join('');
    }

    function searchEditCustomer(q) {
        const box = document.getElementById('editCustomerSearchResults');
        if ((q || '').trim() === '') {
            box.innerHTML = '<div class="text-center text-muted py-4">Ketik nama customer untuk mencari data pelanggan.</div>';
            return;
        }

        box.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Mencari customer...</div>';
        fetch('edit_customer.php?action=search&q=' + encodeURIComponent(q), { cache: 'no-store' })
            .then(async res => {
                const text = await res.text();
                try { return JSON.parse(text); }
                catch(e) { throw new Error(text || 'Response server bukan JSON.'); }
            })
            .then(data => {
                if (data.status === 'success') renderEditCustomerResults(data.data || []);
                else box.innerHTML = '<div class="text-center text-danger py-4">' + escapeHtmlRiwayat(data.message || 'Gagal mencari customer.') + '</div>';
            })
            .catch(err => {
                box.innerHTML = '<div class="text-center text-danger py-4">Gagal mencari customer. ' + escapeHtmlRiwayat(err.message || '') + '</div>';
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('editCustomerSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(editCustomerSearchTimer);
                const q = this.value;
                editCustomerSearchTimer = setTimeout(() => searchEditCustomer(q), 250);
            });
        }
    });

    function prosesEditCustomer() {
        let noFaktur = document.getElementById('editCustomerNoPenjualan').value;
        let kodePelanggan = document.getElementById('editCustomerKodePelangganBaru').value;

        if(!noFaktur || !kodePelanggan) {
            Swal.fire('Warning', 'Pilih customer dari data pelanggan dulu.', 'warning');
            return;
        }

        let btn = document.getElementById('btnProsesEditCustomer');
        let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Menyimpan...';
        btn.disabled = true;

        fetch('edit_customer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                no_penjualan: noFaktur,
                kode_pelanggan: kodePelanggan
            })
        })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch(e) { throw new Error(text || 'Response server bukan JSON.'); }
        })
        .then(data => {
            btn.innerHTML = ori; btn.disabled = false;
            if(data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalEditCustomer')).hide();
                Swal.fire('Sukses!', data.message || 'Customer nota berhasil diperbarui.', 'success')
                    .then(() => location.reload());
            } else {
                Swal.fire('Gagal', data.message || 'Terjadi kesalahan.', 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = ori; btn.disabled = false;
            Swal.fire('Error', 'Gagal menghubungi server. ' + (err.message || ''), 'error');
        });
    }

    function normalisasiMetodeRiwayat(metode) {
        metode = (metode || '').toString().toLowerCase();
        if (metode.includes('qris') || metode.includes('qr') || metode.includes('dana') || metode.includes('gopay') || metode.includes('ovo')) return 'QRIS';
        if (metode.includes('debit') || metode.includes('edc') || metode.includes('card') || metode.includes('kartu')) return 'Debit';
        if (metode.includes('transfer') || metode.includes('tf') || metode.includes('trf') || metode.includes('bank')) return 'Transfer';
        return 'Cash';
    }

    function bukaModalGantiMetode(noNota, metodeSekarang) {
        let metodeNormal = normalisasiMetodeRiwayat(metodeSekarang);
        document.getElementById('metodeLabelNota').innerText = noNota;
        document.getElementById('metodeNoPenjualan').value = noNota;
        document.getElementById('metodeSekarangLabel').innerText = metodeSekarang || '-';
        document.getElementById('metodeInputBaru').value = metodeNormal;
        new bootstrap.Modal(document.getElementById('modalGantiMetode')).show();
    }

    function prosesGantiMetode() {
        let noFaktur = document.getElementById('metodeNoPenjualan').value;
        let metodeBaru = document.getElementById('metodeInputBaru').value;

        if(!noFaktur || !metodeBaru) {
            Swal.fire('Warning', 'Nota dan metode pembayaran wajib dipilih.', 'warning');
            return;
        }

        let btn = document.getElementById('btnProsesGantiMetode');
        let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Menyimpan...';
        btn.disabled = true;

        fetch('ganti_metode.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ no_penjualan: noFaktur, metode_baru: metodeBaru })
        })
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = ori; btn.disabled = false;
            if(data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalGantiMetode')).hide();
                Swal.fire('Sukses!', data.message || 'Metode pembayaran berhasil diperbarui.', 'success')
                    .then(() => location.reload());
            } else {
                Swal.fire('Gagal', data.message || 'Terjadi kesalahan.', 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = ori; btn.disabled = false;
            Swal.fire('Error', 'Gagal menghubungi server.', 'error');
        });
    }

    function bukaModalEditTglCicilan(arusKasId, noNota, tglLama, nominal, metode) {
        if(!arusKasId || parseInt(arusKasId) <= 0) {
            Swal.fire('Gagal', 'ID pembayaran cicilan tidak terbaca. Silakan refresh halaman.', 'error');
            return;
        }
        document.getElementById('editCicilanArusKasId').value = arusKasId;
        document.getElementById('editCicilanLabelNota').innerText = noNota;
        document.getElementById('editCicilanTanggalBaru').value = tglLama;
        document.getElementById('editCicilanNominal').innerText = 'Rp ' + (nominal || '0');
        document.getElementById('editCicilanMetode').innerText = metode || '-';
        new bootstrap.Modal(document.getElementById('modalEditTglCicilan')).show();
    }

    function prosesEditTglCicilan() {
        let arusKasId = document.getElementById('editCicilanArusKasId').value;
        let tglBaru = document.getElementById('editCicilanTanggalBaru').value;

        if(!arusKasId || !tglBaru) {
            Swal.fire('Warning', 'Tanggal cicilan tidak boleh kosong.', 'warning');
            return;
        }

        let btn = document.getElementById('btnProsesEditTglCicilan');
        let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Menyimpan...';
        btn.disabled = true;

        fetch('edit_tanggal_cicilan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ arus_kas_id: arusKasId, tgl_baru: tglBaru })
        })
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = ori; btn.disabled = false;
            if(data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalEditTglCicilan')).hide();
                Swal.fire('Sukses!', data.message || 'Tanggal pembayaran cicilan berhasil diperbarui.', 'success')
                    .then(() => location.reload());
            } else {
                Swal.fire('Gagal', data.message || 'Terjadi kesalahan.', 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = ori; btn.disabled = false;
            Swal.fire('Error', 'Gagal menghubungi server.', 'error');
        });
    }

    function bukaModalEditTgl(noNota, tglLama) {
        document.getElementById('editTglLabelNota').innerText = noNota;
        document.getElementById('editTglNoPenjualan').value = noNota;
        document.getElementById('editTglInputBaru').value = tglLama;
        new bootstrap.Modal(document.getElementById('modalEditTgl')).show();
    }

    function prosesEditTgl() {
        let noFaktur = document.getElementById('editTglNoPenjualan').value;
        let tglBaru = document.getElementById('editTglInputBaru').value;

        if(!tglBaru) {
            Swal.fire('Warning', 'Tanggal tidak boleh kosong!', 'warning');
            return;
        }

        let btn = document.getElementById('btnProsesEditTgl');
        let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Menyimpan...';
        btn.disabled = true;

        fetch('edit_tanggal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ no_penjualan: noFaktur, tgl_baru: tglBaru })
        })
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = ori; btn.disabled = false;
            if(data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalEditTgl')).hide();
                Swal.fire('Sukses!', 'Tanggal transaksi berhasil diperbarui.', 'success')
                    .then(() => location.reload());
            } else {
                Swal.fire('Gagal', data.message || 'Terjadi kesalahan.', 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = ori; btn.disabled = false;
            Swal.fire('Error', 'Gagal menghubungi server.', 'error');
        });
    }

    // --- PENANGKAP ALERT HAPUS TRANSAKSI ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('hapus') === 'sukses') {
        Swal.fire({
            icon: 'success',
            title: 'Berhasil Dihapus!',
            text: 'Transaksi, riwayat arus kas, dan seluruh data produksi berhasil dihapus permanen!',
            confirmButtonColor: '#1a56db',
            iconColor: '#10b981', // Warna hijau modern
            backdrop: `rgba(0,0,0,0.4)`
        }).then(() => {
            // Bersihkan URL agar alert tidak muncul lagi saat halaman di-refresh
            window.history.replaceState(null, null, window.location.pathname);
        });
    }
</script>
</body>
</html>