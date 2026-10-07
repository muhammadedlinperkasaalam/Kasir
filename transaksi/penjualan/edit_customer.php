<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Sesi login habis. Silakan login ulang.']);
    exit;
}

require_once '../../config/database.php';

function json_out($arr) {
    if (ob_get_length()) ob_clean();
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function table_exists(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function column_exists(PDO $pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}

function try_update_order_customer(PDO $pdo, $no_penjualan, $kode_pelanggan, $nama_pelanggan) {
    // Sinkron ringan ke tabel order jika kolomnya tersedia. Diabaikan jika struktur berbeda.
    $tables = ['order_pekerjaan', 'order_files', 'print_jobs'];
    foreach ($tables as $table) {
        if (!table_exists($pdo, $table)) continue;

        $whereCol = null;
        foreach (['no_penjualan', 'no_faktur', 'no_nota', 'kode_transaksi'] as $c) {
            if (column_exists($pdo, $table, $c)) { $whereCol = $c; break; }
        }
        if (!$whereCol) continue;

        $sets = [];
        $params = [];
        if (column_exists($pdo, $table, 'kode_pelanggan')) {
            $sets[] = "kode_pelanggan = ?";
            $params[] = $kode_pelanggan;
        }
        foreach (['nama_pelanggan', 'nama_customer', 'customer_name'] as $c) {
            if (column_exists($pdo, $table, $c)) {
                $sets[] = "`$c` = ?";
                $params[] = $nama_pelanggan;
            }
        }
        if (!$sets) continue;

        $params[] = $no_penjualan;
        try {
            $stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$whereCol` = ?");
            $stmt->execute($params);
        } catch (Throwable $e) {}
    }
}

function safe_like_query(PDO $pdo, $q) {
    $q = trim((string)$q);
    $like = '%' . $q . '%';
    $hasPhone = column_exists($pdo, 'pelanggan', 'no_telepon');

    try {
        if ($hasPhone) {
            $sql = "SELECT kode_pelanggan, nama_pelanggan, no_telepon
                    FROM pelanggan
                    WHERE CONVERT(nama_pelanggan USING utf8mb4) LIKE :q_nama
                       OR CONVERT(COALESCE(no_telepon, '') USING utf8mb4) LIKE :q_telp
                       OR kode_pelanggan LIKE :q_kode
                    ORDER BY nama_pelanggan ASC
                    LIMIT 30";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':q_nama' => $like, ':q_telp' => $like, ':q_kode' => $like]);
        } else {
            $sql = "SELECT kode_pelanggan, nama_pelanggan, '' AS no_telepon
                    FROM pelanggan
                    WHERE CONVERT(nama_pelanggan USING utf8mb4) LIKE :q_nama
                       OR kode_pelanggan LIKE :q_kode
                    ORDER BY nama_pelanggan ASC
                    LIMIT 30";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':q_nama' => $like, ':q_kode' => $like]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fallback paling aman jika CONVERT tidak cocok di database tertentu.
        if ($hasPhone) {
            $stmt = $pdo->prepare("SELECT kode_pelanggan, nama_pelanggan, no_telepon
                                   FROM pelanggan
                                   WHERE nama_pelanggan LIKE ? OR COALESCE(no_telepon, '') LIKE ? OR kode_pelanggan LIKE ?
                                   ORDER BY nama_pelanggan ASC
                                   LIMIT 30");
            $stmt->execute([$like, $like, $like]);
        } else {
            $stmt = $pdo->prepare("SELECT kode_pelanggan, nama_pelanggan, '' AS no_telepon
                                   FROM pelanggan
                                   WHERE nama_pelanggan LIKE ? OR kode_pelanggan LIKE ?
                                   ORDER BY nama_pelanggan ASC
                                   LIMIT 30");
            $stmt->execute([$like, $like]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

try {
    $action = $_GET['action'] ?? '';

    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            // Ambil beberapa pelanggan terbaru/awal sebagai bantuan saat modal dibuka.
            $hasPhone = column_exists($pdo, 'pelanggan', 'no_telepon');
            $cols = $hasPhone ? 'kode_pelanggan, nama_pelanggan, no_telepon' : "kode_pelanggan, nama_pelanggan, '' AS no_telepon";
            $stmt = $pdo->query("SELECT $cols FROM pelanggan ORDER BY nama_pelanggan ASC LIMIT 20");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $rows = safe_like_query($pdo, $q);
        }

        json_out([
            'status' => 'success',
            'data' => array_map(function($r) {
                return [
                    'kode_pelanggan' => (string)($r['kode_pelanggan'] ?? ''),
                    'nama_pelanggan' => (string)($r['nama_pelanggan'] ?? ''),
                    'no_telepon' => (string)($r['no_telepon'] ?? '')
                ];
            }, $rows)
        ]);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $no_penjualan = trim($data['no_penjualan'] ?? '');
    $kode_baru = trim($data['kode_pelanggan'] ?? '');

    if ($no_penjualan === '') json_out(['status' => 'error', 'message' => 'Nomor nota tidak terbaca.']);
    if ($kode_baru === '') json_out(['status' => 'error', 'message' => 'Pilih nama customer dari data pelanggan dulu.']);

    $stmt = $pdo->prepare("SELECT p.no_penjualan, p.kode_pelanggan AS kode_lama, pl.nama_pelanggan AS nama_lama
                           FROM penjualan p
                           LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                           WHERE p.no_penjualan = ?
                           LIMIT 1");
    $stmt->execute([$no_penjualan]);
    $trx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trx) json_out(['status' => 'error', 'message' => 'Data nota tidak ditemukan.']);

    $stmt = $pdo->prepare("SELECT kode_pelanggan, nama_pelanggan, " . (column_exists($pdo, 'pelanggan', 'no_telepon') ? "no_telepon" : "'' AS no_telepon") . "
                           FROM pelanggan
                           WHERE kode_pelanggan = ?
                           LIMIT 1");
    $stmt->execute([$kode_baru]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cust) json_out(['status' => 'error', 'message' => 'Data pelanggan pilihan tidak ditemukan.']);

    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->beginTransaction();

    // Hanya ubah nota ini saja: master pelanggan tidak diubah.
    $stmt = $pdo->prepare("UPDATE penjualan SET kode_pelanggan = ? WHERE no_penjualan = ?");
    $stmt->execute([$kode_baru, $no_penjualan]);

    try_update_order_customer($pdo, $no_penjualan, $kode_baru, $cust['nama_pelanggan'] ?? '');

    if ($pdo->inTransaction()) $pdo->commit();

    json_out([
        'status' => 'success',
        'message' => 'Nama customer pada nota ini berhasil diganti. Data master pelanggan tidak diubah.',
        'no_penjualan' => $no_penjualan,
        'kode_pelanggan' => $kode_baru,
        'nama_pelanggan' => (string)($cust['nama_pelanggan'] ?? ''),
        'no_telepon' => (string)($cust['no_telepon'] ?? ''),
        'mode' => 'nota_only'
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignore) {}
    }
    json_out(['status' => 'error', 'message' => 'Gagal update customer nota: ' . $e->getMessage()]);
}
