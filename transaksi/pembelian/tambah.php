<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';
require_once __DIR__ . '/stock_sync_helper.php';

// --- 1. GENERATE NO PEMBELIAN OTOMATIS ---
$stmt_no = $pdo->query("SELECT MAX(no_pembelian) as max_no FROM pembelian WHERE no_pembelian LIKE 'NP%'");
$row_no  = $stmt_no->fetch();
$last_no = $row_no['max_no']; 

if ($last_no) {
    $urutan = (int) substr($last_no, 2);
    $urutan++; 
} else {
    $urutan = 1;
}
$no_auto = "NP" . str_pad($urutan, 8, "0", STR_PAD_LEFT);


// --- 2. PROSES SIMPAN ---
$alert_msg = '';
$alert_type = '';

if (isset($_POST['simpan_pembelian'])) {
    pembelian_ensure_stock_sync_table($pdo);
    $no_pembelian = $_POST['no_pembelian'];
    $tgl          = $_POST['tanggal'];
    $supplier     = $_POST['kode_supplier'];
    $ket          = $_POST['keterangan'];
    $user         = $_SESSION['user_id']; 
    
    $items        = $_POST['kode_barang'] ?? []; 
    $hargas       = $_POST['harga_beli'] ?? [];  
    $qtys         = $_POST['jumlah'] ?? [];      
    
    $total_transaksi = 0;
    $warningHargaJual = [];

    try {
        $pdo->beginTransaction();

        $cek = $pdo->prepare("SELECT COUNT(*) FROM pembelian WHERE no_pembelian = ?");
        $cek->execute([$no_pembelian]);
        if ($cek->fetchColumn() > 0) {
            throw new Exception("Nomor Pembelian $no_pembelian sudah ada.");
        }

        for ($i = 0; $i < count($items); $i++) {
            $subtotal = $hargas[$i] * $qtys[$i];
            $total_transaksi += $subtotal;
        }

        $sql = "INSERT INTO pembelian (no_pembelian, kode_supplier, kode_user, tgl_pembelian, keterangan, total) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$no_pembelian, $supplier, $user, $tgl, $ket, $total_transaksi]);

        $sqlItem = "INSERT INTO pembelian_item (no_pembelian, kode_barang, harga_beli, jumlah) VALUES (?, ?, ?, ?)";
        // Gunakan COALESCE karena stok barang lama banyak bernilai NULL.
        // Jika stok NULL lalu ditambah biasa, hasilnya tetap NULL sehingga terlihat tidak bertambah.
        // kode_supplier ikut diupdate supaya data barang mengikuti supplier restock terakhir.
        $sqlUpdateStok = "UPDATE barang SET stok = COALESCE(stok, 0) + ?, harga_beli = ?, kode_supplier = ? WHERE kode_barang = ?";
        
        for ($i = 0; $i < count($items); $i++) {
            if(!empty($items[$i])) {
                $kodeBarang = trim((string)$items[$i]);
                $hargaBeli  = pembelian_to_number($hargas[$i] ?? 0);
                $qtyMasuk   = pembelian_to_number($qtys[$i] ?? 0);

                if ($qtyMasuk <= 0) {
                    throw new Exception('Qty restock barang tidak boleh kosong / nol.');
                }

                $stmtBarangInfo = $pdo->prepare("SELECT kode_barang, nama_barang, harga_jual FROM barang WHERE kode_barang = ? LIMIT 1");
                $stmtBarangInfo->execute([$kodeBarang]);
                $barangInfo = $stmtBarangInfo->fetch(PDO::FETCH_ASSOC);
                if (!$barangInfo) {
                    throw new Exception("Barang $kodeBarang tidak ditemukan, stok tidak bisa ditambah.");
                }

                $hargaJualSaatIni = pembelian_to_number($barangInfo['harga_jual'] ?? 0);
                if ($hargaJualSaatIni > 0 && $hargaBeli > $hargaJualSaatIni) {
                    $warningHargaJual[] = sprintf(
                        '%s: harga beli Rp %s lebih mahal dari harga jual Rp %s',
                        $barangInfo['nama_barang'] ?: $kodeBarang,
                        number_format($hargaBeli, 0, ',', '.'),
                        number_format($hargaJualSaatIni, 0, ',', '.')
                    );
                }

                $pdo->prepare($sqlItem)->execute([$no_pembelian, $kodeBarang, $hargaBeli, $qtyMasuk]);

                $updStok = $pdo->prepare($sqlUpdateStok);
                $updStok->execute([$qtyMasuk, $hargaBeli, $supplier, $kodeBarang]);

                // rowCount bisa 0 jika harga/stok kebetulan sama, tapi barang sudah divalidasi di atas.
            }
        }

        // Catat bahwa nota ini sudah menambah stok, supaya tidak disinkronkan dobel dari riwayat.
        pembelian_mark_stock_synced($pdo, $no_pembelian, $user, 'auto saat simpan pembelian');

        $pdo->commit();
        $alert_type = 'success';
        $alert_msg  = 'Data restock barang berhasil disimpan. Stok bertambah dan harga beli barang sudah diperbarui.';
        if (!empty($warningHargaJual)) {
            $alert_msg .= "

PERLU UPDATE HARGA JUAL:
- " . implode("
- ", $warningHargaJual);
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $alert_type = 'error';
        $alert_msg  = 'Gagal menyimpan: ' . $e->getMessage();
    }
}

$suppliers = $pdo->query("SELECT * FROM supplier ORDER BY nama_supplier ASC")->fetchAll();
$barangs   = $pdo->query("SELECT * FROM barang ORDER BY nama_barang ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Input Pembelian Stok | POS System</title>
    
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1a56db;
            --bg-color: #f8f9fa;
            --border-color: #e5e7eb;
            --text-dark: #111827;
            --text-muted: #6b7280;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-color);
            overflow: hidden; 
        }
        
        /* Layout Grid System Murni */
        .wrapper { height: 100vh; width: 100vw; display: flex; }
        .main-content { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: var(--bg-color); flex-grow: 1; }
        .header-top { height: 70px; background-color: #fff; border-bottom: 1px solid var(--border-color); flex-shrink: 0; z-index: 1020; display: flex; align-items: center; justify-content: space-between; }
        
        /* Card & Table Styling */
        .card-custom { border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; display: flex; flex-direction: column; height: 100%;}
        
        .form-control, .form-select { border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;}
        .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.25rem rgba(26, 86, 219, 0.1); }
        .input-group-text { background-color: #f3f4f6; border-color: var(--border-color); color: var(--text-muted); font-size: 0.85rem;}
        
        .select2-container--bootstrap-5 .select2-selection { min-height: 38px; border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;}
        
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; background-color: #fff;}
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); border-bottom: none !important;}
        tfoot td { position: sticky; bottom: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 -1px 0 var(--border-color); border-top: none !important;}
        
        .item-row td { vertical-align: middle; padding: 12px 8px; border-bottom: 1px solid var(--border-color); }
        .btn-hapus { border-radius: 8px; height: 38px; width: 38px; display: flex; align-items: center; justify-content: center; margin: 0 auto;}
    </style>
