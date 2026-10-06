<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// 1. SETUP FILTER TANGGAL
$tgl_awal  = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$tgl_akhir = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

// --- FUNGSI BANTU ---
function cleanNum($str) {
    $str = preg_replace('/[^0-9,.]/', '', $str);
    if (strpos($str, '.') !== false && strpos($str, ',') !== false) {
        $str = str_replace('.', '', $str); $str = str_replace(',', '.', $str);
    } elseif (strpos($str, '.') !== false) {
        if (substr_count($str, '.') > 1 || strlen(explode('.', $str)[1]) == 3) $str = str_replace('.', '', $str);
    }
    return (float)$str;
}

function getMetode($teks_utama, $teks_cadangan = '') {
    $t = strtolower(trim($teks_utama ?? '')); 
    if (strpos($t, 'non tunai') !== false || strpos($t, 'non-tunai') !== false || $t === 'nontunai') return 'Non-Tunai';
    if (preg_match('/\b(cash|tunai)\b/', $t)) return 'Cash';
    if (preg_match('/(qris|dana|gopay|ovo|shopee|linkaja|qr|spay|paylater)/', $t)) return 'QRIS';
    if (preg_match('/(transfer|trf|tf|bca|bri|bni|mandiri|bsi|cimb|jago|seabank|bank|atm)/', $t)) return 'Transfer';
    if (preg_match('/(debit|edc|kartu|card|gesek)/', $t)) return 'Debit';
    
    $f = strtolower(trim($teks_cadangan ?? ''));
    if (!empty($f)) {
        if (strpos($f, 'non tunai') !== false || strpos($f, 'non-tunai') !== false || $f === 'nontunai') return 'Non-Tunai';
        if (preg_match('/\b(qris|dana|gopay|ovo|shopee|linkaja|qr|spay|paylater)\b/', $f)) return 'QRIS';
        if (preg_match('/\b(transfer|trf|tf|bca|bri|bni|mandiri|bsi|cimb|jago|seabank|bank|atm)\b/', $f)) return 'Transfer';
        if (preg_match('/\b(debit|edc|kartu|card|gesek)\b/', $f)) return 'Debit';
    }
    return 'Cash'; 
}

// ARRAY PENYIMPAN DATA PER TANGGAL
$rekap_harian = [];
$current_date = $tgl_awal;
while (strtotime($current_date) <= strtotime($tgl_akhir)) {
    $rekap_harian[$current_date] = [
        'trx_count' => 0, 
        'cash_in' => 0, 
        'non_tunai' => 0, 
        'pengeluaran' => 0, 
        'bersih' => 0,
        'rincian_non_tunai' => [], 
        'rincian_pengeluaran' => [], // WADAH BARU UNTUK INFO PENGELUARAN
        'rincian_cash_in' => [] // <--- WADAH BARU UNTUK RINCIAN CASH
    ];
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// =========================================================================
// 1. AMBIL PEMASUKAN UTAMA DARI TABEL ARUS KAS
// =========================================================================
$sql_arus = "SELECT tanggal, metode_pembayaran, keterangan, jumlah_masuk, no_penjualan 
             FROM arus_kas 
             WHERE tanggal BETWEEN ? AND ? 
             AND jumlah_masuk > 0 
             AND no_penjualan IS NOT NULL";
$stmt_arus = $pdo->prepare($sql_arus);
$stmt_arus->execute([$tgl_awal, $tgl_akhir]);

$processed_trx = []; 
foreach ($stmt_arus->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tgl = $row['tanggal'];
    if (isset($rekap_harian[$tgl])) {
        $nota = $row['no_penjualan'];
        if (!isset($processed_trx[$tgl][$nota])) {
            $rekap_harian[$tgl]['trx_count']++;
            $processed_trx[$tgl][$nota] = true;
        }

        $metode_masuk = getMetode($row['metode_pembayaran'] ?? '', $row['keterangan'] ?? '');
        if ($metode_masuk == 'Cash') {
            $rekap_harian[$tgl]['cash_in'] += $row['jumlah_masuk'];

            // Simpan rincian cash in
            $rekap_harian[$tgl]['rincian_cash_in'][] = [
                'nota' => $nota,
                'nominal' => $row['jumlah_masuk']
            ];
        } else {
            $rekap_harian[$tgl]['non_tunai'] += $row['jumlah_masuk'];
            
            if(!isset($rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk])) {
                $rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk] = 0;
            }
            $rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk] += $row['jumlah_masuk'];
        }
    }
}


