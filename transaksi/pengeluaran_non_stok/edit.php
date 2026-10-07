<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

$id = $_GET['id'];
$data = $pdo->prepare("SELECT * FROM pengeluaran_non_stok WHERE kode_pengeluaran = ?");
$data->execute([$id]);
$row = $data->fetch();

if (!$row) { echo "Data tidak ditemukan!"; exit; }

// Ambil Kategori
$kategori = $pdo->query("SELECT * FROM kategori_pengeluaran")->fetchAll();

if (isset($_POST['update'])) {
    $tgl = $_POST['tanggal'];
    $kat = $_POST['kategori'];
    $jml = str_replace('.', '', $_POST['jumlah']);
    $ket = $_POST['keterangan'];

    $sql = "UPDATE pengeluaran_non_stok SET tanggal=?, kode_kategori_pengeluaran=?, jumlah=?, keterangan=? WHERE kode_pengeluaran=?";
    $pdo->prepare($sql)->execute([$tgl, $kat, $jml, $ket, $id]);
    
    echo "<script>alert('Berhasil diupdate!'); window.location='index.php';</script>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Pengeluaran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow-sm col-md-6 mx-auto">
            <div class="card-header bg-warning text-white fw-bold">Edit Pengeluaran</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label>Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" value="<?= $row['tanggal'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>Kategori</label>
                        <select name="kategori" class="form-select">
                            <?php foreach($kategori as $k): ?>
                                <option value="<?= $k['kode_kategori'] ?>" <?= ($k['kode_kategori'] == $row['kode_kategori_pengeluaran']) ? 'selected' : '' ?>>
                                    <?= $k['nama_kategori'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Jumlah (Rp)</label>
                        <input type="number" name="jumlah" class="form-control" value="<?= $row['jumlah'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>Keterangan</label>
                        <textarea name="keterangan" class="form-control"><?= $row['keterangan'] ?></textarea>
                    </div>
                    <button type="submit" name="update" class="btn btn-warning w-100 fw-bold text-white">UPDATE DATA</button>
                    <a href="index.php" class="btn btn-secondary w-100 mt-2">Batal</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
