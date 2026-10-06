<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- 1. SETTING PARAMETER ---
$tanggal = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$user_filter = isset($_GET['user']) ? $_GET['user'] : ''; 
$modal_awal = isset($_GET['modal']) ? (float)$_GET['modal'] : 0;

$nama_tampilan = "";
if (!empty($user_filter) && $user_filter !== 'SYSTEM') {
    $nama_tampilan = strtoupper($user_filter);
} else {
    $nama_tampilan = isset($_SESSION['nama_lengkap']) ? strtoupper($_SESSION['nama_lengkap']) : "KASIR";
}

// --- FUNGSI BANTU STRICT TUNAI ---
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
    $f = strtolower(trim($teks_cadangan ?? ''));

    if ($t === 'cash' || $t === 'tunai') return 'Cash';
    if (preg_match('/(qris|dana|gopay|ovo|shopee|linkaja|qr|spay|paylater)/', $t)) return 'QRIS';
    if (preg_match('/(transfer|trf|tf|bca|bri|bni|mandiri|bsi|cimb|jago|seabank|bank|atm)/', $t)) return 'Transfer';
    if (preg_match('/(debit|edc|kartu|card|gesek)/', $t)) return 'Debit';

    if (strpos($t, 'non tunai') !== false || strpos($t, 'non-tunai') !== false || $t === 'nontunai') {
        if (preg_match('/(qris|dana|gopay|ovo|shopee|linkaja|qr|spay|paylater)/', $f)) return 'QRIS';
        if (preg_match('/(transfer|trf|tf|bca|bri|bni|mandiri|bsi|cimb|jago|seabank|bank|atm)/', $f)) return 'Transfer';
        if (preg_match('/(debit|edc|kartu|card|gesek)/', $f)) return 'Debit';
        return 'Transfer'; 
    }

    if (preg_match('/(qris|dana|gopay|ovo|shopee|linkaja|qr|spay|paylater)/', $f)) return 'QRIS';
    if (preg_match('/(transfer|trf|tf|bca|bri|bni|mandiri|bsi|cimb|jago|seabank|bank|atm)/', $f)) return 'Transfer';
    if (preg_match('/(debit|edc|kartu|card|gesek)/', $f)) return 'Debit';
    if (preg_match('/\b(cash|tunai)\b/', $f)) return 'Cash';
    
    return 'Cash'; 
}

// VARIABEL TOTAL
$total_cash_in  = 0;
$total_qris     = 0;
$total_debit    = 0;
$total_transfer = 0;
$total_deposit_pakai = 0;
$total_topup_deposit = 0;
$sum_cicilan_tag = 0;

function catatUang($metode_utama, $metode_cadangan, $nominal) {
    if ($nominal <= 0) return;
    $m = getMetode($metode_utama, $metode_cadangan);
    if ($m == 'QRIS') $GLOBALS['total_qris'] += $nominal;
    elseif ($m == 'Transfer') $GLOBALS['total_transfer'] += $nominal;
    elseif ($m == 'Debit') $GLOBALS['total_debit'] += $nominal;
    else $GLOBALS['total_cash_in'] += $nominal;
}


// =========================================================================
// 2. PROSES UANG MASUK HARI INI (KLONINGAN 100% DARI PAGE RIWAYAT)
// =========================================================================
$stmt_ak = $pdo->prepare("SELECT DISTINCT no_penjualan FROM arus_kas WHERE tanggal = ? AND jumlah_masuk > 0 AND no_penjualan IS NOT NULL");
$stmt_ak->execute([$tanggal]);
$ids_ak = $stmt_ak->fetchAll(PDO::FETCH_COLUMN);