</head>
<body>

<?php if(!empty($alert_msg)): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        Swal.fire({
            icon: '<?= $alert_type ?>',
            title: '<?= ($alert_type == "success") ? "Berhasil!" : "Gagal!" ?>',
            html: <?= json_encode(nl2br(htmlspecialchars($alert_msg, ENT_QUOTES, 'UTF-8'))) ?>,
            confirmButtonColor: '#1a56db'
        }).then((result) => {
            if('<?= $alert_type ?>' == 'success') { window.location.href = 'tambah.php'; }
        });
    });
</script>
<?php endif; ?>

<div class="wrapper">
    <?php 
        $base_dir = '../../'; 
        include '../../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="header-top px-3 px-md-4 shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Restock Barang</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Input Pembelian dari Supplier</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="riwayat.php" class="btn btn-light border text-primary fw-bold shadow-sm d-none d-sm-block rounded-pill px-3">
                    <i class="fas fa-history me-1"></i> Riwayat Pembelian
                </a>
                <a href="riwayat.php" class="btn btn-light border text-primary fw-bold shadow-sm d-sm-none" style="border-radius:8px;">
                    <i class="fas fa-history"></i>
                </a>
                
                <div class="d-none d-lg-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="d-flex flex-column ms-2 me-3 justify-content-center">
                        <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid p-3 p-md-4 d-flex flex-column flex-grow-1 overflow-hidden">
            
            <form method="POST" id="formPembelian" class="d-flex flex-column h-100">
                <div class="card bg-white card-custom">
                    
                    <div class="card-header bg-white p-4 border-bottom flex-shrink-0">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <h6 class="mb-0 fw-bolder text-primary"><i class="fas fa-truck me-2"></i>Input Pembelian Baru</h6>
                            <a href="riwayat.php" class="btn btn-outline-primary btn-sm fw-bold rounded-pill px-3 shadow-sm">
                                <i class="fas fa-history me-1"></i> Riwayat Pembelian
                            </a>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem; letter-spacing:0.5px;">Nomor Transaksi</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-receipt text-primary opacity-75"></i></span>
                                    <input type="text" name="no_pembelian" class="form-control fw-bold text-dark border-start-0 ps-0 bg-light" value="<?= $no_auto ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem; letter-spacing:0.5px;">Tanggal Masuk</label>
                                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem; letter-spacing:0.5px;">Pilih Supplier</label>
                                <select name="kode_supplier" class="form-select select2-supplier" required>
                                    <option value="">- Cari Supplier -</option>
                                    <?php foreach($suppliers as $s): ?>
                                        <option value="<?= $s['kode_supplier'] ?>"><?= $s['nama_supplier'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem; letter-spacing:0.5px;">Keterangan Tambahan</label>
                                <input type="text" name="keterangan" class="form-control" placeholder="Contoh: Nota 0991 / Via JNE">
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-0 table-responsive-custom">
                        <table class="table table-borderless align-middle mb-0" id="tabelItem">
                            <thead class="text-muted small text-uppercase fw-bold text-center">
                                <tr>
                                    <th width="35%" class="ps-4 text-start">Nama Barang</th>
                                    <th width="20%">Harga Beli (Satuan)</th>
                                    <th width="15%">Qty</th>
                                    <th width="22%">Subtotal Harga</th>
                                    <th width="8%" class="pe-4">Hapus</th>
                                </tr>
                            </thead>
                            
                            <tbody id="containerItem" class="border-top">
                                <tr class="item-row">
                                    <td class="ps-4">
                                        <select name="kode_barang[]" class="form-select select2-barang" required>
                                            <option value="">- Cari Barang -</option>
                                            <?php foreach($barangs as $b): ?>
                                                <option value="<?= $b['kode_barang'] ?>" data-harga="<?= $b['harga_beli'] ?>" data-harga-jual="<?= $b['harga_jual'] ?? 0 ?>">
                                                    <?= htmlspecialchars($b['nama_barang']) ?> (Stok: <?= $b['stok'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                            <span class="input-group-text border-0">Rp</span>
                                            <input type="number" name="harga_beli[]" class="form-control harga text-end fw-semibold border-0" placeholder="0" required>
                                        </div>
                                        <div class="harga-warning small text-danger fw-semibold mt-1 d-none"></div>
                                    </td>
                                    <td>
                                        <input type="number" name="jumlah[]" class="form-control qty text-center fw-bold shadow-sm" placeholder="1" min="1" required>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-text border-0 bg-transparent text-dark fw-bold">Rp</span>
                                            <input type="text" class="form-control subtotal bg-transparent border-0 text-end fw-bolder fs-6" value="0" readonly>
                                        </div>
                                    </td>
                                    <td class="text-center pe-4">
                                        <button type="button" class="btn btn-light text-danger border shadow-sm btn-hapus" title="Hapus Baris"><i class="fas fa-trash-alt"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                            
                            <tbody>
                                <tr>
                                    <td colspan="5" class="ps-4 py-3 border-bottom-0">
                                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold rounded-pill px-3 shadow-sm" id="btnTambahRow">
                                            <i class="fas fa-plus-circle me-1"></i> Tambah Baris Barang
                                        </button>
                                    </td>
                                </tr>
                            </tbody>

                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end fw-bold text-muted text-uppercase pt-4" style="font-size:0.8rem; letter-spacing:0.5px;">Grand Total Pembelian:</td>
                                    <td class="pt-3">
                                        <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                            <span class="input-group-text bg-white text-dark fw-bolder border-0">Rp</span>
                                            <input type="text" id="totalSemua" class="form-control fw-bolder text-end text-danger fs-5 border-0 bg-white" value="0" readonly>
                                        </div>
                                    </td>
                                    <td class="pe-4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="card-footer bg-white border-top p-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center flex-shrink-0 gap-3">
                        <div class="text-muted small" style="max-width: 450px; line-height:1.4;">
                            <i class="fas fa-info-circle text-primary me-1"></i> 
                            Harga beli yang Anda input akan <strong>memperbarui harga modal</strong>. Jika harga beli lebih mahal dari harga jual, sistem akan memberi info <strong>Perlu Update Harga</strong>.
                        </div>
                        <button type="submit" name="simpan_pembelian" id="btnSimpan" class="btn btn-primary fw-bold px-4 py-2 shadow-sm rounded-pill w-100 w-sm-auto">
                            <i class="fas fa-check-circle me-1"></i> Simpan & Perbarui Stok
                        </button>
                    </div>
                    
                </div>
            </form>
            
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    $(document).ready(function() {
        
        // Inisialisasi Select2
        function initSelect2(element) {
            element.select2({ theme: 'bootstrap-5', width: '100%' });
        }
        
        initSelect2($('.select2-supplier'));
        initSelect2($('.select2-barang'));

        // Tarik otomatis Harga Beli saat barang dipilih
        $(document).on('select2:select', '.select2-barang', function(e) {
            let hargaAwal = $(this).find(':selected').data('harga');
            let row = $(this).closest('tr');
            
            // Jika form harga masih kosong, isi otomatis dengan harga master
            if(row.find('.harga').val() == "" || row.find('.harga').val() == "0") {
                row.find('.harga').val(hargaAwal);
            }
            // Trigger input untuk menghitung subtotal dan default qty ke 1
            row.find('.qty').val(1);
            row.find('.harga').trigger('input');
        });

        function cekHargaJual(row) {
            let hargaBeli = parseFloat(row.find('.harga').val()) || 0;
            let hargaJual = parseFloat(row.find('.select2-barang option:selected').data('harga-jual')) || 0;
            let warningBox = row.find('.harga-warning');
            if (hargaJual > 0 && hargaBeli > hargaJual) {
                warningBox
                    .removeClass('d-none')
                    .html('<i class="fas fa-exclamation-triangle me-1"></i>Harga beli lebih mahal dari harga jual saat ini: Rp ' + new Intl.NumberFormat('id-ID').format(hargaJual) + '. Perlu update harga jual.');
            } else {
                warningBox.addClass('d-none').empty();
            }
        }

        // Kalkulasi Subtotal Per Baris
        $(document).on('input', '.harga, .qty', function() {
            let row = $(this).closest('tr');
            let harga = parseFloat(row.find('.harga').val()) || 0;
            let qty = parseFloat(row.find('.qty').val()) || 0;
            let sub = harga * qty;
            
            row.find('.subtotal').val(new Intl.NumberFormat('id-ID').format(sub));
            cekHargaJual(row);
            hitungTotal();
        });

        // Kalkulasi Grand Total
        function hitungTotal() {
            let total = 0;
            $('.item-row').each(function() {
                let harga = parseFloat($(this).find('.harga').val()) || 0;
                let qty = parseFloat($(this).find('.qty').val()) || 0;
                total += (harga * qty);
            });
            $('#totalSemua').val(new Intl.NumberFormat('id-ID').format(total));
        }

        // Tambah Baris Baru
        $('#btnTambahRow').click(function() {
            let html = `
            <tr class="item-row border-top">
                <td class="ps-4">
                    <select name="kode_barang[]" class="form-select select2-barang-new" required>
                        <option value="">- Cari Barang -</option>
                        <?php foreach($barangs as $b): ?>
                            <option value="<?= $b['kode_barang'] ?>" data-harga="<?= $b['harga_beli'] ?>" data-harga-jual="<?= $b['harga_jual'] ?? 0 ?>">
                                <?= str_replace("'", "\'", htmlspecialchars($b['nama_barang'])) ?> (Stok: <?= $b['stok'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <div class="input-group shadow-sm" style="border-radius: 8px; overflow:hidden;">
                        <span class="input-group-text border-0">Rp</span>
                        <input type="number" name="harga_beli[]" class="form-control harga text-end fw-semibold border-0" placeholder="0" required>
                    </div>
                    <div class="harga-warning small text-danger fw-semibold mt-1 d-none"></div>
                </td>
                <td>
                    <input type="number" name="jumlah[]" class="form-control qty text-center fw-bold shadow-sm" placeholder="1" min="1" required>
                </td>
                <td>
                    <div class="input-group">
                        <span class="input-group-text border-0 bg-transparent text-dark fw-bold">Rp</span>
                        <input type="text" class="form-control subtotal bg-transparent border-0 text-end fw-bolder fs-6" value="0" readonly>
                    </div>
                </td>
                <td class="text-center pe-4">
                    <button type="button" class="btn btn-light text-danger border shadow-sm btn-hapus" title="Hapus Baris"><i class="fas fa-trash-alt"></i></button>
                </td>
            </tr>`;
            
            $('#containerItem').append(html);
            
            let newSelect = $('.select2-barang-new');
            initSelect2(newSelect);
            newSelect.removeClass('select2-barang-new').addClass('select2-barang');
        });

        // Hapus Baris
        $(document).on('click', '.btn-hapus', function() {
            if($('.item-row').length > 1) { 
                $(this).closest('tr').remove(); 
                hitungTotal(); 
            } else { 
                Swal.fire({icon: 'warning', title: 'Oops...', text: 'Tidak bisa dihapus, minimal harus ada 1 barang untuk dibeli.', confirmButtonColor: '#1a56db'}); 
            }
        });

        // Konfirmasi sebelum Submit (Cegah salah pencet)
        $('#formPembelian').on('submit', function(e) {
            e.preventDefault(); // Tahan pengiriman aslinya
            
            let hasItem = false;
            $('.select2-barang').each(function() {
                if ($(this).val() !== "") hasItem = true;
            });

            if (!hasItem) {
                Swal.fire('Form Kosong', 'Anda harus memilih minimal 1 barang untuk disimpan.', 'error');
                return false;
            }

            let warningCount = $('.harga-warning:not(.d-none)').length;
            Swal.fire({
                title: 'Simpan Pembelian?',
                html: "Pastikan jumlah dan harga modal barang sudah benar. Stok akan otomatis bertambah ke gudang." + (warningCount > 0 ? "<br><br><b class='text-danger'>Ada " + warningCount + " barang dengan harga beli lebih mahal dari harga jual. Setelah simpan, update harga jual jika diperlukan.</b>" : ""),
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1a56db',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-check me-1"></i> Ya, Simpan!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#btnSimpan').html('<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...').prop('disabled', true);
                    
                    // Lanjutkan pengiriman form (bypass jQuery submit handler)
                    this.submit();
                }
            });
        });
    });
</script>
</body>
</html>