<?php
// 1. NYALAKAN ERROR REPORTING (SUPAYA TIDAK BLANK JIKA ERROR)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../../config/database.php';

if (!isset($_GET['id'])) { die("ID Transaksi tidak ditemukan."); }
$no_penjualan = $_GET['id'];

// --- TANGKAP DATA DARI URL (DARI KASIR) ---
$utang_lama_input = isset($_GET['utang']) ? (float)$_GET['utang'] : 0;
$depo_awal_input  = isset($_GET['depo_awal']) ? (float)$_GET['depo_awal'] : 0;
$depo_pakai_input = isset($_GET['depo_pakai']) ? (float)$_GET['depo_pakai'] : 0;
$tunai_input      = isset($_GET['tunai']) ? (float)$_GET['tunai'] : 0; // TANGKAP UANG FISIK


function normalize_margin_left_struk($value, $default = 3) {
    if ($value === null || $value === '') return $default;
    $value = (string)$value;
    return preg_match('/^\d+$/', trim($value)) ? (int)trim($value) : strlen($value);
}

// 2. SETTING TOKO
try {
    $stmt_toko = $pdo->query("SELECT * FROM settings_toko WHERE id = 1");
    $toko = $stmt_toko->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error Setting Toko: " . $e->getMessage());
}

$nama_toko     = strtoupper($toko['nama_toko'] ?? "TOKO SAYA");
$alamat_toko   = $toko['alamat_toko'] ?? "Alamat Toko";
$footer_struk  = $toko['footer_struk'] ?? "Terima Kasih";

$width_total   = (int)($toko['content_width'] ?? 33);
$margin_left   = normalize_margin_left_struk($toko['margin_left'] ?? 3, 3);
$margin_right  = (int)($toko['margin_right'] ?? 0);

$content_width = $width_total - $margin_left - $margin_right;
$margin_str    = str_repeat(" ", $margin_left);

// 3. DATA TRANSAKSI
$sql_trx = "SELECT p.*, pl.nama_pelanggan, u.nama_user 
            FROM penjualan p 
            LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan 
            LEFT JOIN operator u ON p.kode_user = u.kode_user 
            WHERE p.no_penjualan = ?";
            
$stmt = $pdo->prepare($sql_trx);
$stmt->execute([$no_penjualan]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) { die("Data Transaksi Tidak Ditemukan."); }

$stmt_item = $pdo->prepare("SELECT pi.*, b.nama_barang FROM penjualan_item pi JOIN barang b ON pi.kode_barang = b.kode_barang WHERE pi.no_penjualan = ?");
$stmt_item->execute([$no_penjualan]);
$items = $stmt_item->fetchAll(PDO::FETCH_ASSOC);

// 4. PARSING ONGKIR
$ongkir_val = 0;
$raw_keterangan = $data['keterangan'] ?? '';
if (preg_match('/\{\{ONGKIR:([0-9\.]+)\}\}/', $raw_keterangan, $matches)) {
    $ongkir_val = (float)str_replace('.', '', $matches[1]);
} elseif (isset($data['ongkir']) && $data['ongkir'] > 0) {
    $ongkir_val = (float)$data['ongkir'];
}

// 5. VALIDASI UTANG LAMA
$utang_lama_val = $utang_lama_input; 

// --- HELPER FORMATTING ---
function centerText($text, $width) {
    $len = strlen($text);
    if ($len >= $width) return $text . "\n";
    $pad = floor(($width - $len) / 2);
    return str_repeat(" ", $pad) . $text . "\n";
}
function bodyText($text, $margin) { return $margin . $text . "\n"; }
function rowTwoCol($label, $val, $cw, $margin) {
    $lenL = strlen($label);
    $lenV = strlen($val);
    $space = $cw - $lenL - $lenV;
    if ($space < 0) $space = 1;
    return $margin . $label . str_repeat(" ", $space) . $val . "\n";
}
function tableRow($nama, $qty, $total, $cw, $margin) {
    $w_qty = 5; $w_tot = 11; $w_nama = $cw - $w_qty - $w_tot - 1;
    if ($w_nama < 5) $w_nama = 5;
    $lines = explode("\n", wordwrap($nama, $w_nama, "\n", true));
    $out = "";
    foreach($lines as $idx => $line) {
        $col_nama = str_pad($line, $w_nama, " ", STR_PAD_RIGHT);
        if ($idx === 0) {
            $col_qty = str_pad($qty, $w_qty, " ", STR_PAD_LEFT);
            $col_tot = str_pad($total, $w_tot, " ", STR_PAD_LEFT);
            $out .= $margin . $col_nama . $col_qty . $col_tot . "\n";
        } else {
            $out .= $margin . $col_nama . str_repeat(" ", $w_qty + $w_tot) . "\n";
        }
    }
    return $out;
}
function rp($angka) { return number_format($angka, 0, ',', '.'); }

