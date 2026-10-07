<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit("<div class='p-4 text-center text-danger'>Akses ditolak.</div>"); }
require_once '../../config/database.php';
require_once __DIR__ . '/stock_sync_helper.php';

if (!isset($_GET['no'])) { exit("<div class='p-4 text-center text-danger'>Nomor pembelian tidak ditemukan.</div>"); }
$no_pembelian = $_GET['no'];

// --- QUERY HEADER ---
$sqlHeader = "SELECT p.*, s.nama_supplier, u.nama_user 
              FROM pembelian p
              LEFT JOIN supplier s ON p.kode_supplier = s.kode_supplier
              LEFT JOIN operator u ON p.kode_user = u.kode_user
              WHERE p.no_pembelian = ?";
$stmtHeader = $pdo->prepare($sqlHeader);
$stmtHeader->execute([$no_pembelian]);
$header = $stmtHeader->fetch();

if (!$header) exit("<div class='p-4 text-center text-danger'>Data transaksi tidak ditemukan.</div>");

$stokSudahSinkron = false;
try {
    $stokSudahSinkron = pembelian_stock_is_synced($pdo, $no_pembelian);
} catch (Throwable $e) {
    $stokSudahSinkron = false;
}

// --- QUERY ITEM ---
$sqlItem = "SELECT pi.*, b.nama_barang, b.satuan, b.harga_jual
            FROM pembelian_item pi 
            JOIN barang b ON pi.kode_barang = b.kode_barang 
            WHERE pi.no_pembelian = ?";
$stmtItem = $pdo->prepare($sqlItem);
$stmtItem->execute([$no_pembelian]);
$items = $stmtItem->fetchAll();
$itemsPerluUpdateHarga = [];
foreach ($items as $item) {
    $hargaBeliItem = pembelian_to_number($item['harga_beli'] ?? 0);
    $hargaJualItem = pembelian_to_number($item['harga_jual'] ?? 0);
    if ($hargaJualItem > 0 && $hargaBeliItem > $hargaJualItem) {
        $itemsPerluUpdateHarga[] = $item;
    }
}
?>

