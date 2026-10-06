<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES UPDATE VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $id     = $_POST['kode_user'] ?? '';
    $nama   = trim($_POST['nama'] ?? '');
    $telp   = trim($_POST['telp'] ?? '');
    $user   = trim($_POST['username'] ?? '');
    $level  = $_POST['level'] ?? '';
    $shift  = $_POST['shift'] ?? '';
    $pass_baru = $_POST['password'] ?? '';

    if (empty($id) || empty($nama) || empty($user)) {
        echo json_encode(['status' => 'error', 'message' => 'Nama dan Username tidak boleh kosong.']);
        exit;
    }

    try {
        // Cek Username Kembar (kecuali punya user itu sendiri)
        $cek = $pdo->prepare("SELECT COUNT(*) FROM operator WHERE username = ? AND kode_user != ?");
        $cek->execute([$user, $id]);
        if($cek->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => "Username '@$user' sudah dipakai oleh orang lain!"]);
            exit;
        }

        if (!empty($pass_baru)) {
            // Update beserta Password (MD5)
            $hash = md5($pass_baru);
            $sql = "UPDATE operator SET nama_user=?, no_telepon=?, username=?, password=?, level=?, shift=? WHERE kode_user=?";
            $params = [$nama, $telp, $user, $hash, $level, $shift, $id];
        } else {
            // Update Tanpa Ganti Password
            $sql = "UPDATE operator SET nama_user=?, no_telepon=?, username=?, level=?, shift=? WHERE kode_user=?";
            $params = [$nama, $telp, $user, $level, $shift, $id];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'message' => "Data akun @$user berhasil diperbarui!"]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => "Gagal Update: " . $e->getMessage()]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
$id = $_GET['id'] ?? '';
if (empty($id)) { exit("<div class='p-4 text-center text-danger'>ID User tidak valid.</div>"); }

$stmt = $pdo->prepare("SELECT * FROM operator WHERE kode_user = ?");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) { exit("<div class='p-4 text-center text-danger'>Data user tidak ditemukan.</div>"); }
?>

<div class="modal-header bg-warning border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-user-edit me-2"></i>Edit Data Operator</h5>
    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formEditUser" onsubmit="event.preventDefault(); simpanEditUserAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <label class="form-label fw-bold small text-muted">Kode ID</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-id-badge text-warning opacity-75"></i></span>
                    <input type="text" name="kode_user" class="form-control fw-bolder text-muted border-0 bg-white" value="<?= htmlspecialchars($data['kode_user']) ?>" readonly>
                </div>
            </div>
            <div class="col-md-7">
                <label class="form-label fw-bold small text-muted">Nama Lengkap Pekerja <span class="text-danger">*</span></label>
                <input type="text" name="nama" id="inputEditNamaUser" class="form-control border-0 shadow-sm fw-bold text-dark" style="border-radius: 8px;" value="<?= htmlspecialchars($data['nama_user']) ?>" required autocomplete="off">
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Username (Login) <span class="text-danger">*</span></label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0 fw-bold text-muted">@</span>
                    <input type="text" name="username" class="form-control border-0 fw-bold text-primary" value="<?= htmlspecialchars($data['username']) ?>" required autocomplete="off">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">No. Telepon / WA</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fab fa-whatsapp text-success opacity-75"></i></span>
                    <input type="text" name="telp" class="form-control border-0 fw-semibold" value="<?= htmlspecialchars($data['no_telepon']) ?>" placeholder="08xxxxxxxx" maxlength="15">
                </div>
            </div>
        </div>

        <div class="row g-3 mb-2">
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Level Akses</label>
                <select name="level" class="form-select border-0 shadow-sm" style="border-radius: 8px;">
                    <option value="Kasir" <?= $data['level']=='Kasir'?'selected':'' ?>>Kasir</option>
                    <option value="Admin" <?= $data['level']=='Admin'?'selected':'' ?>>Admin</option>
                    <option value="Owner" <?= $data['level']=='Owner'?'selected':'' ?>>Owner</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Jadwal Shift</label>
                <select name="shift" class="form-select border-0 shadow-sm" style="border-radius: 8px;">
                    <option value="Pagi" <?= $data['shift']=='Pagi'?'selected':'' ?>>Pagi</option>
                    <option value="Siang" <?= $data['shift']=='Siang'?'selected':'' ?>>Siang</option>
                    <option value="Malam" <?= $data['shift']=='Malam'?'selected':'' ?>>Malam</option>
                    <option value="Full" <?= $data['shift']=='Full'?'selected':'' ?>>Full Time</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Ganti Password</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-key text-warning opacity-75"></i></span>
                    <input type="password" name="password" class="form-control border-0" placeholder="Kosongkan jika tetap">
                </div>
            </div>
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanEditUser" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm text-dark">
            <i class="fas fa-save me-1"></i> Update Data
        </button>
    </div>
</form>

<script>
    // Autofocus ke input nama & letakkan kursor di paling kanan teks
    setTimeout(() => { 
        let input = document.getElementById('inputEditNamaUser');
        input.focus();
        let val = input.value;
        input.value = '';
        input.value = val;
    }, 300);

    // Mencegah spasi pada username
    document.querySelector('input[name="username"]').addEventListener('keypress', function(e) {
        if (e.which === 32) e.preventDefault();
    });

    // Auto-format nomor telepon
    document.querySelector('input[name="telp"]').addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // Fungsi Submit AJAX
    function simpanEditUserAjax() {
        let form = document.getElementById('formEditUser');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanEditUser');
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
                var myModalEl = document.getElementById('modalEditUser');
                var modal = bootstrap.Modal.getInstance(myModalEl);
                if(modal) modal.hide();

                Swal.fire({
                    icon: 'success', title: 'Berhasil!', text: data.message, 
                    timer: 1500, showConfirmButton: false
                }).then(() => {
                    window.location.reload(); 
                });
            } else {
                Swal.fire('Gagal', data.message, 'warning');
                btn.innerHTML = oriText; btn.disabled = false;
            }
        })
        .catch(err => {
            Swal.fire('Koneksi Gagal', 'Tidak dapat menghubungi server.', 'error');
            btn.innerHTML = oriText; btn.disabled = false;
        });
    }
</script>