$sql_penj = "SELECT p.*,
        CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END as nilai_transaksi_real
        FROM penjualan p
        WHERE (p.tgl_penjualan = ? OR (p.is_verif_qris = 'Y' AND p.tgl_verif_qris = ?) ";
$params_penj = [$tanggal, $tanggal];

if (count($ids_ak) > 0) {
    $in = implode(',', array_fill(0, count($ids_ak), '?'));
    $sql_penj .= " OR p.no_penjualan IN ($in) ";
    $params_penj = array_merge($params_penj, $ids_ak);
}
$sql_penj .= ")";
$stmt_penj = $pdo->prepare($sql_penj);
$stmt_penj->execute($params_penj);
$raw_data = $stmt_penj->fetchAll(PDO::FETCH_ASSOC);

$no_penjualan_list = array_column($raw_data, 'no_penjualan');
$cicilan_map = [];

if (!empty($no_penjualan_list)) {
    $inQuery = implode(',', array_fill(0, count($no_penjualan_list), '?'));
    $sql_arus = "SELECT no_penjualan, tanggal, metode_pembayaran, jumlah_masuk FROM arus_kas WHERE jumlah_masuk > 0 AND no_penjualan IN ($inQuery)";
    $stmt_arus = $pdo->prepare($sql_arus);
    $stmt_arus->execute($no_penjualan_list);
    
    foreach ($stmt_arus->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cicilan_map[$c['no_penjualan']][] = $c;
    }
}

foreach ($raw_data as $row) {
    $grand_total = (float)$row['nilai_transaksi_real'];
    $tgl_trx_db  = $row['tgl_penjualan'];
    $is_nota_in_range = ($tgl_trx_db == $tanggal);
    
    $cicilan_db = $cicilan_map[$row['no_penjualan']] ?? [];
    $total_sudah_dicicil = 0; 
    
    foreach ($cicilan_db as $c) {
        $tgl_cicil     = $c['tanggal'];
        $metode_cicil  = $c['metode_pembayaran'] ?: 'Cash';
        $nominal_cicil = (float)$c['jumlah_masuk'];
        $total_sudah_dicicil += $nominal_cicil;

        if ($tgl_cicil == $tanggal) {
            catatUang($metode_cicil, '', $nominal_cicil);
            $sum_cicilan_tag += $nominal_cicil;
        }
    }

    $nominal_deposit = (float)($row['nominal_deposit'] ?? 0);
    if ($nominal_deposit == 0 && stripos($row['keterangan'] ?? '', 'depo') !== false) {
        if (preg_match('/(?:Depo|Deposit).*?([0-9.,]+)/i', $row['keterangan'], $md)) {
            $nominal_deposit = cleanNum($md[1]);
        } elseif (strtolower(trim($row['keterangan'])) == 'deposit') {
            $nominal_deposit = $grand_total;
        }
    }
    if($is_nota_in_range) $total_deposit_pakai += $nominal_deposit;

    $is_verif_qris = ($row['is_verif_qris'] === 'Y');
    $tgl_qris = $row['tgl_verif_qris'] ?: $tgl_trx_db;

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

        if ($tgl_qris == $tanggal && $nominal_verif_qris > 0) {
            $GLOBALS['total_qris'] += $nominal_verif_qris;
        }
    }

    if ($is_nota_in_range) {
        $dp_awal = 0;
        if (!$is_verif_qris) {
            $dp_awal = (float)$row['uang_bayar'] - $total_sudah_dicicil; 
            if ($dp_awal < 0) $dp_awal = 0;
            
            // --- FILTER UANG KEMBALIAN ---
            $max_tagihan = $grand_total - $nominal_deposit - $total_sudah_dicicil;
            if ($max_tagihan < 0) $max_tagihan = 0;
            
            if ($dp_awal > $max_tagihan) {
                $dp_awal = $max_tagihan; 
            }
            // -----------------------------
            
            if ($dp_awal > 0) {
                $metode_dp = $row['metode_pembayaran'] ?: 'Cash';
                catatUang($metode_dp, '', $dp_awal);
            }
        }
    }
}

// Fitur Pemasukan Lain dimatikan agar 100% sama dengan page Riwayat
/* $stmt_lain = ... 
(kode pemasukan lain disembunyikan)
*/


// =========================================================================
// 3. MENGHITUNG HUTANG KEMARIN (SISTEM ANTREAN / FIFO - MUNDUR 365 HARI)
// =========================================================================
$tgl_start_past = date('Y-m-d', strtotime('-365 days', strtotime($tanggal))); 
$tgl_end_past = date('Y-m-d', strtotime('-1 day', strtotime($tanggal)));

