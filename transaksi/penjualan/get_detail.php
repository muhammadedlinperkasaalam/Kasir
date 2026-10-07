<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

$no_penjualan = $_GET['id'] ?? '';

if(empty($no_penjualan)) { echo json_encode([]); exit; }

// Ambil Header
$stmtHead = $pdo->prepare("SELECT p.*, pl.nama_pelanggan, u.nama_user 
                           FROM penjualan p 
                           LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan 
                           LEFT JOIN operator u ON p.kode_user = u.kode_user 
                           WHERE p.no_penjualan = ?");
$stmtHead->execute([$no_penjualan]);
$header = $stmtHead->fetch(PDO::FETCH_ASSOC);

// Parsing Ongkir dari Keterangan
$ongkir = 0;
if (preg_match('/\{\{ONGKIR\s*:\s*([0-9\.]+)\}\}/i', $header['keterangan'], $match_ongkir)) {
    $ongkir = (float) $match_ongkir[1];
}
$header['ongkir'] = $ongkir;

// Ambil Items
$stmtItems = $pdo->prepare("SELECT pi.*, b.nama_barang 
                            FROM penjualan_item pi 
                            JOIN barang b ON pi.kode_barang = b.kode_barang 
                            WHERE pi.no_penjualan = ?");
$stmtItems->execute([$no_penjualan]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['header' => $header, 'items' => $items]);
?>