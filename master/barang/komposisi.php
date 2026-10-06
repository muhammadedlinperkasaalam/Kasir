<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Unauthorized'])); }
require_once '../../config/database.php';

// ---------------------------------------------------------
// 1. PENANGANAN AJAX POST (TAMBAH / HAPUS KOMPOSISI)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // A. TAMBAH KOMPOSISI
    if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
        $id_induk   = $_POST['id_induk'] ?? '';
        $kode_bahan = $_POST['kode_keluar'] ?? '';
        $jumlah     = $_POST['jumlah'] ?? 0;

        if(empty($id_induk) || empty($kode_bahan) || $jumlah <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap atau jumlah tidak valid.']);
            exit;
        }

        // Cek duplikasi
        $cek = $pdo->prepare("SELECT COUNT(*) FROM pengeluaran_barang WHERE kode_barang = ? AND kode_keluar = ?");
        $cek->execute([$id_induk, $kode_bahan]);
        
        if ($cek->fetchColumn() == 0) {
            try {
                $sql = "INSERT INTO pengeluaran_barang (kode_barang, kode_keluar, jumlah) VALUES (?, ?, ?)";
                $pdo->prepare($sql)->execute([$id_induk, $kode_bahan, $jumlah]);
                echo json_encode(['status' => 'success', 'message' => 'Bahan berhasil ditambahkan.']);
            } catch(Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan ke database.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Bahan ini sudah ada di daftar komposisi!']);
        }
        exit;
    }

    // B. HAPUS KOMPOSISI
    if (isset($_POST['action']) && $_POST['action'] === 'hapus') {
        $id_induk    = $_POST['id_induk'] ?? '';
        $bahan_hapus = $_POST['kode_keluar'] ?? '';

        if(empty($id_induk) || empty($bahan_hapus)) {
            echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap.']);
            exit;
        }

        try {
            $sql = "DELETE FROM pengeluaran_barang WHERE kode_barang = ? AND kode_keluar = ?";
            $pdo->prepare($sql)->execute([$id_induk, $bahan_hapus]);
            echo json_encode(['status' => 'success', 'message' => 'Bahan berhasil dihapus.']);
        } catch(Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus dari database.']);
        }
        exit;
    }
}


// ---------------------------------------------------------
// 2. RENDER TAMPILAN MODAL (GET REQUEST)
// ---------------------------------------------------------
if (!isset($_GET['id'])) { die('<div class="alert alert-danger m-3">ID Barang tidak ditemukan.</div>'); }
$id_induk = $_GET['id'];

// Ambil data Barang Induk
$stmt = $pdo->prepare("SELECT * FROM barang WHERE kode_barang = ?");
$stmt->execute([$id_induk]);
$induk = $stmt->fetch();

if (!$induk) die('<div class="alert alert-danger m-3">Barang tidak ditemukan di database.</div>');

// Ambil Daftar Bahan Baku (Untuk Dropdown)
$list_bahan = $pdo->prepare("SELECT kode_barang, nama_barang, satuan, harga_beli FROM barang WHERE kode_barang != ? ORDER BY nama_barang ASC");
$list_bahan->execute([$id_induk]);
$semua_bahan = $list_bahan->fetchAll();