$past_rekap = [];
$curr = $tgl_start_past;
while(strtotime($curr) <= strtotime($tgl_end_past)) {
    $past_rekap[$curr] = ['in' => 0, 'out_items' => []];
    $curr = date('Y-m-d', strtotime('+1 day', strtotime($curr)));
}

$stmt_arus_past = $pdo->prepare("SELECT tanggal, jumlah_masuk, metode_pembayaran, keterangan FROM arus_kas WHERE tanggal BETWEEN ? AND ? AND jumlah_masuk > 0 AND no_penjualan IS NOT NULL");
$stmt_arus_past->execute([$tgl_start_past, $tgl_end_past]);
foreach($stmt_arus_past->fetchAll(PDO::FETCH_ASSOC) as $a) {
    if (getMetode($a['metode_pembayaran'], $a['keterangan']) == 'Cash' && isset($past_rekap[$a['tanggal']])) {
        $past_rekap[$a['tanggal']]['in'] += (float)$a['jumlah_masuk'];
    }
}

$stmt_lain_past = $pdo->prepare("SELECT tgl_pemasukan as tgl, nominal, metode_bayar, keterangan FROM pemasukan_lain WHERE tgl_pemasukan BETWEEN ? AND ?");
$stmt_lain_past->execute([$tgl_start_past, $tgl_end_past]);
foreach($stmt_lain_past->fetchAll(PDO::FETCH_ASSOC) as $l) { 
    if(getMetode($l['metode_bayar'], $l['keterangan']) == 'Cash') $past_rekap[$l['tgl']]['in'] += $l['nominal']; 
}

try {
    if ($pdo->query("SHOW TABLES LIKE 'log_deposit'")->rowCount() > 0) {
        $stmt_depo_past = $pdo->prepare("SELECT DATE(tgl_deposit) as tgl, nominal, keterangan FROM log_deposit WHERE DATE(tgl_deposit) BETWEEN ? AND ? AND nominal > 0");
        $stmt_depo_past->execute([$tgl_start_past, $tgl_end_past]);
        foreach($stmt_depo_past->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if(getMetode('', $d['keterangan']) == 'Cash') $past_rekap[$d['tgl']]['in'] += $d['nominal'];
        }
    }
} catch(Exception $e) {}

$stmt_out_past = $pdo->prepare("SELECT tanggal, jumlah, metode_bayar, keterangan FROM pengeluaran_non_stok WHERE tanggal BETWEEN ? AND ?");
$stmt_out_past->execute([$tgl_start_past, $tgl_end_past]);
foreach($stmt_out_past->fetchAll(PDO::FETCH_ASSOC) as $o) {
    if (getMetode($o['metode_bayar'] ?? '', $o['keterangan'] ?? '') == 'Cash' && isset($past_rekap[$o['tanggal']])) {
        $past_rekap[$o['tanggal']]['out_items'][] = [
            'keterangan' => trim($o['keterangan']),
            'jumlah' => (float)$o['jumlah']
        ];
    }
}

$unpaid_expenses = []; 
foreach ($past_rekap as $tgl => $d) {
    $daily_income = $d['in'];
    foreach ($d['out_items'] as $item) {
        $unpaid_expenses[] = [
            'keterangan' => $item['keterangan'],
            'sisa' => $item['jumlah']
        ];
    }
    
    if ($daily_income > 0) {
        foreach ($unpaid_expenses as $k => $hutang) {
            if ($daily_income >= $hutang['sisa']) {
                $daily_income -= $hutang['sisa'];
                unset($unpaid_expenses[$k]);
            } else {
                $unpaid_expenses[$k]['sisa'] -= $daily_income;
                $daily_income = 0;
                break; 
            }
        }
        $unpaid_expenses = array_values($unpaid_expenses); 
    }
}

$hutang_kemarin = 0;
foreach ($unpaid_expenses as $u) {
    $hutang_kemarin += $u['sisa'];
}


// =========================================================================
// 4. PENGELUARAN HARI INI & PENYUSUNAN DAFTAR TAMPILAN
// =========================================================================
$stmt_out = $pdo->prepare("SELECT * FROM pengeluaran_non_stok WHERE tanggal = ?"); 
$stmt_out->execute([$tanggal]);