<div class="modal-header bg-white border-bottom px-4 py-3">
    <div>
        <h5 class="modal-title fw-bolder text-dark mb-1" style="letter-spacing: -0.5px;">Detail Pembelian</h5>
        <div class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">
            <i class="fas fa-receipt me-1"></i> <?= $no_pembelian ?>
        </div>
    </div>
    <button type="button" class="btn-close shadow-none mt-1" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body bg-light p-4" id="areaCetakDetail">
    
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-md-4">
            <div class="bg-white p-3 rounded-3 border shadow-sm h-100">
                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Tanggal Masuk</small>
                <div class="fw-bold text-dark"><i class="far fa-calendar-alt text-primary opacity-50 me-2"></i><?= date('d F Y', strtotime($header['tgl_pembelian'])) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="bg-white p-3 rounded-3 border shadow-sm h-100">
                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Nama Supplier</small>
                <div class="fw-bold text-dark"><i class="fas fa-truck text-primary opacity-50 me-2"></i><?= htmlspecialchars($header['nama_supplier'] ?? 'UMUM') ?></div>
            </div>
        </div>
        <div class="col-sm-12 col-md-4">
            <div class="bg-white p-3 rounded-3 border shadow-sm h-100">
                <small class="text-uppercase text-muted fw-bold d-block mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Operator Sistem</small>
                <div class="fw-bold text-dark"><i class="fas fa-user-edit text-primary opacity-50 me-2"></i><?= htmlspecialchars($header['nama_user'] ?? 'Sistem') ?></div>
            </div>
        </div>
    </div>

    <div class="alert <?= $stokSudahSinkron ? 'alert-success' : 'alert-warning' ?> bg-white border shadow-sm py-2 px-3 mb-4 d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center">
            <i class="fas <?= $stokSudahSinkron ? 'fa-check-circle text-success' : 'fa-exclamation-triangle text-warning' ?> fs-5 me-3"></i>
            <div>
                <strong class="d-block small <?= $stokSudahSinkron ? 'text-success' : 'text-warning' ?>">Status Stok Barang</strong>
                <span class="text-dark" style="font-size: 0.9rem;">
                    <?= $stokSudahSinkron ? 'Stok dari nota ini sudah tercatat bertambah.' : 'Stok dari nota ini belum tercatat sinkron. Gunakan tombol sinkron jika stok barang belum bertambah.' ?>
                </span>
            </div>
        </div>
        <?php if(!$stokSudahSinkron): ?>
        <button type="button" class="btn btn-warning btn-sm fw-bold rounded-pill px-3" onclick="sinkronStokPembelian('<?= htmlspecialchars($no_pembelian, ENT_QUOTES) ?>')">
            <i class="fas fa-sync-alt me-1"></i> Sinkron Stok
        </button>
        <?php endif; ?>
    </div>

    <?php if(!empty($header['keterangan'])): ?>
    <div class="alert alert-info bg-white border-info border-opacity-25 shadow-sm py-2 px-3 mb-4 d-flex align-items-center">
        <i class="fas fa-info-circle text-info fs-5 me-3"></i> 
        <div>
            <strong class="d-block small text-info">Catatan Transaksi:</strong>
            <span class="text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($header['keterangan']) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($itemsPerluUpdateHarga)): ?>
    <div class="alert alert-danger bg-white border-danger border-opacity-25 shadow-sm py-2 px-3 mb-4 d-flex align-items-start">
        <i class="fas fa-exclamation-triangle text-danger fs-5 me-3 mt-1"></i>
        <div>
            <strong class="d-block small text-danger">Perlu Update Harga Jual</strong>
            <div class="text-dark" style="font-size:0.9rem;">
                Harga beli pada nota ini lebih mahal dari harga jual saat ini:
                <ul class="mb-0 mt-1 ps-3">
                    <?php foreach($itemsPerluUpdateHarga as $warn): ?>
                    <li>
                        <?= htmlspecialchars($warn['nama_barang'] ?? $warn['kode_barang']) ?> —
                        Beli Rp <?= number_format((float)$warn['harga_beli'], 0, ',', '.') ?>,
                        Jual Rp <?= number_format((float)$warn['harga_jual'], 0, ',', '.') ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive" style="max-height: 40vh; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="table-light text-muted" style="position: sticky; top: 0; z-index: 10;">
                    <tr>
                        <th class="ps-4 py-3 border-0">Data Barang</th>
                        <th class="text-end py-3 border-0">Harga Beli</th>
                        <th class="text-end py-3 border-0">Harga Jual</th>
                        <th class="text-center py-3 border-0">Qty Masuk</th>
                        <th class="text-end pe-4 py-3 border-0">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach($items as $item): ?>
                    <tr>
                        <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                            <span class="fw-bold text-dark d-block"><?= htmlspecialchars($item['nama_barang']) ?></span>
                            <small class="text-muted"><i class="fas fa-barcode opacity-50 me-1"></i><?= $item['kode_barang'] ?></small>
                        </td>
                        <td class="text-end py-3 border-bottom-0 fw-semibold text-muted" style="border-bottom: 1px solid var(--border-color) !important;">
                            Rp <?= number_format($item['harga_beli'], 0, ',', '.') ?>
                            <?php if((float)$item['harga_jual'] > 0 && (float)$item['harga_beli'] > (float)$item['harga_jual']): ?>
                                <div class="small text-danger fw-bold mt-1">Perlu update harga</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end py-3 border-bottom-0 fw-semibold text-muted" style="border-bottom: 1px solid var(--border-color) !important;">
                            Rp <?= number_format((float)($item['harga_jual'] ?? 0), 0, ',', '.') ?>
                        </td>
                        <td class="text-center py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                            <span class="badge bg-success rounded-pill px-3 py-2 fs-6 shadow-sm"><?= $item['jumlah'] ?> <small class="fw-normal"><?= htmlspecialchars($item['satuan']) ?></small></span>
                        </td>
                        <td class="text-end pe-4 py-3 border-bottom-0 fw-bold text-dark" style="border-bottom: 1px solid var(--border-color) !important;">
                            Rp <?= number_format($item['harga_beli'] * $item['jumlah'], 0, ',', '.') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                
                <tfoot style="position: sticky; bottom: 0; z-index: 10;">
                    <tr class="bg-light">
                        <td colspan="4" class="text-end text-muted fw-bold pt-4 pb-3 text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Grand Total Pembelian:</td>
                        <td class="text-end text-primary fw-bolder pe-4 pt-4 pb-3" style="font-size: 1.2rem; letter-spacing: -0.5px;">
                            Rp <?= number_format($header['total'], 0, ',', '.') ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
    <button type="button" class="btn btn-outline-secondary fw-bold rounded-pill px-4" onclick="cetakStrukPembelian()">
        <i class="fas fa-print me-1"></i> Cetak Bukti
    </button>
    <button type="button" class="btn btn-dark fw-bold rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
</div>

<script>
    function sinkronStokPembelian(noPembelian) {
        if (!confirm('Sinkron stok dari nota ' + noPembelian + '?

Gunakan hanya jika stok barang memang belum bertambah agar tidak dobel.')) {
            return;
        }
        var formData = new FormData();
        formData.append('no_pembelian', noPembelian);

        fetch('sync_stok.php', { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) throw new Error(data.message || 'Gagal sinkron stok');
                alert(data.message || 'Stok berhasil disinkronkan');
                if (typeof bukaModalDetail === 'function') {
                    bukaModalDetail(noPembelian);
                } else {
                    location.reload();
                }
            })
            .catch(function(err) {
                alert(err.message || 'Gagal sinkron stok');
            });
    }

    function cetakStrukPembelian() {
        let konten = document.getElementById('areaCetakDetail').innerHTML;
        let jendelaCetak = window.open('', '', 'width=800,height=600');
        
        jendelaCetak.document.write('<html><head><title>Cetak Bukti Pembelian</title>');
        jendelaCetak.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
        jendelaCetak.document.write('<style>body{padding:20px; font-family:Arial,sans-serif;} .card{border:none!important; box-shadow:none!important;} .badge{border:1px solid #000; color:#000!important; background:transparent!important;} @media print{ .btn, .d-none{display:none!important;} }</style>');
        jendelaCetak.document.write('</head><body>');
        
        jendelaCetak.document.write('<div class="text-center mb-4"><h4>BUKTI PEMBELIAN STOK</h4><h6>No. Transaksi: <?= $no_pembelian ?></h6></div>');
        jendelaCetak.document.write(konten);
        
        jendelaCetak.document.write('</body></html>');
        jendelaCetak.document.close();
        
        // Beri waktu browser untuk memuat CSS sebelum print
        setTimeout(function() {
            jendelaCetak.focus();
            jendelaCetak.print();
            jendelaCetak.close();
        }, 500);
    }
</script>
