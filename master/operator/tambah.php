<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES SIMPAN VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $kode  = $_POST['kode'];
    $nama  = trim($_POST['nama']);
    $telp  = trim($_POST['telp']); 
    $user  = trim($_POST['username']);
    $pass  = md5($_POST['password']); 
    $level = $_POST['level'];
    $shift = $_POST['shift']; 

    if (empty($nama) || empty($user) || empty($_POST['password'])) {
        echo json_encode(['status' => 'error', 'message' => 'Nama, Username, dan Password wajib diisi.']);
        exit;
    }

    try {
        // Cek apakah username kembar
        $cek = $pdo->prepare("SELECT COUNT(*) FROM operator WHERE username = ?");
        $cek->execute([$user]);
        if($cek->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => "Username '@$user' sudah dipakai oleh orang lain."]);
            exit;
        }

        $sql = "INSERT INTO operator (kode_user, nama_user, no_telepon, username, password, level, shift) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kode, $nama, $telp, $user, $pass, $level, $shift]);

        echo json_encode(['status' => 'success', 'message' => "Akun $nama (@$user) berhasil didaftarkan!"]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => "Gagal menyimpan: " . $e->getMessage()]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
$stmt = $pdo->query("SELECT MAX(kode_user) as max_code FROM operator WHERE kode_user LIKE 'u%'");
$max = $stmt->fetchColumn();

if ($max) {
    $urutan = (int) substr($max, 1); 
    $urutan++; 
} else {
    $urutan = 1;
}
$kode_auto = "u" . sprintf("%09s", $urutan);
?>

<div class="modal-header bg-dark text-white border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-user-plus me-2"></i>Tambah Hak Akses User</h5>
    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formTambahUser" onsubmit="event.preventDefault(); simpanUserAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <label class="form-label fw-bold small text-muted">Kode ID</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-id-badge text-muted opacity-50"></i></span>
                    <input type="text" name="kode" class="form-control fw-bolder text-muted border-0 bg-white" value="<?= $kode_auto ?>" readonly>
                </div>
            </div>
            <div class="col-md-7">
                <label class="form-label fw-bold small text-muted">Nama Lengkap Pekerja <span class="text-danger">*</span></label>
                <input type="text" name="nama" id="inputNamaUser" class="form-control border-0 shadow-sm fw-bold text-dark" style="border-radius: 8px;" placeholder="Contoh: Budi Kasir" required autocomplete="off">
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Username (Login) <span class="text-danger">*</span></label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0 fw-bold">@</span>
                    <input type="text" name="username" class="form-control border-0 fw-semibold text-primary" placeholder="tanpaspasi" required autocomplete="off">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">No. Telepon</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fab fa-whatsapp text-success opacity-75"></i></span>
                    <input type="text" name="telp" class="form-control border-0" placeholder="08xxxxxxxx" maxlength="15">
                </div>
            </div>
        </div>

        <div class="row g-3 mb-2">
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Level Akses</label>
                <select name="level" class="form-select border-0 shadow-sm" style="border-radius: 8px;">
                    <option value="Kasir">Kasir</option>
                    <option value="Admin">Admin</option>
                    <option value="Owner">Owner</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Jadwal Shift</label>
                <select name="shift" class="form-select border-0 shadow-sm" style="border-radius: 8px;">
                    <option value="Pagi">Pagi</option>
                    <option value="Siang">Siang</option>
                    <option value="Malam">Malam</option>
                    <option value="Full">Full Time</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Password Awal <span class="text-danger">*</span></label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-key text-muted opacity-50"></i></span>
                    <input type="password" name="password" class="form-control border-0" placeholder="***" required>
                </div>
            </div>
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanUser" class="btn btn-dark fw-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-save me-1"></i> Daftarkan User
        </button>
    </div>
</form>

<script>
    // Autofocus
    setTimeout(() => { document.getElementById('inputNamaUser').focus(); }, 300);

    // Mencegah spasi pada username
    document.querySelector('input[name="username"]').addEventListener('keypress', function(e) {
        if (e.which === 32) e.preventDefault();
    });

    // Fungsi Submit AJAX
    function simpanUserAjax() {
        let form = document.getElementById('formTambahUser');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanUser');
        let oriText = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memproses...';
        btn.disabled = true;

        fetch('tambah.php', { method: 'POST', body: formData })
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
                var myModalEl = document.getElementById('modalTambahUser');
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