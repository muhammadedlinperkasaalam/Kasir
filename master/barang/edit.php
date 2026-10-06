<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Unauthorized'])); }
require_once '../../config/database.php';

// ---------------------------------------------------------
// 1. PENANGANAN AJAX POST (UPDATE BARANG)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gunakan Output Buffer agar tidak ada warning PHP yang bocor dan merusak JSON
    ob_start(); 
    
    $id = $_POST['kode_barang'] ?? '';
    if(empty($id)) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'ID Barang tidak valid.']);
        exit;
    }

    $nama  = trim($_POST['nama_barang'] ?? '');
    $kat   = $_POST['kode_kategori'] ?? '';
    $sat   = $_POST['satuan'] ?? '';
    
    // Pastikan angka benar-benar fallback ke 0 jika kosong
    $beli  = !empty($_POST['harga_beli']) ? $_POST['harga_beli'] : 0;
    $jual  = !empty($_POST['harga_jual']) ? $_POST['harga_jual'] : 0;
    $stok  = !empty($_POST['stok']) ? $_POST['stok'] : 0;
    $limit = !empty($_POST['stok_limit']) ? $_POST['stok_limit'] : 0;
    $aktif = $_POST['aktif'] ?? 'Y';

    try {
        $sql = "UPDATE barang SET nama_barang=?, kode_kategori=?, satuan=?, harga_beli=?, harga_jual=?, stok=?, stok_limit=?, aktif=? WHERE kode_barang=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nama, $kat, $sat, $beli, $jual, $stok, $limit, $aktif, $id]);
        
        ob_end_clean(); // Bersihkan buffer
        echo json_encode(['status' => 'success', 'message' => 'Data barang berhasil diperbarui!']);
    } catch(Exception $e) {
        ob_end_clean(); // Bersihkan buffer
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengupdate: ' . $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// 2. RENDER TAMPILAN MODAL (GET REQUEST)
// ---------------------------------------------------------
$id = $_GET['id'] ?? '';
$data_brg = $pdo->prepare("SELECT * FROM barang WHERE kode_barang = ?");
$data_brg->execute([$id]);
$data = $data_brg->fetch();

if(!$data) { die('<div class="alert alert-danger m-3">Barang tidak ditemukan di database.</div>'); }

$kategori = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC")->fetchAll();
$list_satuan = $pdo->query("SELECT * FROM satuan ORDER BY nama_satuan ASC")->fetchAll();
?>

<div class="modal-header bg-white border-bottom py-3 px-4">
    <div>
        <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-pen text-warning me-2"></i>Edit Data Barang</h6>
        <small class="text-muted fw-bold">Update informasi produk di bawah ini</small>
    </div>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-4 bg-light">
    
    <form id="formEditBarang">
        <input type="hidden" name="kode_barang" value="<?= htmlspecialchars($data['kode_barang']) ?>">
        
        <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
            <div class="small text-muted fw-bold mb-1 text-uppercase">Kode Barcode</div>
            <h4 class="fw-bolder text-primary mb-0"><i class="fas fa-barcode me-2 text-muted"></i><?= htmlspecialchars($data['kode_barang']) ?></h4>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Nama Barang</label>
            <input type="text" name="nama_barang" class="form-control form-control-lg fw-bold border-0 shadow-sm rounded-3" value="<?= htmlspecialchars($data['nama_barang']) ?>" required>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Kategori</label>
                <div class="bg-white rounded-3 shadow-sm border p-1">
                    <select name="kode_kategori" id="editKategori" class="form-select select2-edit-modal border-0" style="width:100%;">
                        <option value="">- Pilih Kategori -</option>
                        <?php foreach($kategori as $k): ?>
                            <?php $sel = ($k['kode_kategori'] == $data['kode_kategori']) ? 'selected' : ''; ?>
                            <option value="<?= $k['kode_kategori'] ?>" <?= $sel ?>><?= htmlspecialchars($k['nama_kategori']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Satuan Ukur</label>
                <div class="bg-white rounded-3 shadow-sm border p-1">
                    <select name="satuan" id="editSatuan" class="form-select select2-edit-modal border-0" style="width:100%;">
                        <option value="">- Pilih Satuan -</option>
                        <?php foreach($list_satuan as $s): ?>
                            <?php $sel = ($s['nama_satuan'] == $data['satuan']) ? 'selected' : ''; ?>
                            <option value="<?= htmlspecialchars($s['nama_satuan']) ?>" <?= $sel ?>><?= htmlspecialchars($s['nama_satuan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Harga Beli Dasar (Rp)</label>
                <input type="number" name="harga_beli" class="form-control border-0 shadow-sm rounded-3" value="<?= $data['harga_beli'] ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Harga Jual (Rp)</label>
                <input type="number" name="harga_jual" class="form-control fw-bold text-success border-0 shadow-sm rounded-3" value="<?= $data['harga_jual'] ?>" required>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Stok Fisik</label>
                <input type="number" name="stok" class="form-control text-center border-0 shadow-sm rounded-3" value="<?= $data['stok'] ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Limit Minimum</label>
                <input type="number" name="stok_limit" class="form-control text-center border-0 shadow-sm rounded-3" value="<?= $data['stok_limit'] ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Status</label>
                <select name="aktif" class="form-select border-0 shadow-sm rounded-3 fw-bold <?= $data['aktif'] == 'Y' ? 'text-primary' : 'text-danger' ?>" onchange="this.className = this.value == 'Y' ? 'form-select border-0 shadow-sm rounded-3 fw-bold text-primary' : 'form-select border-0 shadow-sm rounded-3 fw-bold text-danger'">
                    <option value="Y" <?= $data['aktif'] == 'Y' ? 'selected' : '' ?>>Aktif (Tampil)</option>
                    <option value="N" <?= $data['aktif'] == 'N' ? 'selected' : '' ?>>Non-Aktif</option>
                </select>
            </div>
        </div>
    </form>
</div>

<div class="modal-footer py-3 bg-white px-4 border-top">
    <button type="button" class="btn btn-light fw-bold text-muted px-4 rounded-3 border shadow-sm" data-bs-dismiss="modal">Batal</button>
    <button type="button" id="btnSimpanEdit" class="btn btn-warning fw-bold px-4 rounded-3 shadow-sm text-dark" onclick="window.simpanEditAjax()">
        <i class="fas fa-save me-1"></i> Simpan Perubahan
    </button>
</div>

<script>
    // Inisialisasi Ulang Select2 di dalam Modal AJAX
    setTimeout(function() {
        if(typeof jQuery !== 'undefined' && jQuery.fn.select2) {
            $('.select2-edit-modal').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#modalEditUniversal') 
            });
        }
    }, 150);

    window.simpanEditAjax = function() {
        let form = document.getElementById('formEditBarang');
        
        // VALIDASI MANUAL SELECT2
        let kategori = document.getElementById('editKategori').value;
        let satuan = document.getElementById('editSatuan').value;

        if (kategori === '') { Swal.fire('Peringatan', 'Kategori Barang belum dipilih!', 'warning'); return; }
        if (satuan === '') { Swal.fire('Peringatan', 'Satuan Ukur belum dipilih!', 'warning'); return; }
        if (!form.reportValidity()) { return; }

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanEdit');
        let oriText = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Menyimpan...';
        btn.disabled = true;

        fetch('edit.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(text => {
            let data;
            try {
                let jsonStart = text.indexOf('{');
                let jsonEnd = text.lastIndexOf('}');
                if (jsonStart !== -1 && jsonEnd !== -1) {
                    data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
                } else {
                    data = JSON.parse(text); 
                }
            } catch(e) {
                console.error("Server Response Error:", text);
                Swal.fire('Error Database', 'Format data dari server rusak.', 'error');
                btn.innerHTML = oriText;
                btn.disabled = false;
                return; 
            }

            if (data.status === 'success') {
                // 1. TUTUP MODAL SECARA INSTAN
                var myModalEl = document.getElementById('modalEditUniversal');
                var modal = bootstrap.Modal.getInstance(myModalEl);
                if(modal) modal.hide();

                // 2. MUNCULKAN NOTIF KILAT (1 DETIK) LALU REFRESH HALAMAN INDEX
                Swal.fire({
                    icon: 'success', 
                    title: 'Tersimpan!', 
                    showConfirmButton: false,
                    timer: 1000 
                }).then(() => {
                    // Paksa browser memuat ulang halaman index.php
                    window.location.href = 'index.php'; 
                });
            } else {
                Swal.fire('Gagal', data.message, 'error');
                btn.innerHTML = oriText; 
                btn.disabled = false;
            }
        })
        .catch(err => {
            Swal.fire('Koneksi Gagal', 'Tidak bisa menghubungi server.', 'error');
            btn.innerHTML = oriText; 
            btn.disabled = false;
        });
    };
</script>
