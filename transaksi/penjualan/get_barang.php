<?php
// Matikan semua error display agar tidak merusak format JSON
error_reporting(0);
ini_set('display_errors', 0);

// Mulai buffer output
ob_start();

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Koneksi Database
    $path_config = __DIR__ . '/../../config/database.php';
    if (!file_exists($path_config)) {
        throw new Exception("File config database tidak ditemukan di: " . $path_config);
    }
    require_once $path_config;

    $keyword  = $_GET['keyword'] ?? '';
    $kategori = $_GET['kategori'] ?? '';

    // 2. Query Barang Dasar
    // Tips: Tambahkan "aktif='Y'" jika ingin menyembunyikan barang non-aktif
    $sql = "SELECT * FROM barang WHERE 1=1";
    $params = [];

    // --- FILTER PENCARIAN (Multi-word) ---
    if (!empty($keyword)) {
        $clean_keyword = str_replace('%', '', $keyword);
        $words = explode(' ', $clean_keyword);
        
        $sql .= " AND (";
        $conditions = [];
        foreach ($words as $word) {
            if (trim($word) !== '') {
                $conditions[] = "(nama_barang LIKE ? OR kode_barang LIKE ?)";
                $params[] = "%$word%";
                $params[] = "%$word%";
            }
        }
        $sql .= !empty($conditions) ? implode(' AND ', $conditions) : "1=1";
        $sql .= ")";
    }

    // --- FILTER KATEGORI ---
    if (!empty($kategori)) {
        $sql .= " AND kode_kategori = ?";
        $params[] = $kategori;
    }

    // --- PENGURUTAN (SORTING) ---
    // 1. Nama Barang (A-Z)
    // 2. Stok (Terbanyak ke Sedikit) -> Biar barang ready muncul duluan kalau nama mirip
    $sql .= " ORDER BY kode_barang ASC, stok DESC";

    // --- LIMIT DATA ---
    // Jika tidak mencari, ambil 50 saja biar ringan. Jika mencari, ambil 100.
    $limit = (empty($keyword) && empty($kategori)) ? 50 : 100;
    $sql .= " LIMIT $limit";

    // 3. Eksekusi Query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Cek Tabel Grosir (Sekali saja agar cepat)
    $grosir_exists = false;
    try {
        $pdo->query("SELECT 1 FROM barang_grosir LIMIT 1");
        $grosir_exists = true;
    } catch (Exception $e) { $grosir_exists = false; }

    // 5. Looping Data untuk Format Angka & Grosir
    foreach ($products as &$p) {
        // Konversi tipe data agar JSON valid
        $p['harga_jual'] = (float)$p['harga_jual'];
        $p['stok']       = (float)$p['stok'];
        
        // Bersihkan nama barang dari karakter aneh (Non-UTF8)
        if (isset($p['nama_barang'])) {
            $p['nama_barang'] = mb_convert_encoding($p['nama_barang'], 'UTF-8', 'UTF-8');
        }

        // Ambil data grosir jika tabelnya ada
        $p['grosir'] = [];
        if ($grosir_exists) {
            try {
                $stmt_grosir = $pdo->prepare("SELECT min_qty, harga_grosir FROM barang_grosir WHERE kode_barang = ? ORDER BY min_qty ASC");
                $stmt_grosir->execute([$p['kode_barang']]);
                $p['grosir'] = $stmt_grosir->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $ex) { /* Ignore */ }
        }
    }

    // 6. Output JSON Bersih
    ob_end_clean(); 
    echo json_encode($products, JSON_INVALID_UTF8_IGNORE); 

} catch (Exception $e) {
    // Error Handling
    ob_end_clean();
    http_response_code(500); 
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}
?>