<?php
require_once '../../config/database.php';

header('Content-Type: application/json');

$keyword = isset($_GET['keyword']) ? $_GET['keyword'] : '';
$search = "%$keyword%";

// Cari pelanggan berdasarkan Nama atau Nomor Telepon
// Limit 10 agar tidak terlalu panjang
$sql = "SELECT * FROM pelanggan 
        WHERE nama_pelanggan LIKE ? OR no_telepon LIKE ? 
        ORDER BY nama_pelanggan ASC LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute([$search, $search]);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($result);
?>