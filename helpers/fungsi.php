<?php
// helpers/fungsi.php

function buatKode($pdo, $tabel, $kolom, $prefix) {
    // 1. Cari kode terakhir yang HANYA berawalan prefix tersebut (misal 'JL')
    // Tujuannya agar tidak tercampur dengan format lama (misal 'TRX')
    $query = "SELECT $kolom FROM $tabel WHERE $kolom LIKE '$prefix%' ORDER BY $kolom DESC LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch();

    if ($row) {
        // Contoh data di DB: JL00227116
        $lastCode = $row[$kolom];
        
        // Ambil angka dibelakang prefix
        // substr('JL00227116', 2) akan mengambil "00227116"
        $lastNo = substr($lastCode, strlen($prefix));
        
        // Ubah jadi integer (227116) lalu tambah 1
        $nextNo = (int)$lastNo + 1;
    } else {
        // Jika belum ada data sama sekali dengan prefix JL
        // Default mulai dari 1 (atau bisa diatur angka lain)
        $nextNo = 1;
    }

    // 2. Format Padding (Pengisian Angka 0)
    // Total panjang struktur database adalah char(10)
    // Panjang Prefix (JL) = 2
    // Berarti jatah angka = 10 - 2 = 8 digit
    $target_length = 10;
    $padding = $target_length - strlen($prefix);

    // Hasil: JL + 00227117
    $angka_format = str_pad($nextNo, $padding, "0", STR_PAD_LEFT);
    
    return $prefix . $angka_format;
}
?>
