<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// Ambil Data Barang yang Stoknya <= Limit
// Kita join dengan supplier agar mudah menghubungi supplier
$sql = "SELECT b.*, k.nama_kategori, s.nama_supplier, s.no_telp as telp_supplier
        FROM barang b
        LEFT JOIN kategori k ON b.kode_kategori = k.kode_kategori
        LEFT JOIN supplier s ON b.kode_supplier = s.kode_supplier
        WHERE b.stok <= b.stok_limit AND b.aktif = 'Y'
        ORDER BY b.stok ASC"; // Urutkan dari yang paling kritis (stok terkecil/0)

$stmt = $pdo->prepare($sql);
$stmt->execute();
$data_alert = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Peringatan Stok Menipis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-danger mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.php">
                <i class="fas fa-exclamation-triangle me-2"></i>ALERT STOK
            </a>
            <div class="ms-auto">
                <a href="../index.php" class="btn btn-outline-light btn-sm">Kembali ke Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        <div class="card shadow border-danger">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-danger"><i class="fas fa-box-open me-2"></i>Daftar Barang Perlu Restock</h5>
                    <span class="badge bg-danger rounded-pill"><?= count($data_alert) ?> Item</span>
                </div>
            </div>
            <div class="card-body">
                
                <?php if(count($data_alert) == 0): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                        <h4>Stok Aman!</h4>
                        <p>Tidak ada barang yang stoknya di bawah batas minimum.</p>
                        <a href="../index.php" class="btn btn-primary mt-2">Kembali ke Dashboard</a>
                    </div>
                <?php else: ?>

                <div class="alert alert-warning border-start border-warning border-4">
                    <i class="fas fa-info-circle me-2"></i>
                    Daftar di bawah ini adalah barang yang stok saat ini <strong>kurang dari atau sama dengan</strong> batas minimum (Stok Limit). Segera lakukan pembelian ke supplier.
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th>Kategori</th>
                                <th class="text-center bg-danger text-white" width="100">Sisa Stok</th>
                                <th class="text-center text-muted" width="100">Limit Min</th>
                                <th>Supplier Utama</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($data_alert as $row): 
                                // Visual Cues
                                $is_empty = ($row['stok'] <= 0);
                                $row_class = $is_empty ? 'table-danger' : '';
                                $stok_class = $is_empty ? 'text-danger fw-bold' : 'text-warning fw-bold';
                                $icon = $is_empty ? '<i class="fas fa-times-circle me-1"></i>Habis' : '<i class="fas fa-exclamation-circle me-1"></i>Tipis';
                            ?>
                            <tr class="<?= $row_class ?>">
                                <td class="fw-bold"><?= $row['kode_barang'] ?></td>
                                <td>
                                    <?= $row['nama_barang'] ?>
                                    <div class="small text-muted"><?= $row['satuan'] ?></div>
                                </td>
                                <td><?= $row['nama_kategori'] ?></td>
                                
                                <td class="text-center fs-5 <?= $stok_class ?>">
                                    <?= $row['stok'] ?>
                                </td>
                                
                                <td class="text-center text-muted"><?= $row['stok_limit'] ?></td>
                                
                                <td>
                                    <?php if($row['nama_supplier']): ?>
                                        <div class="fw-bold"><?= $row['nama_supplier'] ?></div>
                                        <a href="https://wa.me/<?= preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $row['telp_supplier'])) ?>" target="_blank" class="btn btn-success btn-sm py-0" style="font-size: 0.7rem;">
                                            <i class="fab fa-whatsapp me-1"></i> Hubungi
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <a href="../transaksi/pembelian/index.php" class="btn btn-primary btn-sm" title="Beli Stok Baru">
                                            <i class="fas fa-cart-plus"></i> Restock
                                        </a>
                                        
                                        <a href="../master/barang/edit.php?id=<?= $row['kode_barang'] ?>" class="btn btn-outline-secondary btn-sm" title="Edit Data">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>