// Ambil List Komposisi + Harga Belinya (Untuk Tabel & Hitung Total)
$list_resep = $pdo->prepare("SELECT pb.*, b.nama_barang, b.satuan, b.harga_beli 
                             FROM pengeluaran_barang pb 
                             JOIN barang b ON pb.kode_keluar = b.kode_barang 
                             WHERE pb.kode_barang = ?");
$list_resep->execute([$id_induk]);
$resep = $list_resep->fetchAll();

// HITUNG TOTAL & DETEKSI KEYWORD MANUAL
$total_tabel = 0;
$total_bahan_murni = 0;
$biaya_listrik_aktual = 0;
$biaya_gaji_aktual = 0;
$ada_listrik = false;
$ada_gaji = false;

foreach($resep as $r) {
    $subtotal = $r['harga_beli'] * $r['jumlah'];
    $total_tabel += $subtotal;
    
    $nama_item = strtolower($r['nama_barang']);
    if (strpos($nama_item, 'listrik') !== false) {
        $biaya_listrik_aktual += $subtotal;
        $ada_listrik = true;
    } elseif (strpos($nama_item, 'gaji') !== false || strpos($nama_item, 'jasa') !== false || strpos($nama_item, 'tenaga') !== false) {
        $biaya_gaji_aktual += $subtotal;
        $ada_gaji = true;
    } else {
        $total_bahan_murni += $subtotal;
    }
}

// HITUNG ESTIMASI & SARAN HARGA
if ($ada_listrik) { $est_listrik = $biaya_listrik_aktual; $label_listrik = "Listrik (Aktual)"; } 
else { $est_listrik = $total_bahan_murni * 0.10; $label_listrik = "Estimasi Listrik (10%)"; }

if ($ada_gaji) { $est_gaji = $biaya_gaji_aktual; $label_gaji = "Gaji/Jasa (Aktual)"; } 
else { $est_gaji = $total_bahan_murni * 0.15; $label_gaji = "Estimasi Gaji (15%)"; }

$hpp_real = $total_bahan_murni + $est_listrik + $est_gaji;
$saran_harga = ceil(($hpp_real * 2) / 100) * 100;
?>

<div class="modal-header bg-white border-bottom py-3 px-4">
    <div>
        <h5 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-flask text-primary me-2"></i>Komposisi Resep</h5>
        <small class="text-muted fw-bold"><?= htmlspecialchars($induk['nama_barang']) ?></small>
    </div>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-4 bg-light">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <span class="badge bg-success fs-6 rounded-pill px-3 shadow-sm">
            Harga Jual: Rp <?= number_format($induk['harga_jual'], 0, ',', '.') ?>
        </span>
        <div class="text-end bg-white text-primary px-3 py-1 rounded-3 shadow-sm border">
            <small class="fw-bold d-block text-uppercase" style="font-size:0.65rem;">Total Biaya Tabel</small>
            <span class="fs-5 fw-bolder">Rp <?= number_format($total_tabel, 0, ',', '.') ?></span>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3">
            <form id="formTambahKomposisi" onsubmit="event.preventDefault(); simpanKomposisiAjax();">
                <input type="hidden" name="action" value="tambah">
                <input type="hidden" name="id_induk" value="<?= $id_induk ?>">
                
                <div class="row g-2 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label fw-bold small text-muted mb-1">Cari Bahan / Jasa</label>
                        <select name="kode_keluar" class="form-select select2-bahan-modal" required style="width:100%;">
                            <option value="">- Ketik Nama Item -</option>
                            <?php foreach($semua_bahan as $b): ?>
                                <option value="<?= $b['kode_barang'] ?>">
                                    <?= htmlspecialchars($b['nama_barang']) ?> (Rp <?= number_format($b['harga_beli'], 0, ',', '.') ?> / <?= $b['satuan'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small text-muted mb-1">Jumlah</label>
                        <div class="input-group input-group-sm shadow-sm">
                            <input type="number" name="jumlah" class="form-control fw-bold border-end-0" placeholder="0.5" step="any" required>
                            <span class="input-group-text bg-white text-muted"><i class="fas fa-calculator"></i></span>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm" style="height: 31px;">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <h6 class="fw-bold text-dark mb-2">Daftar Rincian Komposisi:</h6>
    <div class="table-responsive bg-white border rounded-3 shadow-sm mb-4">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
            <thead class="table-light text-muted">
                <tr>
                    <th class="ps-3">Item / Bahan Baku</th>
                    <th class="text-end">Harga Satuan</th>
                    <th class="text-center">Qty</th>
                    <th class="text-end">Subtotal Biaya</th>
                    <th class="text-center pe-3">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($resep as $r): 
                    $subtotal = $r['harga_beli'] * $r['jumlah'];    
                ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold text-dark"><?= htmlspecialchars($r['nama_barang']) ?></div>
                        <small class="text-muted"><?= $r['kode_keluar'] ?> (<?= $r['satuan'] ?>)</small>
                    </td>
                    <td class="text-end">Rp <?= number_format($r['harga_beli'], 0, ',', '.') ?></td>
                    <td class="text-center fw-bold text-dark"><?= (float)$r['jumlah'] ?></td>
                    <td class="text-end fw-bold text-primary">Rp <?= number_format($subtotal, 0, ',', '.') ?></td>
                    <td class="text-center pe-3">
                        <button type="button" class="btn btn-light border btn-sm text-danger shadow-sm py-0" 
                                onclick="hapusKomposisiAjax('<?= $r['kode_keluar'] ?>', '<?= $id_induk ?>')" title="Hapus">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if(count($resep) > 0): ?>
                <tr class="bg-light">
                    <td colspan="3" class="text-end fw-bold text-uppercase text-muted" style="font-size:0.75rem;">Total Rincian:</td>
                    <td class="text-end fw-bolder text-dark fs-6">Rp <?= number_format($total_tabel, 0, ',', '.') ?></td>
                    <td></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center py-4 text-muted">Belum ada rincian komposisi.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if(count($resep) > 0): ?>
    <div class="bg-white rounded-3 border shadow-sm p-3">
        <h6 class="fw-bolder text-dark mb-3" style="font-size:0.85rem;"><i class="fas fa-lightbulb text-warning me-2"></i>Analisa & Saran HPP</h6>
        
        <div class="row g-2">
            <div class="col-md-3">
                <div class="border rounded p-2 text-center h-100 bg-light">
                    <small class="text-muted fw-bold d-block mb-1" style="font-size:0.6rem;"><?= $label_listrik ?></small>
                    <span class="fw-bold text-secondary">Rp <?= number_format($est_listrik, 0, ',', '.') ?></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-2 text-center h-100 bg-light">
                    <small class="text-muted fw-bold d-block mb-1" style="font-size:0.6rem;"><?= $label_gaji ?></small>
                    <span class="fw-bold text-secondary">Rp <?= number_format($est_gaji, 0, ',', '.') ?></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border border-danger rounded p-2 text-center h-100 bg-danger bg-opacity-10">
                    <small class="text-danger fw-bolder d-block mb-1" style="font-size:0.6rem;">TOTAL HPP AKTUAL</small>
                    <span class="fw-bolder text-danger">Rp <?= number_format($hpp_real, 0, ',', '.') ?></span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border border-success rounded p-2 text-center h-100 bg-success text-white">
                    <small class="fw-bolder d-block mb-1 text-white-50" style="font-size:0.6rem;">SARAN HARGA JUAL</small>
                    <span class="fw-bolder">Rp <?= number_format($saran_harga, 0, ',', '.') ?></span>
                </div>
            </div>
        </div>

        <?php if($hpp_real > $induk['harga_jual']): ?>
        <div class="alert alert-danger d-flex align-items-center mt-3 mb-0 py-2 px-3" role="alert" style="font-size:0.8rem;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <div>Harga jual saat ini (<b>Rp <?= number_format($induk['harga_jual']) ?></b>) lebih rendah dari HPP (<b>Rp <?= number_format($hpp_real) ?></b>). Potensi RUGI!</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<div class="modal-footer py-2 bg-white">
    <button type="button" class="btn btn-light fw-bold text-muted px-4 rounded-3 border" data-bs-dismiss="modal">Tutup</button>
</div>

<script>
    // Inisialisasi Ulang Select2 karena DOM baru dimuat via AJAX
    $(document).ready(function() {
        $('.select2-bahan-modal').select2({
            theme: 'bootstrap-5',
            dropdownParent: $('#modalKomposisiUniversal') // Pastikan menempel pada ID modal pemanggil Anda
        });
    });

    // FUNGSI SIMPAN VIA AJAX
    function simpanKomposisiAjax() {
        let form = document.getElementById('formTambahKomposisi');
        let formData = new FormData(form);

        fetch('komposisi.php?id=<?= $id_induk ?>', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                // Refresh Modal secara mandiri
                muatModalKomposisi('<?= $id_induk ?>'); 
                Swal.fire({icon: 'success', title: 'Tersimpan', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
            } else {
                Swal.fire('Gagal', data.message, 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Terjadi kesalahan sistem', 'error'));
    }

    // FUNGSI HAPUS VIA AJAX
    function hapusKomposisiAjax(kodeBahan, idInduk) {
        if(confirm('Hapus bahan ini dari komposisi?')) {
            let formData = new FormData();
            formData.append('action', 'hapus');
            formData.append('id_induk', idInduk);
            formData.append('kode_keluar', kodeBahan);

            fetch('komposisi.php?id=' + idInduk, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    muatModalKomposisi(idInduk); 
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            });
        }
    }
</script>