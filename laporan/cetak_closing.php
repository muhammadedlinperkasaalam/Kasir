<?php
session_start();
if (!isset($_SESSION['user_id'])) { die("Akses Ditolak"); }
require_once '../config/database.php';

// --- VALIDASI & LOGIKA PHP (SAMA PERSIS DENGAN KODE BAPAK) ---
$tgl  = isset($_GET['tgl']) ? $_GET['tgl'] : date('Y-m-d');
$user = isset($_GET['user']) ? $_GET['user'] : '';

// Query Data Utama
$sql = "SELECT 
            COUNT(no_penjualan) as total_trx,
            SUM(total_omzet) as total_omzet,
            SUM(uang_bayar) as total_setor
        FROM penjualan 
        WHERE tgl_penjualan = ? ";

$params = [$tgl];

if (!empty($user) && $user !== 'SYSTEM') {
    $sql .= " AND kode_user = ?";
    $params[] = $user;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

// Query Rincian (Tunai vs Non-Tunai)
$sql_rincian = "SELECT keterangan, uang_bayar FROM penjualan WHERE tgl_penjualan = ?";
if (!empty($user) && $user !== 'SYSTEM') {
    $sql_rincian .= " AND kode_user = ?";
}
$stmt_rincian = $pdo->prepare($sql_rincian);
$stmt_rincian->execute($params);
$transaksi = $stmt_rincian->fetchAll(PDO::FETCH_ASSOC);

$tunai = 0;
$non_tunai = 0;

foreach ($transaksi as $row) {
    $ket  = $row['keterangan'];
    $uang = $row['uang_bayar'];

    if (strpos($ket, '{{') !== false) {
        $non_tunai += $uang;
    } else {
        $tunai += $uang;
    }
}

$nama_display = empty($user) ? 'SEMUA KASIR' : $user;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Harian - <?= date('d/m/Y', strtotime($tgl)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { background-color: #525659; font-family: 'Times New Roman', Times, serif; }
        
        /* Format Kertas A4 */
        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            display: block;
            margin: 20px auto;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            position: relative;
        }

        .header-laporan { border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 20px; text-align: center; }
        .judul { font-size: 18pt; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; }
        .sub-judul { font-size: 12pt; margin-top: 5px; }

        .info-table td { padding: 5px; font-size: 11pt; }
        .main-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .main-table th, .main-table td { border: 1px solid #000; padding: 10px; font-size: 11pt; }
        .main-table th { background-color: #f0f0f0; text-align: left; }
        
        .signature-box { margin-top: 50px; display: flex; justify-content: space-between; }
        .sign-area { text-align: center; width: 200px; }
        .sign-line { border-bottom: 1px solid #000; margin-top: 60px; display: block; }

        /* Mode Cetak */
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .page { margin: 0; border: none; width: 100%; box-shadow: none; padding: 10mm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print container mt-3 mb-3 text-center">
        <button onclick="window.print()" class="btn btn-primary fw-bold"><i class="fas fa-print"></i> Cetak Laporan</button>
        <button onclick="window.close()" class="btn btn-secondary"><i class="fas fa-times"></i> Tutup</button>
    </div>

    <div class="page">
        
        <div class="header-laporan">
            <div class="judul">ADDINTA PRINTING</div>
            <div class="sub-judul">LAPORAN SETORAN KASIR HARIAN</div>
            <small>Jl. Contoh Alamat No. 123, Kota Surabaya</small>
        </div>

        <table class="info-table mb-3">
            <tr>
                <td width="150"><strong>Hari / Tanggal</strong></td>
                <td width="20">:</td>
                <td><?= date('l, d F Y', strtotime($tgl)) ?></td>
            </tr>
            <tr>
                <td><strong>Nama Kasir</strong></td>
                <td>:</td>
                <td class="text-uppercase"><?= $nama_display ?></td>
            </tr>
            <tr>
                <td><strong>Waktu Cetak</strong></td>
                <td>:</td>
                <td><?= date('d-m-Y H:i:s') ?></td>
            </tr>
        </table>

        <h5 class="fw-bold border-bottom pb-2">Rincian Keuangan</h5>
        <table class="main-table">
            <tr>
                <th width="50%">Keterangan</th>
                <th width="50%" class="text-end">Nominal (Rp)</th>
            </tr>
            <tr>
                <td>Total Transaksi (Nota)</td>
                <td class="text-end"><?= $data['total_trx'] ?> Transaksi</td>
            </tr>
            <tr>
                <td><strong>Total Omzet Penjualan</strong></td>
                <td class="text-end"><strong><?= number_format($data['total_omzet'], 0, ',', '.') ?></strong></td>
            </tr>
            
            <tr style="background-color: #fafafa;"><td colspan="2"></td></tr>

            <tr>
                <td>Penerimaan Tunai (Cash)</td>
                <td class="text-end"><?= number_format($tunai, 0, ',', '.') ?></td>
            </tr>
            <tr>
                <td>Penerimaan Non-Tunai (QRIS/Transfer)</td>
                <td class="text-end"><?= number_format($non_tunai, 0, ',', '.') ?></td>
            </tr>
            
            <tr style="background-color: #e9ecef;">
                <td class="fw-bold text-uppercase">Total Setor (Uang Masuk)</td>
                <td class="text-end fw-bold" style="font-size: 14pt;">Rp <?= number_format($data['total_setor'], 0, ',', '.') ?></td>
            </tr>
        </table>

        <div class="mt-4 p-3 border rounded" style="background-color: #f9f9f9;">
            <strong>Catatan:</strong>
            <ul>
                <li>Total Omzet adalah nilai total barang yang terjual hari ini.</li>
                <li>Total Setor adalah uang fisik/digital yang diterima (Omzet dikurangi Piutang/Belum Lunas).</li>
                <li>Pastikan uang fisik di laci sesuai dengan <strong>Penerimaan Tunai</strong>.</li>
            </ul>
        </div>

        <div class="signature-box">
            <div class="sign-area">
                <br>Dibuat Oleh,<br>
                <span class="sign-line text-uppercase fw-bold"><?= $nama_display ?></span>
                <small>Kasir / Staff</small>
            </div>

            <div class="sign-area">
                Surabaya, <?= date('d/m/Y') ?><br>
                Mengetahui,<br>
                <span class="sign-line fw-bold">OWNER / ADMIN</span>
                <small>Penerima Setoran</small>
            </div>
        </div>

    </div>

</body>
</html>