// =========================================================================
// 1B. FALLBACK CASH DARI TABEL PENJUALAN
// =========================================================================
// Catatan:
// Riwayat transaksi menghitung Cash Masuk dari penjualan.uang_bayar
// jika pembayaran awal belum tercatat di arus_kas. Laporan Cash sebelumnya
// hanya membaca arus_kas, sehingga bisa lebih kecil dari Riwayat.
// Bagian ini menambahkan selisih cash yang belum ada di arus_kas agar sinkron.
$sql_cash_fallback = "
    SELECT 
        p.no_penjualan,
        p.tgl_penjualan,
        p.metode_pembayaran,
        p.keterangan,
        p.uang_bayar,
        p.is_verif_qris,
        COALESCE(SUM(CASE WHEN ak.jenis = 'Pemasukan' AND ak.jumlah_masuk > 0 THEN ak.jumlah_masuk ELSE 0 END), 0) AS total_arus_masuk
    FROM penjualan p
    LEFT JOIN arus_kas ak ON ak.no_penjualan = p.no_penjualan
    WHERE p.tgl_penjualan BETWEEN ? AND ?
      AND COALESCE(p.uang_bayar, 0) > 0
    GROUP BY p.no_penjualan, p.tgl_penjualan, p.metode_pembayaran, p.keterangan, p.uang_bayar, p.is_verif_qris
";
$stmt_cash_fallback = $pdo->prepare($sql_cash_fallback);
$stmt_cash_fallback->execute([$tgl_awal, $tgl_akhir]);
foreach ($stmt_cash_fallback->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tgl = $row['tgl_penjualan'];
    if (!isset($rekap_harian[$tgl])) continue;

    $metode_penjualan = getMetode($row['metode_pembayaran'] ?? '', $row['keterangan'] ?? '');

    // Jika transaksi sudah diverifikasi QRIS, jangan dianggap cash.
    if (($row['is_verif_qris'] ?? 'N') === 'Y') continue;

    // Fallback ini hanya untuk cash/tunai.
    if ($metode_penjualan !== 'Cash') continue;

    $uang_bayar = (float)($row['uang_bayar'] ?? 0);
    $total_arus_masuk = (float)($row['total_arus_masuk'] ?? 0);
    $sisa_cash_belum_tercatat = $uang_bayar - $total_arus_masuk;

    // Toleransi kecil supaya tidak muncul selisih receh.
    if ($sisa_cash_belum_tercatat > 0.5) {
        $rekap_harian[$tgl]['cash_in'] += $sisa_cash_belum_tercatat;

        if (!isset($processed_trx[$tgl][$row['no_penjualan']])) {
            $rekap_harian[$tgl]['trx_count']++;
            $processed_trx[$tgl][$row['no_penjualan']] = true;
        }

        $rekap_harian[$tgl]['rincian_cash_in'][] = [
            'nota' => $row['no_penjualan'] . ' (fallback penjualan)',
            'nominal' => $sisa_cash_belum_tercatat
        ];
    }
}

// =========================================================================
// 2. PEMASUKAN LAIN & DEPOSIT
// =========================================================================
$sql_lain = "SELECT tgl_pemasukan, nominal, metode_bayar, keterangan FROM pemasukan_lain WHERE tgl_pemasukan BETWEEN ? AND ?";
$stmt_lain = $pdo->prepare($sql_lain);
$stmt_lain->execute([$tgl_awal, $tgl_akhir]);
while($lain = $stmt_lain->fetch(PDO::FETCH_ASSOC)) {
    $tgl = $lain['tgl_pemasukan'];
    if (isset($rekap_harian[$tgl])) {
        $metode_masuk = getMetode($lain['metode_bayar'] ?? '', $lain['keterangan'] ?? '');
        if ($metode_masuk == 'Cash') {
            $rekap_harian[$tgl]['cash_in'] += $lain['nominal'];
        } else {
            $rekap_harian[$tgl]['non_tunai'] += $lain['nominal'];
            if(!isset($rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk])) $rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk] = 0;
            $rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk] += $lain['nominal'];
        }
    }
}

