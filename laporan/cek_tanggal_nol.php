<?php
session_start();
require_once '../config/database.php';

echo "<h3>🕵️‍♂️ DETEKTIF DATA: Mencari Tanggal 0000-00-00</h3>";
echo "<a href='../index.php'>Kembali ke Dashboard</a><hr>";

// 1. CEK JUMLAH DATA ERROR
$sql = "SELECT COUNT(*) as total FROM penjualan WHERE tgl_penjualan = '0000-00-00' OR tgl_penjualan IS NULL";
$total_error = $pdo->query($sql)->fetchColumn();

if ($total_error > 0) {
    echo "<div style='background:#ffcccc; padding:15px; border:1px solid red; border-radius:5px;'>";
    echo "⚠️ <strong>DITEMUKAN:</strong> Ada <strong>$total_error Transaksi</strong> dengan tanggal 0000-00-00 atau Kosong!";
    echo "</div><br>";

    // 2. TAMPILKAN DATA ERRORNYA (URUT NO PENJUALAN KECIL KE BESAR)
    echo "<strong>Daftar 50 Data Pertama (Urut No Penjualan ASC):</strong>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='width:100%; border-collapse:collapse; margin-top:10px;'>";
    echo "<tr style='background:#eee'>";
    echo "<th>No Penjualan</th>";
    echo "<th>Kasir</th>";
    echo "<th>Total Omzet</th>";
    echo "<th>Keterangan</th>";
    echo "<th>Aksi Cepat (Ubah ke Hari Ini)</th>";
    echo "</tr>";

    // PERBAIKAN DISINI: ORDER BY no_penjualan ASC
    $sql_list = "SELECT * FROM penjualan 
                 WHERE tgl_penjualan = '0000-00-00' OR tgl_penjualan IS NULL 
                 ORDER BY no_penjualan ASC 
                 LIMIT 50";
    $stmt = $pdo->query($sql_list);

    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['no_penjualan']}</td>";
        echo "<td>{$row['kode_user']}</td>";
        echo "<td>Rp " . number_format($row['total_omzet']) . "</td>";
        echo "<td>{$row['keterangan']}</td>";
        echo "<td align='center'>
                <form method='POST' style='margin:0;'>
                    <input type='hidden' name='fix_no' value='{$row['no_penjualan']}'>
                    <input type='date' name='fix_tgl' value='".date('Y-m-d')."' required>
                    <button type='submit' name='btn_fix'>Simpan Tanggal</button>
                </form>
              </td>";
        echo "</tr>";
    }
    echo "</table>";

} else {
    echo "<div style='background:#ccffcc; padding:15px; border:1px solid green; border-radius:5px;'>";
    echo "✅ <strong>BERSIH:</strong> Tidak ditemukan data dengan tanggal 0000-00-00.";
    echo "</div>";
    echo "<p>Jika data tahun 2015-2018 masih tidak muncul, berarti data tersebut memang <strong>belum ada/terhapus</strong> dari database.</p>";
}

// --- PROSES PERBAIKAN PER ITEM ---
if(isset($_POST['btn_fix'])) {
    $no  = $_POST['fix_no'];
    $tgl = $_POST['fix_tgl'];
    
    // Update Tanggal
    $upd = $pdo->prepare("UPDATE penjualan SET tgl_penjualan = ? WHERE no_penjualan = ?");
    $upd->execute([$tgl, $no]);
    
    // Refresh halaman agar data yang sudah diperbaiki hilang dari list
    echo "<script>window.location='cek_tanggal_nol.php';</script>";
}
?>