$total_keluar_tunai = 0;
$display_items = []; 
$pengeluaran_non_tunai_info = []; 

foreach($stmt_out->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $metode_out = getMetode($p['metode_bayar'] ?? '', $p['keterangan'] ?? '');
    
    if ($metode_out == 'Cash') {
        $total_keluar_tunai += $p['jumlah'];
        $display_items[] = [
            'label' => trim($p['keterangan']),
            'nominal' => $p['jumlah'],
            'is_minus' => false 
        ];
    } else {
        $pengeluaran_non_tunai_info[] = $p;
    }
}

foreach ($unpaid_expenses as $u) {
    $display_items[] = [
        'label' => "Minus: " . $u['keterangan'],
        'nominal' => $u['sisa'],
        'is_minus' => true 
    ];
}


// =========================================================================
// 5. PERHITUNGAN FINAL AMPLOP
// =========================================================================
$total_penerimaan_real = $total_cash_in + $total_qris + $total_transfer + $total_debit;
$total_beban_hari_ini = $total_keluar_tunai + $hutang_kemarin;

$kas_real_system = ($modal_awal + $total_cash_in) - $total_beban_hari_ini;
$wajib_setor_tampil = ($kas_real_system < 0) ? 0 : $kas_real_system;

$status_kas = "";
$warna_status = "";
if ($kas_real_system < 0) {
    $hutang_baru = abs($kas_real_system);
    
    $alasan_badge = [];
    foreach (array_slice($display_items, 0, 3) as $di) {
        $alasan_badge[] = str_replace('Minus: ', '', $di['label']);
    }
    $teks_alasan = implode(", ", array_unique($alasan_badge));
    if (count($display_items) > 3) $teks_alasan .= ", dll";
    if (empty($teks_alasan)) $teks_alasan = "PENGELUARAN";
    
    if(strlen($teks_alasan) > 35) { $teks_alasan = substr($teks_alasan, 0, 32) . ".."; }
    
    $status_kas = "MINUS (-" . number_format($hutang_baru, 0, ',', '.') . ") KRN " . strtoupper($teks_alasan);
    $warna_status = "bg-danger text-white";
} else {
    $status_kas = "KAS NORMAL";
    $warna_status = "bg-dark text-white";
}

$sql_piutang = "SELECT pl.nama_pelanggan, SUM(
                    (CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END) 
                    - p.uang_bayar - COALESCE(p.nominal_deposit, 0)
                ) as sisa_utang
                FROM penjualan p 
                JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                WHERE p.pelunasan = 'N'
                GROUP BY pl.kode_pelanggan, pl.nama_pelanggan 
                HAVING sisa_utang > 100 
                ORDER BY sisa_utang DESC LIMIT 15";
