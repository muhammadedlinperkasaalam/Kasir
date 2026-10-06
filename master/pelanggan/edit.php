<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES UPDATE VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $id     = $_POST['kode_pelanggan'];
    $nama   = trim($_POST['nama']);
    $raw_hp = $_POST['no_telepon'];
    $alamat = trim($_POST['alamat']);

    // Bersihkan No HP
    $clean_hp = preg_replace('/[^0-9]/', '', $raw_hp);
    if (substr($clean_hp, 0, 1) == '0') {
        $clean_hp = '62' . substr($clean_hp, 1);
    } elseif (substr($clean_hp, 0, 1) == '8') {
        $clean_hp = '62' . $clean_hp;
    }
    if(strlen($clean_hp) > 15) $clean_hp = substr($clean_hp, 0, 15);

    if (empty($nama)) {
        echo json_encode(['status' => 'error', 'message' => 'Nama pelanggan wajib diisi.']);
        exit;
    }

    try {
        $sql = "UPDATE pelanggan SET nama_pelanggan = ?, no_telepon = ?, alamat = ? WHERE kode_pelanggan = ?";
        $stmtUpdate = $pdo->prepare($sql);
        $stmtUpdate->execute([$nama, $clean_hp, $alamat, $id]);

        echo json_encode(['status' => 'success', 'message' => 'Data profil pelanggan berhasil diperbarui!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal Update: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
$id = $_GET['id'] ?? '';
if (empty($id)) { exit("<div class='p-4 text-center text-danger'>ID Pelanggan tidak valid.</div>"); }

$stmt = $pdo->prepare("SELECT * FROM pelanggan WHERE kode_pelanggan = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) { exit("<div class='p-4 text-center text-danger'>Data pelanggan tidak ditemukan.</div>"); }
?>

<div class="modal-header bg-warning border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profil Pelanggan</h5>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formEditPelanggan" onsubmit="event.preventDefault(); simpanEditPelangganAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Kode Anggota</label>
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white border-0"><i class="fas fa-id-badge text-warning opacity-75"></i></span>
                <input type="text" name="kode_pelanggan" class="form-control fw-bolder text-muted border-0 bg-white" value="<?= htmlspecialchars($data['kode_pelanggan']) ?>" readonly>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Nama Lengkap <span class="text-danger">*</span></label>
            <input type="text" name="nama" id="inputEditNamaPelanggan" class="form-control form-control-lg border-0 shadow-sm fw-bold text-dark" style="border-radius: 8px;" value="<?= htmlspecialchars($data['nama_pelanggan']) ?>" required autocomplete="off">
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">No. Telepon / WhatsApp</label>
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white border-0"><i class="fab fa-whatsapp text-success opacity-75"></i></span>
                <input type="text" name="no_telepon" id="inputEditTelpPelanggan" class="form-control border-0 fw-semibold" value="<?= htmlspecialchars($data['no_telepon']) ?>" placeholder="08xxxxxxxx">
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Alamat Lengkap</label>
            <textarea name="alamat" class="form-control border-0 shadow-sm" style="border-radius: 8px;" rows="2" placeholder="Tuliskan alamat..."><?= htmlspecialchars($data['alamat']) ?></textarea>
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanEditPelanggan" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm text-dark">
            <i class="fas fa-save me-1"></i> Update Profil
        </button>
    </div>
</form>

<script>
    // Autofocus ke input nama & letakkan kursor di paling kanan teks
    setTimeout(() => { 
        let input = document.getElementById('inputEditNamaPelanggan');
        input.focus();
        let val = input.value;
        input.value = '';
        input.value = val;
    }, 300);

    // Auto-format nomor telepon (hanya angka)
    document.getElementById('inputEditTelpPelanggan').addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // Fungsi Submit AJAX
    function simpanEditPelangganAjax() {
        let form = document.getElementById('formEditPelanggan');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanEditPelanggan');
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
                var myModalEl = document.getElementById('modalEditPelanggan');
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
