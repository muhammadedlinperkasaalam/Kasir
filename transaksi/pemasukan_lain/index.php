<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// --- 1. PROSES SIMPAN ---
$script_swal = "";
if (isset($_POST['simpan'])) {
    try {
        $tgl    = $_POST['tgl'];
        $pel    = $_POST['pelanggan'] == 'UMUM' ? null : $_POST['pelanggan']; 
        $kat    = $_POST['kategori'];
        $nom    = str_replace('.', '', $_POST['nominal']); 
        $metode = $_POST['metode'];
        $ket    = $_POST['keterangan'];
        $user   = $_SESSION['user_id'];

        $sql = "INSERT INTO pemasukan_lain (tgl_pemasukan, kode_pelanggan, kategori, nominal, metode_bayar, keterangan, kode_user) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tgl, $pel, $kat, $nom, $metode, $ket, $user]);

        $script_swal = "Swal.fire({icon: 'success', title: 'Berhasil!', text: 'Pemasukan tambahan tercatat.', timer: 1500, showConfirmButton: false}).then(() => { window.location='index.php'; });";
    } catch (Exception $e) {
        $script_swal = "Swal.fire('Gagal', '" . $e->getMessage() . "', 'error');";
    }
}

// --- 2. PROSES HAPUS ---
if (isset($_GET['hapus'])) {
    $id = $_GET['hapus'];
    $pdo->prepare("DELETE FROM pemasukan_lain WHERE id = ?")->execute([$id]);
    header("Location: index.php"); exit;
}

// --- 3. AMBIL DATA PELANGGAN ---
$pelanggans = $pdo->query("SELECT kode_pelanggan, nama_pelanggan FROM pelanggan ORDER BY nama_pelanggan ASC")->fetchAll();

// --- 4. LOGIKA FILTER DATA ---
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$kat_filter = isset($_GET['kategori']) ? $_GET['kategori'] : '';

// Query Dasar
$sql = "SELECT p.*, pel.nama_pelanggan, u.nama_user 
        FROM pemasukan_lain p 
        LEFT JOIN pelanggan pel ON p.kode_pelanggan = pel.kode_pelanggan
        LEFT JOIN operator u ON p.kode_user = u.kode_user
        WHERE (p.tgl_pemasukan BETWEEN ? AND ?)";

$params = [$tgl_awal, $tgl_akhir];

// Tambahan Filter Kategori
if (!empty($kat_filter)) {
    $sql .= " AND p.kategori = ?";
    $params[] = $kat_filter;
}

