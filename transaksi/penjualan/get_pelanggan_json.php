<?php
// Header JSON
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

try {
    // 1. Cek Koneksi Database
    $path_config = '../../config/database.php';
    if (!file_exists($path_config)) {
        throw new Exception("File database.php tidak ditemukan.");
    }
    require_once $path_config;

    // 2. Tangkap Input Pencarian
    $search = isset($_GET['term']) ? trim($_GET['term']) : '';
    
    $results = [];

    // 3. Tambahkan Opsi Default "UMUM"
    if (empty($search) || stripos('Pelanggan Umum', $search) !== false) {
        $results[] = [
            'id' => 'UMUM',
            'text' => 'Pelanggan Umum (Cash)',
            'telp' => '-',
            'alamat' => '-',
            'saldo_deposit' => 0,
            'sisa_utang' => 0
        ];
    }

    // 4. Cari di Database
    if ($pdo) {
        // Cek kolom saldo_deposit
        $cekKolom = $pdo->query("SHOW COLUMNS FROM pelanggan LIKE 'saldo_deposit'");
        $adaKolomDeposit = $cekKolom->fetch() ? true : false;

        $sql = "SELECT kode_pelanggan, nama_pelanggan, no_telepon, alamat";
        if($adaKolomDeposit) { $sql .= ", saldo_deposit"; }
        
        $sql .= " FROM pelanggan 
                  WHERE nama_pelanggan LIKE :nama 
                     OR no_telepon LIKE :telp 
                  ORDER BY nama_pelanggan ASC LIMIT 20";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'nama' => "%$search%",
            'telp' => "%$search%"
        ]);
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($data as $row) {
            if ($row['kode_pelanggan'] !== 'UMUM') {
                
                // --- HITUNG SISA UTANG ---
                // Menggunakan total_omzet (karena masalah 0 sudah selesai, kita pakai cara standar yang cepat)
                $utang = 0;
                try {
                    $stmtUtang = $pdo->prepare("
                        SELECT COALESCE(SUM(total_omzet - uang_bayar), 0)
                        FROM penjualan 
                        WHERE kode_pelanggan = ? AND pelunasan = 'N'
                    ");
                    $stmtUtang->execute([$row['kode_pelanggan']]);
                    $utang = (float) $stmtUtang->fetchColumn();
                    
                    if($utang < 0) $utang = 0;

                } catch(Exception $ex) { 
                    $utang = 0; 
                }

                $results[] = [
                    'id' => $row['kode_pelanggan'],
                    'text' => $row['nama_pelanggan'],
                    'telp' => $row['no_telepon'] ?? '-',
                    'alamat' => $row['alamat'] ?? '-',
                    'saldo_deposit' => $adaKolomDeposit ? (float)$row['saldo_deposit'] : 0,
                    'sisa_utang' => $utang // Data ini yang akan diambil Kasir
                ];
            }
        }
    }

    echo json_encode(['results' => $results]);

} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
?>