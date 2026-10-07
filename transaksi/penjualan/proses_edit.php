<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

// Tangkap Data JSON
$json = $_POST['data'];
$data = json_decode($json, true);

// Variabel Dasar
$no_penjualan = $data['no_penjualan'];
$pelanggan    = $data['kode_pelanggan'];
$uang_bayar   = str_replace('.', '', $data['uang_bayar']);
$metode       = $data['metode'];
$items_baru   = $data['items'];
$user_login   = $_SESSION['user_id'] ?? 'SYSTEM';
$tgl_sekarang = date('Y-m-d');

if(empty($items_baru)) {
    echo json_encode(['status' => 'error', 'message' => 'Keranjang kosong']);
    exit;
}

try {
    $pdo->beginTransaction();

    // --------------------------------------------------------
    // 1. KEMBALIKAN STOK LAMA (RESTOCK)
    // --------------------------------------------------------
    $stmt_old = $pdo->prepare("SELECT kode_barang, jumlah FROM penjualan_item WHERE no_penjualan = ?");
    $stmt_old->execute([$no_penjualan]);
    $old_items = $stmt_old->fetchAll();

    foreach($old_items as $old) {
        $cek = $pdo->prepare("SELECT satuan FROM barang WHERE kode_barang = ?");
        $cek->execute([$old['kode_barang']]);
        $brg = $cek->fetch();
        
        if($brg && stripos($brg['satuan'], 'jasa') === false) {
            $stmt_restock = $pdo->prepare("UPDATE barang SET stok = stok + ? WHERE kode_barang = ?");
            $stmt_restock->execute([$old['jumlah'], $old['kode_barang']]);
        }
    }

    // --------------------------------------------------------
    // 2. HAPUS ITEM LAMA
    // --------------------------------------------------------
    $del = $pdo->prepare("DELETE FROM penjualan_item WHERE no_penjualan = ?");
    $del->execute([$no_penjualan]);

    // --------------------------------------------------------
    // 3. PERSIAPAN DATA (HITUNG TOTAL & FORMAT KETERANGAN)
    // --------------------------------------------------------
    $total_transaksi = 0;
    
    // Hitung total dulu
    foreach ($items_baru as $itm) {
        $total_transaksi += ($itm['harga'] * $itm['jumlah']);
    }

    // Logika Uang Bayar (QRIS/Transfer/Debit = Lunas Pas)
    if($metode != 'Cash' && $metode != 'Utang') {
        $uang_bayar = $total_transaksi;
    }

    // --- FORMAT KETERANGAN BARU (PENTING) ---
    // Format: Lunas | Bayar: {{QRIS:2500}}
    $status_ket = ($metode == 'Utang') ? 'Tempo' : 'Lunas';
    $nominal_ket = (float)$uang_bayar;
    $format_keterangan = "$status_ket | Bayar: {{" . $metode . ":" . $nominal_ket . "}}";

    // --------------------------------------------------------
    // 4. INPUT ITEM BARU
    // --------------------------------------------------------
    $sql_insert = "INSERT INTO penjualan_item (
        no_penjualan, tgl_penjualan, kode_user, kode_pelanggan, kode_barang, 
        harga_beli_kotor, harga_beli_bersih, harga_jual, diskon, jumlah, 
        stok_awal, stok_terakhir, keterangan, shift_new, pelunasan
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_potong_stok = $pdo->prepare("UPDATE barang SET stok = stok - ? WHERE kode_barang = ?");
    $stmt_info_barang = $pdo->prepare("SELECT stok, harga_beli, satuan FROM barang WHERE kode_barang = ?");

    foreach ($items_baru as $item) {
        $kode = $item['kode_barang'];
        $qty  = $item['jumlah'];
        $harga_jual = $item['harga'];
        $diskon = 0; 

        $stmt_info_barang->execute([$kode]);
        $info = $stmt_info_barang->fetch();
        
        $stok_sekarang_db = $info['stok'] ?? 0;
        $stok_nanti       = $stok_sekarang_db - $qty;

        $hb_kotor  = $info['harga_beli'] ?? 0;
        $hb_bersih = $info['harga_beli'] ?? 0;

        $status_lunas = ($metode == 'Utang') ? 'N' : 'Y';

        $stmt_insert->execute([
            $no_penjualan,      
            $tgl_sekarang,      
            $user_login,        
            $pelanggan,         
            $kode,              
            $hb_kotor,          
            $hb_bersih,         
            $harga_jual,        
            $diskon,            
            $qty,               
            $stok_sekarang_db,  
            $stok_nanti,        
            $format_keterangan, 
            '',                 
            $status_lunas       
        ]);

        if($info && stripos($info['satuan'], 'jasa') === false) {
            $stmt_potong_stok->execute([$qty, $kode]);
        }
    }

    // --------------------------------------------------------
    // 5. UPDATE HEADER (TABEL PENJUALAN)
    // --------------------------------------------------------
    $kembali = $uang_bayar - $total_transaksi;
    $pelunasan_header = ($metode == 'Utang') ? 'N' : 'Y';

    $update_head = $pdo->prepare("UPDATE penjualan SET 
                                    kode_pelanggan = ?, 
                                    uang_bayar = ?, 
                                    pelunasan = ?, 
                                    tgl_transaksi = ?,
                                    keterangan = ?
                                  WHERE no_penjualan = ?");
    
    $update_head->execute([
        $pelanggan, 
        $uang_bayar, 
        $pelunasan_header, 
        $tgl_sekarang, 
        $format_keterangan, 
        $no_penjualan
    ]);

    // --------------------------------------------------------
    // 6. CATAT KE ARUS KAS
    // --------------------------------------------------------
    // Ambil uang pelunasan utang dari JSON (jika pelanggan sekalian bayar utang)
    $uang_pelunasan_utang = isset($data['uang_pelunasan_utang']) ? (float)$data['uang_pelunasan_utang'] : 0;
    
    // Hanya catat ke arus kas JIKA metode BUKAN Utang dan BUKAN Deposit
    if ($metode != 'Utang' && $metode != 'Deposit') {
        
        // Total uang riil yang masuk = total belanja barang + uang bayar utang (jika ada)
        $total_uang_masuk = $total_transaksi + $uang_pelunasan_utang;

        if ($total_uang_masuk > 0) {
            $keterangan_kas = "Penjualan Nota: " . $no_penjualan;
            if ($uang_pelunasan_utang > 0) {
                $keterangan_kas .= " (+ Pelunasan Utang)";
            }

            $sql_kas = "INSERT INTO arus_kas 
                        (no_penjualan, metode_pembayaran, tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt_kas = $pdo->prepare($sql_kas);
            $stmt_kas->execute([
                $no_penjualan,
                $metode,
                $tgl_sekarang,
                'Pemasukan',
                $keterangan_kas,
                $total_uang_masuk,
                0,
                $user_login
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>