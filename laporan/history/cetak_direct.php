<?php
// 1. SILENT MODE
error_reporting(0);
ini_set('display_errors', 0);

session_start();
// Pastikan path config benar (Naik 2 tingkat: laporan/history/ -> root)
require_once '../../config/database.php';

if (!isset($_GET['id'])) { echo "<script>window.close();</script>"; exit; }
$no_penjualan = $_GET['id'];

try {
    // 1. SETTING TOKO
    $stmt_toko = $pdo->query("SELECT * FROM settings_toko WHERE id = 1");
    $toko = $stmt_toko->fetch(PDO::FETCH_ASSOC);

    $printer_port  = $toko['printer_port'] ?? "LPT1";
    $nama_toko     = strtoupper($toko['nama_toko'] ?? "TOKO SAYA");
    $alamat_toko   = $toko['alamat_toko'] ?? "Alamat Toko";
    $footer_struk  = $toko['footer_struk'] ?? "Terima Kasih";
    $feed_lines    = (int)($toko['feed_lines'] ?? 3);

    $width_total   = (int)($toko['content_width'] ?? 33);
    $margin_left   = normalize_margin_left_struk($toko['margin_left'] ?? 3, 3); 
    $margin_right  = (int)($toko['margin_right'] ?? 0);

    $content_width = $width_total - $margin_left - $margin_right;
    $margin_str    = str_repeat(" ", $margin_left);

    // 2. DATA TRANSAKSI
    $stmt = $pdo->prepare("SELECT p.*, pl.nama_pelanggan, u.nama_user FROM penjualan p LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan LEFT JOIN operator u ON p.kode_user = u.kode_user WHERE p.no_penjualan = ?");
    $stmt->execute([$no_penjualan]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) { echo "<script>window.close();</script>"; exit; }

    $stmt_item = $pdo->prepare("SELECT pi.*, b.nama_barang FROM penjualan_item pi JOIN barang b ON pi.kode_barang = b.kode_barang WHERE pi.no_penjualan = ?");
    $stmt_item->execute([$no_penjualan]);
    $items = $stmt_item->fetchAll(PDO::FETCH_ASSOC);

    // 3. CLASS PRINTER
    class EscPosDirect {
        private $buffer = "";
        function initialize() { $this->buffer .= chr(27) . "@"; }
        function center() { $this->buffer .= chr(27) . "a" . chr(1); }
        function left()   { $this->buffer .= chr(27) . "a" . chr(0); }
        function red()    { $this->buffer .= chr(27) . "r" . chr(1); } 
        function black()  { $this->buffer .= chr(27) . "r" . chr(0); } 
        function double() { $this->buffer .= chr(27) . "!" . chr(48); }
        function normal() { $this->buffer .= chr(27) . "!" . chr(0); }
        function bold($on=true) { $this->buffer .= $on ? chr(27) . "E" . chr(1) : chr(27) . "E" . chr(0); }
        function text($str) { $this->buffer .= $str; }
        function feedRaw($lines=1) { $this->buffer .= str_repeat("\n", $lines); }
        function cut() { $this->buffer .= chr(29) . "V" . chr(66) . chr(0); }
        function openDrawer() { $this->buffer .= chr(27) . "p" . chr(0) . chr(50) . chr(250); }
        function getBuffer() { return $this->buffer; }
    }

    function normalize_margin_left_struk($value, $default = 3) {
    if ($value === null || $value === '') return $default;
    $value = (string)$value;
    return preg_match('/^\d+$/', trim($value)) ? (int)trim($value) : strlen($value);
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
function printWrappedLine($p, $text, $cw, $margin, $indent = '') {
        $lineWidth = max(8, $cw - strlen($indent));
        $lines = explode("\n", wordwrap((string)$text, $lineWidth, "\n", true));
        foreach ($lines as $line) $p->text($margin . $indent . $line . "\n");
    }
function printDirectItemRow($p, $nama, $qtyText, $totalText, $cw, $margin) {
        $w_qty = 5; $w_tot = 11;
        $w_nama = $cw - $w_qty - $w_tot - 1;
        if ($w_nama < 5) $w_nama = 5;
        $lines = explode("\n", wordwrap($nama, $w_nama, "\n", true));
        foreach($lines as $idx => $line) {
            $col_nama = str_pad($line, $w_nama, " ", STR_PAD_RIGHT);
            if ($idx === 0) {
                $col_qty = str_pad($qtyText, $w_qty, " ", STR_PAD_LEFT);
                $col_tot = str_pad($totalText, $w_tot, " ", STR_PAD_LEFT);
                $p->text($margin . $col_nama . $col_qty . $col_tot . "\n");
            } else {
                $p->text($margin . $col_nama . str_repeat(" ", $w_qty + $w_tot) . "\n");
            }
        }
    }
function strukTrimWidth($text, $width) {
        $text = trim((string)$text);
        if ($width <= 0) return '';
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $width, '', 'UTF-8');
        }
        return strlen($text) > $width ? substr($text, 0, $width) : $text;
    }
    function printDirectAnalyzerItem($p, $row, $cw, $margin, $no = 0) {
        $qtyText = analyzerQtyText($row['qty']) . 'x';
        $totalText = rp($row['sub']);
        $wQty = 4;
        $wTotal = 9;
        $wNama = max(10, $cw - $wQty - $wTotal);
        $nama = strukTrimWidth($row['nama'] ?? '', $wNama);
        $p->text($margin
            . str_pad($nama, $wNama, ' ', STR_PAD_RIGHT)
            . str_pad($qtyText, $wQty, ' ', STR_PAD_LEFT)
            . str_pad($totalText, $wTotal, ' ', STR_PAD_LEFT)
            . "
");
        if($row['diskon'] > 0) {
            $disc = '-' . rp($row['diskon']);
            $label = 'Diskon';
            $space = $cw - strlen($label) - strlen($disc);
            if ($space < 1) $space = 1;
            $p->text($margin . $label . str_repeat(' ', $space) . $disc . "
");
        }
    }
function printDirectItemsKategoriPerFile($p, $items, $cw, $margin) {
        $blocks=[]; $fileIndex=[];
        foreach($items as $i) {
            $sub = ((float)$i['harga_jual'] * (float)$i['jumlah']) - (float)$i['diskon'];
            $row=['nama'=>(string)($i['nama_barang'] ?? ''),'qty'=>(float)$i['jumlah'],'harga'=>(float)$i['harga_jual'],'diskon'=>(float)$i['diskon'],'sub'=>$sub,'meta'=>parseAnalyzerNotaMeta($i['keterangan'] ?? '')];
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
                $p->text($margin . str_repeat('-', $cw) . "\n");
                printWrappedLine($p, 'FILE: '.$block['file'], $cw, $margin);
                $no=1;
                foreach($block['rows'] as $row) printDirectAnalyzerItem($p, $row, $cw, $margin, $no++);
            } else {
                $row=$block['row'];
                $qtyText = analyzerQtyText($row['qty']) . 'x';
                printDirectItemRow($p, $row['nama'], $qtyText, rp($row['sub']), $cw, $margin);
                if($row['diskon'] > 0) $p->text($margin . str_repeat(' ', 14) . str_pad('(Disc '.rp($row['diskon']).')', 16, ' ', STR_PAD_LEFT) . "\n");
            }
        }
    }


    $p = new EscPosDirect();
    $p->initialize();

    // HEADER
    $p->center();
    $p->red();
    $p->double();
    $nama_wrapped = wordwrap($nama_toko, floor($width_total / 2), "\n", true);
    $p->text($nama_wrapped . "\n");
    $p->normal(); 
    $p->black();

    $alamat_blocks = explode("\n", $alamat_toko);
    foreach($alamat_blocks as $block) {
        $wrapped = wordwrap(trim($block), $width_total, "\n", true);
        $p->text($wrapped . "\n");
    }

    $p->left(); 
    $p->text(str_repeat("=", $width_total) . "\n");

    // BODY
    $dash_line = str_repeat("-", $content_width);

    $p->red();
    $p->text($margin_str . "No   : " . $no_penjualan . "\n");
    $p->black();

    $p->text($margin_str . "Tgl  : " . date('d/m/y H:i', strtotime($data['tgl_penjualan'].' '.$data['jam'])) . "\n");
    $p->text($margin_str . "Kasir: " . substr($data['nama_user'] ?? 'Admin', 0, 15) . "\n");
    
    $pelanggan = !empty($data['nama_pelanggan']) ? $data['nama_pelanggan'] : 'Umum';
    $p->text($margin_str . "Plg  : " . substr($pelanggan, 0, 15) . "\n");

    // ONGKIR & METODE
    $ongkir_val = 0;
    $raw_keterangan = $data['keterangan'] ?? '';
    if (preg_match('/\{\{ONGKIR:([0-9\.]+)\}\}/', $raw_keterangan, $matches)) {
        $ongkir_val = (float)str_replace('.', '', $matches[1]);
    } elseif (isset($data['ongkir']) && $data['ongkir'] > 0) {
        $ongkir_val = (float)$data['ongkir'];
    }

    $metode_bersih = preg_replace('/\{\{.*?\}\}/', '', $raw_keterangan);
    $metode_bersih = trim(str_replace(['|', 'TEMPO'], ['', 'UTANG'], $metode_bersih));
    if(empty($metode_bersih)) $metode_bersih = "CASH";
    
    $p->text($margin_str . "Metode: " . strtoupper(substr($metode_bersih, 0, 15)) . "\n");
    $p->text($margin_str . $dash_line . "\n");

    $p->bold(true);
    $p->text($margin_str . "ITEM           QTY       TOTAL\n");
    $p->bold(false);
    $p->text($margin_str . $dash_line . "\n");

    $qty_tot = 0; 
    $sub_tot_murni = 0;

    foreach($items as $i) {
        $sub = ($i['harga_jual'] * $i['jumlah']) - $i['diskon'];
        $qty_tot += $i['jumlah'];
        $sub_tot_murni += $sub;
    }
    printDirectItemsKategoriPerFile($p, $items, $content_width, $margin_str);

    $p->text($margin_str . $dash_line . "\n");

    // --- FOOTER ANGKA ---
    $grand_total_calc = $sub_tot_murni;

    function printRow($p, $margin, $label, $val, $cw) {
        $lenL = strlen($label);
        $lenV = strlen($val);
        $space = $cw - $lenL - $lenV;
        if($space < 0) $space = 1;
        $p->text($margin . $label . str_repeat(" ", $space) . $val . "\n");
    }

    printRow($p, $margin_str, "Total Item:", $qty_tot, $content_width);
    printRow($p, $margin_str, "Subtotal:", rp($sub_tot_murni), $content_width);

    $diskon_global = (float)($data['diskon_global'] ?? 0);
    if($diskon_global > 0) {
        printRow($p, $margin_str, "Diskon Nota:", "-" . rp($diskon_global), $content_width);
        $grand_total_calc -= $diskon_global;
    }

    // ONGKIR
    if($ongkir_val > 0) {
        printRow($p, $margin_str, "Ongkir:", rp($ongkir_val), $content_width);
        $grand_total_calc += $ongkir_val;
    }

    // UTANG LAMA (Ambil dari DB, bukan URL karena ini reprint)
    $utang_lama_val = (float)($data['uang_pelunasan_utang'] ?? 0);
    if($utang_lama_val > 0) {
        printRow($p, $margin_str, "+ Utang Lama:", rp($utang_lama_val), $content_width);
        $grand_total_calc += $utang_lama_val;
    }

    $p->text($margin_str . $dash_line . "\n");

    // GRAND TOTAL
    $p->bold(true);
    printRow($p, $margin_str, "GRAND TOTAL:", rp($grand_total_calc), $content_width);
    $p->bold(false);
    $p->text("\n");

    $uang_bayar = (float)($data['uang_bayar'] ?? 0);
    printRow($p, $margin_str, "Bayar:", rp($uang_bayar), $content_width);
    
    // INFO PAKAI DEPOSIT (Ambil dari DB)
    $deposit_used = (float)($data['nominal_deposit'] ?? 0);
    
    if($deposit_used > 0) {
        printRow($p, $margin_str, "Pakai Deposit:", rp($deposit_used), $content_width);
    }

    // HITUNG SISA/KEMBALI
    $total_dibayar = $uang_bayar + $deposit_used;
    
    if ($data['pelunasan'] == 'N') {
        $sisa = $grand_total_calc - $total_dibayar;
        if($sisa < 0) $sisa = 0;
        $p->red();
        printRow($p, $margin_str, "SISA UTANG:", rp($sisa), $content_width);
        $p->black();
    } else {
        $kembali = $total_dibayar - $grand_total_calc;
        if($kembali < 0) $kembali = 0;
        printRow($p, $margin_str, "KEMBALI:", rp($kembali), $content_width);
    }

    // FOOTER
    $p->text(str_repeat("=", $width_total) . "\n");
    $p->center();
    
    $footer_wrapped = wordwrap($footer_struk, $width_total, "\n", true);
    $p->text($footer_wrapped . "\n");
    
    $p->text("-- Cetak Ulang --\n");

    $p->feedRaw($feed_lines); 
    $p->cut(); 
    $p->openDrawer();

    // PROSES KIRIM KE PRINTER
    if (strpos(strtoupper($printer_port), 'LPT') !== false || strpos(strtoupper($printer_port), 'COM') !== false) {
        $handle = fopen($printer_port, "w");
        if ($handle) { 
            fwrite($handle, $p->getBuffer()); 
            fclose($handle); 
        }
        echo "<script>window.close();</script>";
    } else {
        $file = sys_get_temp_dir() . "/struk_print.txt";
        file_put_contents($file, $p->getBuffer());
        // Perintah Print Windows (Sesuaikan jika Linux)
        shell_exec("COPY /B \"$file\" \"$printer_port\" > NUL 2>&1");
        echo "<script>window.close();</script>";
    }

} catch (Exception $e) {
    echo "<script>window.close();</script>";
}
?>
