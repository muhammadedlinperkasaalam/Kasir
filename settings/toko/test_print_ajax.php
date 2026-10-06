<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/thermal_printer_helper.php';
session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error', 'message'=>'Session login habis. Silakan login ulang.']); exit; }

// 1. TERIMA DATA DARI FORM
$port    = trim($_POST['port'] ?? '');
if ($port === '') { echo json_encode(['status'=>'error', 'message'=>'Printer port/nama printer masih kosong. Klik Deteksi lalu pilih printer, atau isi Share Name/IP printer.']); exit; }
$width   = max(24, (int)($_POST['width'] ?? 33));        // Lebar Total (misal 33)
$mL      = max(0, (int)($_POST['margin'] ?? 0));       // Margin Kiri
$mR      = max(0, (int)($_POST['margin_right'] ?? 0)); // Margin Kanan
$feed    = max(0, (int)($_POST['feed'] ?? 3));
$nama    = strtoupper($_POST['nama'] ?? 'TEST PRINT');
$alamat  = $_POST['alamat'] ?? '';
if (($width - $mL - $mR) < 10) { echo json_encode(['status'=>'error', 'message'=>'Lebar konten terlalu kecil. Kurangi margin kiri/kanan atau tambah lebar char.']); exit; }

// Hitung Lebar Konten Efektif
$content_width = $width - $mL - $mR;
$margin_str    = str_repeat(" ", $mL);

// --- CLASS PRINTER ---
class EscPosTest {
    private $buffer = "";
    
    function initialize() { $this->buffer .= chr(27) . "@"; }
    
    // FORMATTING
    function center() { $this->buffer .= chr(27) . "a" . chr(1); }
    function left()   { $this->buffer .= chr(27) . "a" . chr(0); }
    function right()  { $this->buffer .= chr(27) . "a" . chr(2); }
    
    // STYLE
    function red()    { $this->buffer .= chr(27) . "r" . chr(1); } 
    function black()  { $this->buffer .= chr(27) . "r" . chr(0); } 
    function double() { $this->buffer .= chr(27) . "!" . chr(48); } // Besar
    function normal() { $this->buffer .= chr(27) . "!" . chr(0); }
    function bold($on=true) { $this->buffer .= $on ? chr(27) . "E" . chr(1) : chr(27) . "E" . chr(0); }
    
    function text($str) { $this->buffer .= $str; }
    function feedRaw($lines=1) { $this->buffer .= str_repeat("\n", $lines); }
    
    function cut() { 
        $this->buffer .= chr(29) . "V" . chr(66) . chr(0); 
    }
    
    function getBuffer() { return $this->buffer; }
}

try {
    $p = new EscPosTest();
    $p->initialize();

    // ==========================================
    // 1. HEADER (CENTER ALIGN - ABAIKAN MARGIN)
    // ==========================================
    $p->center();
    
    // Nama Toko (Merah & Double)
    $p->red();
    $p->double();
    // Wordwrap Nama Toko jika kepanjangan
    $nama_wrapped = wordwrap($nama, $width / 2, "\n", true); // Bagi 2 karena font double lebar
    $p->text($nama_wrapped . "\n");
    $p->normal(); // Balik normal
    $p->black();  // Balik hitam

    // Alamat (Support Enter & Wrap)
    // Pecah berdasarkan Enter manual dulu
    $alamat_blocks = explode("\n", $alamat);
    foreach($alamat_blocks as $block) {
        // Pecah lagi jika kepanjangan (Wrap)
        $wrapped = wordwrap(trim($block), $width, "\n", true);
        $p->text($wrapped . "\n");
    }

    $p->text(str_repeat("=", $width) . "\n");

    // ==========================================
    // 2. BODY (LEFT ALIGN + MARGIN KIRI)
    // ==========================================
    $p->left();

    // Garis Putus (Sesuai Content Width)
    $dash_line = str_repeat("-", $content_width);

    // Info Transaksi
    $p->red(); // No Nota Merah
    $p->text($margin_str . "No     : 20231001\n");
    $p->black();
    
    $p->text($margin_str . "Tgl    : " . date('d/m/y H:i') . "\n");
    $p->text($margin_str . "Kasir  : Admin\n");
    $p->text($margin_str . "Plg    : Umum\n");
    $p->text($margin_str . "Metode : CASH\n");
    $p->text($margin_str . $dash_line . "\n");

    // Header Tabel
    $p->bold(true);
    $p->text($margin_str . "ITEM           QTY       TOTAL\n");
    $p->bold(false);
    $p->text($margin_str . $dash_line . "\n");

    // Item Dummy (Logic Kolom Adaptif)
    $w_qty = 5; 
    $w_tot = 11;
    // Jika margin kanan besar / kertas kecil, kolom nama otomatis mengecil
    $w_nama = $content_width - $w_qty - $w_tot - 1; 
    if($w_nama < 5) $w_nama = 5; // Safety

    // Fungsi Cetak Baris Item
    function printItem($p, $margin, $nama, $qty, $tot, $w_n, $w_q, $w_t) {
        $wrapped = wordwrap($nama, $w_n, "\n", true);
        $lines = explode("\n", $wrapped);
        foreach($lines as $idx => $line) {
            $col_nama = str_pad($line, $w_n, " ", STR_PAD_RIGHT);
            if ($idx === 0) {
                $col_qty = str_pad($qty, $w_q, " ", STR_PAD_LEFT);
                $col_tot = str_pad($tot, $w_t, " ", STR_PAD_LEFT);
                $p->text($margin . $col_nama . $col_qty . $col_tot . "\n");
            } else {
                $p->text($margin . $col_nama . str_repeat(" ", $w_q + $w_t) . "\n");
            }
        }
    }

    printItem($p, $margin_str, "Kertas A4 70gr", "1x", "150", $w_nama, $w_qty, $w_tot);
    printItem($p, $margin_str, "Kertas A3 80gr Tebal", "3x", "900", $w_nama, $w_qty, $w_tot);

    $p->text($margin_str . $dash_line . "\n");

    // ==========================================
    // 3. FOOTER ANGKA (LEFT + MARGIN)
    // ==========================================
    
    function printRow($p, $margin, $label, $val, $cw) {
        $space = $cw - strlen($label) - strlen($val);
        if($space < 0) $space = 1;
        $p->text($margin . $label . str_repeat(" ", $space) . $val . "\n");
    }

    printRow($p, $margin_str, "Total Item :", "4", $content_width);
    printRow($p, $margin_str, "Subtotal   :", "1.050", $content_width);
    $p->text("\n"); // Jarak

    $p->bold(true);
    printRow($p, $margin_str, "GRAND TOTAL:", "1.050", $content_width);
    $p->bold(false);

    printRow($p, $margin_str, "Bayar      :", "1.050", $content_width);
    printRow($p, $margin_str, "KEMBALI    :", "0", $content_width);

    // ==========================================
    // 4. FOOTER BAWAH (CENTER)
    // ==========================================
    $p->center(); // Balik ke Center
    $p->text(str_repeat("=", $width) . "\n");
    
    // Footer Message Wrap
    $foot_wrapped = wordwrap("Setting Printer Sesuai.", $width, "\n", true);
    $p->text($foot_wrapped . "\n");
    
    $p->text("-- Test Selesai --\n");

    // CUT & SEND
    $p->feedRaw($feed);
    $p->cut();

    // PROSES KIRIM KE PRINTER
    $result = thermal_send_raw_to_target($port, $p->getBuffer());

    echo json_encode([
        'status' => 'success',
        'method' => $result['method'] ?? '',
        'target' => $result['target'] ?? $port
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
