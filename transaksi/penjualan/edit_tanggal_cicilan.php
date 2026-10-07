<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session habis. Silakan login ulang.']);
    exit;
}

require_once '../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Payload tidak valid.');
    }

    $arusKasId = isset($input['arus_kas_id']) ? (int)$input['arus_kas_id'] : 0;
    $tglBaru   = isset($input['tgl_baru']) ? trim($input['tgl_baru']) : '';

    if ($arusKasId <= 0) {
        throw new Exception('ID pembayaran cicilan tidak valid.');
    }
    if ($tglBaru === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglBaru)) {
        throw new Exception('Format tanggal tidak valid.');
    }

    $stmt = $pdo->prepare("SELECT id, tanggal, no_penjualan, jumlah_masuk, metode_pembayaran, keterangan FROM arus_kas WHERE id = ? AND jenis = 'Pemasukan' LIMIT 1");
    $stmt->execute([$arusKasId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Data pembayaran cicilan tidak ditemukan di arus kas.');
    }
    if (empty($row['no_penjualan'])) {
        throw new Exception('Data ini bukan pembayaran nota/piutang.');
    }
    if ((float)$row['jumlah_masuk'] <= 0) {
        throw new Exception('Nominal pembayaran cicilan tidak valid.');
    }

    $pdo->beginTransaction();

    $oldDate = $row['tanggal'];
    $ketLama = (string)($row['keterangan'] ?? '');
    $catatan = "[Edit tanggal cicilan: {$oldDate} -> {$tglBaru} oleh " . ($_SESSION['nama_user'] ?? $_SESSION['user_id']) . "]";

    if (strpos($ketLama, $catatan) === false) {
        $ketBaru = trim($ketLama . ' ' . $catatan);
    } else {
        $ketBaru = $ketLama;
    }

    $upd = $pdo->prepare("UPDATE arus_kas SET tanggal = ?, keterangan = ? WHERE id = ? AND jenis = 'Pemasukan'");
    $upd->execute([$tglBaru, $ketBaru, $arusKasId]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Tanggal cicilan nota ' . $row['no_penjualan'] . ' berhasil dipindah dari ' . $oldDate . ' ke ' . $tglBaru . '.'
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