try {
    if ($pdo->query("SHOW TABLES LIKE 'log_deposit'")->rowCount() > 0) {
        $sql_depo = "SELECT DATE(tgl_deposit) as tgl, nominal, keterangan FROM log_deposit WHERE DATE(tgl_deposit) BETWEEN ? AND ? AND nominal > 0";
        $stmt_depo = $pdo->prepare($sql_depo);
        $stmt_depo->execute([$tgl_awal, $tgl_akhir]);
        while($depo = $stmt_depo->fetch(PDO::FETCH_ASSOC)) {
            $tgl = $depo['tgl'];
            if (isset($rekap_harian[$tgl])) {
                $metode_masuk = getMetode('', $depo['keterangan'] ?? '');
                if ($metode_masuk == 'Cash') {
                    $rekap_harian[$tgl]['cash_in'] += $depo['nominal'];
                } else {
                    $rekap_harian[$tgl]['non_tunai'] += $depo['nominal'];
                    if(!isset($rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk])) $rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk] = 0;
                    $rekap_harian[$tgl]['rincian_non_tunai'][$metode_masuk] += $depo['nominal'];
                }
            }
        }
    }
} catch (Exception $e) {}

// =========================================================================
// 3. PENGELUARAN OPS 
// =========================================================================
$sql_out = "SELECT tanggal, jumlah, metode_bayar, keterangan FROM pengeluaran_non_stok WHERE tanggal BETWEEN ? AND ?";
$stmt_out = $pdo->prepare($sql_out);
$stmt_out->execute([$tgl_awal, $tgl_akhir]);
while($out = $stmt_out->fetch(PDO::FETCH_ASSOC)) {
    $tgl = $out['tanggal'];
    if (isset($rekap_harian[$tgl])) {
        $metode_out = getMetode($out['metode_bayar'] ?? '', $out['keterangan'] ?? '');
        if ($metode_out == 'Cash') {
            $rekap_harian[$tgl]['pengeluaran'] += $out['jumlah'];
            
            // Simpan rincian pengeluaran untuk ditampilkan di tabel
            $rekap_harian[$tgl]['rincian_pengeluaran'][] = [
                'nama' => trim($out['keterangan']),
                'nominal' => $out['jumlah']
            ];
        }
    }
}

// =========================================================================
// 4. MENCARI HUTANG DARI SEBELUM TANGGAL FILTER (Mundur 365 Hari)
// =========================================================================
$tgl_start_past = date('Y-m-d', strtotime('-365 days', strtotime($tgl_awal)));
$tgl_end_past = date('Y-m-d', strtotime('-1 day', strtotime($tgl_awal)));
$tanggungan_minus = 0;

if (strtotime($tgl_start_past) <= strtotime($tgl_end_past)) {
    $past_rekap = [];
    $curr = $tgl_start_past;
    while(strtotime($curr) <= strtotime($tgl_end_past)) {
        $past_rekap[$curr] = ['in' => 0, 'out' => 0];
        $curr = date('Y-m-d', strtotime('+1 day', strtotime($curr)));
    }

    $stmt_in_past = $pdo->prepare("SELECT tanggal, jumlah_masuk, metode_pembayaran, keterangan FROM arus_kas WHERE tanggal BETWEEN ? AND ? AND jumlah_masuk > 0 AND no_penjualan IS NOT NULL");
    $stmt_in_past->execute([$tgl_start_past, $tgl_end_past]);
    foreach($stmt_in_past->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (getMetode($a['metode_pembayaran'], $a['keterangan']) == 'Cash' && isset($past_rekap[$a['tanggal']])) $past_rekap[$a['tanggal']]['in'] += $a['jumlah_masuk'];
    }

    $stmt_lain_past = $pdo->prepare("SELECT tgl_pemasukan as tgl, nominal, metode_bayar, keterangan FROM pemasukan_lain WHERE tgl_pemasukan BETWEEN ? AND ?");
    $stmt_lain_past->execute([$tgl_start_past, $tgl_end_past]);
    foreach($stmt_lain_past->fetchAll(PDO::FETCH_ASSOC) as $l) { 
        if(getMetode($l['metode_bayar'], $l['keterangan']) == 'Cash' && isset($past_rekap[$l['tgl']])) $past_rekap[$l['tgl']]['in'] += $l['nominal']; 
    }

    $stmt_out_past = $pdo->prepare("SELECT tanggal, jumlah, metode_bayar, keterangan FROM pengeluaran_non_stok WHERE tanggal BETWEEN ? AND ?");
    $stmt_out_past->execute([$tgl_start_past, $tgl_end_past]);
    foreach($stmt_out_past->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if (getMetode($o['metode_bayar'], $o['keterangan']) == 'Cash' && isset($past_rekap[$o['tanggal']])) $past_rekap[$o['tanggal']]['out'] += $o['jumlah'];
    }
    
    foreach ($past_rekap as $tgl => $d) {
        $kalk_bersih = $d['in'] - ($d['out'] + $tanggungan_minus);
        if ($kalk_bersih < 0) {
            $tanggungan_minus = abs($kalk_bersih);
        } else {
            $tanggungan_minus = 0;
        }
    }
}

