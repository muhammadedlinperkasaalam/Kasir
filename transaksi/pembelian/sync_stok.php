<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Sesi login habis. Silakan login ulang.');
    }

    require_once '../../config/database.php';
    require_once __DIR__ . '/stock_sync_helper.php';

    $no = trim($_POST['no_pembelian'] ?? $_GET['no'] ?? '');
    if ($no === '') {
        throw new Exception('Nomor pembelian kosong.');
    }

    $updated = pembelian_apply_stock_from_items($pdo, $no, $_SESSION['user_id'] ?? null);

    $warnings = [];
    foreach ($updated as $row) {
        if (!empty($row['perlu_update_harga'])) {
            $warnings[] = ($row['nama_barang'] ?? $row['kode_barang']) . ' perlu update harga jual';
        }
    }
    $message = 'Stok berhasil disinkronkan dari nota ' . $no . '. Harga beli barang juga sudah diperbarui.';
    if (!empty($warnings)) {
        $message .= ' Perlu update harga jual: ' . implode(', ', $warnings) . '.';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'updated' => $updated,
        'price_warnings' => $warnings,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
