<?php
session_start();
require_once '../../config/database.php';

// Cek Login
if (!isset($_SESSION['user_id'])) { die("Akses Ditolak."); }
if (!isset($_GET['id'])) { die("ID Transaksi tidak ditemukan."); }

$no_penjualan = $_GET['id'];

// 1. AMBIL DATA SETTING TOKO
$stmt_toko = $pdo->query("SELECT * FROM settings_toko WHERE id = 1");
$toko = $stmt_toko->fetch(PDO::FETCH_ASSOC);

$nama_toko     = strtoupper($toko['nama_toko'] ?? "TOKO SAYA");
$alamat_toko   = $toko['alamat_toko'] ?? "Alamat Toko";
$footer_struk  = $toko['footer_struk'] ?? "Terima Kasih";
$width_total   = (int)($toko['content_width'] ?? 33);
$margin_left   = normalize_margin_left_struk($toko['margin_left'] ?? 1, 1);

function normalize_margin_left_struk($value, $default = 3) {
    if ($value === null || $value === '') return $default;
    $value = (string)$value;
    return preg_match('/^\d+$/', trim($value)) ? (int)trim($value) : strlen($value);
}

// Helper Formatting
$margin_str    = str_repeat(" ", $margin_left);
$content_width = $width_total - $margin_left;

// 2. AMBIL HEADER TRANSAKSI
// Kita ambil total_omzet sebagai Grand Total Akhir
$query = "SELECT p.*, pl.nama_pelanggan, u.nama_user 
          FROM penjualan p 
          LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan 
          LEFT JOIN operator u ON p.kode_user = u.kode_user 
          WHERE p.no_penjualan = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$no_penjualan]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) { die("Data Transaksi Tidak Ditemukan."); }