// =========================================================================
// 5. HITUNG BERSIH & GRAND TOTAL
// =========================================================================
$gt_trx = 0; $gt_cash = 0; $gt_non = 0; $gt_out = 0; $gt_net = 0;

foreach ($rekap_harian as $tgl => $data) {
    $beban_total_hari_ini = $data['pengeluaran'] + $tanggungan_minus;
    $kalkulasi_bersih = $data['cash_in'] - $beban_total_hari_ini;
    
    if ($kalkulasi_bersih < 0) {
        $rekap_harian[$tgl]['bersih'] = 0;
        $hutang_baru = abs($kalkulasi_bersih);
        
        $rekap_harian[$tgl]['info_defisit'] = "<br><span class='text-danger info-print-minus'>(-" . number_format($hutang_baru, 0, ',', '.') . " ditagih ke besok)</span>";
        $tanggungan_minus = $hutang_baru; 
    } else {
        $rekap_harian[$tgl]['bersih'] = $kalkulasi_bersih;
        if ($tanggungan_minus > 0) {
            $rekap_harian[$tgl]['info_defisit'] = "<br><span class='text-warning text-dark info-print-note'>(Dipotong hutang kemarin " . number_format($tanggungan_minus, 0, ',', '.') . ")</span>";
        } else {
            $rekap_harian[$tgl]['info_defisit'] = "";
        }
        $tanggungan_minus = 0; 
    }

    $gt_trx += $data['trx_count'];
    $gt_cash += $data['cash_in'];
    $gt_non += $data['non_tunai'];
    $gt_out += $data['pengeluaran']; 
    $gt_net += $rekap_harian[$tgl]['bersih'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Arus Kas Harian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Gaya Khusus untuk Layar Monitor */
        .judul-laporan-print { display: none; }
        .nominal { font-weight: bold; font-family: 'Courier New', monospace; }
        .bg-total { background-color: #d1e7dd; font-weight: bold; border-top: 2px solid #333; }
        .info-print-minus { font-size: 0.75rem; }
        .info-print-note { font-size: 0.75rem; }

        @media (max-width: 768px) {
            .table { font-size: 0.75rem; }
            .nominal { font-size: 0.7rem; }
        }

        /* GAYA KHUSUS UNTUK CETAK KERTAS / PRINT (LEBAR FULL KERTAS) */
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            
            body { 
                background-color: white !important; 
                font-family: 'Arial', sans-serif !important; 
                color: black !important;
                font-size: 12px;
            }
            
            .no-print, .navbar, .card-header, .card-footer { display: none !important; }
            
            .container { 
                width: 100% !important; 
                max-width: 100% !important; 
                padding: 0 !important; 
                margin: 0 !important; 
            }
            
            .card { border: none !important; box-shadow: none !important; width: 100% !important; }
            .card-body { padding: 0 !important; width: 100% !important; }

            .judul-laporan-print { 
                display: block !important; 
                text-align: center; 
                margin-bottom: 25px; 
                border-bottom: 2px solid black; 
                padding-bottom: 15px; 
            }
            .judul-laporan-print h2 { font-size: 22px; font-weight: 800; margin: 0; letter-spacing: 1px; }
            .judul-laporan-print h4 { font-size: 16px; margin: 5px 0; font-weight: 600; }
            .judul-laporan-print p { font-size: 12px; margin: 0; color: #333; }

            table { width: 100% !important; border-collapse: collapse !important; }
            th { 
                background-color: #f2f2f2 !important; 
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
                color: black !important; 
                border: 1px solid black !important; 
                padding: 10px 8px; 
                font-size: 11px; 
                text-transform: uppercase; 
            }
            td { 
                border: 1px solid black !important; 
                padding: 8px; 
                font-size: 12px; 
                vertical-align: top; /* Diubah ke top agar list item sejajar bagus */
            }
            
            .text-success, .text-danger, .text-primary, .text-warning, .text-muted { color: black !important; }
            .bg-light { background-color: transparent !important; }
            
            .info-print-minus, .info-print-note { 
                font-size: 10px; 
                font-style: italic; 
                display: block; 
                margin-top: 4px;
                color: #444 !important;
            }

            .bg-total { background-color: #e6e6e6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .bg-total td { border-top: 2px solid black !important; border-bottom: 2px solid black !important; font-size: 13px; font-weight: bold; vertical-align: middle; }

            .signature-area { width: 100%; margin-top: 50px; page-break-inside: avoid; border: none !important; }
            .signature-area td { border: none !important; text-align: center; width: 50%; padding-top: 60px; }
            .signature-name { border-top: 1px solid black; display: inline-block; width: 180px; padding-top: 5px; font-weight: bold; font-size: 12px; }
        }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-secondary mb-4 no-print">
        <div class="container">
            <a class="navbar-brand" href="../../index.php"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
            <span class="navbar-text text-white fw-bold">Laporan Kas Harian</span>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="judul-laporan-print">
            <h2>ADDINTA PRINTING</h2>
            <h4>LAPORAN ARUS KAS HARIAN</h4>
            <p>Periode: <strong><?= date('d M Y', strtotime($tgl_awal)) ?></strong> s/d <strong><?= date('d M Y', strtotime($tgl_akhir)) ?></strong></p>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white py-3 no-print">
                <form method="GET">
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-md-4">
                            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-wallet me-2"></i> Kas Fisik & Non-Tunai</h5>
                        </div>
                        <div class="col-6 col-md-3">
                            <input type="date" name="start" class="form-control form-control-sm" value="<?= $tgl_awal ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <input type="date" name="end" class="form-control form-control-sm" value="<?= $tgl_akhir ?>">
                        </div>
                        <div class="col-12 col-md-2 d-grid gap-1 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter"></i></button>
                            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm flex-fill" title="Cetak Laporan"><i class="fas fa-print"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0 align-middle">
                        <thead class="text-center">
                            <tr>
                                <th width="5%">No</th>
                                <th width="15%">Tanggal</th>
                                <th width="10%">Total Nota</th>
                                <th width="18%">Pemasukan Tunai</th>
                                <th width="20%">Pengeluaran Operasional</th>
                                <th width="17%">SISA FISIK KASIR</th>
                                <th width="15%" class="text-secondary">Pemasukan Non-Tunai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            $has_data = false;
                            foreach($rekap_harian as $tgl => $data): 
                                if($data['cash_in'] == 0 && $data['pengeluaran'] == 0 && $data['non_tunai'] == 0 && $data['info_defisit'] == '') continue;
                                $has_data = true;
                                
                                // Susun Rincian Non-Tunai
                                $info_rincian_non = "";
                                if($data['non_tunai'] > 0 && !empty($data['rincian_non_tunai'])) {
                                    foreach($data['rincian_non_tunai'] as $metode => $nominal) {
                                        $info_rincian_non .= "<span class='d-block info-print-note mt-1' style='color:#555;'>- $metode: " . number_format($nominal, 0, ',', '.') . "</span>";
                                    }
                                }

                                // Susun Rincian Pengeluaran
                                $info_rincian_out = "";
                                if($data['pengeluaran'] > 0 && !empty($data['rincian_pengeluaran'])) {
                                    $info_rincian_out .= "<div class='mt-2 text-start'>";
                                    foreach($data['rincian_pengeluaran'] as $item) {
                                        $nama_item = htmlspecialchars(strlen($item['nama']) > 20 ? substr($item['nama'],0,18).'..' : $item['nama']);
                                        $info_rincian_out .= "<div class='info-print-note text-danger' style='line-height: 1.3;'>- {$nama_item}: " . number_format($item['nominal'], 0, ',', '.') . "</div>";
                                    }
                                    $info_rincian_out .= "</div>";
                                }

                                // Susun Rincian Cash In
                                $info_rincian_cash = "";
                                if($data['cash_in'] > 0 && !empty($data['rincian_cash_in'])) {
                                    $info_rincian_cash .= "<div class='mt-2 text-start no-print'>"; // Tambahkan class no-print agar tidak merusak kertas struk
                                    foreach($data['rincian_cash_in'] as $item) {
                                        $info_rincian_cash .= "<div class='info-print-note text-success' style='line-height: 1.3;'>+ {$item['nota']}: " . number_format($item['nominal'], 0, ',', '.') . "</div>";
                                    }
                                    $info_rincian_cash .= "</div>";
                                }
                            ?>
                                <tr>
                                    <td class="text-center align-top pt-3"><?= $no++ ?></td>
                                    <td class="text-center fw-bold align-top pt-3">
                                        <?= date('d/m/Y', strtotime($tgl)) ?>
                                    </td>
                                    <td class="text-center text-muted align-top pt-3">
                                        <?= number_format($data['trx_count']) ?> Nota
                                    </td>
                                    
                                    <td class="text-end nominal text-success align-top pt-3">
                                        <?= number_format($data['cash_in'], 0, ',', '.') ?>
                                        <?= $info_rincian_cash ?> </td>
                                    </td>

                                    <td class="text-end bg-light align-top pt-3">
                                        <div class="nominal fw-bold text-danger">
                                            <?= ($data['pengeluaran'] > 0) ? '('.number_format($data['pengeluaran'], 0, ',', '.').')' : '-' ?>
                                        </div>
                                        <?= $info_rincian_out ?>
                                    </td>

                                    <td class="text-end bg-light align-top pt-3">
                                        <div class="nominal fw-bold text-primary" style="font-size:1.1em;">
                                            <?= number_format($data['bersih'], 0, ',', '.') ?>
                                        </div>
                                        <?= $data['info_defisit'] ?>
                                    </td>
                                    
                                    <td class="text-end bg-light align-top pt-3">
                                        <div class="nominal fw-bold text-secondary">
                                            <?= ($data['non_tunai'] > 0) ? number_format($data['non_tunai'], 0, ',', '.') : '-' ?>
                                        </div>
                                        <div class="text-start"><?= $info_rincian_non ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if(!$has_data): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">Tidak ada data arus kas pada periode ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="bg-total">
                            <tr>
                                <td colspan="2" class="text-center text-uppercase">GRAND TOTAL</td>
                                <td class="text-center"><?= number_format($gt_trx) ?></td>
                                <td class="text-end"><?= number_format($gt_cash, 0, ',', '.') ?></td>
                                <td class="text-end"><?= '('.number_format($gt_out, 0, ',', '.').')' ?></td>
                                <td class="text-end" style="font-size:1.1em;"><?= number_format($gt_net, 0, ',', '.') ?></td>
                                <td class="text-end text-muted"><?= number_format($gt_non, 0, ',', '.') ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="card-footer bg-white small text-muted no-print">
                <i class="fas fa-info-circle me-1"></i> <b>Logika Perhitungan (Arus Kas Version):</b><br>
                1. <b>Pemasukan Tunai:</b> Total uang tunai yang ditarik mutlak dari tabel `arus_kas` dan pemasukan lainnya.<br>
                2. <b>Pengeluaran:</b> Biaya operasional yang dibayar tunai (Non-Stok).<br>
                3. <b>Sisa Fisik:</b> Prediksi uang tunai bersih yang seharusnya ada di laci (Masuk - Keluar - Hutang Kemarin).<br>
                4. <b>Pemasukan Non-Tunai:</b> Transaksi via Transfer/QRIS/Debit (hanya info laporan, tidak masuk hitungan fisik).
            </div>
        </div>
        
        <table class="signature-area d-none d-print-table">
            <tr>
                <td>
                    <span class="signature-name">Disetor & Dihitung Oleh</span>
                    <br><span style="font-size: 10px; font-weight: normal; color: #555;">( Kasir / Admin )</span>
                </td>
                <td>
                    <span class="signature-name">Mengetahui & Menerima</span>
                    <br><span style="font-size: 10px; font-weight: normal; color: #555;">( Owner )</span>
                </td>
            </tr>
        </table>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>