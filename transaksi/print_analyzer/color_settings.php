<?php
require_once __DIR__.'/_init.php';
PA_KasirBridge::ensureTables($pdo);
PA_AnalyzerSettings::ensure($pdo);

$title = 'Setelan Warna';
$errors = [];
$success = false;
$settings = PA_AnalyzerSettings::current($pdo);

function pa_float_input(string $key, array $source, float $min, float $max, array &$errors): float {
    $raw = trim((string)($source[$key] ?? ''));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        $errors[$key] = 'Wajib diisi angka.';
        return $min;
    }
    $value = (float)$raw;
    if ($value < $min || $value > $max) {
        $errors[$key] = "Nilai harus antara {$min} sampai {$max}.";
    }
    return max($min, min($max, $value));
}

function pa_int_input(string $key, array $source, int $min, int $max, array &$errors): int {
    $raw = trim((string)($source[$key] ?? ''));
    if ($raw === '' || !is_numeric($raw)) {
        $errors[$key] = 'Wajib diisi angka.';
        return $min;
    }
    $value = (int)round((float)$raw);
    if ($value < $min || $value > $max) {
        $errors[$key] = "Nilai harus antara {$min} sampai {$max}.";
    }
    return max($min, min($max, $value));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(pa_csrf(), (string)($_POST['_csrf'] ?? ''))) {
        $errors['_csrf'] = 'Sesi form tidak valid. Muat ulang halaman lalu simpan lagi.';
    } else {
        $new = [
            'gray_tolerance' => pa_int_input('gray_tolerance', $_POST, 0, 80, $errors),
            'ignore_light_pixels_min_rgb' => pa_int_input('ignore_light_pixels_min_rgb', $_POST, 0, 255, $errors),
            'ignore_white_pixels_min_rgb' => pa_int_input('ignore_white_pixels_min_rgb', $_POST, 0, 255, $errors),
            'bw_max_color_percent' => pa_float_input('bw_max_color_percent', $_POST, 0, 100, $errors),
            'color_25_max_percent' => pa_float_input('color_25_max_percent', $_POST, 0, 100, $errors),
            'color_50_max_percent' => pa_float_input('color_50_max_percent', $_POST, 0, 100, $errors),
            'color_75_max_percent' => pa_float_input('color_75_max_percent', $_POST, 0, 100, $errors),
            'sample_max_width' => pa_int_input('sample_max_width', $_POST, 100, 2000, $errors),
            'sample_max_height' => pa_int_input('sample_max_height', $_POST, 100, 3000, $errors),
        ];

        if (($new['bw_max_color_percent'] ?? 0) > ($new['color_25_max_percent'] ?? 0)) {
            $errors['color_25_max_percent'] = 'Batas Warna 25% harus lebih besar atau sama dengan batas Hitam Putih.';
        }
        if (($new['color_25_max_percent'] ?? 0) > ($new['color_50_max_percent'] ?? 0)) {
            $errors['color_50_max_percent'] = 'Batas Warna 50% harus lebih besar atau sama dengan batas Warna 25%.';
        }
        if (($new['color_50_max_percent'] ?? 0) > ($new['color_75_max_percent'] ?? 0)) {
            $errors['color_75_max_percent'] = 'Batas Warna 75% harus lebih besar atau sama dengan batas Warna 50%.';
        }

        if (!$errors) {
            PA_AnalyzerSettings::update($pdo, $new);
            pa_flash('success', 'Setelan warna berhasil disimpan. Analisis berikutnya akan memakai setelan baru.');
            header('Location: color_settings.php');
            exit;
        }
        $settings = array_merge($settings, $new);
    }
}