$stmt_piutang = $pdo->query($sql_piutang); 
$raw_piutang = $stmt_piutang->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Amplop | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { background: #e2e8f0; font-family: 'Inter', sans-serif; margin: 0; padding: 20px; color: #1e293b; }
        
        .envelope { background: #fff; width: 210mm; height: 296mm; margin: 0 auto; position: relative; box-sizing: border-box; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 4px; }
        .fold-line-1, .fold-line-2 { position: absolute; left: 0; width: 100%; border-top: 1.5px dashed #cbd5e1; z-index: 10; }
        .fold-line-1 { top: 99mm; } .fold-line-2 { top: 198mm; }
        .cut-label { position: absolute; right: 5mm; top: -9px; font-size: 10px; color: #94a3b8; background: #fff; padding: 0 8px; font-weight: 600; text-transform: uppercase; }
        
        .section-1, .section-2, .section-3 { height: 99mm; padding: 12mm 15mm; box-sizing: border-box; }
        
        .header-print { text-align: center; border-bottom: 2px solid #1e293b; padding-bottom: 8px; margin-bottom: 12px; }
        .header-print h4 { font-weight: 800; font-size: 16px; margin: 0; }
        .header-print p { margin: 3px 0 0 0; font-size: 11px; font-weight: 500; color: #475569; }
        
        .summary-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 8px; text-align: center; background: #f8fafc; }
        .summary-title { font-size: 9px; font-weight: 700; color: #64748b; margin-bottom: 2px; display: block; text-transform: uppercase; }
        .summary-value { font-size: 14px; font-weight: 800; color: #0f172a; }
        
        .table-xs { font-size: 10px; margin-bottom: 0; }
        .table-xs td { padding: 3px 4px; border-bottom: 1px dashed #f1f5f9; }
        .table-xs tr:last-child td { border-bottom: none; }
        
        .section-2 { display: flex; flex-direction: column; justify-content: center; background-color: #fafaf9; }
        .big-txt { font-size: 32px; font-weight: 800; color: #0f172a; border-bottom: 3px solid #0f172a; display: inline-block; padding: 0 20px 5px 20px; margin-top: 5px; }
        .money-box { border: 2.5px dashed #cbd5e1; border-radius: 12px; flex-grow: 1; display: flex; align-items: center; justify-content: center; flex-direction: column; color: #94a3b8; background: white; margin-top: 10px; }
        
        .section-3 { padding-top: 15mm; }
        .debt-list-wrapper { overflow-y: hidden; max-height: 70mm; border: 1px solid #e2e8f0; border-radius: 8px; }
        .sign-box-table { width: 100%; border-collapse: collapse; font-size: 11px; text-align: left; }
        .sign-box-table td { border: 1px solid #cbd5e1; padding: 6px; }
        .sign-box-table .bg-light-gray { background: #f1f5f9; font-weight: 600; text-align: center; }

        @media print { 
            @page { size: A4 portrait; margin: 0; } 
            body { background: #fff; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; } 
            .no-print { display: none !important; } 
            .envelope { box-shadow: none; border: none; width: 100%; height: 297mm; } 
            .money-box { background: #f8fafc !important; }
            .section-2 { background-color: #fff !important; }
        }
    </style>
</head>
<body>

    <div class="container mt-3 mb-4 no-print">
        <div class="bg-white p-3 rounded-4 shadow-sm border border-light d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px;">
                    <i class="fas fa-envelope-open-text fs-5"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold text-dark">Amplop Setoran</h5>
                    <small class="text-muted">Pratinjau Cetak Kertas A4</small>
                </div>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-3">
                <form class="d-flex flex-wrap align-items-center gap-2 m-0" method="GET">
                    <input type="hidden" name="user" value="<?= htmlspecialchars($user_filter) ?>">
                    
                    <div class="bg-light rounded-pill d-flex align-items-center border px-3 py-1">
                        <i class="fas fa-calendar-alt text-muted me-2"></i>
                        <input type="date" name="tanggal" value="<?= $tanggal ?>" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 text-dark fw-medium" onchange="this.form.submit()" style="outline: none; cursor: pointer;">
                    </div>

                    <div class="bg-light rounded-pill d-flex align-items-center border px-3 py-1">
                        <span class="text-muted small fw-bold me-2">Modal Rp</span>
                        <input type="number" name="modal" value="<?= $modal_awal ?>" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-bold text-primary" placeholder="0" onchange="this.form.submit()" style="width: 90px; outline: none;">
                    </div>
                </form>

                <div class="vr text-muted d-none d-md-block opacity-25" style="height: 30px;"></div>
                <button onclick="window.print()" class="btn btn-primary fw-bold shadow-sm px-4 rounded-pill d-flex align-items-center gap-2">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

    <div class="envelope">
        
        <div class="fold-line-1"><span class="cut-label"><i class="fas fa-cut me-1"></i> Lipat 1</span></div>
        <div class="fold-line-2"><span class="cut-label"><i class="fas fa-cut me-1"></i> Lipat 2</span></div>

        <div class="section-1">
            <div class="header-print">
                <h4>AMPLOP SETORAN KASIR</h4>
                <p>Periode: <strong><?= date('d M Y', strtotime($tanggal)) ?></strong> &nbsp;|&nbsp; Kasir: <strong><?= $nama_tampilan ?></strong></p>
            </div>
            
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <div class="summary-box">
                        <span class="summary-title">Modal Awal Sistem</span>
                        <span class="summary-value">Rp <?= number_format($modal_awal,0,',','.') ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="summary-box" style="border-color: #cbd5e1; background: white;">
                        <span class="summary-title">Total Terima Semua Metode</span>
                        <span class="summary-value">Rp <?= number_format($total_penerimaan_real,0,',','.') ?></span>
                    </div>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px;">
                        <strong class="d-block mb-1 text-uppercase" style="font-size: 9px; color: #475569;">Rincian Pemasukan</strong>
                        <table class="table table-xs table-borderless w-100">
                            <tr><td class="fw-medium">Tunai (Cash)</td><td class="text-end fw-bold text-dark">Rp <?= number_format($total_cash_in) ?></td></tr>
                            
                            <?php if($total_qris > 0): ?><tr><td class="text-muted">QRIS</td><td class="text-end text-muted">Rp <?= number_format($total_qris) ?></td></tr><?php endif; ?>
                            <?php if($total_transfer > 0): ?><tr><td class="text-muted">Transfer Bank</td><td class="text-end text-muted">Rp <?= number_format($total_transfer) ?></td></tr><?php endif; ?>
                            <?php if($total_debit > 0): ?><tr><td class="text-muted">Kartu Debit</td><td class="text-end text-muted">Rp <?= number_format($total_debit) ?></td></tr><?php endif; ?>
                            
                            <?php if($total_deposit_pakai > 0): ?><tr><td class="text-muted" style="font-size:8.5px;"><i>*Pemakaian Deposit</i></td><td class="text-end text-muted" style="font-size:8.5px;"><i>Rp <?= number_format($total_deposit_pakai) ?></i></td></tr><?php endif; ?>
                            <?php if($total_topup_deposit > 0): ?><tr><td class="text-muted" style="font-size:8.5px;"><i>*Top Up Deposit</i></td><td class="text-end text-muted" style="font-size:8.5px;"><i>Rp <?= number_format($total_topup_deposit) ?></i></td></tr><?php endif; ?>
                            <?php if($sum_cicilan_tag > 0): ?><tr><td class="text-muted" style="font-size:8.5px;"><i>*Pelunasan Piutang</i></td><td class="text-end text-muted" style="font-size:8.5px;"><i>Rp <?= number_format($sum_cicilan_tag) ?></i></td></tr><?php endif; ?>
                            
                            <tr style="border-top: 1px solid #cbd5e1;"><td class="fw-bold pt-1">Total Terima</td><td class="text-end fw-bold pt-1">Rp <?= number_format($total_penerimaan_real) ?></td></tr>
                        </table>
                    </div>
                </div>
                <div class="col-6">
                    <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; height: 100%;">
                        <strong class="d-block mb-1 text-uppercase" style="font-size: 9px; color: #475569;">Pengeluaran Toko</strong>
                        <table class="table table-xs w-100 mb-0" style="table-layout: fixed;">
                            <?php 
                            $count_items = count($display_items);
                            $max_items = $count_items; // Tampilkan semua pengeluaran, tanpa baris Lainnya
                            
                            if($count_items > 0): 
                                for($i = 0; $i < $count_items; $i++):
                                    $item = $display_items[$i];
                                    $class_text = $item['is_minus'] ? 'text-danger fw-bold' : 'text-muted';
                            ?>
                                <tr>
                                    <td class="<?= $class_text ?>" style="word-wrap: break-word; overflow-wrap: anywhere; white-space: normal; line-height: 1.15; font-size: 8.6px;">
                                        <?= htmlspecialchars($item['label']) ?>
                                    </td>
                                    <td class="text-end <?= $class_text ?> align-top" style="width: 62px; white-space: nowrap;">
                                        <?= number_format($item['nominal']) ?>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                                
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted" style="padding: 5px 0; font-style: italic;">Nihil.</td></tr>
                            <?php endif; ?>
                            
                            <tr style="border-top: 1px solid #cbd5e1;"><td class="fw-bold pt-1">Total Potong Cash</td><td class="text-end fw-bold text-danger pt-1"><?= number_format($total_beban_hari_ini) ?></td></tr>
                            
                            <?php if(count($pengeluaran_non_tunai_info) > 0): ?>
                                <tr>
                                    <td colspan="2" class="pb-0" style="border-top: 1px dashed #cbd5e1; padding-top: 6px; margin-top: 6px;">
                                        <span style="font-size: 7.5px; color: #64748b; font-weight: bold; letter-spacing: 0.5px;">INFO NON-TUNAI (TIDAK POTONG LACI):</span>
                                    </td>
                                </tr>
                                <?php 
                                $max_non = count($pengeluaran_non_tunai_info); // Tampilkan semua pengeluaran non-tunai, tanpa baris Lainnya
                                for($j=0; $j < count($pengeluaran_non_tunai_info); $j++):
                                    $pn = $pengeluaran_non_tunai_info[$j];
                                ?>
                                <tr>
                                    <td class="text-muted" style="font-size: 8.3px; word-wrap: break-word; overflow-wrap: anywhere; white-space: normal; line-height: 1.1;">
                                        <i><?= htmlspecialchars($pn['keterangan']) ?></i>
                                    </td>
                                    <td class="text-end text-muted align-top" style="font-size: 8.5px; width: 62px; white-space: nowrap;">
                                        <i><?= number_format($pn['jumlah']) ?></i>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            <?php endif; ?>

                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-2">
            <div class="text-center mb-3">
                <span class="d-block text-uppercase fw-bold text-muted mb-1" style="font-size: 11px; letter-spacing: 1px;">Sisa Kas Fisik (Wajib Setor)</span>
                <div class="big-txt">Rp <?= number_format($wajib_setor_tampil, 0, ',', '.') ?></div>
                <div class="mt-2"><span class="badge <?= $warna_status ?> px-3 py-1 border rounded-pill" style="font-size: 9px; letter-spacing: 0.5px;"><?= $status_kas ?></span></div>
            </div>
            
            <div class="money-box">
                <i class="fas fa-money-bill-wave fa-2x mb-2" style="color: #cbd5e1;"></i>
                <span class="fw-bold" style="color: #94a3b8; font-size: 10px; letter-spacing: 1px;">LETAKKAN UANG KERTAS DI SINI</span>
            </div>
        </div>

        <div class="section-3">
            <div class="row h-100 g-3">
                <div class="col-7">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong class="text-uppercase" style="font-size: 10px; color: #0f172a;"><i class="fas fa-book-open text-muted me-1"></i> Tunggakan</strong>
                        <span class="badge bg-light text-dark border" style="font-size: 8px;"><?= count($raw_piutang) ?> Nota</span>
                    </div>
                    <div class="debt-list-wrapper">
                        <table class="table table-xs table-striped mb-0 w-100">
                            <tbody>
                                <?php if(count($raw_piutang) > 0): ?>
                                    <?php foreach($raw_piutang as $row): ?>
                                    <tr>
                                        <td class="fw-medium"><div class="text-truncate" style="max-width: 130px;"><?= htmlspecialchars($row['nama_pelanggan']) ?></div></td>
                                        <td class="text-end fw-bold text-danger">Rp <?= number_format($row['sisa_utang']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" class="text-center text-muted py-2">Nihil.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="col-5 d-flex flex-column justify-content-between">
                    <div>
                        <table class="sign-box-table">
                            <tr><td class="bg-light-gray" colspan="2">Verifikasi Hitung Manual</td></tr>
                            <tr><td width="50%">Hitungan Fisik</td><td height="20"></td></tr>
                            <tr><td>Selisih (+/-)</td><td height="20"></td></tr>
                        </table>
                    </div>
                    
                    <div class="text-center mt-auto pb-1">
                        <small class="d-block mb-4" style="font-size: 9px; color: #64748b;">Disetor & Dihitung Oleh,</small>
                        <span class="d-inline-block border-bottom border-dark fw-bold px-3 pb-1" style="font-size: 13px; min-width: 110px;"><?= $nama_tampilan ?></span><br>
                        <small style="font-size: 8px; color: #94a3b8;">Kasir Bertugas</small>
                    </div>
                </div>
            </div>
        </div>

    </div>
</body>
</html>
