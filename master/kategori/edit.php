<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES UPDATE VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    $id = $_POST['kode_kategori'] ?? '';
    $nama = trim($_POST['nama_kategori'] ?? '');

    if (!empty($nama) && !empty($id)) {
        try {
            $stmt = $pdo->prepare("UPDATE kategori SET nama_kategori = ? WHERE kode_kategori = ?");
            $stmt->execute([$nama, $id]);

            echo json_encode(['status' => 'success', 'message' => "Data kategori berhasil diperbarui!"]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => "Gagal: " . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => "Nama kategori tidak boleh kosong."]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
$id = $_GET['id'] ?? '';
if (empty($id)) { exit("<div class='p-4 text-center text-danger'>ID Kategori tidak valid.</div>"); }

$stmt = $pdo->prepare("SELECT * FROM kategori WHERE kode_kategori = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) { exit("<div class='p-4 text-center text-danger'>Data kategori tidak ditemukan di database.</div>"); }
?>

<div class="modal-header bg-warning border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-edit me-2"></i>Edit Kategori</h5>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formEditKategori" onsubmit="event.preventDefault(); simpanEditAjax();">
    <input type="hidden" name="kode_kategori" value="<?= htmlspecialchars($data['kode_kategori']) ?>">

    <div class="modal-body p-4 bg-light">
        
        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Kode Kategori</label>
            <input type="text" class="form-control form-control-sm bg-white border-0 shadow-sm fw-bold text-muted mb-3" value="<?= htmlspecialchars($data['kode_kategori']) ?>" disabled>

            <label class="form-label fw-bold small text-muted">Ubah Nama Kategori</label>
            <input type="text" name="nama_kategori" id="inputEditNama" class="form-control form-control-lg border-0 shadow-sm fw-bold text-dark" value="<?= htmlspecialchars($data['nama_kategori']) ?>" required autocomplete="off">
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanEdit" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm text-dark">
            <i class="fas fa-save me-1"></i> Update Data
        </button>
    </div>
</form>

<script>
    // Autofocus ke input nama & taruh kursor di akhir teks saat modal terbuka
    setTimeout(() => { 
        let input = document.getElementById('inputEditNama');
        input.focus(); 
        let val = input.value;
        input.value = '';
        input.value = val;
    }, 300);

    function simpanEditAjax() {
        let form = document.getElementById('formEditKategori');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanEdit');
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
                var myModalEl = document.getElementById('modalEditKategori');
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
