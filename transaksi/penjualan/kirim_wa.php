<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function rupiah($angka): string {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function buildGatewayUrl(string $rawUrl): string {
    $base = rtrim(trim($rawUrl), '/');
    if ($base === '') $base = 'http://localhost:8000/api';
    if (substr($base, -4) !== '/api') $base .= '/api';
    return $base;
}

function normalizeWaNumber(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    // Pertahankan format JID jika sudah ada, terutama @lid dari WhatsApp baru.
    if (stripos($raw, '@lid') !== false || stripos($raw, '@s.whatsapp.net') !== false) {
        $raw = preg_replace('/:\d+@/', '@', $raw); // 628xx:15@s -> 628xx@s
        $raw = str_replace(' ', '', $raw);
        return $raw;
    }

    $num = preg_replace('/[^0-9]/', '', $raw);
    if ($num === '') return '';

    if (substr($num, 0, 1) === '0') {
        $num = '62' . substr($num, 1);
    }

    // Jangan menebak @lid hanya dari panjang nomor.
    // Gateway akan menambahkan @s.whatsapp.net untuk nomor biasa.
    return $num;
}

function sendWaRequest(string $url, array $payload, int $timeout = 15): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'message' => 'CURL Error: ' . $error, 'http' => $http, 'raw' => $raw];
    }

    $json = json_decode((string)$raw, true);
    $okHttp = ($http >= 200 && $http < 300);
    $okJson = false;

    if (is_array($json)) {
        $status = $json['status'] ?? null;
        if ($status === true || $status === 'true' || strtoupper((string)$status) === 'SUCCESS' || strtolower((string)$status) === 'success') {
            $okJson = true;
        }
        if (isset($json['error']) && $json['error']) {
            $okJson = false;
        }
    }

    if (!$okHttp || !$okJson) {
        $msg = 'Gateway tidak mengembalikan status sukses';
        if (is_array($json)) {
            $msg = $json['message'] ?? $json['error'] ?? $msg;
        }
        return ['ok' => false, 'message' => $msg, 'http' => $http, 'raw' => $raw, 'json' => $json];
    }

    return ['ok' => true, 'message' => 'Terkirim', 'http' => $http, 'raw' => $raw, 'json' => $json];
}

function publicAssetUrl(string $file): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $currentDir = dirname($_SERVER['SCRIPT_NAME'] ?? ''); // /addinta/transaksi/penjualan
    $rootProject = rtrim(dirname(dirname($currentDir)), '/\\'); // /addinta
    if ($rootProject === '/' || $rootProject === '\\') $rootProject = '';
    $url = $protocol . '://' . $host . $rootProject . '/assets/img/' . ltrim($file, '/');
    return str_replace(' ', '%20', $url);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonOut(['status' => 'error', 'message' => 'Payload JSON tidak valid']);
}

$no_penjualan = trim($input['no_penjualan'] ?? '');
$nomor_hp_raw = trim($input['nomor_hp'] ?? '');
$utang_lama_input  = (float)($input['utang_lama_input'] ?? 0);
$total_bayar_input = (float)($input['total_bayar_input'] ?? 0);
$depo_pakai = (float)($input['depo_pakai'] ?? 0);

if ($no_penjualan === '') {
    jsonOut(['status' => 'error', 'message' => 'Nomor penjualan kosong']);
}

