<?php $title = $title ?? 'Print Analyzer'; ?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Addinta POS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>body{background:#f8f9fa}.wrapper{display:flex;width:100%}.main-content{flex:1;min-height:100vh}.preview-card img{width:100%;border:1px solid #e5e7eb;border-radius:10px;background:white}.preview-card{border:1px solid #e5e7eb;border-radius:14px;background:white;padding:12px}.small-muted{font-size:.8rem;color:#6b7280}.badge-cat{font-size:.75rem}.table td,.table th{vertical-align:middle}</style></head><body><div class="wrapper">
<?php include pa_project_root() . '/sidebar.php'; ?>
<div class="main-content"><div class="container-fluid p-4">
<div class="d-flex justify-content-between align-items-center mb-3"><div><h3 class="mb-0 fw-bold"><?= e($title) ?></h3><small class="text-muted">Analisis halaman dan warna file cetak</small></div><div><a class="btn btn-outline-secondary btn-sm" href="../penjualan/index.php"><i class="fas fa-cash-register"></i> Kasir</a></div></div>
<?php if($m=pa_flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if($m=pa_flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>
<ul class="nav nav-pills mb-4"><li class="nav-item"><a class="nav-link" href="index.php">Riwayat</a></li><li class="nav-item"><a class="nav-link" href="upload.php">Upload</a></li><li class="nav-item"><a class="nav-link" href="profiles.php">Profil Harga</a></li><li class="nav-item"><a class="nav-link" href="color_settings.php">Setelan Warna</a></li></ul>
