<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';
require_once '../../helpers/fungsi.php'; // Panggil helper buatKode

// ==========================================================
// 1. PROSES SIMPAN VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    $nama = trim($_POST['nama_kategori']);

    if (!empty($nama)) {
        try {
            // Generate Kode Otomatis
            $kode_baru = buatKode($pdo, 'kategori', 'kode_kategori', 'K');

            // Simpan ke Database
            $stmt = $pdo->prepare("INSERT INTO kategori (kode_kategori, nama_kategori) VALUES (?, ?)");
            $stmt->execute([$kode_baru, $nama]);

            echo json_encode(['status' => 'success', 'message' => "Kategori '$nama' berhasil ditambahkan!"]);
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
?>

<div class="modal-header bg-primary text-white border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-plus-circle me-2"></i>Tambah Kategori Baru</h5>
    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formTambahKategori" onsubmit="event.preventDefault(); simpanKategoriAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="alert alert-info d-flex align-items-center py-2 px-3 mb-4 shadow-sm border-info border-opacity-25" style="border-radius: 8px;">
            <i class="fas fa-info-circle text-info fs-4 me-3"></i>
            <div class="small text-dark" style="line-height: 1.3;">
                Kode kategori akan digenerate <strong>Otomatis</strong> oleh sistem (contoh: K000000001).
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Nama Kategori</label>
            <input type="text" name="nama_kategori" id="inputNamaKategori" class="form-control form-control-lg border-0 shadow-sm fw-bold text-primary" placeholder="Contoh: Kertas Print, Stempel, dll..." required autocomplete="off">
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanKategori" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-save me-1"></i> Simpan Kategori
        </button>
    </div>
</form>

<script>
    // Autofocus ke input saat modal selesai dibuka
    setTimeout(() => { document.getElementById('inputNamaKategori').focus(); }, 300);

    function simpanKategoriAjax() {
        let form = document.getElementById('formTambahKategori');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanKategori');
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
                var myModalEl = document.getElementById('modalTambahKategori');
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