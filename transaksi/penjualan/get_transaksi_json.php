<?php
require_once '../../config/database.php';
header('Content-Type: application/json');

$id = $_GET['id'] ?? '';

if(empty($id)) { echo json_encode(null); exit; }

// 1. Ambil Data Header
$stmt = $pdo->prepare("SELECT * FROM penjualan WHERE no_penjualan = ?");
$stmt->execute([$id]);
$header = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$header) { echo json_encode(null); exit; }

// 2. Ambil Data Item + Nama Barang
$stmt_item = $pdo->prepare("
    SELECT pi.*, b.nama_barang 
    FROM penjualan_item pi 
    JOIN barang b ON pi.kode_barang = b.kode_barang 
    WHERE pi.no_penjualan = ?
");
$stmt_item->execute([$id]);
$items = $stmt_item->fetchAll(PDO::FETCH_ASSOC);

// 3. Susun Format agar mirip object keranjang di JS
$keranjang = [];
foreach($items as $i) {
    $keranjang[] = [
        'kode' => $i['kode_barang'],
        'nama' => $i['nama_barang'],
        'harga' => (float)$i['harga_jual'],
        'qty' => (int)$i['jumlah']
    ];
}

echo json_encode([
    'header' => $header,
    'items' => $keranjang
]);
?>