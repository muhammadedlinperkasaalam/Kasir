<?php
// Tambahkan baris ini agar jam sesuai WIB (Jakarta)
date_default_timezone_set('Asia/Jakarta');
// config/database.php

$host = 'localhost';
$db   = 'u230482849_kasir';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Fungsi Helper untuk Generate ID (Auto Number Custom char10)
// function buatKode($pdo, $tabel, $kolom, $prefix) {
//     // Ambil kode terakhir
//     $query = "SELECT $kolom FROM $tabel ORDER BY $kolom DESC LIMIT 1";
//     $stmt = $pdo->prepare($query);
//     $stmt->execute();
//     $row = $stmt->fetch();

//     if ($row) {
//         $lastCode = $row[$kolom]; // misal BRG0001
//         $number = (int) substr($lastCode, 3); // ambil 0001 jadi integer
//         $number++;
//     } else {
//         $number = 1;
//     }
    
//     return $prefix . str_pad($number, 7, "0", STR_PAD_LEFT); // BRG0000001
// }
?>
