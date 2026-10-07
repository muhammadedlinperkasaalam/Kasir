<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

$periode = $_GET['periode'] ?? 'hari_ini';
$q = trim($_GET['q'] ?? '');
$tgl_awal = $_GET['start'] ?? date('Y-m-d');
$tgl_akhir = $_GET['end'] ?? date('Y-m-d');

if ($periode === 'kemarin') {
    $tgl_awal = $tgl_akhir = date('Y-m-d', strtotime('-1 day'));
} elseif ($periode === '7hari') {
    $tgl_awal = date('Y-m-d', strtotime('-6 days'));
    $tgl_akhir = date('Y-m-d');
} elseif ($periode === 'bulan_ini') {
    $tgl_awal = date('Y-m-01');
    $tgl_akhir = date('Y-m-d');
} elseif ($periode !== 'custom') {
    $periode = 'hari_ini';
    $tgl_awal = $tgl_akhir = date('Y-m-d');
}

$params = [$tgl_awal, $tgl_akhir];
$where = "p.tgl_penjualan BETWEEN ? AND ?";
if ($q !== '') {
    $where .= " AND (p.no_penjualan LIKE ? OR pl.nama_pelanggan LIKE ? OR pl.no_telepon LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}

$sql = "SELECT p.no_penjualan, p.tgl_penjualan, p.jam, p.metode_pembayaran, p.uang_bayar,
               p.total_omzet, p.pelunasan, p.nominal_deposit, p.diskon_global, p.ongkir,
               COALESCE(pl.nama_pelanggan, 'Umum') AS nama_pelanggan,
               COALESCE(pl.no_telepon, '') AS no_telepon,
               COALESCE(o.nama_user, '') AS nama_user,
               COALESCE((SELECT SUM((pi.harga_jual * pi.jumlah) - pi.diskon) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan),0) AS total_item
        FROM penjualan p
        LEFT JOIN pelanggan pl ON pl.kode_pelanggan = p.kode_pelanggan
        LEFT JOIN operator o ON o.kode_user = p.kode_user
        WHERE $where
        ORDER BY p.tgl_penjualan DESC, p.jam DESC, p.no_penjualan DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function rupiah($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>List Transaksi Android</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background:#f3f4f6; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .app { display:flex; min-height:100vh; }
        .content { flex:1; min-width:0; padding:14px; }
        .topbar { position:sticky; top:0; z-index:1010; background:#f3f4f6; padding-bottom:10px; }
        .trx-card { border:0; border-radius:16px; box-shadow:0 2px 10px rgba(15,23,42,.08); margin-bottom:10px; }
        .copy-btn { min-width:86px; }
        .name-text { font-size:1.05rem; font-weight:800; color:#111827; word-break:break-word; }
        .nota-text { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.86rem; color:#6b7280; }
        .small-label { font-size:.75rem; color:#6b7280; }
        .badge-status { font-size:.72rem; }
        @media(max-width:768px){ .content { padding:10px; } .desktop-title { display:none; } }
    </style>
</head>
<body>
<div class="app">
    <?php include '../../sidebar.php'; ?>
    <main class="content">
        <div class="topbar">
            <div class="d-flex align-items-center gap-2 mb-2">
                <button class="btn btn-light border d-md-none" id="btnOpenSidebar" type="button"><i class="fas fa-bars"></i></button>
                <div>
                    <h5 class="mb-0 fw-bold">List Transaksi</h5>
                    <div class="text-muted small">Terbaru tampil paling atas</div>
                </div>
            </div>
            <form class="card border-0 shadow-sm rounded-4 p-2" method="get">
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <select name="periode" class="form-select form-select-sm" onchange="toggleCustom(); this.form.submit();">
                            <option value="hari_ini" <?= $periode==='hari_ini'?'selected':'' ?>>Hari ini</option>
                            <option value="kemarin" <?= $periode==='kemarin'?'selected':'' ?>>Kemarin</option>
                            <option value="7hari" <?= $periode==='7hari'?'selected':'' ?>>7 hari</option>
                            <option value="bulan_ini" <?= $periode==='bulan_ini'?'selected':'' ?>>Bulan ini</option>
                            <option value="custom" <?= $periode==='custom'?'selected':'' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Cari nama/nota/HP">
                    </div>
                    <div class="col-6 col-md-2 custom-date <?= $periode==='custom'?'':'d-none' ?>">
                        <input type="date" name="start" value="<?= h($tgl_awal) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-2 custom-date <?= $periode==='custom'?'':'d-none' ?>">
                        <input type="date" name="end" value="<?= h($tgl_akhir) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i> Tampilkan</button>
                    </div>
                </div>
            </form>
            <div class="small text-muted mt-2">Periode: <?= h($tgl_awal) ?> s/d <?= h($tgl_akhir) ?> · <?= count($rows) ?> transaksi</div>
        </div>

        <div id="copyAlert" class="alert alert-success py-2 d-none" role="alert">Nama berhasil dicopy.</div>

        <?php if (!$rows): ?>
            <div class="card trx-card"><div class="card-body text-center text-muted py-5">Tidak ada transaksi.</div></div>
        <?php endif; ?>

        <?php foreach($rows as $r):
            $total = (float)($r['total_omzet'] ?: $r['total_item']);
            $dibayar = (float)($r['uang_bayar'] ?? 0) + (float)($r['nominal_deposit'] ?? 0);
            $lunas = ($r['pelunasan'] === 'Y');
        ?>
        <div class="card trx-card">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1 min-w-0">
                        <div class="name-text" data-name="<?= h($r['nama_pelanggan']) ?>"><?= h($r['nama_pelanggan']) ?></div>
                        <div class="nota-text"><?= h($r['no_penjualan']) ?> · <?= h(substr($r['jam'],0,5)) ?> · <?= h(date('d/m/Y', strtotime($r['tgl_penjualan']))) ?></div>
                    </div>
                    <button class="btn btn-outline-primary btn-sm copy-btn" type="button" data-copy="<?= h($r['nama_pelanggan']) ?>">
                        <i class="fas fa-copy me-1"></i> Nama
                    </button>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-6">
                        <div class="small-label">Total</div>
                        <div class="fw-bold"><?= rupiah($total) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small-label">Metode</div>
                        <div class="fw-bold"><?= h($r['metode_pembayaran'] ?: '-') ?></div>
                    </div>
                    <div class="col-6">
                        <span class="badge <?= $lunas?'bg-success':'bg-warning text-dark' ?> badge-status"><?= $lunas?'Lunas':'Belum Lunas' ?></span>
                    </div>
                    <div class="col-6 text-end">
                        <?php if(!empty($r['no_telepon'])): ?><span class="text-muted small"><i class="fas fa-phone me-1"></i><?= h($r['no_telepon']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </main>
</div>

<script>
function toggleCustom(){
    const sel = document.querySelector('select[name="periode"]');
    document.querySelectorAll('.custom-date').forEach(el => el.classList.toggle('d-none', sel.value !== 'custom'));
}
async function copyText(text){
    try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
        else {
            const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
        }
        const a = document.getElementById('copyAlert');
        a.textContent = 'Nama dicopy: ' + text;
        a.classList.remove('d-none');
        setTimeout(()=>a.classList.add('d-none'), 1600);
    } catch(e) { alert('Gagal copy nama: ' + text); }
}
document.addEventListener('click', function(e){
    const btn = e.target.closest('[data-copy]');
    if(btn) copyText(btn.dataset.copy || '');
});
</script>
</body>
</html>