$sql .= " ORDER BY p.tgl_pemasukan DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data_masuk = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pemasukan Lain | POS System</title>
    
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1a56db;
            --success-color: #10b981;
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
        .form-control:focus, .form-select:focus { border-color: var(--success-color); box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.1); }
        
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; background-color: #fff;}
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); border-bottom: none !important;}
        tfoot td { position: sticky; bottom: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 -1px 0 var(--border-color); border-top: none !important;}
        
        .table-hover tbody tr:hover { background-color: #ecfdf5 !important; }
        
        .badge-kategori { background-color: #e0f2fe; color: #047857; border: 1px solid #a7f3d0; padding: 0.4em 0.8em; font-weight: 600;}
        
        /* Modal Select2 Fix */
        .select2-container--bootstrap-5 .select2-selection { min-height: 38px; border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;}
    </style>
</head>
<body>

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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Pemasukan Non-Penjualan</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Jasa Desain, Servis, & Ongkir</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-success fw-bold shadow-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalTambah">
                    <i class="fas fa-plus me-1"></i> <span class="d-none d-sm-inline">Input Pemasukan</span>
                </button>
                
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
            
            <div class="card bg-white card-custom">
                
                <div class="card-header bg-white p-3 border-bottom flex-shrink-0">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Dari Tanggal</label>
                            <input type="date" name="tgl_awal" class="form-control bg-light" value="<?= $tgl_awal ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Sampai Tanggal</label>
                            <input type="date" name="tgl_akhir" class="form-control bg-light" value="<?= $tgl_akhir ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Kategori Pemasukan</label>
                            <select name="kategori" class="form-select bg-light">
                                <option value="">-- Semua Kategori --</option>
                                <option value="Jasa Service" <?= $kat_filter == 'Jasa Service' ? 'selected' : '' ?>>🔧 Jasa Service</option>
                                <option value="Jasa Design" <?= $kat_filter == 'Jasa Design' ? 'selected' : '' ?>>🎨 Jasa Design</option>
                                <option value="Sewa" <?= $kat_filter == 'Sewa' ? 'selected' : '' ?>>📦 Sewa / Rental</option>
                                <option value="Ongkir" <?= $kat_filter == 'Ongkir' ? 'selected' : '' ?>>🚚 Ongkir</option>
                                <option value="Lainnya" <?= $kat_filter == 'Lainnya' ? 'selected' : '' ?>>✨ Lainnya</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-dark fw-bold flex-grow-1 shadow-sm" style="border-radius: 8px;">
                                    <i class="fas fa-filter me-1"></i> Filter
                                </button>
                                <a href="index.php" class="btn btn-light border text-muted shadow-sm" title="Reset Filter" style="border-radius: 8px;">
                                    <i class="fas fa-sync-alt"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted small text-uppercase fw-bold">
                            <tr>
                                <th class="ps-4 py-3 border-0">Tanggal Masuk</th>
                                <th class="py-3 border-0">Kategori</th>
                                <th class="py-3 border-0">Dari Pelanggan</th>
                                <th class="py-3 border-0 d-none d-lg-table-cell">Keterangan Tambahan</th>
                                <th class="py-3 border-0 text-center">Via</th>
                                <th class="py-3 border-0 text-end text-success">Nominal</th>
                                <?php if($_SESSION['level'] == 'admin'): ?>
                                <th class="py-3 border-0 text-center pe-4" width="80">Hapus</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total = 0;
                            foreach($data_masuk as $row): 
                                $total += $row['nominal'];
                            ?>
                            <tr>
                                <td class="ps-4 py-3 border-bottom-0 text-dark fw-semibold" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <i class="far fa-calendar-alt text-muted opacity-50 me-2"></i><?= date('d M Y', strtotime($row['tgl_pemasukan'])) ?>
                                </td>
                                
                                <td class="py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge badge-kategori rounded-pill">
                                        <?= $row['kategori'] ?>
                                    </span>
                                </td>
                                
                                <td class="py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($row['nama_pelanggan'] ?? 'Pelanggan Umum') ?></div>
                                    <div class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25" style="font-size: 0.65rem;">
                                        <i class="fas fa-user-edit me-1"></i>Oleh: <?= $row['nama_user'] ?? 'System' ?>
                                    </div>
                                </td>
                                
                                <td class="py-3 border-bottom-0 d-none d-lg-table-cell text-muted" style="border-bottom: 1px solid var(--border-color) !important; max-width: 250px;">
                                    <span class="text-truncate d-inline-block w-100" title="<?= htmlspecialchars($row['keterangan']) ?>">
                                        <?= htmlspecialchars($row['keterangan']) ?: '-' ?>
                                    </span>
                                </td>
                                
                                <td class="py-3 border-bottom-0 text-center" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge bg-light text-dark border px-2 py-1"><?= $row['metode_bayar'] ?></span>
                                </td>
                                
                                <td class="text-end py-3 border-bottom-0 fw-bolder text-success fs-6" style="border-bottom: 1px solid var(--border-color) !important; letter-spacing: -0.5px;">
                                    Rp <?= number_format($row['nominal'], 0, ',', '.') ?>
                                </td>
                                
                                <?php if($_SESSION['level'] == 'admin'): ?>
                                <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <a href="index.php?hapus=<?= $row['id'] ?>" class="btn btn-light text-danger border shadow-sm btn-sm" style="border-radius: 8px;" onclick="return confirm('Anda yakin ingin menghapus data pemasukan ini?')" title="Hapus Data">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($data_masuk)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                            <i class="fas fa-wallet fa-4x opacity-25 mb-3 text-success"></i>
                                            <h5 class="fw-bolder text-dark">Data Kosong</h5>
                                            <p class="mb-0">Tidak ada pemasukan lain (non-penjualan) pada rentang tanggal ini.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        
                        <?php if($total > 0): ?>
                        <tfoot>
                            <tr>
                                <td colspan="<?= ($_SESSION['level'] == 'admin') ? '5' : '4' ?>" class="text-end text-uppercase text-muted fw-bold pt-4 pb-3" style="font-size: 0.8rem; letter-spacing: 0.5px;">Total Pemasukan Tambahan:</td>
                                <td class="text-end text-success fw-bolder pt-4 pb-3 pe-4" style="font-size: 1.25rem; letter-spacing: -0.5px;">
                                    Rp <?= number_format($total, 0, ',', '.') ?>
                                </td>
                                <?php if($_SESSION['level'] == 'admin'): ?><td></td><?php endif; ?>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                        
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>

<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-success text-white border-0 px-4 py-3">
                <h5 class="modal-title fw-bold mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Catat Pemasukan Baru</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST">
                <div class="modal-body p-4 bg-light">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Tanggal Masuk</label>
                            <input type="date" name="tgl" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Kategori Transaksi</label>
                            <select name="kategori" class="form-select bg-white" required>
                                <option value="Jasa Service">Jasa Service</option>
                                <option value="Jasa Design">Jasa Design</option>
                                <option value="Sewa">Sewa / Rental</option>
                                <option value="Ongkir">Ongkir Ekspedisi</option>
                                <option value="Lainnya">Lain-Lainnya</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Diterima Dari Pelanggan</label>
                        <select name="pelanggan" class="form-select select2-modal" style="width:100%" required>
                            <option value="UMUM">-- Pelanggan Umum / Anonim --</option>
                            <?php foreach($pelanggans as $p): ?>
                                <option value="<?= $p['kode_pelanggan'] ?>"><?= htmlspecialchars($p['nama_pelanggan']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Nominal Uang (Rp)</label>
                        <div class="input-group input-group-lg shadow-sm" style="border-radius: 8px; overflow:hidden;">
                            <span class="input-group-text bg-white text-muted border-0"><i class="fas fa-money-bill-wave text-success"></i></span>
                            <input type="text" name="nominal" id="inputNominal" class="form-control border-0 fw-bolder text-success" placeholder="0" required autocomplete="off">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Metode Pembayaran</label>
                            <select name="metode" class="form-select">
                                <option value="Cash">💵 Uang Tunai (Cash)</option>
                                <option value="Transfer">🏦 Transfer Bank</option>
                                <option value="QRIS">📱 E-Wallet / QRIS</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Keterangan Spesifik</label>
                            <textarea name="keterangan" class="form-control" rows="2" placeholder="Contoh: Service Ganti LCD Asus"></textarea>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm"><i class="fas fa-save me-1"></i> SIMPAN PEMASUKAN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        // Select2 di dalam Modal (Fix bug Select2 tertutup modal)
        $('.select2-modal').select2({
            dropdownParent: $('#modalTambah'),
            theme: 'bootstrap-5',
            placeholder: 'Cari Nama Pelanggan...'
        });

        // Format Ribuan Otomatis saat mengetik nominal
        $('#inputNominal').on('keyup', function(e) {
            let val = $(this).val().replace(/[^0-9]/g, ''); // Buang selain angka
            if(val !== "") {
                $(this).val(new Intl.NumberFormat('id-ID').format(val));
            } else {
                $(this).val("");
            }
        });
    });

    // Render output pesan sukses/gagal dari PHP Server
    <?= $script_swal ?>
</script>

</body>
</html>
