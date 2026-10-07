<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit(json_encode(['status' => 'error', 'message' => 'Akses ditolak.'])); }
require_once '../../config/database.php';

// ==========================================================
// 1. PROSES SIMPAN VIA AJAX (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $kode   = $_POST['kode_pengeluaran'];
    $tgl    = $_POST['tanggal'];
    $kat    = $_POST['kode_kategori'];
    $ket    = $_POST['keterangan'];
    // Bersihkan titik ribuan dari input nominal
    $jumlah = str_replace('.', '', $_POST['jumlah']); 
    $metode = $_POST['metode_bayar']; 

    try {
        $sql = "INSERT INTO pengeluaran_non_stok (kode_pengeluaran, tanggal, keterangan, kode_kategori_pengeluaran, jumlah, metode_bayar) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$kode, $tgl, $ket, $kat, $jumlah, $metode]);
        
        echo json_encode(['status' => 'success', 'message' => 'Biaya operasional berhasil dicatat!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================================
// 2. RENDER KERANGKA MODAL (GET)
// ==========================================================

// --- LOGIKA GENERATE ID OTOMATIS ---
$stmt = $pdo->query("SELECT MAX(kode_pengeluaran) as max_kode FROM pengeluaran_non_stok WHERE kode_pengeluaran LIKE 'PN%'");
$data = $stmt->fetch();
$kode_terakhir = $data['max_kode'];

if ($kode_terakhir) {
    $no_urut = (int) substr($kode_terakhir, 2); 
    $no_urut++; 
} else {
    $no_urut = 1;
}
$no_auto = "PN" . sprintf("%08s", $no_urut);

// --- AMBIL KATEGORI PENGELUARAN ---
$kategori = $pdo->query("SELECT * FROM kategori_pengeluaran ORDER BY nama_kategori ASC")->fetchAll();
?>

<div class="modal-header bg-danger text-white border-0 px-4 py-3">
    <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-wallet me-2"></i>Catat Pengeluaran Baru</h5>
    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
</div>

<form id="formTambahPengeluaran" onsubmit="event.preventDefault(); simpanPengeluaranAjax();">
    <div class="modal-body p-4 bg-light">
        
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Kode Transaksi</label>
                <div class="input-group shadow-sm" style="border-radius:8px; overflow:hidden;">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-receipt text-danger opacity-50"></i></span>
                    <input type="text" name="kode_pengeluaran" class="form-control fw-bold border-0 bg-white" value="<?= $no_auto ?>" readonly>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold small text-muted">Tanggal Keluar</label>
                <input type="date" name="tanggal" class="form-control border-0 shadow-sm" style="border-radius:8px;" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Kategori Biaya</label>
            <select name="kode_kategori" class="form-select border-0 shadow-sm" style="border-radius:8px;" required>
                <option value="">- Pilih Jenis Biaya -</option>
                <?php foreach($kategori as $k): ?>
                    <option value="<?= $k['kode_kategori'] ?>"><?= $k['nama_kategori'] ?></option>
                <?php endforeach; ?>
            </select>
            
            <div class="alert alert-secondary border-0 shadow-sm mt-2 mb-0 p-3" style="border-radius: 8px; font-size: 0.8rem;">
                <p class="fw-bold mb-1 text-dark"><i class="fas fa-info-circle me-1"></i> Panduan Pengisian Kategori:</p>
                <ul class="mb-0 ps-3 text-muted">
                    <li><strong>Bahan Baku Cetak:</strong> Kertas, tinta, toner, pita, lem, spiral, mika laminasi, dll.</li>
                    <li><strong>Biaya Overhead Toko:</strong> Listrik, konsumsi harian (yakult/makanan), alat kebersihan, materai, kas minus.</li>
                    <li><strong>Pemeliharaan & Perbaikan:</strong> Service printer, beli sparepart, cuci head.</li>
                    <li><strong>Beban Gaji & Upah:</strong> Gaji karyawan.</li>
                    <li><strong>Software & Pemasaran:</strong> Biaya click xerox, potongan MDR QRIS/Gopay.</li>
                    <li><strong>Perlengkapan Pengemasan:</strong> Plastik packing, amplop, lakban.</li>
                    <li><strong>Transportasi & Ekspedisi:</strong> Ongkir, Gojek/Grab/Maxim, kurir paxel, bensin, parkir, tol.</li>
                    <li><strong>Belanja Aset & Inventaris:</strong> Pembelian aset toko (Mesin, Komputer, dll).</li>
                </ul>
            </div>
            </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Jumlah Pengeluaran (Rp)</label>
            <div class="input-group input-group-lg shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white text-muted border-0"><i class="fas fa-money-bill-wave text-danger"></i></span>
                <input type="text" name="jumlah" id="inputJumlahPengeluaran" class="form-control border-0 fw-bolder text-danger" placeholder="0" required autocomplete="off">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Metode Pembayaran</label>
            <select name="metode_bayar" class="form-select border-0 shadow-sm fw-bold text-dark" style="border-radius:8px;" required>
                <option value="Tunai">💵 Tunai (Kas Laci)</option>
                <option value="Non Tunai">💳 Non Tunai (Transfer / Rekening)</option>
            </select>
        </div>

        <div class="mb-2">
            <label class="form-label fw-bold small text-muted">Keterangan Spesifik</label>
            <textarea name="keterangan" class="form-control border-0 shadow-sm" style="border-radius:8px;" rows="2" placeholder="Contoh: Beli token listrik, Bensin kurir, dll..." required></textarea>
        </div>

    </div>
    
    <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
        <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
        <button type="submit" id="btnSimpanPengeluaran" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-save me-1"></i> Simpan Pengeluaran
        </button>
    </div>
</form>

<script>
    // Format Ribuan Otomatis
    $('#inputJumlahPengeluaran').on('keyup', function() {
        let val = $(this).val().replace(/[^0-9]/g, '');
        if(val !== "") {
            $(this).val(new Intl.NumberFormat('id-ID').format(val));
        } else {
            $(this).val("");
        }
    });

    // Proses Submit AJAX
    function simpanPengeluaranAjax() {
        let form = document.getElementById('formTambahPengeluaran');
        if (!form.reportValidity()) return;

        let formData = new FormData(form);
        let btn = document.getElementById('btnSimpanPengeluaran');
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
                Swal.fire('Error Database', 'Format data dari server rusak.', 'error');
                btn.innerHTML = oriText; btn.disabled = false; return;
            }

            if (data.status === 'success') {
                var myModalEl = document.getElementById('modalTambahPengeluaran');
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