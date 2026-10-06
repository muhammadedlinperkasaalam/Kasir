<?php
// Fungsi untuk mencari harga terbaik berdasarkan Qty
function getHargaJual($pdo, $kode_barang, $qty_beli) {
    
    // 1. Ambil Harga Normal dulu
    $stmt = $pdo->prepare("SELECT harga_jual FROM barang WHERE kode_barang = ?");
    $stmt->execute([$kode_barang]);
    $harga_final = $stmt->fetchColumn();

    // 2. Cek Aturan Grosir
    // Logic: Cari aturan yang min_qty-nya terpenuhi, urutkan dari yang terbesar (Priority)
    // Contoh: Beli 50. Ada aturan Min 10 dan Min 20. 
    // Query akan cek yang Min 20 dulu. Jika masuk, pakai harga itu.
    
    $sql_grosir = "SELECT harga_grosir FROM barang_grosir 
                   WHERE kode_barang = ? AND min_qty <= ? 
                   ORDER BY min_qty DESC LIMIT 1";
                   
    $stmt_g = $pdo->prepare($sql_grosir);
    $stmt_g->execute([$kode_barang, $qty_beli]);
    $harga_grosir = $stmt_g->fetchColumn();

    // 3. Jika ada harga grosir yang cocok, pakai itu
    if ($harga_grosir !== false) {
        $harga_final = $harga_grosir;
    }

    return $harga_final;
}
?>
