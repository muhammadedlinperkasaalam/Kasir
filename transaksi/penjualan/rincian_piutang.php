<?php
session_start();
if (!isset($_SESSION['user_id'])) { exit("Akses Ditolak."); }
require_once '../../config/database.php';
require_once __DIR__ . '/piutang_helper.php';

$kode_pelanggan = $_GET['kode'] ?? '';
if(empty($kode_pelanggan)) { exit("<div class='p-4 text-center text-muted'>Data pelanggan tidak valid.</div>"); }

// QUERY HYBRID DENGAN DEPOSIT LOGIC
// total_tagihan_fix pakai rumus yang SAMA PERSIS dengan daftar_piutang.php & bayar_cicilan.php
// (lihat piutangSisaNotaExpr() / PIUTANG_AMBANG_MINIMAL di piutang_helper.php).
$sql = "SELECT p.*, 
               pl.nama_pelanggan, 
               pl.no_telepon,
               pl.saldo_deposit,
               CASE 
                   WHEN p.total_omzet > 0 THEN p.total_omzet
                   ELSE (
                       SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) 
                       FROM penjualan_item pi 
                       WHERE pi.no_penjualan = p.no_penjualan
                   )
               END as total_tagihan_fix
        FROM penjualan p
        JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
        WHERE p.pelunasan = 'N' AND p.kode_pelanggan = ? 
        ORDER BY p.tgl_penjualan ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$kode_pelanggan]);
$piutang = $stmt->fetchAll();

// Cek ulang jika ternyata kosong (atau terfilter habis)
$sisa_valid = false;
foreach ($piutang as $r) {
    $tagihan = (float)$r['total_tagihan_fix'];
    $bayar   = (float)$r['uang_bayar'];
    $depo    = (float)($r['nominal_deposit'] ?? 0);
    if (($tagihan - $bayar - $depo) >= PIUTANG_AMBANG_MINIMAL) {
        $sisa_valid = true;
        break;
    }
}

// JIKA LUNAS, KEMBALIKAN SCRIPT UNTUK ME-RELOAD HALAMAN UTAMA
if(count($piutang) == 0 || !$sisa_valid) {
    echo "<script>
            Swal.fire({
                icon: 'success', title: 'Sudah Lunas!', text: 'Semua nota untuk pelanggan ini sudah LUNAS!',
                confirmButtonColor: '#1a56db', timer: 2000, showConfirmButton: false
            }).then(() => { window.location.reload(); });
          </script>";
    exit;
}

$info_pelanggan = $piutang[0];
$saldo_deposit_pelanggan = (float)$info_pelanggan['saldo_deposit'];
?>