require __DIR__.'/_header.php';
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <b>Setelan Deteksi Warna</b><br>
                    <small class="text-muted">Setelan ini dipakai saat file baru dianalisis. Koreksi manual tetap bisa mengubah hasil per sheet/halaman.</small>
                </div>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <b>Gagal menyimpan.</b> Periksa kembali nilai yang ditandai.
                    </div>
                <?php endif; ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="_csrf" value="<?= e(pa_csrf()) ?>">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Toleransi grayscale</label>
                        <input type="number" min="0" max="80" class="form-control <?= isset($errors['gray_tolerance'])?'is-invalid':'' ?>" name="gray_tolerance" value="<?= e($settings['gray_tolerance']) ?>">
                        <div class="form-text">Makin besar, warna tipis/scan noise lebih mudah dianggap hitam putih.</div>
                        <?php if(isset($errors['gray_tolerance'])): ?><div class="invalid-feedback"><?= e($errors['gray_tolerance']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Abaikan warna sangat terang</label>
                        <input type="number" min="0" max="255" class="form-control <?= isset($errors['ignore_light_pixels_min_rgb'])?'is-invalid':'' ?>" name="ignore_light_pixels_min_rgb" value="<?= e($settings['ignore_light_pixels_min_rgb']) ?>">
                        <div class="form-text">Pixel RGB di atas nilai ini tidak dihitung sebagai warna kuat.</div>
                        <?php if(isset($errors['ignore_light_pixels_min_rgb'])): ?><div class="invalid-feedback"><?= e($errors['ignore_light_pixels_min_rgb']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Batas putih / area kosong</label>
                        <input type="number" min="0" max="255" class="form-control <?= isset($errors['ignore_white_pixels_min_rgb'])?'is-invalid':'' ?>" name="ignore_white_pixels_min_rgb" value="<?= e($settings['ignore_white_pixels_min_rgb']) ?>">
                        <div class="form-text">Pixel sangat putih bisa diabaikan dari area isi halaman.</div>
                        <?php if(isset($errors['ignore_white_pixels_min_rgb'])): ?><div class="invalid-feedback"><?= e($errors['ignore_white_pixels_min_rgb']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-12"><hr></div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Maks. Hitam Putih (%)</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control <?= isset($errors['bw_max_color_percent'])?'is-invalid':'' ?>" name="bw_max_color_percent" value="<?= e($settings['bw_max_color_percent']) ?>">
                        <div class="form-text">Contoh 2–5 agar logo/noise kecil tetap BW.</div>
                        <?php if(isset($errors['bw_max_color_percent'])): ?><div class="invalid-feedback"><?= e($errors['bw_max_color_percent']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Maks. Warna 25% (%)</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control <?= isset($errors['color_25_max_percent'])?'is-invalid':'' ?>" name="color_25_max_percent" value="<?= e($settings['color_25_max_percent']) ?>">
                        <?php if(isset($errors['color_25_max_percent'])): ?><div class="invalid-feedback"><?= e($errors['color_25_max_percent']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Maks. Warna 50% (%)</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control <?= isset($errors['color_50_max_percent'])?'is-invalid':'' ?>" name="color_50_max_percent" value="<?= e($settings['color_50_max_percent']) ?>">
                        <?php if(isset($errors['color_50_max_percent'])): ?><div class="invalid-feedback"><?= e($errors['color_50_max_percent']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Maks. Warna 75% (%)</label>
                        <input type="number" min="0" max="100" step="0.01" class="form-control <?= isset($errors['color_75_max_percent'])?'is-invalid':'' ?>" name="color_75_max_percent" value="<?= e($settings['color_75_max_percent']) ?>">
                        <div class="form-text">Di atas nilai ini masuk Warna 100%.</div>
                        <?php if(isset($errors['color_75_max_percent'])): ?><div class="invalid-feedback"><?= e($errors['color_75_max_percent']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-12"><hr></div>

                    <div class="col-12">
                        <div class="alert alert-secondary small mb-0">
                            <b>Catatan sampling:</b> nilai <b>Sampling max width</b> dan <b>Sampling max height</b> memakai satuan <b>pixel</b>, bukan mm/cm.
                            Ini hanya ukuran gambar kecil untuk proses deteksi warna, tidak mengubah ukuran cetak asli.
                            Makin kecil nilainya, analisis makin cepat tetapi detail kecil bisa kurang terbaca.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Sampling max width <span class="text-muted">(pixel)</span></label>
                        <input type="number" min="100" max="2000" class="form-control <?= isset($errors['sample_max_width'])?'is-invalid':'' ?>" name="sample_max_width" value="<?= e($settings['sample_max_width']) ?>">
                        <div class="form-text">Lebar maksimal gambar sampel dalam pixel. Contoh cepat: 240, seimbang: 300, detail: 450.</div>
                        <?php if(isset($errors['sample_max_width'])): ?><div class="invalid-feedback"><?= e($errors['sample_max_width']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Sampling max height <span class="text-muted">(pixel)</span></label>
                        <input type="number" min="100" max="3000" class="form-control <?= isset($errors['sample_max_height'])?'is-invalid':'' ?>" name="sample_max_height" value="<?= e($settings['sample_max_height']) ?>">
                        <div class="form-text">Tinggi maksimal gambar sampel dalam pixel. Contoh cepat: 340, seimbang: 420, detail: 650.</div>
                        <?php if(isset($errors['sample_max_height'])): ?><div class="invalid-feedback"><?= e($errors['sample_max_height']) ?></div><?php endif; ?>
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <div class="d-grid gap-2 w-100">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Setelan
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">Kembali ke Riwayat</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><b>Urutan Kategori</b></div>
            <div class="card-body small">
                <div class="mb-2"><span class="badge bg-dark">Hitam Putih</span> 0% sampai <?= e($settings['bw_max_color_percent']) ?>%</div>
                <div class="mb-2"><span class="badge bg-info text-dark">Warna 25%</span> &gt; <?= e($settings['bw_max_color_percent']) ?>% sampai <?= e($settings['color_25_max_percent']) ?>%</div>
                <div class="mb-2"><span class="badge bg-primary">Warna 50%</span> &gt; <?= e($settings['color_25_max_percent']) ?>% sampai <?= e($settings['color_50_max_percent']) ?>%</div>
                <div class="mb-2"><span class="badge bg-warning text-dark">Warna 75%</span> &gt; <?= e($settings['color_50_max_percent']) ?>% sampai <?= e($settings['color_75_max_percent']) ?>%</div>
                <div><span class="badge bg-danger">Warna 100%</span> &gt; <?= e($settings['color_75_max_percent']) ?>%</div>
            </div>
        </div>
        <div class="alert alert-info small mb-3">
            <b>Catatan:</b> File yang sudah dianalisis tidak otomatis berubah setelah setelan disimpan. Setelan baru dipakai untuk analisis berikutnya. Untuk file lama, upload/analisis ulang.
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><b>Rekomendasi Sampling</b></div>
            <div class="card-body small">
                <div class="mb-2"><b>Cepat:</b> 240 × 340 pixel</div>
                <div class="mb-2"><b>Seimbang:</b> 300 × 420 pixel</div>
                <div><b>Lebih detail:</b> 450 × 650 pixel</div>
                <hr>
                <div class="text-muted">Sampling hanya untuk mempercepat pembacaan warna. Ukuran file/hasil cetak tetap mengikuti setting print, bukan nilai sampling ini.</div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>
