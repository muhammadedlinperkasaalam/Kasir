<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES SIMPAN VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $kode   = $_POST['kode'];
    $nama   = trim($_POST['nama']);
    $raw_hp = $_POST['telp']; 
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
        $sql = "INSERT INTO pelanggan (kode_pelanggan, nama_pelanggan, alamat, no_telepon) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kode, $nama, $alamat, $clean_hp]);
        
        echo json_encode(['status' => 'success', 'message' => "Pelanggan $nama berhasil ditambahkan!"]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => "Gagal menyimpan: " . $e->getMessage()]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
$stmt = $pdo->query("SELECT MAX(kode_pelanggan) as max_code FROM pelanggan WHERE kode_pelanggan LIKE 'P%'");
$max = $stmt->fetchColumn();

if ($max) {
    $urutan = (int) substr($max, 1);
    $urutan++;
} else {
    $urutan = 1;
}
$kode_auto = "P" . sprintf("%09s", $urutan);
?>

<div class="modal-header bg-primary text-white border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-user-plus me-2"></i>Tambah Pelanggan Baru</h5>
    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formTambahPelanggan" onsubmit="event.preventDefault(); simpanPelangganAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Kode Anggota</label>
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white border-0"><i class="fas fa-id-badge text-primary opacity-50"></i></span>
                <input type="text" name="kode" class="form-control fw-bolder text-primary border-0 bg-white" value="<?= $kode_auto ?>" readonly>
            </div>
            <div class="form-text small mt-1"><i class="fas fa-info-circle me-1"></i> Format otomatis oleh sistem.</div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Nama Lengkap <span class="text-danger">*</span></label>
            <input type="text" name="nama" id="inputNamaPelanggan" class="form-control form-control-lg border-0 shadow-sm fw-bold text-dark" style="border-radius: 8px;" placeholder="Contoh: Budi Santoso" maxlength="100" required autocomplete="off">
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">No. Telepon / WhatsApp</label>
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white border-0"><i class="fab fa-whatsapp text-success opacity-75"></i></span>
                <input type="text" name="telp" id="inputTelpPelanggan" class="form-control border-0 fw-semibold" placeholder="08xxxxxxxx">
            </div>
            <div class="form-text small mt-1">Maksimal 15 digit. Boleh pakai 08 atau 62.</div>
        </div>

        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Alamat Lengkap</label>
            <textarea name="alamat" class="form-control border-0 shadow-sm" style="border-radius: 8px;" rows="2" placeholder="Tuliskan alamat pengiriman..." maxlength="100"></textarea>
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanPelanggan" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-save me-1"></i> Simpan Data
        </button>
    </div>
</form>

<script>
    // Autofocus ke input nama saat modal terbuka
    setTimeout(() => { document.getElementById('inputNamaPelanggan').focus(); }, 300);

    // Auto-format nomor telepon agar hanya angka yang tersisa (Real-time)
    document.getElementById('inputTelpPelanggan').addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });

    // Fungsi Submit AJAX
    function simpanPelangganAjax() {
        let form = document.getElementById('formTambahPelanggan');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanPelanggan');
        let oriText = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Menyimpan...';
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
                var myModalEl = document.getElementById('modalTambahPelanggan');
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