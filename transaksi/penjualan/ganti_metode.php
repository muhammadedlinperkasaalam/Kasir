<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}

require_once '../../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);
$no_penjualan = trim($data['no_penjualan'] ?? '');
$metode_baru  = trim($data['metode_baru'] ?? '');

$metode_valid = ['Cash', 'QRIS', 'Debit', 'Transfer'];
if ($no_penjualan === '' || $metode_baru === '') {
    echo json_encode(['status' => 'error', 'message' => 'Data yang dikirim tidak lengkap.']);
    exit;
}

if (!in_array($metode_baru, $metode_valid, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Metode pembayaran tidak valid.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT no_penjualan, metode_pembayaran, keterangan, is_verif_qris FROM penjualan WHERE no_penjualan = ? LIMIT 1");
    $stmt->execute([$no_penjualan]);
    $trx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trx) {
        echo json_encode(['status' => 'error', 'message' => 'Nota transaksi tidak ditemukan.']);
        exit;
    }

    $keterangan = $trx['keterangan'] ?? '';

    $pdo->beginTransaction();

    if ($metode_baru === 'QRIS') {
        // Jika hanya mengganti metode ke QRIS, jangan paksa menjadi Auto-QRIS.
        $stmt_update = $pdo->prepare("UPDATE penjualan SET metode_pembayaran = ? WHERE no_penjualan = ?");
        $stmt_update->execute([$metode_baru, $no_penjualan]);
    } else {
        // Jika sebelumnya Auto-QRIS lalu diganti Cash/Debit/Transfer, matikan tanda Auto-QRIS agar tampilan history ikut berubah.
        $keterangan_baru = preg_replace('/\[Verif:QRIS(?::.*?)?\]/i', '', $keterangan);
        $keterangan_baru = trim(preg_replace('/\s+/', ' ', $keterangan_baru));

        $stmt_update = $pdo->prepare("UPDATE penjualan SET metode_pembayaran = ?, is_verif_qris = 'N', tgl_verif_qris = NULL, keterangan = ? WHERE no_penjualan = ?");
        $stmt_update->execute([$metode_baru, $keterangan_baru, $no_penjualan]);
    }

    // Sinkronkan riwayat uang masuk supaya filter dan rekap per metode ikut berubah.
    $stmt_arus = $pdo->prepare("UPDATE arus_kas SET metode_pembayaran = ? WHERE jenis = 'Pemasukan' AND no_penjualan = ?");
    $stmt_arus->execute([$metode_baru, $no_penjualan]);
    $arus_updated = $stmt_arus->rowCount();

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Metode pembayaran nota ' . $no_penjualan . ' berhasil diganti ke ' . $metode_baru . '.',
        'arus_updated' => $arus_updated
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}
?>
