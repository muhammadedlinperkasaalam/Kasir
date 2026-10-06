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
    $telp   = trim($_POST['telp']);
    $alamat = trim($_POST['alamat']);
    $ket    = trim($_POST['ket']);

    if (!empty($nama)) {
        try {
            $sql = "INSERT INTO supplier (kode_supplier, nama_supplier, no_telepon, alamat, keterangan) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$kode, $nama, $telp, $alamat, $ket]);

            echo json_encode(['status' => 'success', 'message' => "Supplier $nama berhasil didaftarkan!"]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => "Gagal menyimpan: " . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => "Nama supplier tidak boleh kosong."]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================
// Cari kode tertinggi yang depannya 'SP'
$stmt = $pdo->query("SELECT MAX(kode_supplier) as max_code FROM supplier WHERE kode_supplier LIKE 'SP%'");
$max = $stmt->fetchColumn();

if ($max) {
    $urutan = (int) substr($max, 2); 
    $urutan++; 
} else {
    $urutan = 1;
}

// Gabungkan 'SP' dengan angka padding 8 digit
$kode_auto = "SP" . sprintf("%08s", $urutan);
?>

<div class="modal-header bg-primary text-white border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-truck me-2"></i>Tambah Supplier Baru</h5>
    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form id="formTambahSupplier" onsubmit="event.preventDefault(); simpanSupplierAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Kode ID (Otomatis)</label>
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white border-0"><i class="fas fa-barcode text-primary opacity-50"></i></span>
                <input type="text" name="kode" class="form-control fw-bolder text-primary border-0 bg-white" value="<?= $kode_auto ?>" readonly>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Nama Perusahaan / Supplier <span class="text-danger">*</span></label>
            <input type="text" name="nama" id="inputNamaSupplier" class="form-control form-control-lg border-0 shadow-sm fw-bold text-dark" style="border-radius: 8px;" placeholder="Contoh: PT. Sumber Makmur" required autocomplete="off">
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">No. Telepon / WA</label>
                <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fab fa-whatsapp text-success opacity-75"></i></span>
                    <input type="number" name="telp" class="form-control border-0" placeholder="08xxxxxxxx">
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Alamat Lengkap</label>
            <textarea name="alamat" class="form-control border-0 shadow-sm" style="border-radius: 8px;" rows="2" placeholder="Nama Jalan, Kota..."></textarea>
        </div>

        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Keterangan Tambahan</label>
            <textarea name="ket" class="form-control border-0 shadow-sm bg-white" style="border-radius: 8px;" rows="2" placeholder="Barang yang sering dibeli, nomor rekening, dll..."></textarea>
        </div>

    </div>

    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanSupplier" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-save me-1"></i> Simpan Data
        </button>
    </div>
</form>

<script>
    // Autofocus ke input nama saat modal selesai merender
    setTimeout(() => { document.getElementById('inputNamaSupplier').focus(); }, 300);

    // Fungsi Submit AJAX
    function simpanSupplierAjax() {
        let form = document.getElementById('formTambahSupplier');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanSupplier');
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
                var myModalEl = document.getElementById('modalTambahSupplier');
                var modal = bootstrap.Modal.getInstance(myModalEl);
                if(modal) modal.hide();

                Swal.fire({
                    icon: 'success', title: 'Tersimpan!', text: data.message, 
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