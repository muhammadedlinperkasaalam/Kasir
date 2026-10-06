<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES UPDATE VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $id     = $_POST['kode_supplier'] ?? '';
    $nama   = trim($_POST['nama'] ?? '');
    $telp   = trim($_POST['telp'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $ket    = trim($_POST['ket'] ?? '');

    if (empty($id) || empty($nama)) {
        echo json_encode(['status' => 'error', 'message' => 'ID atau Nama Supplier tidak boleh kosong.']);
        exit;
    }

    try {
        $sql = "UPDATE supplier SET nama_supplier=?, no_telepon=?, alamat=?, keterangan=? WHERE kode_supplier=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nama, $telp, $alamat, $ket, $id]);

        echo json_encode(['status' => 'success', 'message' => 'Data supplier berhasil diperbarui!']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal Update: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
$id = $_GET['id'] ?? '';
if (empty($id)) { exit("<div class='p-4 text-center text-danger'>ID Supplier tidak valid.</div>"); }

$stmt = $pdo->prepare("SELECT * FROM supplier WHERE kode_supplier = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) { exit("<div class='p-4 text-center text-danger'>Data supplier tidak ditemukan.</div>"); }
?>

<div class="modal-header bg-warning border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-edit me-2"></i>Edit Data Supplier</h5>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formEditSupplier" onsubmit="event.preventDefault(); simpanEditSupplierAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Kode ID</label>
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white border-0"><i class="fas fa-barcode text-warning opacity-75"></i></span>
                <input type="text" name="kode_supplier" class="form-control fw-bolder text-muted border-0 bg-white" value="<?= htmlspecialchars($data['kode_supplier']) ?>" readonly>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Nama Perusahaan / Supplier <span class="text-danger">*</span></label>
            <input type="text" name="nama" id="inputEditNamaSupplier" class="form-control form-control-lg border-0 shadow-sm fw-bold text-dark" style="border-radius: 8px;" value="<?= htmlspecialchars($data['nama_supplier']) ?>" required autocomplete="off">
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">No. Telepon / WA</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fab fa-whatsapp text-success opacity-75"></i></span>
                    <input type="number" name="telp" class="form-control border-0" value="<?= htmlspecialchars($data['no_telepon']) ?>" placeholder="08xxxxxxxx">
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Alamat Lengkap</label>
            <textarea name="alamat" class="form-control border-0 shadow-sm" style="border-radius: 8px;" rows="2" placeholder="Nama Jalan, Kota..."><?= htmlspecialchars($data['alamat']) ?></textarea>
        </div>

        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Keterangan Tambahan</label>
            <textarea name="ket" class="form-control border-0 shadow-sm bg-white" style="border-radius: 8px;" rows="2" placeholder="Barang yang sering dibeli, dll..."><?= htmlspecialchars($data['keterangan']) ?></textarea>
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanEditSupplier" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm text-dark">
            <i class="fas fa-save me-1"></i> Update Data
        </button>
    </div>
</form>

<script>
    // Autofocus ke input nama & letakkan kursor di paling kanan teks
    setTimeout(() => { 
        let input = document.getElementById('inputEditNamaSupplier');
        input.focus();
        let val = input.value;
        input.value = '';
        input.value = val;
    }, 300);

    // Fungsi Submit AJAX
    function simpanEditSupplierAjax() {
        let form = document.getElementById('formEditSupplier');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanEditSupplier');
        let oriText = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memproses...';
        btn.disabled = true;

        fetch('edit.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(text => {
            let data;
            try {
                let jsonStart = text.indexOf('{'); let jsonEnd = text.lastIndexOf('}');
                data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
            } catch(e) {
                Swal.fire('Error System', 'Respon server tidak valid.', 'error');
                btn.innerHTML = oriText; btn.disabled = false; return;
            }

            if (data.status === 'success') {
                var myModalEl = document.getElementById('modalEditSupplier');
                var modal = bootstrap.Modal.getInstance(myModalEl);
                if(modal) modal.hide();

                Swal.fire({
                    icon: 'success', title: 'Berhasil!', text: data.message, 
                    timer: 1500, showConfirmButton: false
                }).then(() => {
                    window.location.reload(); 
                });
            } else {
                Swal.fire('Gagal', data.message, 'error');
                btn.innerHTML = oriText; btn.disabled = false;
            }
        })
        .catch(err => {
            Swal.fire('Koneksi Gagal', 'Tidak dapat menghubungi server.', 'error');
            btn.innerHTML = oriText; btn.disabled = false;
        });
    }
</script>
