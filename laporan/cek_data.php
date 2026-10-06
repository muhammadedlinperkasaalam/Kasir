<?php
session_start();
require_once '../config/database.php';

echo "<h3>DIAGNOSA DATA PENJUALAN</h3>";

// 1. CEK RENTANG TANGGAL TOTAL
$sql1 = "SELECT MIN(tgl_penjualan) as tgl_min, MAX(tgl_penjualan) as tgl_max, COUNT(*) as total FROM penjualan";
$cek1 = $pdo->query($sql1)->fetch(PDO::FETCH_ASSOC);

echo "<strong>1. Rentang Data Global:</strong><br>";
echo "Data Pertama: " . ($cek1['tgl_min'] ?? 'KOSONG') . "<br>";
echo "Data Terakhir: " . ($cek1['tgl_max'] ?? 'KOSONG') . "<br>";
echo "Total Transaksi: " . number_format($cek1['total']) . " baris<br><br>";

// 2. CEK JUMLAH DATA PER TAHUN
echo "<strong>2. Rincian Per Tahun (Dari Database):</strong><br>";
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr style='background:#eee'><th>Tahun</th><th>Jumlah Transaksi</th><th>Total Omzet</th></tr>";

$sql2 = "SELECT YEAR(tgl_penjualan) as thn, COUNT(*) as jml, SUM(total_omzet) as omzet 
         FROM penjualan 
         GROUP BY YEAR(tgl_penjualan) 
         ORDER BY thn ASC";
$stmt2 = $pdo->query($sql2);

$found_years = [];
while($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $found_years[] = $row['thn'];
    echo "<tr>";
    echo "<td>" . $row['thn'] . "</td>";
    echo "<td>" . number_format($row['jml']) . "</td>";
    echo "<td>Rp " . number_format($row['omzet']) . "</td>";
    echo "</tr>";
}
echo "</table><br>";

// 3. CEK KHUSUS TAHUN 2018 (Januari - April)
echo "<strong>3. Cek Detail Tahun 2018 (Jan-Apr):</strong><br>";
$sql3 = "SELECT MONTH(tgl_penjualan) as bln, COUNT(*) as jml 
         FROM penjualan 
         WHERE YEAR(tgl_penjualan) = 2018 
         GROUP BY MONTH(tgl_penjualan) 
         ORDER BY bln ASC";
$stmt3 = $pdo->query($sql3);
$data2018 = $stmt3->fetchAll(PDO::FETCH_KEY_PAIR);

echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr style='background:#eee'><th>Bulan</th><th>Status</th></tr>";
$bulan_indo = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

for($i=1; $i<=12; $i++) {
    $jml = $data2018[$i] ?? 0;
    $status = ($jml > 0) ? "<span style='color:green'>Ada ($jml trx)</span>" : "<span style='color:red'>KOSONG</span>";
    echo "<tr><td>{$bulan_indo[$i]}</td><td>$status</td></tr>";
}
echo "</table>";
?>
