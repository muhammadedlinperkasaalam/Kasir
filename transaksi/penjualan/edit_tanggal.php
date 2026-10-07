<?php
session_start();

// 1. Cek sesi login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}

require_once '../../config/database.php';

$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!isset($data['no_penjualan']) || !isset($data['tgl_baru'])) {
    echo json_encode(['status' => 'error', 'message' => 'Data yang dikirim tidak lengkap.']);
    exit;
}

$no_penjualan = $data['no_penjualan'];
$tgl_baru = $data['tgl_baru'];

// Buat format tanggal baru menjadi "dd/mm" (contoh: 01/03)
$tgl_baru_dm = date('d/m', strtotime($tgl_baru));

try {
    // 2. Ambil isi 'keterangan' lama dari database terlebih dahulu
    $stmt_get = $pdo->prepare("SELECT keterangan FROM penjualan WHERE no_penjualan = ?");
    $stmt_get->execute([$no_penjualan]);
    $row = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Data transaksi tidak ditemukan.']);
        exit;
    }

    $keterangan = $row['keterangan'] ?? '';

    // 3. RESTRUKTURISASI TANGGAL DI DALAM KETERANGAN MENGGUNAKAN REGEX
    // Target A: Format "Utang | 01/03 Bayar CASH: 50.000" -> Ubah 01/03 nya
    $keterangan = preg_replace('/(Utang\s*\|\s*)\d{2}\/\d{2}(\s*Bayar)/i', '${1}' . $tgl_baru_dm . '${2}', $keterangan);
    
    // Target B: Format cicilan "{{ PAY : 01/03 | Cash : 50000 }}" -> Ubah 01/03 nya
    $keterangan = preg_replace('/(\{\{\s*PAY\s*:\s*)\d{2}\/\d{2}(?:\/\d{2,4})?(\s*\|)/i', '${1}' . $tgl_baru_dm . '${2}', $keterangan);

    // Target C: Format Auto-QRIS "[Verif:QRIS:2023-12-01]" -> Ubah format full YYYY-MM-DD
    $keterangan = preg_replace('/(\[Verif:QRIS:)\d{4}-\d{2}-\d{2}(\])/i', '${1}' . $tgl_baru . '${2}', $keterangan);


    // 4. MULAI TRANSAKSI DATABASE (Ubah kedua tabel sekaligus)
    $pdo->beginTransaction();

    // A. Update tabel penjualan (Tabel Utama) - UPDATE tgl_penjualan, tgl_transaksi, dan keterangan
    $sql_penjualan = "UPDATE penjualan SET tgl_penjualan = ?, tgl_transaksi = ?, keterangan = ? WHERE no_penjualan = ?";
    $stmt_penjualan = $pdo->prepare($sql_penjualan);
    $stmt_penjualan->execute([$tgl_baru, $tgl_baru, $keterangan, $no_penjualan]);

    // B. Update tabel penjualan_item (Tabel Detail)
    $sql_detail = "UPDATE penjualan_item SET tgl_penjualan = ? WHERE no_penjualan = ?";
    $stmt_detail = $pdo->prepare($sql_detail);
    $stmt_detail->execute([$tgl_baru, $no_penjualan]);

    // Jika semua eksekusi di atas sukses tanpa error, simpan permanen
    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Tanggal di nota utama, detail item, & keterangan berhasil diperbarui!']);

} catch (PDOException $e) {
    // Jika ada error di tengah jalan, batalkan semua perubahan
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}
?>