function parseAnalyzerNotaMeta($keterangan) {
    $ket = trim((string)$keterangan);
    if ($ket === '' || stripos($ket, 'Analyzer:') === false) return null;
    $ket = preg_replace('/^.*?Analyzer:\s*/i', '', $ket);
    $parts = array_map('trim', explode('|', $ket));
    $file = $parts[0] ?? '';
    if ($file === '') return null;
    return [
        'file' => $file,
        'kategori' => $parts[1] ?? '',
        'detail' => $parts[2] ?? '',
        'profile' => $parts[3] ?? '',
    ];
}
function analyzerQtyText($qty) {
    return rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.');
}
function analyzerWrapEcho($text, $cw, $margin, $indent = '') {
    $lineWidth = max(8, $cw - strlen($indent));
    $lines = explode("\n", wordwrap((string)$text, $lineWidth, "\n", true));
    foreach ($lines as $line) echo bodyText($indent . $line, $margin);
}
function renderStrukSeparator($label, $cw, $margin) {
    echo bodyText(str_repeat('-', $cw), $margin);
    $label = trim((string)$label);
    if ($label !== '') {
        echo bodyText($label, $margin);
        echo bodyText(str_repeat('-', $cw), $margin);
    }
}
function renderAnalyzerFileHeader($file, $cw, $margin) {
    analyzerWrapEcho('FILE: ' . $file, $cw, $margin, '');
}
function strukTrimWidth($text, $width) {
    $text = trim((string)$text);
    if ($width <= 0) return '';
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, '', 'UTF-8');
    }
    return strlen($text) > $width ? substr($text, 0, $width) : $text;
}
function renderAnalyzerItemDetail($row, $cw, $margin, $no = 0) {
    $qtyText = analyzerQtyText($row['qty']) . 'x';
    $totalText = rp($row['sub']);
    $wQty = 4;
    $wTotal = 9;
    $wNama = max(10, $cw - $wQty - $wTotal);
    $nama = strukTrimWidth($row['nama'] ?? '', $wNama);

    echo $margin
        . str_pad($nama, $wNama, ' ', STR_PAD_RIGHT)
        . str_pad($qtyText, $wQty, ' ', STR_PAD_LEFT)
        . str_pad($totalText, $wTotal, ' ', STR_PAD_LEFT)
        . "
";

    if($row['diskon'] > 0) {
        echo rowTwoCol('Diskon', '-' . rp($row['diskon']), $cw, $margin);
    }
}
function renderNotaItemsKategoriPerFile($items, $content_width, $margin_str) {
    $fileBlocks = [];
    $fileIndex = [];
    $normalRows = [];

    foreach ($items as $i) {
        $sub = ((float)$i['harga_jual'] * (float)$i['jumlah']) - (float)$i['diskon'];
        $row = [
            'nama' => (string)($i['nama_barang'] ?? ''),
            'qty' => (float)$i['jumlah'],
            'harga' => (float)$i['harga_jual'],
            'diskon' => (float)$i['diskon'],
            'sub' => $sub,
            'meta' => parseAnalyzerNotaMeta($i['keterangan'] ?? ''),
        ];
        if ($row['meta']) {
            $key = $row['meta']['file'];
            if (!isset($fileIndex[$key])) {
                $fileIndex[$key] = count($fileBlocks);
                $fileBlocks[] = ['file' => $key, 'rows' => []];
            }
            $fileBlocks[$fileIndex[$key]]['rows'][] = $row;
        } else {
            $normalRows[] = $row;
        }
    }

    if (!empty($fileBlocks)) {
        renderStrukSeparator('NOTA FILE', $content_width, $margin_str);
        foreach ($fileBlocks as $idx => $block) {
            if ($idx > 0) renderStrukSeparator('', $content_width, $margin_str);
            renderAnalyzerFileHeader($block['file'], $content_width, $margin_str);
            $no = 1;
            foreach ($block['rows'] as $row) {
                renderAnalyzerItemDetail($row, $content_width, $margin_str, $no++);
            }
        }
    }

    if (!empty($normalRows)) {
        if (!empty($fileBlocks)) renderStrukSeparator('NOTA TAMBAHAN', $content_width, $margin_str);
        foreach ($normalRows as $row) {
            $qtyText = analyzerQtyText($row['qty']) . 'x';
            echo tableRow($row['nama'], $qtyText, rp($row['sub']), $content_width, $margin_str);
            if($row['diskon'] > 0) {
                echo rowTwoCol('Diskon', '-' . rp($row['diskon']), $content_width, $margin_str);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Struk #<?= $no_penjualan ?></title>
    <style>
        body { margin: 0; padding: 10px; background-color: #f8f9fa; display: flex; justify-content: center; }
        .struk-wrapper { background-color: #fff; padding: 15px 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border: 1px solid #ddd; width: fit-content; }
        pre { font-family: 'Courier New', Courier, monospace; font-size: 11px; line-height: 1.2; white-space: pre; margin: 0; color: #000; }
        .red { color: red; font-weight: bold; }
        .bold { font-weight: bold; }
        .header-center { text-align: center; width: 100%; display: block; }
        .header-title { font-size: 16px; font-weight: 900; color: red; text-transform: uppercase; }
        /* Style baru untuk nama pelanggan */
        .nama-pelanggan-besar { font-size: 15px; font-weight: bold; color: red; }
    </style>
</head>
<body>
<div class="struk-wrapper">
    <pre><?php
    // --- HEADER ---
    echo '<div class="header-center header-title">' . $nama_toko . '</div>';
    $alamat_lines = explode("\n", wordwrap($alamat_toko, $width_total, "\n", true));
    foreach($alamat_lines as $line) { echo centerText(trim($line), $width_total); }
    echo str_repeat("=", $width_total) . "\n";

    // --- BODY ---
    echo '<span class="red">' . bodyText("No   : " . $no_penjualan, $margin_str) . '</span>';
    echo bodyText("Tgl  : " . date('d/m/y H:i', strtotime($data['tgl_penjualan'].' '.$data['jam'])), $margin_str);
    echo bodyText("Kasir: " . substr($data['nama_user'] ?? 'Admin', 0, 15), $margin_str);
    
    // Perubahan: Menambahkan class CSS ke nama pelanggan agar besar dan merah
    $pelanggan = !empty($data['nama_pelanggan']) ? $data['nama_pelanggan'] : 'Umum';
    echo bodyText("Plg  : <span class=\"nama-pelanggan-besar\">" . substr($pelanggan, 0, 15) . "</span>", $margin_str);

    // --- METODE BAYAR ---
    if (!empty($data['is_verif_qris']) && $data['is_verif_qris'] == 'Y') {
        $metode_bersih = "QRIS";
    } elseif (!empty($data['metode_pembayaran'])) {
        $metode_bersih = strtoupper($data['metode_pembayaran']);
    } else {
        $metode_bersih = preg_replace('/\[Verif:QRIS.*?\]/i', '', $raw_keterangan);
        $metode_bersih = preg_replace('/\{\{.*?\}\}/', '', $metode_bersih);
        $metode_bersih = trim(str_replace(['|', 'TEMPO'], ['', 'UTANG'], $metode_bersih));
        if(empty($metode_bersih)) $metode_bersih = "CASH";
    }
    echo bodyText("Metode: " . substr($metode_bersih, 0, 15), $margin_str);
    
    echo bodyText(str_repeat("-", $content_width), $margin_str);
    echo "<b>" . bodyText("ITEM           QTY       TOTAL", $margin_str) . "</b>";
    echo bodyText(str_repeat("-", $content_width), $margin_str);

    // --- ITEMS ---
    $qty_tot = 0; 
    $sub_tot_murni = 0;

    foreach($items as $i) {
        $sub = ($i['harga_jual'] * $i['jumlah']) - $i['diskon'];
        $qty_tot += $i['jumlah'];
        $sub_tot_murni += $sub;
    }
    renderNotaItemsKategoriPerFile($items, $content_width, $margin_str);

    echo bodyText(str_repeat("-", $content_width), $margin_str);

    // --- KALKULASI GRAND TOTAL ---
    $grand_total_calc = $sub_tot_murni;

    echo rowTwoCol("Total Item:", $qty_tot, $content_width, $margin_str);
    echo rowTwoCol("Subtotal:", rp($sub_tot_murni), $content_width, $margin_str);

    $diskon_global = (float)($data['diskon_global'] ?? 0);
    if($diskon_global > 0) {
        echo rowTwoCol("Diskon Nota:", "-" . rp($diskon_global), $content_width, $margin_str);
        $grand_total_calc -= $diskon_global;
    }

    if($ongkir_val > 0) {
        echo rowTwoCol("Ongkir:", rp($ongkir_val), $content_width, $margin_str);
        $grand_total_calc += $ongkir_val;
    }

    if($utang_lama_val > 0) {
        echo rowTwoCol("+ Utang Lama:", rp($utang_lama_val), $content_width, $margin_str);
        $grand_total_calc += $utang_lama_val;
    }

    echo bodyText(str_repeat("-", $content_width), $margin_str);
    
    // --- DISPLAY TOTAL ---
    echo "<b>" . rowTwoCol("GRAND TOTAL:", rp($grand_total_calc), $content_width, $margin_str) . "</b>\n";
    
    // FIX UANG BAYAR: Gunakan tunai input jika lebih besar dari nilai di DB
    $uang_bayar_db = (float)($data['uang_bayar'] ?? 0);
    $uang_bayar = ($tunai_input > $uang_bayar_db) ? $tunai_input : $uang_bayar_db;

    echo rowTwoCol("Bayar:", rp($uang_bayar), $content_width, $margin_str);
    
    $deposit_used = ($depo_pakai_input > 0) ? $depo_pakai_input : ((isset($data['nominal_deposit'])) ? (float)$data['nominal_deposit'] : 0);
    
    if($deposit_used > 0) {
        echo rowTwoCol("Pakai Deposit:", rp($deposit_used), $content_width, $margin_str);
    }

    // Hitung Sisa/Kembali
    $total_dibayar = $uang_bayar + $deposit_used;
    
    if ($data['pelunasan'] == 'N') {
        $sisa = $grand_total_calc - $total_dibayar;
        if($sisa < 0) $sisa = 0;
        echo '<span class="red">' . rowTwoCol("SISA UTANG:", rp($sisa), $content_width, $margin_str) . '</span>';
    } else {
        $kembali = $total_dibayar - $grand_total_calc;
        if($kembali < 0) $kembali = 0;
        echo rowTwoCol("KEMBALI:", rp($kembali), $content_width, $margin_str);
    }

    if ($deposit_used > 0 && $depo_awal_input > 0) {
        echo bodyText(str_repeat("-", $content_width), $margin_str);
        $sisa_depo_akhir = $depo_awal_input - $deposit_used;
        echo rowTwoCol("Saldo Awal:", "Rp " . rp($depo_awal_input), $content_width, $margin_str);
        echo rowTwoCol("Akan Dipotong:", "-Rp " . rp($deposit_used), $content_width, $margin_str);
        echo "<b>" . rowTwoCol("Sisa Saldo:", "Rp " . rp($sisa_depo_akhir), $content_width, $margin_str) . "</b>";
        echo bodyText(str_repeat("-", $content_width), $margin_str);
    }

    // --- FOOTER ---
    echo str_repeat("=", $width_total) . "\n";
    $foot_lines = explode("\n", wordwrap($footer_struk, $width_total, "\n", true));
    foreach($foot_lines as $line) { echo centerText(trim($line), $width_total); }
    echo centerText("-- Terima Kasih --", $width_total);
    ?></pre>
</div>
</body>
</html>
>