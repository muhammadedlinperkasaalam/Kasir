<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Unauthorized'])); }
require_once '../../config/database.php';

// ---------------------------------------------------------
// 1. PENANGANAN AJAX POST (TAMBAH / HAPUS GROSIR)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $kode = $_POST['kode_barang'] ?? '';
    
    if (empty($kode)) {
        echo json_encode(['status' => 'error', 'message' => 'Kode barang tidak valid.']);
        exit;
    }

    // A. TAMBAH GROSIR
    if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
        $min = $_POST['min_qty'] ?? 0;
        $hrg = $_POST['harga_grosir'] ?? 0;
        
        if ($min < 2 || $hrg <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Input tidak valid.']);
            exit;
        }
        
        // Cek duplikat qty
        $cek = $pdo->prepare("SELECT count(*) FROM barang_grosir WHERE kode_barang = ? AND min_qty = ?");
        $cek->execute([$kode, $min]);
        
        if($cek->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => "Minimal Qty $min sudah ada! Hapus dulu jika ingin diubah."]);
        } else {
            try {
                $ins = $pdo->prepare("INSERT INTO barang_grosir (kode_barang, min_qty, harga_grosir) VALUES (?,?,?)");
                $ins->execute([$kode, $min, $hrg]);
                echo json_encode(['status' => 'success', 'message' => 'Aturan grosir berhasil ditambahkan.']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan ke database.']);
            }
        }
        exit;
    }

    // B. HAPUS GROSIR
    if (isset($_POST['action']) && $_POST['action'] === 'hapus') {
        $id_del = $_POST['id_hapus'] ?? '';
        try {
            $del = $pdo->prepare("DELETE FROM barang_grosir WHERE id = ?");
            $del->execute([$id_del]);
            echo json_encode(['status' => 'success', 'message' => 'Aturan berhasil dihapus.']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus dari database.']);
        }
        exit;
    }
}

// ---------------------------------------------------------
// 2. RENDER TAMPILAN MODAL (GET REQUEST)
// ---------------------------------------------------------
$kode = $_GET['id'] ?? '';
if(empty($kode)) { die('<div class="alert alert-danger m-3">Kode barang tidak ditemukan.</div>'); }

// Ambil Info Barang
$brg = $pdo->prepare("SELECT * FROM barang WHERE kode_barang = ?");
$brg->execute([$kode]);
$d_brg = $brg->fetch();

if(!$d_brg) { die('<div class="alert alert-danger m-3">Barang tidak ada di database.</div>'); }

// Ambil Data Grosir Existing
$list = $pdo->prepare("SELECT * FROM barang_grosir WHERE kode_barang = ? ORDER BY min_qty ASC");
$list->execute([$kode]);
$rows = $list->fetchAll();
?>

<div class="modal-header bg-white border-bottom py-3 px-4">
    <div>
        <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-tags text-primary me-2"></i>Setting Harga Grosir</h6>
        <small class="text-muted fw-bold"><?= htmlspecialchars($d_brg['nama_barang']) ?></small>
    </div>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-4 bg-light">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-3 border shadow-sm">
        <div>
            <span class="badge bg-light text-secondary border rounded-pill px-2 mb-1"><i class="fas fa-barcode me-1"></i><?= $d_brg['kode_barang'] ?></span>
            <div class="small text-muted fw-bold mt-1">Satuan: <?= $d_brg['satuan'] ?></div>
        </div>
        <div class="text-end border-start ps-3">
            <small class="fw-bold d-block text-uppercase text-muted" style="font-size:0.65rem;">Harga Eceran (Normal)</small>
            <span class="fs-5 fw-bolder text-primary">Rp <?= number_format($d_brg['harga_jual'], 0, ',', '.') ?></span>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3">
            <h6 class="fw-bold text-dark mb-3" style="font-size:0.85rem;"><i class="fas fa-plus-circle text-primary me-1"></i> Tambah Aturan Baru</h6>
            <form id="formTambahGrosir" onsubmit="event.preventDefault(); simpanGrosirAjax();">
                <input type="hidden" name="action" value="tambah">
                <input type="hidden" name="kode_barang" value="<?= $kode ?>">
                
                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label fw-bold small text-muted mb-1">Minimal Beli (Qty)</label>
                        <input type="number" name="min_qty" class="form-control fw-bold border" placeholder="Cth: 12" required min="2">
                    </div>
                    <div class="col-5">
                        <label class="form-label fw-bold small text-muted mb-1">Harga Satuan</label>
                        <input type="number" name="harga_grosir" class="form-control fw-bold border text-success" placeholder="Cth: 9500" required min="1">
                    </div>
                    <div class="col-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm" style="height: 38px;">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <h6 class="fw-bold text-dark mb-2 mt-4" style="font-size: 0.9rem;">Daftar Harga Bertingkat:</h6>
    <div class="table-responsive bg-white border rounded-3 shadow-sm mb-2">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
            <thead class="table-light text-muted">
                <tr>
                    <th class="ps-3">Syarat Qty</th>
                    <th class="text-end">Harga Satuan</th>
                    <th class="text-center pe-3" width="15%">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                <tr>
                    <td class="ps-3">
                        <span class="text-muted">Min.</span> <span class="badge bg-primary rounded-pill px-2 fs-6 mx-1"><?= $r['min_qty'] ?></span> <?= $d_brg['satuan'] ?>
                    </td>
                    <td class="text-end fw-bold text-success">
                        Rp <?= number_format($r['harga_grosir'], 0, ',', '.') ?>
                    </td>
                    <td class="text-center pe-3">
                        <button type="button" class="btn btn-light border btn-sm text-danger shadow-sm py-0" 
                                onclick="hapusGrosirAjax('<?= $r['id'] ?>', '<?= $kode ?>')" title="Hapus Aturan">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(!$rows): ?>
                <tr>
                    <td colspan="3" class="text-center py-4 text-muted bg-light">
                        <i class="fas fa-tags fa-2x mb-2 opacity-25"></i><br>
                        Belum ada aturan harga grosir.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<div class="modal-footer py-2 bg-white">
    <button type="button" class="btn btn-light fw-bold text-muted px-4 rounded-3 border" data-bs-dismiss="modal">Tutup</button>
</div>

<script>
    // FUNGSI SIMPAN GROSIR VIA AJAX
    function simpanGrosirAjax() {
        let form = document.getElementById('formTambahGrosir');
        let formData = new FormData(form);

        fetch('grosir_setting.php?id=<?= $kode ?>', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                muatModalGrosir('<?= $kode ?>'); 
                Swal.fire({icon: 'success', title: 'Tersimpan', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
            } else {
                Swal.fire('Gagal', data.message, 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Terjadi kesalahan sistem', 'error'));
    }

    // FUNGSI HAPUS GROSIR VIA AJAX
    function hapusGrosirAjax(idHapus, kodeBarang) {
        if(confirm('Hapus aturan grosir ini?')) {
            let formData = new FormData();
            formData.append('action', 'hapus');
            formData.append('kode_barang', kodeBarang);
            formData.append('id_hapus', idHapus);

            fetch('grosir_setting.php?id=' + kodeBarang, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    muatModalGrosir(kodeBarang); 
                } else {
                    Swal.fire('Gagal', data.message, 'error');
                }
            });
        }
    }
</script>