// 3. AMBIL ITEM BARANG
$stmt_item = $pdo->prepare("SELECT pi.*, b.nama_barang 
                            FROM penjualan_item pi 
                            JOIN barang b ON pi.kode_barang = b.kode_barang 
                            WHERE pi.no_penjualan = ?");
$stmt_item->execute([$no_penjualan]);
$items = $stmt_item->fetchAll(PDO::FETCH_ASSOC);

// 4. PARSING METODE BAYAR DARI KETERANGAN
$raw_ket = $data['keterangan'];
// Hapus tag sistem seperti {{ONGKIR:..}} {{PAY:..}}
$metode_display = preg_replace('/\{\{.*?\}\}/', '', $raw_ket); 
// Hapus info deposit tambahan seperti " | Depo:50000"
$metode_display = preg_replace('/\|.*?$/', '', $metode_display);
$metode_display = trim(strtoupper($metode_display));
if(empty($metode_display)) $metode_display = "CASH";

// --- FUNGSI PRINTING ---
function centerText($text, $width) {
    $len = strlen($text);
    if ($len >= $width) return $text . "\n";
    $pad = floor(($width - $len) / 2);
    return str_repeat(" ", $pad) . $text . "\n";
}
function rowTwoCol($label, $val, $cw, $margin) {
    $lenL = strlen($label);
    $lenV = strlen($val);
    $space = $cw - $lenL - $lenV;
    if ($space < 0) $space = 1;
    return $margin . $label . str_repeat(" ", $space) . $val . "\n";
}
function rp($angka) { return number_format($angka, 0, ',', '.'); }

function parseAnalyzerNotaMeta($keterangan) {
    $ket = trim((string)$keterangan);
    if ($ket === '' || stripos($ket, 'Analyzer:') === false) return null;
    $ket = preg_replace('/^.*?Analyzer:\s*/i', '', $ket);
    $parts = array_map('trim', explode('|', $ket));
    $file = $parts[0] ?? '';
    if ($file === '') return null;
    return ['file'=>$file,'kategori'=>$parts[1] ?? '','detail'=>$parts[2] ?? '','profile'=>$parts[3] ?? ''];
}
function analyzerQtyText($qty) {
    return rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.');
}
function strukTrimWidth($text, $width) {
    $text = trim((string)$text);
    if ($width <= 0) return '';
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, '', 'UTF-8');
    }
    return strlen($text) > $width ? substr($text, 0, $width) : $text;
}
function analyzerWrapEcho($text, $cw, $margin, $indent = '') {
    $lineWidth = max(8, $cw - strlen($indent));
    $lines = explode("\n", wordwrap((string)$text, $lineWidth, "\n", true));
    foreach($lines as $line) echo $margin . $indent . $line . "\n";
}
function renderNotaItemsKategoriPerFileSimple($items, $content_width, $margin_str) {
    $blocks=[]; $fileIndex=[];
    foreach ($items as $i) {
        $qty=(float)$i['jumlah'];
        $sub=((float)$i['harga_jual'] * $qty) - (float)$i['diskon'];
        $row=['nama'=>(string)($i['nama_barang'] ?? ''),'qty'=>$qty,'harga'=>(float)$i['harga_jual'],'diskon'=>(float)$i['diskon'],'sub'=>$sub,'meta'=>parseAnalyzerNotaMeta($i['keterangan'] ?? '')];
        if ($row['meta']) {
            $key=$row['meta']['file'];
            if (!isset($fileIndex[$key])) { $fileIndex[$key]=count($blocks); $blocks[]=['type'=>'file','file'=>$key,'rows'=>[]]; }
            $blocks[$fileIndex[$key]]['rows'][]=$row;
        } else {
            $blocks[]=['type'=>'normal','row'=>$row];
        }
    }
    foreach($blocks as $block) {
        if ($block['type']==='file') {
            echo $margin_str . str_repeat('-', $content_width) . "\n";
            analyzerWrapEcho('FILE: '.$block['file'], $content_width, $margin_str);
            $no=1;
            foreach($block['rows'] as $row) {
                $qtyText = analyzerQtyText($row['qty']) . 'x';
                $totalText = rp($row['sub']);
                $wQty = 4;
                $wTotal = 9;
                $wNama = max(10, $content_width - $wQty - $wTotal);
                $nama = strukTrimWidth($row['nama'], $wNama);
                echo $margin_str
                    . str_pad($nama, $wNama, ' ', STR_PAD_RIGHT)
                    . str_pad($qtyText, $wQty, ' ', STR_PAD_LEFT)
                    . str_pad($totalText, $wTotal, ' ', STR_PAD_LEFT)
                    . "
";
                if($row['diskon'] > 0) echo rowTwoCol('Diskon', '-' . rp($row['diskon']), $content_width, $margin_str);
                $no++;
            }
        } else {
            $row=$block['row'];
            $namaLines = explode("\n", wordwrap($row['nama'], $content_width, "\n", true));
            foreach($namaLines as $line) echo $margin_str . $line . "\n";
            $qtyText = analyzerQtyText($row['qty']);
            echo rowTwoCol($qtyText.' x '.rp($row['harga']), rp($row['sub']), $content_width, $margin_str);
            if($row['diskon'] > 0) echo rowTwoCol('(Disc)', '-' . rp($row['diskon']), $content_width, $margin_str);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Nota #<?= $no_penjualan ?></title>
    <style>
        body { margin: 0; padding: 20px; font-family: 'Courier New', monospace; font-size: 12px; background: #eee; }
        .struk-box { background: #fff; width: <?= ($width_total * 8.5) ?>px; padding: 10px 5px; margin: 0 auto; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border: 1px solid #ccc; }
        pre { white-space: pre-wrap; margin: 0; line-height: 1.2; }
        .btn-print { display: block; width: 100%; max-width: <?= ($width_total * 8.5) ?>px; margin: 0 auto 10px auto; padding: 10px; background: #212529; color: #fff; text-align: center; text-decoration: none; font-weight: bold; border-radius: 5px; }
        .btn-print:hover { background: #000; }
        @media print { 
            body { background: #fff; padding: 0; } 
            .struk-box { width: 100%; box-shadow: none; border: none; padding: 0; margin: 0; }
            .btn-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

<a href="#" onclick="window.print(); return false;" class="btn-print">
    <span style="font-size:16px;">🖨️</span> CETAK NOTA
</a>

<div class="struk-box">
<pre><?php
// HEADER
echo '<div style="text-align:center; font-weight:bold; font-size:14px;">' . $nama_toko . "</div>\n";
$alamat_lines = explode("\n", wordwrap($alamat_toko, $width_total, "\n", true));
foreach($alamat_lines as $line) { echo centerText(trim($line), $width_total); }
echo str_repeat("=", $width_total) . "\n";

// INFO NOTA
echo $margin_str . "No   : " . $no_penjualan . "\n";
echo $margin_str . "Waktu: " . date('d/m/y H:i', strtotime($data['tgl_penjualan'].' '.$data['jam'])) . "\n";
echo $margin_str . "Kasir: " . substr($data['nama_user'] ?? 'Admin', 0, 15) . "\n";
echo $margin_str . "Plg  : " . substr($data['nama_pelanggan'] ?? 'Umum', 0, 15) . "\n";
echo $margin_str . "Bayar: " . $metode_display . "\n";

echo $margin_str . str_repeat("-", $content_width) . "\n";

// ITEMS
$qty_total = 0;
$subtotal_murni = 0;

foreach ($items as $i) {
    $qty = $i['jumlah'];
    $sub = ($i['harga_jual'] * $qty) - $i['diskon'];
    $qty_total += $qty;
    $subtotal_murni += $sub;
}
renderNotaItemsKategoriPerFileSimple($items, $content_width, $margin_str);

echo $margin_str . str_repeat("-", $content_width) . "\n";

// --- FOOTER KEUANGAN (Ambil langsung dari kolom tabel penjualan) ---

// 1. Total Item
echo rowTwoCol("Total Item:", $qty_total, $content_width, $margin_str);

// 2. Subtotal (Jumlah harga item bersih)
echo rowTwoCol("Subtotal:", rp($subtotal_murni), $content_width, $margin_str);

// 3. Diskon Global (Nota)
$disc_global = (float)$data['diskon_global'];
if ($disc_global > 0) {
    echo rowTwoCol("Disc Nota:", "-" . rp($disc_global), $content_width, $margin_str);
}

// 4. Ongkir
$ongkir = (float)$data['ongkir'];
if ($ongkir > 0) {
    echo rowTwoCol("Ongkir:", rp($ongkir), $content_width, $margin_str);
}

// 5. Utang Lama (Jika ada pembayaran utang lama di nota ini)
$bayar_utang_lalu = (float)$data['uang_pelunasan_utang'];
if ($bayar_utang_lalu > 0) {
    echo rowTwoCol("Byr Utang Lalu:", rp($bayar_utang_lalu), $content_width, $margin_str);
}

echo $margin_str . str_repeat("-", $content_width) . "\n";

// 6. GRAND TOTAL
// Di proses.php kita simpan (total_omzet = (subtotal - diskon) + ongkir)
// Tapi kita perlu tambah bayar_utang_lalu jika ingin ditampilkan sebagai Total Tagihan di struk
$grand_total_display = $data['total_omzet'] + $bayar_utang_lalu;
echo '<div style="font-weight:bold;">' . rowTwoCol("GRAND TOTAL:", rp($grand_total_display), $content_width, $margin_str) . "</div>";

// 7. PEMBAYARAN
$uang_bayar = (float)$data['uang_bayar'];
echo rowTwoCol("Tunai/Transfer:", rp($uang_bayar), $content_width, $margin_str);

$pakai_deposit = (float)$data['nominal_deposit'];
if ($pakai_deposit > 0) {
    echo rowTwoCol("Pakai Deposit:", rp($pakai_deposit), $content_width, $margin_str);
}

// 8. SISA / KEMBALI
$total_dibayar = $uang_bayar + $pakai_deposit;
$selisih = $total_dibayar - $grand_total_display;

if ($data['pelunasan'] == 'N') {
    $sisa_utang = abs($selisih);
    echo '<div style="font-weight:bold;">' . rowTwoCol("SISA UTANG:", rp($sisa_utang), $content_width, $margin_str) . "</div>";
} else {
    $kembali = ($selisih < 0) ? 0 : $selisih;
    echo rowTwoCol("KEMBALI:", rp($kembali), $content_width, $margin_str);
}

// FOOTER
echo "\n";
echo str_repeat("=", $width_total) . "\n";
$footer_lines = explode("\n", wordwrap($footer_struk, $width_total, "\n", true));
foreach($footer_lines as $line) { echo centerText(trim($line), $width_total); }
echo centerText("-- Cetak Ulang --", $width_total);

?></pre>
</div>
</body>
</html>