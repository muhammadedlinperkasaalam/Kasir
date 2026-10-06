<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// 1. DATA REFERENSI
$kategori = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC")->fetchAll();
$list_satuan = $pdo->query("SELECT * FROM satuan ORDER BY nama_satuan ASC")->fetchAll();

// 2. LOGIKA KODE BARANG OTOMATIS (Format: B000000860)
$q_kode = $pdo->query("SELECT MAX(kode_barang) as kode_terbesar FROM barang");
$d_kode = $q_kode->fetch();
$kode_otomatis = "B000000001"; // Default jika database kosong

if ($d_kode['kode_terbesar']) {
    // Ambil angka dari kode terakhir (Hapus huruf 'B' di depan)
    $urutan = (int) substr($d_kode['kode_terbesar'], 1);
    $urutan++; // Tambah 1
    
    // Format ulang menjadi B + 9 digit angka (padding nol di kiri)
    $kode_otomatis = "B" . sprintf("%09s", $urutan);
}

$berhasil = false;

// 3. PROSES SIMPAN
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $kode  = $_POST['kode_barang'];
    $nama  = $_POST['nama_barang'];
    $kat   = $_POST['kode_kategori'];
    $sat   = $_POST['satuan'];
    $beli  = str_replace('.', '', $_POST['harga_beli']);
    $jual  = str_replace('.', '', $_POST['harga_jual']);
    $stok  = $_POST['stok'];
    $limit = $_POST['stok_limit'];
    $aktif = $_POST['aktif'];

    try {
        $sql = "INSERT INTO barang (kode_barang, nama_barang, kode_kategori, satuan, harga_beli, harga_jual, stok, stok_limit, aktif) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kode, $nama, $kat, $sat, $beli, $jual, $stok, $limit, $aktif]);
        
        $berhasil = true;
        
        // Update kode otomatis untuk tampilan selanjutnya (opsional, agar jika refresh tidak duplikat visual)
        // Tapi karena redirect, ini aman.
        
    } catch (Exception $e) {
        echo "<script>alert('Gagal: " . $e->getMessage() . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tambah Barang</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        .select2-container .select2-selection--single { height: 38px; border: 1px solid #dee2e6; padding-top: 5px; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { top: 5px; }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5 mb-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Tambah Barang Baru</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kode Barang (Otomatis)</label>
                            <input type="text" name="kode_barang" class="form-control bg-light fw-bold" value="<?= $kode_otomatis ?>" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nama Barang</label>
                            <input type="text" name="nama_barang" class="form-control" required autofocus>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Kategori</label>
                            <select name="kode_kategori" class="form-select select2-search" required>
                                <option value="">- Pilih -</option>
                                <?php foreach($kategori as $k): ?>
                                    <option value="<?= $k['kode_kategori'] ?>"><?= $k['nama_kategori'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Satuan</label>
                            <select name="satuan" class="form-select select2-search" required>
                                <option value="">- Pilih -</option>
                                <?php foreach($list_satuan as $s): ?>
                                    <option value="<?= $s['nama_satuan'] ?>"><?= $s['nama_satuan'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harga Beli</label>
                            <input type="number" name="harga_beli" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Harga Jual</label>
                            <input type="number" name="harga_jual" class="form-control" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Stok Awal</label>
                            <input type="number" name="stok" class="form-control" value="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-danger">Limit Alert</label>
                            <input type="number" name="stok_limit" class="form-control" value="5" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Status Barang</label>
                            <select name="aktif" class="form-select bg-light" required>
                                <option value="Y" selected>AKTIF (Tampil)</option>
                                <option value="N">TIDAK AKTIF (Sembunyi)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Simpan Barang</button>
                    <a href="index.php" class="btn btn-secondary">Kembali</a>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() { $('.select2-search').select2({ placeholder: "Pilih...", width: '100%' }); });
        <?php if($berhasil): ?>
            Swal.fire({ icon: 'success', title: 'Berhasil', text: 'Barang disimpan!', timer: 1500, showConfirmButton: false }).then(() => { window.location = 'index.php'; });
        <?php endif; ?>
    </script>
</body>
</html>