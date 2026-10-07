<?php
require_once __DIR__.'/_init.php';
PA_KasirBridge::ensureTables($pdo);
$title='Print Analyzer';
$stats=$pdo->query("SELECT COUNT(*) jobs, COALESCE(SUM(total_pages),0) pages, COALESCE(SUM(estimated_total),0) total FROM print_jobs WHERE status IN ('done','converted')")->fetch(PDO::FETCH_ASSOC);
$jobs=$pdo->query("SELECT j.*, pp.name profile_name FROM print_jobs j LEFT JOIN price_profiles pp ON pp.id=j.price_profile_id ORDER BY j.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
require __DIR__.'/_header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Total Analisis</small><h3><?= (int)$stats['jobs'] ?></h3></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Total Halaman</small><h3><?= (int)$stats['pages'] ?></h3></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">Total Estimasi</small><h3><?= rupiah($stats['total']) ?></h3></div></div></div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <b>Riwayat File</b>
        <a href="upload.php" class="btn btn-primary btn-sm"><i class="fas fa-upload"></i> Upload File</a>
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Profil</th>
                    <th>Halaman</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>No. Nota</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($jobs as $j): ?>
                <tr>
                    <td>
                        <b><?= e($j['original_filename']) ?></b><br>
                        <small class="text-muted"><?= e(date('d/m/Y H:i',strtotime($j['created_at']))) ?></small>
                    </td>
                    <td><?= e($j['profile_name'] ?? '-') ?></td>
                    <td><?= (int)$j['total_pages'] ?></td>
                    <td><?= rupiah($j['estimated_total']) ?></td>
                    <td><span class="badge bg-<?= $j['status']==='failed'?'danger':'success' ?>"><?= e($j['status']) ?></span></td>
                    <td><?= e($j['no_penjualan'] ?: '-') ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm" role="group">
                            <a class="btn btn-outline-primary" href="job.php?id=<?= (int)$j['id'] ?>">
                                <i class="fas fa-eye"></i> Lihat
                            </a>
                            <form method="post" action="delete_job.php" class="d-inline" onsubmit="return confirm('Hapus riwayat Print Analyzer ini? File upload dan preview analyzer akan dihapus. Transaksi kasir/nota yang sudah dibuat tidak ikut dihapus.');">
                                <input type="hidden" name="_csrf" value="<?= e(pa_csrf()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; if(!$jobs): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada file.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>