<div class="modal-header bg-white border-bottom px-4 py-3 align-items-start">
    <div>
        <h5 class="modal-title fw-bolder text-dark mb-1" style="letter-spacing: -0.5px;">Rincian Piutang</h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1"><i class="fas fa-user me-1"></i><?= htmlspecialchars($info_pelanggan['nama_pelanggan']) ?></span>
            <span class="text-muted small"><i class="fab fa-whatsapp me-1"></i><?= htmlspecialchars($info_pelanggan['no_telepon'] ?: '-') ?></span>
        </div>
    </div>
    <button type="button" class="btn-close shadow-none mt-1" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body bg-light p-0">

    <?php if($saldo_deposit_pelanggan > 0): ?>
    <div class="bg-success text-white px-4 py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <div class="bg-white text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                <i class="fas fa-wallet fs-5"></i>
            </div>
            <div>
                <div class="small text-white-50 text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Saldo Deposit Aktif</div>
                <h5 class="mb-0 fw-bold">Rp <?= number_format($saldo_deposit_pelanggan, 0, ',', '.') ?></h5>
            </div>
        </div>
        <small class="d-none d-sm-block text-end opacity-75" style="max-width: 200px; font-size: 0.75rem; line-height: 1.2;">
            Saldo ini dapat otomatis memotong tagihan saat Anda memproses pembayaran.
        </small>
    </div>
    <?php endif; ?>

    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
            <thead class="table-light" style="position: sticky; top: 0; z-index: 10;">
                <tr>
                    <th class="ps-4 py-3 border-0">Data Transaksi</th>
                    <th class="text-end py-3 border-0">Total Tagihan</th>
                    <th class="text-end py-3 border-0">Telah Dibayar</th>
                    <th class="text-end py-3 border-0 text-danger">Sisa Utang</th>
                    <th class="text-center pe-4 py-3 border-0" width="100">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white">
                <?php 
                $total_sisa_piutang = 0;
                foreach($piutang as $row): 
                    $total_tagihan = (float) $row['total_tagihan_fix'];
                    $uang_bayar    = (float) $row['uang_bayar'];
                    $nominal_depo  = (float) ($row['nominal_deposit'] ?? 0);
                    
                    $total_pembayaran_sah = $uang_bayar + $nominal_depo;
                    $sisa = $total_tagihan - $total_pembayaran_sah;
                    
                    if ($sisa < PIUTANG_AMBANG_MINIMAL) continue;

                    $total_sisa_piutang += $sisa;
                    $persen = ($total_tagihan > 0) ? ($total_pembayaran_sah / $total_tagihan) * 100 : 0;
                ?>
                <tr>
                    <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                        <span class="fw-bold text-dark d-block mb-1">#<?= $row['no_penjualan'] ?></span>
                        <small class="text-muted"><i class="far fa-calendar-alt me-1 opacity-50"></i><?= date('d M Y', strtotime($row['tgl_penjualan'])) ?></small>
                    </td>
                    <td class="text-end py-3 border-bottom-0 fw-semibold text-dark" style="border-bottom: 1px solid var(--border-color) !important;">
                        Rp <?= number_format($total_tagihan, 0, ',', '.') ?>
                    </td>
                    <td class="text-end py-3 border-bottom-0 text-success" style="border-bottom: 1px solid var(--border-color) !important;">
                        <div class="fw-bold">Rp <?= number_format($total_pembayaran_sah, 0, ',', '.') ?></div>
                        <div class="progress mt-2 mb-1 shadow-sm" style="height: 5px; border-radius: 10px; background-color: #e5e7eb;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $persen ?>%"></div>
                        </div>
                        <?php if($nominal_depo > 0): ?>
                        <small class="text-muted" style="font-size:0.65rem;">(Inc. Depo: <?= number_format($nominal_depo, 0, ',', '.') ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-end py-3 border-bottom-0 fw-bolder text-danger fs-6" style="border-bottom: 1px solid var(--border-color) !important; letter-spacing: -0.5px;">
                        Rp <?= number_format($sisa, 0, ',', '.') ?>
                    </td>
                    <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                        <button type="button" class="btn btn-primary btn-sm fw-bold rounded-pill px-3 shadow-sm" onclick="bukaModalBayar('<?= $row['no_penjualan'] ?>')">
                            Bayar <i class="fas fa-chevron-right ms-1" style="font-size: 0.6rem;"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            
            <tfoot style="position: sticky; bottom: 0; z-index: 10;">
                <tr class="bg-light shadow-sm">
                    <td colspan="3" class="text-end text-muted fw-bold pt-4 pb-3 text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Total Sisa Utang Pelanggan:</td>
                    <td class="text-end text-danger fw-bolder pt-4 pb-3" style="font-size: 1.25rem; letter-spacing: -0.5px;">Rp <?= number_format($total_sisa_piutang, 0, ',', '.') ?></td>
                    <td class="pt-4 pb-3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
    <button type="button" class="btn btn-success fw-bold px-4 rounded-pill shadow-sm" onclick="bukaModalBayarSekaligus('<?= htmlspecialchars($kode_pelanggan, ENT_QUOTES) ?>')">
        <i class="fas fa-layer-group me-2"></i>Bayar Sekaligus
    </button>
    <button type="button" class="btn btn-light border text-muted fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Tutup</button>

<div class="modal fade" id="modalBayarCicilan" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalBayar">
            </div>
    </div>
</div>

<script>
    function bukaModalBayar(noPenjualan) {
        $('#kontenModalBayar').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Membuka form kasir...</div>');
        
        var modalBayar = new bootstrap.Modal(document.getElementById('modalBayarCicilan'));
        modalBayar.show();
        
        $.ajax({
            url: 'bayar_cicilan.php?id=' + encodeURIComponent(noPenjualan),
            type: 'GET',
            success: function(response) {
                $('#kontenModalBayar').html(response);
            },
            error: function() {
                $('#kontenModalBayar').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form pembayaran.</div>');
            }
        });
    }

    function bukaModalBayarSekaligus(kodePelanggan) {
        $('#kontenModalBayar').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-success"></i><br>Membuka form bayar sekaligus...</div>');

        var modalBayar = new bootstrap.Modal(document.getElementById('modalBayarCicilan'));
        modalBayar.show();

        $.ajax({
            url: 'bayar_piutang_sekaligus.php?kode=' + encodeURIComponent(kodePelanggan),
            type: 'GET',
            success: function(response) {
                $('#kontenModalBayar').html(response);
            },
            error: function() {
                $('#kontenModalBayar').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form bayar sekaligus.</div>');
            }
        });
    }
</script>
</div>