// Ambil konfigurasi gateway. Prioritas waha_setting, fallback setting_wa.
try {
    $setting = $pdo->query("SELECT base_url, session_name FROM waha_setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $setting = false;
}

if (!$setting) {
    try {
        $setting = $pdo->query("SELECT base_url, session_name FROM setting_wa WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $setting = false;
    }
}

$base_url = buildGatewayUrl($setting['base_url'] ?? 'http://localhost:8000/api');
$session_id = trim($setting['session_name'] ?? 'device_admin_1');
if ($session_id === '') $session_id = 'device_admin_1';

// Ambil transaksi.
$stmt_head = $pdo->prepare("SELECT p.*, pl.nama_pelanggan, pl.no_telepon AS pelanggan_no_telepon, pl.wa_lid, pl.saldo_deposit,
    CASE
        WHEN p.total_omzet > 0 THEN p.total_omzet
        ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan)
    END AS total_belanja_ini
    FROM penjualan p
    LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
    WHERE p.no_penjualan = ?");
$stmt_head->execute([$no_penjualan]);
$trx = $stmt_head->fetch(PDO::FETCH_ASSOC);

if (!$trx) {
    jsonOut(['status' => 'error', 'message' => 'Transaksi tidak ditemukan']);
}

// Fallback nomor dari pelanggan jika input kosong.
if ($nomor_hp_raw === '') {
    $nomor_hp_raw = trim($trx['wa_lid'] ?? '');
    if ($nomor_hp_raw === '') $nomor_hp_raw = trim($trx['pelanggan_no_telepon'] ?? '');
}

$nomor_hp = normalizeWaNumber($nomor_hp_raw);
if ($nomor_hp === '') {
    jsonOut(['status' => 'error', 'message' => 'Nomor WhatsApp pelanggan kosong/tidak valid']);
}

// Ambil item.
$stmt_item = $pdo->prepare("SELECT pi.*, COALESCE(b.nama_barang, pi.kode_barang) AS nama_barang
    FROM penjualan_item pi
    LEFT JOIN barang b ON pi.kode_barang = b.kode_barang
    WHERE pi.no_penjualan = ?
    ORDER BY pi.kode_barang ASC");
$stmt_item->execute([$no_penjualan]);
$items = $stmt_item->fetchAll(PDO::FETCH_ASSOC);

function formatQtyWa($qty): string {
    $txt = number_format((float)$qty, 2, ',', '.');
    $txt = rtrim(rtrim($txt, '0'), ',');
    return $txt === '' ? '0' : $txt;
}

function parseAnalyzerMetaWa($keterangan): ?array {
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

function trimTextWa($text, int $width): string {
    $text = trim((string)$text);
    if ($width <= 0) return '';
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, '', 'UTF-8');
    }
    return strlen($text) > $width ? substr($text, 0, $width) : $text;
}

function waItemLine(string $nama, $qty, $subtotal, int $width = 34): string {
    $qtyText = formatQtyWa($qty) . 'x';
    $totalText = number_format((float)$subtotal, 0, ',', '.');

    $wQty = 5;
    $wTotal = 10;
    $wNama = max(12, $width - $wQty - $wTotal);
    $nama = trimTextWa($nama, $wNama);

    return str_pad($nama, $wNama, ' ', STR_PAD_RIGHT)
        . str_pad($qtyText, $wQty, ' ', STR_PAD_LEFT)
        . str_pad($totalText, $wTotal, ' ', STR_PAD_LEFT);
}

function waSeparator(string $label = ''): string {
    $line = "-----------------------------";
    $label = trim($label);
    if ($label === '') return $line . "\n";
    return $line . "\n" . $label . "\n" . $line . "\n";
}

function buildListBarangWa(array $items): string {
    if (empty($items)) return "- (Tidak ada item)\n";

    $fileBlocks = [];
    $fileIndex = [];
    $normalRows = [];

    foreach ($items as $item) {
        $jumlah = (float)($item['jumlah'] ?? 0);
        $harga = (float)($item['harga_jual'] ?? 0);
        $diskon = (float)($item['diskon'] ?? 0);
        $subtotal = ($harga * $jumlah) - $diskon;

        $row = [
            'nama' => (string)($item['nama_barang'] ?? '-'),
            'qty' => $jumlah,
            'subtotal' => $subtotal,
            'meta' => parseAnalyzerMetaWa($item['keterangan'] ?? ''),
        ];

        if ($row['meta']) {
            $file = $row['meta']['file'];
            if (!isset($fileIndex[$file])) {
                $fileIndex[$file] = count($fileBlocks);
                $fileBlocks[] = ['file' => $file, 'rows' => []];
            }
            $fileBlocks[$fileIndex[$file]]['rows'][] = $row;
        } else {
            $normalRows[] = $row;
        }
    }

    $out = '';

    if (!empty($fileBlocks)) {
        $out .= waSeparator('*NOTA FILE*');
        foreach ($fileBlocks as $idx => $block) {
            if ($idx > 0) $out .= waSeparator();
            $out .= 'FILE: ' . $block['file'] . "\n";
            foreach ($block['rows'] as $row) {
                $out .= waItemLine($row['nama'], $row['qty'], $row['subtotal']) . "\n";
            }
        }
    }

    if (!empty($normalRows)) {
        if (!empty($fileBlocks)) {
            // Label Nota Tambahan hanya muncul kalau ada Nota File juga.
            // Kalau transaksi hanya berisi barang/item biasa, format tetap polos seperti nota lama.
            if ($out !== '') $out .= waSeparator();
            $out .= waSeparator('*NOTA TAMBAHAN*');
        }
        foreach ($normalRows as $row) {
            $out .= waItemLine($row['nama'], $row['qty'], $row['subtotal']) . "\n";
        }
    }

    return rtrim($out) . "\n";
}

$list_barang_text = buildListBarangWa($items);

// Hitungan nota.
$grand_total_nota = (float)($trx['total_belanja_ini'] ?? 0);
$grand_total_kotor = $grand_total_nota + $utang_lama_input;

$metode_db_real = strtoupper(trim($trx['metode_pembayaran'] ?? ''));
$keterangan_db = strtolower(trim($trx['keterangan'] ?? ''));

if (($metode_db_real === 'DEPOSIT' || strpos($keterangan_db, 'deposit') !== false) && $depo_pakai == 0) {
    $depo_pakai = $grand_total_kotor;
}

$tagihan_bersih = max(0, $grand_total_kotor - $depo_pakai);
$uang_bayar_db = (float)($trx['uang_bayar'] ?? 0);
$uang_bayar = max($total_bayar_input, $uang_bayar_db);

$sisa_utang_aktual = $tagihan_bersih - $uang_bayar;
$kembali = 0;
if ($sisa_utang_aktual < 0) {
    $kembali = abs($sisa_utang_aktual);
    $sisa_utang_aktual = 0;
}
$is_utang = ($sisa_utang_aktual > 1);

if (!empty($trx['is_verif_qris']) && $trx['is_verif_qris'] === 'Y') {
    $metode_cetak = 'QRIS';
} elseif (!empty($trx['metode_pembayaran'])) {
    $metode_cetak = strtoupper($trx['metode_pembayaran']);
} else {
    $metode_cetak = preg_replace('/\[Verif:QRIS.*?\]/i', '', $trx['keterangan'] ?? '');
    $metode_cetak = preg_replace('/\{\{.*?\}\}/', '', $metode_cetak);
    $metode_cetak = trim(str_replace(['|', 'TEMPO'], ['', 'UTANG'], $metode_cetak));
    if ($metode_cetak === '') $metode_cetak = 'CASH';
}

try {
    $sql_antrian = "SELECT COUNT(DISTINCT no_penjualan) FROM (
        SELECT p.no_penjualan
        FROM penjualan p
        LEFT JOIN order_pekerjaan op ON p.no_penjualan = op.no_penjualan
        WHERE p.tgl_penjualan >= CURDATE() AND op.status_order IS NULL
        UNION
        SELECT no_penjualan FROM order_pekerjaan WHERE status_order = 'Antri'
    ) AS antrian_aktif";
    $jumlah_antrian = (int)$pdo->query($sql_antrian)->fetchColumn();
} catch (Throwable $e) {
    $jumlah_antrian = 0;
}

try {
    try {
        $pdo->query("SELECT send_qris_unpaid FROM setting_wa LIMIT 1");
    } catch (Throwable $e) {
        $pdo->exec("ALTER TABLE setting_wa ADD COLUMN send_qris_unpaid TINYINT(1) NOT NULL DEFAULT 1");
    }
    $stmt_tpl = $pdo->query("SELECT template_header, template_footer, template_deposit, qris_image, qris_caption, send_qris_unpaid FROM setting_wa WHERE id = 1 LIMIT 1");
    $tpl = $stmt_tpl->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $tpl = [];
}

$template_raw = $is_utang
    ? (!empty($tpl['template_footer']) ? $tpl['template_footer'] : "*TAGIHAN*\n{total}\nSisa: {sisa}")
    : (!empty($tpl['template_header']) ? $tpl['template_header'] : "*LUNAS*\n{total}\nKembali: {kembali}");

if ($utang_lama_input > 0 || $depo_pakai > 0) {
    $total_text = 'Belanja Saat Ini: ' . rupiah($grand_total_nota) . "\n";
    if ($utang_lama_input > 0) $total_text .= 'Utang Sebelumnya: ' . rupiah($utang_lama_input) . "\n";
    if ($depo_pakai > 0) $total_text .= 'Potong Deposit: -' . rupiah($depo_pakai) . "\n";
    $total_text .= '*Grand Total: ' . rupiah($tagihan_bersih) . '*';
} else {
    $total_text = '*Total: ' . rupiah($tagihan_bersih) . '*';
}

$map = [
    '{nama_pelanggan}' => $trx['nama_pelanggan'] ?? 'Pelanggan',
    '{no_nota}'        => $no_penjualan,
    '{tanggal}'        => date('d-m-Y H:i'),
    '{metode}'         => $metode_cetak,
    '{list_barang}'    => $list_barang_text,
    '{ongkir}'         => rupiah($trx['ongkir'] ?? 0),
    '{total}'          => $total_text,
    '{bayar}'          => rupiah($uang_bayar),
    '{kembali}'        => rupiah($kembali),
    '{sisa}'           => rupiah($sisa_utang_aktual),
    '{antrian}'        => (string)$jumlah_antrian,
];

$pesan_final = str_replace(array_keys($map), array_values($map), $template_raw);

$sisa_depo_aktual = (float)($trx['saldo_deposit'] ?? 0);
if ($depo_pakai > 0 || $sisa_depo_aktual > 0) {
    if ($depo_pakai > 0) {
        $depo_awal_aktual = $sisa_depo_aktual + $depo_pakai;
        $tpl_depo = !empty($tpl['template_deposit']) ? $tpl['template_deposit'] : "*INFO DEPOSIT*\nSaldo Awal: {depo_awal}\nAkan Dipotong: -{depo_pakai}\nSisa Saldo: {sisa_depo}";
        $map_depo = [
            '{depo_awal}'  => rupiah($depo_awal_aktual),
            '{depo_pakai}' => rupiah($depo_pakai),
            '{sisa_depo}'  => rupiah($sisa_depo_aktual),
        ];
        $pesan_final .= "\n\n" . str_replace(array_keys($map_depo), array_values($map_depo), $tpl_depo);
    } else {
        $pesan_final .= "\n\n*INFO DEPOSIT*\nSisa Saldo Deposit Anda saat ini: *" . rupiah($sisa_depo_aktual) . '*';
    }
}

// Kirim teks dan tunggu hasil asli dari gateway, agar tidak false success.
$textReq = sendWaRequest($base_url . '/send-message', [
    'session_id' => $session_id,
    'phone'      => $nomor_hp,
    'message'    => $pesan_final,
]);

if (!$textReq['ok']) {
    jsonOut([
        'status' => 'error',
        'message' => 'Gagal kirim teks WhatsApp: ' . $textReq['message'],
        'debug' => ['http' => $textReq['http'] ?? 0, 'raw' => $textReq['raw'] ?? null]
    ]);
}

$mediaWarning = null;
$send_qris_unpaid = isset($tpl['send_qris_unpaid']) ? (int)$tpl['send_qris_unpaid'] : 1;
if ($is_utang && $send_qris_unpaid === 1 && !empty($tpl['qris_image'])) {
    $qris_url = publicAssetUrl($tpl['qris_image']);
    $caption_final = str_replace(array_keys($map), array_values($map), (!empty($tpl['qris_caption']) ? $tpl['qris_caption'] : 'Silakan scan QRIS.'));

    $mediaReq = sendWaRequest($base_url . '/send-media', [
        'session_id' => $session_id,
        'phone'      => $nomor_hp,
        'type'       => 'image',
        'url'        => $qris_url,
        'caption'    => $caption_final,
    ]);

    if (!$mediaReq['ok']) {
        $mediaWarning = 'Teks terkirim, tetapi gambar QRIS gagal dikirim: ' . $mediaReq['message'];
    }
}

jsonOut([
    'status' => 'success',
    'message' => $mediaWarning ?: 'Nota berhasil dikirim ke WhatsApp',
    'warning' => $mediaWarning,
    'phone' => $nomor_hp,
]);
