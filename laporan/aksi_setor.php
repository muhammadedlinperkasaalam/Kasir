<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tgl  = $_POST['tgl'];
    $user = $_POST['user'];

    try {
        // Gunakan REPLACE INTO agar jika diklik ulang, waktunya ter-update
        $sql = "REPLACE INTO log_setoran (tgl_laporan, kode_user, waktu_cetak) VALUES (?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tgl, $user]);
        echo "ok";
    } catch (Exception $e) {
        echo "error";
    }
}
?>
