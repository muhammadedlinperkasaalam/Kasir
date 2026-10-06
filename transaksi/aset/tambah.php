<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// --- PROSES SIMPAN (TETAP SAMA SEPERTI KODE BAPAK) ---
if (isset($_POST['simpan_aset'])) {
    $tgl    = $_POST['tanggal'];
    $nama   = $_POST['nama_barang'];
    $specs  = $_POST['spesifikasi'];
    $vendor = $_POST['vendor'];
    $qty    = (int) $_POST['qty'];
    $harga  = (float) str_replace('.', '', $_POST['harga_satuan']);
    $total  = $qty * $harga;
    $metode = $_POST['metode_bayar'];
    $ket    = $_POST['keterangan'];

    try {
        $pdo->beginTransaction();

        // 1. SIMPAN KE TABEL ASET
        $sqlAset = "INSERT INTO inventaris_aset (tanggal_beli, nama_barang, spesifikasi, vendor, qty, harga_satuan, total_harga, metode_bayar, keterangan) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sqlAset)->execute([$tgl, $nama, $specs, $vendor, $qty, $harga, $total, $metode, $ket]);

        // 2. OTOMATIS CATAT KE PENGELUARAN
        $stmtKode = $pdo->query("SELECT MAX(kode_pengeluaran) as max_kode FROM pengeluaran_non_stok WHERE kode_pengeluaran LIKE 'PN%'");
        $dataKode = $stmtKode->fetch();
        $no_urut = $dataKode['max_kode'] ? (int)substr($dataKode['max_kode'], 2) + 1 : 1;
        $no_pn   = "PN" . sprintf("%08s", $no_urut);

        $sqlKeuangan = "INSERT INTO pengeluaran_non_stok (kode_pengeluaran, tanggal, keterangan, kode_kategori_pengeluaran, jumlah, metode_bayar) 
                        VALUES (?, ?, ?, 'K99', ?, ?)";
        $ket_keuangan = "Beli Aset: $nama ($qty unit)";
        $pdo->prepare($sqlKeuangan)->execute([$no_pn, $tgl, $ket_keuangan, $total, $metode]);

        $pdo->commit();
        $_SESSION['swal_success'] = "Aset berhasil dibeli & tercatat di pengeluaran!";
        header("Location: tambah.php");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['swal_error'] = "Error: " . $e->getMessage();
    }
}

// --- AMBIL DATA RIWAYAT ---
$tgl_awal  = $_GET['tgl_awal'] ?? date('Y-m-01'); // Default awal bulan
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');  // Default hari ini

$sql = "SELECT * FROM inventaris_aset WHERE tanggal_beli BETWEEN ? AND ? ORDER BY tanggal_beli DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tgl_awal, $tgl_akhir]);
$data_aset = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manajemen Aset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

    <nav class="navbar navbar-dark bg-secondary mb-4 shadow-sm">
        <div class="container">
            <span class="navbar-brand fw-bold"><i class="fas fa-desktop me-2"></i>Data Aset & Inventaris</span>
            <a href="../../index.php" class="btn btn-sm btn-light fw-bold text-secondary">Dashboard</a>
        </div>
    </nav>

    <div class="container pb-5">
        
        <div class="card shadow-sm mb-4 border-0 rounded-3">
            <div class="card-body py-3">
                <div class="row align-items-center g-2">
                    <div class="col-md-8">
                        <form method="GET" class="d-flex align-items-center gap-2">
                            <input type="date" name="tgl_awal" class="form-control form-control-sm" value="<?= $tgl_awal ?>">
                            <span>s/d</span>
                            <input type="date" name="tgl_akhir" class="form-control form-control-sm" value="<?= $tgl_akhir ?>">
                            <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-filter"></i> Filter</button>
                        </form>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <button type="button" class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambah">
                            <i class="fas fa-plus-circle me-2"></i> Beli Aset Baru
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow border-0 rounded-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Tanggal</th>
                                <th>Nama Barang</th>
                                <th>Vendor</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Total Harga</th>
                                <th>Metode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($data_aset) > 0): ?>
                                <?php foreach($data_aset as $row): ?>
                                <tr>
                                    <td class="ps-3 small"><?= date('d/m/Y', strtotime($row['tanggal_beli'])) ?></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['nama_barang']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['spesifikasi']) ?></small>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($row['vendor']) ?></td>
                                    <td class="text-center fw-bold"><?= $row['qty'] ?></td>
                                    <td class="text-end fw-bold text-primary">Rp <?= number_format($row['total_harga'], 0, ',', '.') ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= $row['metode_bayar'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted">Tidak ada data pembelian aset pada periode ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-shopping-cart me-2"></i>Input Pembelian Aset</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small">Tanggal Pembelian</label>
                                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small">Vendor / Toko</label>
                                <input type="text" name="vendor" class="form-control" placeholder="Nama Toko..." required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Nama Barang</label>
                            <input type="text" name="nama_barang" class="form-control" placeholder="Contoh: Printer Epson..." required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Spesifikasi (Opsional)</label>
                            <textarea name="spesifikasi" class="form-control" rows="2" placeholder="Warna, No Seri, Garansi..."></textarea>
                        </div>

                        <div class="row bg-light p-3 rounded border mb-3 mx-0">
                            <div class="col-md-4 mb-2">
                                <label class="fw-bold small">Qty</label>
                                <input type="number" name="qty" id="qty" class="form-control text-center" value="1" min="1" required oninput="hitungTotal()">
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="fw-bold small">Harga Satuan</label>
                                <input type="text" name="harga_satuan" id="harga" class="form-control text-end" placeholder="0" required onkeyup="formatRupiah(this); hitungTotal()">
                            </div>
                            <div class="col-md-4 mb-2">
                                <label class="fw-bold small text-muted">Total</label>
                                <input type="text" id="total_display" class="form-control bg-white fw-bold text-success text-end" readonly value="Rp 0">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Metode Pembayaran</label>
                            <select name="metode_bayar" class="form-select">
                                <option value="Tunai">Tunai (Ambil dari Laci Kasir)</option>
                                <option value="Non Tunai">Transfer / QRIS (Rekening Toko)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small">Keterangan Lain</label>
                            <input type="text" name="keterangan" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="simpan_aset" class="btn btn-primary fw-bold px-4">SIMPAN DATA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function formatRupiah(el) {
            let angka = el.value.replace(/\./g, '');
            el.value = new Intl.NumberFormat('id-ID').format(angka);
        }

        function hitungTotal() {
            let qty = document.getElementById('qty').value || 0;
            let harga = document.getElementById('harga').value.replace(/\./g, '') || 0;
            let total = qty * harga;
            document.getElementById('total_display').value = "Rp " + new Intl.NumberFormat('id-ID').format(total);
        }

        // Notifikasi
        <?php if(isset($_SESSION['swal_success'])): ?>
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: '<?= $_SESSION['swal_success'] ?>', showConfirmButton: false, timer: 2000 });
            <?php unset($_SESSION['swal_success']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['swal_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Gagal!', text: '<?= $_SESSION['swal_error'] ?>' });
            <?php unset($_SESSION['swal_error']); ?>
        <?php endif; ?>
    </script>

</body>
</html>
