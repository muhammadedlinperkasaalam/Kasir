<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// --- 1. PROSES SIMPAN (POST) ---
$berhasil_tambah = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_barang'])) {
    $kode  = $_POST['kode_barang'];
    $nama  = $_POST['nama_barang'];
    $kat   = $_POST['kode_kategori'];
    $sat   = $_POST['satuan'];
    $beli  = str_replace('.', '', $_POST['harga_beli']);
    $jual  = str_replace('.', '', $_POST['harga_jual']);
    $stok  = $_POST['stok'];
    $limit = $_POST['stok_limit'];
    $aktif = $_POST['aktif'];

    try {
        $sql = "INSERT INTO barang (kode_barang, nama_barang, kode_kategori, satuan, harga_beli, harga_jual, stok, stok_limit, aktif) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kode, $nama, $kat, $sat, $beli, $jual, $stok, $limit, $aktif]);
        $berhasil_tambah = true;
    } catch (Exception $e) {
        echo "<script>alert('Gagal Simpan: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// --- 2. PROSES HAPUS CEPAT ---
if (isset($_GET['hapus'])) {
    $id = $_GET['hapus'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM pengeluaran_barang WHERE kode_barang = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM barang WHERE kode_barang = ?")->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); }
    header("Location: index.php"); exit;
}

// --- DATA UNTUK MODAL TAMBAH ---
$kategori = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC")->fetchAll();
$list_satuan = $pdo->query("SELECT * FROM satuan ORDER BY nama_satuan ASC")->fetchAll();

$q_kode = $pdo->query("SELECT MAX(kode_barang) as kode_terbesar FROM barang");
$d_kode = $q_kode->fetch();
$kode_otomatis = "B000000001"; 
if ($d_kode['kode_terbesar']) {
    $urutan = (int) substr($d_kode['kode_terbesar'], 1);
    $urutan++;
    $kode_otomatis = "B" . sprintf("%09s", $urutan);
}

// --- 3. SETUP PAGINATION ---
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit   = isset($_GET['limit']) ? (int)$_GET['limit'] : 10; 
$page    = isset($_GET['page']) ? (int)$_GET['page'] : 1; 
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$searchClause = "";
$params = [];
if (!empty($keyword)) {
    $searchClause = "AND (b.nama_barang LIKE :k1 OR b.kode_barang LIKE :k2)";
    $params[':k1'] = "%$keyword%";
    $params[':k2'] = "%$keyword%";
}

// --- 4. AMBIL DATA UTAMA ---
$sqlData = "SELECT b.*, k.nama_kategori, s.nama_supplier
            FROM barang b
            LEFT JOIN kategori k ON b.kode_kategori = k.kode_kategori
            LEFT JOIN supplier s ON b.kode_supplier = s.kode_supplier
            WHERE 1=1 $searchClause
            ORDER BY b.nama_barang ASC
            LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sqlData);
foreach($params as $key => $val) { $stmt->bindValue($key, $val); }
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$barang = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 5. HITUNG HPP RESEP ---
$ids_barang = array_column($barang, 'kode_barang');
$data_hpp_resep = [];
if (!empty($ids_barang)) {
    $in  = str_repeat('?,', count($ids_barang) - 1) . '?';
    $sqlHPP = "SELECT pb.kode_barang, SUM(pb.jumlah * bb.harga_beli) as total_hpp
               FROM pengeluaran_barang pb
               JOIN barang bb ON pb.kode_keluar = bb.kode_barang
               WHERE pb.kode_barang IN ($in)
               GROUP BY pb.kode_barang";
    $stmtHPP = $pdo->prepare($sqlHPP);
    $stmtHPP->execute($ids_barang);
    while ($row = $stmtHPP->fetch(PDO::FETCH_ASSOC)) {
        $data_hpp_resep[$row['kode_barang']] = $row['total_hpp'];
    }
}

$sqlCount = "SELECT COUNT(*) FROM barang b WHERE 1=1 $searchClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$total_data = $stmtCount->fetchColumn();
$total_pages = ceil($total_data / $limit);
$no_urut = $offset + 1;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master Barang | POS System</title>
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #1a56db; --bg-color: #f8f9fa; --border-color: #e5e7eb; --text-dark: #111827; --text-muted: #6b7280; }
        body { background-color: var(--bg-color); font-family: 'Inter', sans-serif; overflow-x: hidden; }
        .wrapper { display: flex; width: 100vw; height: 100vh; overflow: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; height: 100vh; overflow-y: auto; background: var(--bg-color);}
        .header-top { height: 70px; background-color: #fff; padding: 0 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; z-index: 1020; position: sticky; top: 0;}
        .card-custom { border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); background: #fff;}
        .table-custom th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; color: var(--text-muted); background: #f9fafb; border-bottom: 2px solid var(--border-color) !important;}
        .table-custom td { font-size: 0.85rem; vertical-align: middle; color: var(--text-dark); border-bottom: 1px dashed var(--border-color);}
        .badge-stok { font-size: 0.75rem; padding: 0.35em 0.65em; }
        .col-aksi { white-space: nowrap; width: 1%; }
        .action-btn { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem; transition: all 0.2s ease; }
        .action-btn:hover { transform: translateY(-2px); filter: brightness(0.95); }
        .select2-container .select2-selection--single { height: 38px; border: 1px solid #dee2e6; padding-top: 5px; }
        @media (max-width: 768px) { .wrapper { flex-direction: column; height: auto; overflow: visible;} .bg-white.border-end { display: none !important; } .main-content { height: auto; overflow: visible;} }
    </style>
</head>
<body>

<div class="wrapper">
    <?php $base_dir = '../../'; include '../../sidebar.php'; ?>

    <div class="main-content">
        <header class="header-top shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;"><i class="fas fa-bars"></i></button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Master Barang</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Addinta Printing</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="d-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="d-none d-sm-flex flex-column ms-2 me-3 justify-content-center">
                        <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid p-4">
            <div class="card card-custom mb-5">
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3">
                        <button type="button" class="btn btn-primary fw-bold text-nowrap rounded-3 shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#modalTambahBaru">
                            <i class="fas fa-plus me-1"></i> Tambah Barang Baru
                        </button>
                        
                        <form method="GET" class="d-flex gap-2 w-100 justify-content-md-end">
                            <select name="limit" class="form-select form-select-sm text-muted rounded-3" style="width: 80px;" onchange="this.form.submit()">
                                <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                            <div class="input-group input-group-sm rounded-3 shadow-sm" style="max-width: 300px;">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" name="q" class="form-control border-start-0 border-end-0 py-2" placeholder="Cari nama / kode..." value="<?= htmlspecialchars($keyword) ?>" style="box-shadow:none;">
                                <button class="btn btn-primary px-3 fw-bold border-start-0" type="submit">Cari</button>
                            </div>
                        </form>
                    </div>

                    <div class="table-responsive border rounded-3">
                        <table class="table table-custom table-hover align-middle mb-0">
                            <thead class="text-center">
                                <tr>
                                    <th width="5%" class="ps-3">No</th>
                                    <th class="text-start">Info Barang</th>
                                    <th>Kategori</th>
                                    <th width="15%" class="text-end">Total Modal (HPP)</th>
                                    <th width="12%" class="text-end">Harga Jual</th>
                                    <th width="10%" class="text-end">Laba</th>
                                    <th width="8%">Stok</th>
                                    <th class="pe-3 col-aksi">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($barang as $row): 
                                    $kode = $row['kode_barang'];
                                    $modal_resep = isset($data_hpp_resep[$kode]) ? $data_hpp_resep[$kode] : 0;
                                    $modal_beli  = $row['harga_beli'];
                                    $total_modal = $modal_resep + $modal_beli;
                                    $jual  = $row['harga_jual'];
                                    $laba  = $jual - $total_modal;
                                    $warna_laba = $laba > 0 ? 'text-success' : 'text-danger fw-bold';
                                    $bg_stok = ($row['stok'] <= $row['stok_limit']) ? 'bg-danger' : 'bg-success';
                                ?>
                                <tr>
                                    <td class="text-center text-muted ps-3"><?= $no_urut++ ?></td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($row['nama_barang']) ?></div>
                                        <small class="text-muted"><i class="fas fa-barcode text-secondary me-1"></i><?= $kode ?> <span class="mx-1">•</span> <?= $row['satuan'] ?></small>
                                    </td>
                                    <td class="text-center"><span class="badge bg-light text-secondary border rounded-pill px-2"><?= htmlspecialchars($row['nama_kategori'] ?? '-') ?></span></td>
                                    <td class="text-end"><div class="fw-bold text-dark">Rp <?= number_format($total_modal, 0, ',', '.') ?></div></td>
                                    <td class="text-end fw-bold text-dark">Rp <?= number_format($jual, 0, ',', '.') ?></td>
                                    <td class="text-end <?= $warna_laba ?> fw-bold">Rp <?= number_format($laba, 0, ',', '.') ?></td>
                                    <td class="text-center"><span class="badge badge-stok rounded-pill <?= $bg_stok ?>"><?= $row['stok'] ?></span></td>
                                    <td class="text-center pe-3 col-aksi">
                                        <div class="d-flex justify-content-center gap-2">
                                            <button class="btn action-btn text-info bg-info bg-opacity-10 border-0 rounded-3" onclick="muatModalKomposisi('<?= $kode ?>')"><i class="fas fa-flask"></i></button>
                                            <button class="btn action-btn text-primary bg-primary bg-opacity-10 border-0 rounded-3" onclick="muatModalGrosir('<?= $kode ?>')"><i class="fas fa-tags"></i></button>
                                            <button class="btn action-btn text-dark bg-warning bg-opacity-25 border-0 rounded-3" onclick="muatModalEdit('<?= $kode ?>')"><i class="fas fa-pen"></i></button>
                                            <?php if($_SESSION['level'] == 'admin' || $_SESSION['level'] == 'Owner'): ?>
                                            <a href="index.php?hapus=<?= $kode ?>" class="btn action-btn text-danger bg-danger bg-opacity-10 border-0 rounded-3" onclick="return confirm('Hapus?')"><i class="fas fa-trash-alt"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTambahBaru" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Tambah Barang Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Kode Barang (Otomatis)</label>
                            <input type="text" name="kode_barang" class="form-control bg-light fw-bold" value="<?= $kode_otomatis ?>" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Nama Barang</label>
                            <input type="text" name="nama_barang" class="form-control" placeholder="Input nama barang..." required autofocus>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Kategori</label>
                            <select name="kode_kategori" class="form-select select2-modal" required>
                                <option value="">- Pilih Kategori -</option>
                                <?php foreach($kategori as $k): ?>
                                    <option value="<?= $k['kode_kategori'] ?>"><?= $k['nama_kategori'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Satuan</label>
                            <select name="satuan" class="form-select select2-modal" required>
                                <option value="">- Pilih Satuan -</option>
                                <?php foreach($list_satuan as $s): ?>
                                    <option value="<?= $s['nama_satuan'] ?>"><?= $s['nama_satuan'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Harga Beli</label>
                            <input type="number" name="harga_beli" class="form-control" value="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold small">Harga Jual</label>
                            <input type="number" name="harga_jual" class="form-control" value="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">Stok Awal</label>
                            <input type="number" name="stok" class="form-control" value="0" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small text-danger">Limit Alert</label>
                            <input type="number" name="stok_limit" class="form-control" value="5" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold small">Status</label>
                            <select name="aktif" class="form-select bg-light">
                                <option value="Y" selected>AKTIF</option>
                                <option value="N">TIDAK AKTIF</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3">
                    <button type="button" class="btn btn-secondary fw-bold px-4 rounded-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan_barang" class="btn btn-primary fw-bold px-4 rounded-3 shadow-sm">Simpan Barang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="modal fade" id="modalKomposisiUniversal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalKomposisi"></div></div></div>
<div class="modal fade" id="modalGrosirUniversal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalGrosir"></div></div></div>
<div class="modal fade" id="modalEditUniversal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalEdit"></div></div></div>

<script>
$(document).ready(function() {
    // Jalankan Select2 di dalam modal saat modal muncul
    $('#modalTambahBaru').on('shown.bs.modal', function () {
        $('.select2-modal').select2({ dropdownParent: $('#modalTambahBaru'), width: '100%' });
    });

    <?php if($berhasil_tambah): ?>
        Swal.fire({ icon: 'success', title: 'Berhasil', text: 'Barang baru ditambahkan!', timer: 1500, showConfirmButton: false }).then(() => { window.location = 'index.php'; });
    <?php endif; ?>
});

function muatModalKomposisi(idBarang) {
    document.getElementById('kontenModalKomposisi').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted fw-bold">Memuat...</p></div>';
    new bootstrap.Modal(document.getElementById('modalKomposisiUniversal')).show();
    fetch('komposisi.php?id=' + idBarang).then(res => res.text()).then(html => {
        document.getElementById('kontenModalKomposisi').innerHTML = html;
        const scripts = document.getElementById('kontenModalKomposisi').querySelectorAll('script');
        scripts.forEach(s => { const n = document.createElement('script'); n.text = s.innerHTML; document.body.appendChild(n).parentNode.removeChild(n); });
    });
}
function muatModalGrosir(idBarang) {
    document.getElementById('kontenModalGrosir').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted fw-bold">Memuat...</p></div>';
    new bootstrap.Modal(document.getElementById('modalGrosirUniversal')).show();
    fetch('grosir_setting.php?id=' + idBarang).then(res => res.text()).then(html => {
        document.getElementById('kontenModalGrosir').innerHTML = html;
        const scripts = document.getElementById('kontenModalGrosir').querySelectorAll('script');
        scripts.forEach(s => { const n = document.createElement('script'); n.text = s.innerHTML; document.body.appendChild(n).parentNode.removeChild(n); });
    });
}
function muatModalEdit(idBarang) {
    document.getElementById('kontenModalEdit').innerHTML = '<div class="text-center p-5"><div class="spinner-border text-warning"></div><p class="mt-2 text-muted fw-bold">Memuat...</p></div>';
    new bootstrap.Modal(document.getElementById('modalEditUniversal')).show();
    fetch('edit.php?id=' + idBarang).then(res => res.text()).then(html => {
        document.getElementById('kontenModalEdit').innerHTML = html;
        const scripts = document.getElementById('kontenModalEdit').querySelectorAll('script');
        scripts.forEach(s => { const n = document.createElement('script'); n.text = s.innerHTML; document.body.appendChild(n).parentNode.removeChild(n); });
    });
}
</script>
</body>
</html>