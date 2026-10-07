<?php
// Widget modal Print Analyzer untuk dipanggil dari transaksi/penjualan/index.php
$paProfiles = [];
try {
    require_once __DIR__ . '/../../config/database.php';
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_profiles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        paper_size VARCHAR(20) NOT NULL,
        paper_type VARCHAR(50) NOT NULL,
        print_mode ENUM('color','grayscale') NOT NULL DEFAULT 'color',
        printer_paper_type ENUM('plain','photo_glossy') NOT NULL DEFAULT 'plain',
        print_side ENUM('1_sisi','2_sisi') NOT NULL DEFAULT '1_sisi',
        price_bw INT UNSIGNED NOT NULL DEFAULT 0,
        price_color_25 INT UNSIGNED NOT NULL DEFAULT 0,
        price_color_50 INT UNSIGNED NOT NULL DEFAULT 0,
        price_color_75 INT UNSIGNED NOT NULL DEFAULT 0,
        price_color_100 INT UNSIGNED NOT NULL DEFAULT 0,
        kode_barang_bw CHAR(10) DEFAULT NULL,
        kode_barang_color_25 CHAR(10) DEFAULT NULL,
        kode_barang_color_50 CHAR(10) DEFAULT NULL,
        kode_barang_color_75 CHAR(10) DEFAULT NULL,
        kode_barang_color_100 CHAR(10) DEFAULT NULL,
        duplex_discount_per_sheet INT UNSIGNED NOT NULL DEFAULT 50,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(['print_mode'=>"ENUM('color','grayscale') NOT NULL DEFAULT 'color'",'printer_paper_type'=>"ENUM('plain','photo_glossy') NOT NULL DEFAULT 'plain' AFTER print_mode",'kode_barang_bw'=>'CHAR(10) DEFAULT NULL','kode_barang_color_25'=>'CHAR(10) DEFAULT NULL','kode_barang_color_50'=>'CHAR(10) DEFAULT NULL','kode_barang_color_75'=>'CHAR(10) DEFAULT NULL','kode_barang_color_100'=>'CHAR(10) DEFAULT NULL','duplex_discount_per_sheet'=>'INT UNSIGNED NOT NULL DEFAULT 50'] as $col=>$def){
        try{$pdo->query("SELECT $col FROM price_profiles LIMIT 1");}catch(Exception $e){$pdo->exec("ALTER TABLE price_profiles ADD COLUMN $col $def");}
    }
    $paProfiles = $pdo->query("SELECT * FROM price_profiles WHERE is_active=1 ORDER BY paper_size, paper_type, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $paProfiles = []; }
if (!function_exists('pa_modal_e')) { function pa_modal_e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }
?>
<style>
.pa-modal-preview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}.pa-modal-preview-card{border:1px solid #e5e7eb;border-radius:14px;background:#fff;overflow:hidden}.pa-modal-preview-card img{width:100%;height:170px;object-fit:contain;background:#f8fafc;border-bottom:1px solid #e5e7eb}.pa-modal-preview-body{padding:10px}.pa-sheet-card{border:1px solid #dbeafe;border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 6px 18px rgba(15,23,42,.06)}.pa-sheet-head{padding:9px 11px;background:#eff6ff;border-bottom:1px solid #dbeafe;display:flex;align-items:center;justify-content:space-between;gap:8px}.pa-sheet-pages{display:grid;gap:6px;padding:8px;background:#f8fafc}.pa-sheet-pages.pps-1{grid-template-columns:1fr}.pa-sheet-pages.pps-2{grid-template-columns:repeat(2,1fr)}.pa-sheet-pages.pps-4{grid-template-columns:repeat(2,1fr)}.pa-sheet-pages.pps-6{grid-template-columns:repeat(3,1fr)}.pa-sheet-pages.pps-9{grid-template-columns:repeat(3,1fr)}.pa-sheet-pages.pps-16{grid-template-columns:repeat(4,1fr)}.pa-sheet-page{border:1px solid #e5e7eb;border-radius:10px;background:white;overflow:hidden;min-width:0}.pa-sheet-page img{width:100%;height:135px;object-fit:contain;background:white;display:block;border-bottom:1px solid #e5e7eb}.pa-sheet-page-body{padding:7px}.pa-sheet-page-empty{height:135px;border:1px dashed #cbd5e1;border-radius:10px;background:repeating-linear-gradient(45deg,#f8fafc,#f8fafc 8px,#f1f5f9 8px,#f1f5f9 16px);display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.75rem}.pa-modal-mini{font-size:.78rem}.pa-modal-scroll{max-height:58vh;overflow:auto}.pa-modal-summary-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:.8rem}.pa-modal-file-title{max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pa-smooth-progress-wrap{height:22px;border-radius:999px;background:#e2e8f0;overflow:hidden;position:relative;box-shadow:inset 0 1px 2px rgba(15,23,42,.12)}.pa-smooth-progress-bar{height:100%;min-width:0;border-radius:999px;background:linear-gradient(90deg,#2563eb,#06b6d4,#16a34a);position:relative;transition:width .45s cubic-bezier(.22,.61,.36,1);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.78rem;text-shadow:0 1px 1px rgba(0,0,0,.25)}.pa-smooth-progress-bar::after{content:"";position:absolute;inset:0;background:linear-gradient(110deg,transparent 0%,rgba(255,255,255,.28) 42%,rgba(255,255,255,.55) 50%,rgba(255,255,255,.28) 58%,transparent 100%);transform:translateX(-100%);animation:paSmoothShine 1.1s linear infinite}.pa-smooth-progress-percent{position:relative;z-index:1}.pa-smooth-progress-pulse{font-size:.78rem;color:#64748b}@keyframes paSmoothShine{to{transform:translateX(100%)}}
</style>
<div class="modal fade" id="modalPrintAnalyzerKasir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px; overflow:hidden;">
            <div class="modal-header border-0 text-white" style="background:linear-gradient(135deg,#1d4ed8,#0f766e);">
                <div>
                    <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>Print Analyzer</h5>
                    <small class="opacity-75">Upload file, preview halaman, koreksi kategori, lalu masukkan ke keranjang kasir.</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-3 p-md-4">
                <form id="formPrintAnalyzerKasir" enctype="multipart/form-data">
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <label class="form-label small fw-bold text-muted">File cetak</label>
                                    <input class="form-control" type="file" name="documents[]" id="paKasirFiles" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png" multiple>
                                    <div id="paKasirWaFilesHolder"></div>
                                    <small class="text-muted d-block mt-2">Bisa upload manual atau ambil file dari WhatsApp. Setelah dianalisis, tiap sheet bisa dikoreksi di preview.</small>

                                    <div class="mt-3">
                                        <label class="form-label small fw-bold text-muted">Profil harga</label>
                                        <select class="form-select" name="price_profile_id" id="paKasirProfile" required>
                                            <option value="">Pilih profil harga</option>
                                            <?php foreach($paProfiles as $p): ?>
                                                <option value="<?= (int)$p['id'] ?>" data-profile-name="<?= pa_modal_e($p['name']) ?>" data-paper-size="<?= pa_modal_e($p['paper_size']) ?>" data-paper-type="<?= pa_modal_e($p['paper_type']) ?>" data-print-side="<?= pa_modal_e($p['print_side'] ?? '1_sisi') ?>" data-print-mode="<?= pa_modal_e($p['print_mode'] ?? 'color') ?>" data-print-grayscale="<?= (($p['print_mode'] ?? 'color') === 'grayscale') ? '1' : '0' ?>" data-printer-paper-type="<?= pa_modal_e($p['printer_paper_type'] ?? 'plain') ?>">
                                                    <?= pa_modal_e($p['name']) ?> — <?= pa_modal_e($p['paper_size']) ?> / <?= pa_modal_e($p['paper_type']) ?><?= (($p['print_mode'] ?? 'color') === 'grayscale') ? ' / Grayscale' : ' / Warna' ?><?= (($p['printer_paper_type'] ?? 'plain') === 'photo_glossy') ? ' / Photo Glossy' : ' / Plain Paper' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Harga mengambil dari master barang/jasa di profil.</small>
                                    </div>

                                    <div class="row g-2 mt-2">
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-muted">Qty/rangkap</label>
                                            <input type="number" min="1" value="1" name="qty_cetak" id="paKasirQty" class="form-control" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-muted">Page / sheet</label>
                                            <select name="pages_per_sheet" id="paKasirPagesPerSheet" class="form-select">
                                                <option value="1">1 page / sheet</option>
                                                <option value="2">2 page / sheet</option>
                                                <option value="4">4 page / sheet</option>
                                                <option value="6">6 page / sheet</option>
                                                <option value="9">9 page / sheet</option>
                                                <option value="16">16 page / sheet</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-muted">Orientasi</label>
                                            <select name="orientation" id="paKasirOrientation" class="form-select">
                                                <option value="auto" selected>Auto</option>
                                                <option value="portrait">Portrait</option>
                                                <option value="landscape">Landscape</option>
                                            </select>
                                            <div class="form-text small">Auto = mengikuti orientasi file/printer.</div>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-muted">Halaman</label>
                                            <input type="text" name="halaman" id="paKasirHalaman" class="form-control" placeholder="Semua / 1-5">
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <label class="form-label small fw-bold text-muted">Finishing/catatan</label>
                                        <input type="text" name="finishing" id="paKasirFinishing" class="form-control" placeholder="Jilid, laminating, potong, dll">
                                    </div>
                                    <div class="form-check form-switch mt-3 p-3 rounded border bg-light">
                                        <input class="form-check-input" type="checkbox" value="1" name="borderless" id="paKasirBorderless">
                                        <label class="form-check-label fw-bold" for="paKasirBorderless">Borderless</label>
                                        <div class="small text-muted mt-1">Centang jika file ini harus dicetak tanpa tepi/border. Setelan ini ikut masuk ke keranjang dan Manajemen Order.</div>
                                    </div>
                                    <input type="hidden" name="print_grayscale" id="paKasirGrayscale" value="0">
                                    <input type="hidden" name="printer_paper_type" id="paKasirPrinterPaperType" value="plain">
                                    <div class="mt-2 p-3 rounded border bg-white" id="paKasirPrintModeInfo">
                                        <div class="small text-muted">Mode Print dari Profil Harga</div>
                                        <div class="fw-bold" id="paKasirPrintModeLabel"><i class="fas fa-palette me-1"></i>Warna</div>
                                        <div class="small mt-1"><span class="text-muted">Paper Type:</span> <b id="paKasirPrinterPaperTypeLabel">Plain Paper</b></div>
                                        <div class="small text-muted mt-1" id="paKasirPrintModeNote">Pilih profil Grayscale di Profil Harga jika customer minta cetak murah. Setelan ini otomatis sinkron ke Print Klien.</div>
                                    </div>
                                    <div class="alert alert-info small mt-3 mb-0">
                                        <b>Page / sheet</b> menghitung harga per sheet. Contoh 4 halaman dengan 2 page/sheet menjadi 2 sheet per rangkap. Jika isi sheet campuran, harga sheet mengikuti kategori warna tertinggi di sheet tersebut. Koreksi kategori per sheet akan memperbarui total.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <h6 class="fw-bolder mb-0 text-dark">Preview & Hasil Analisis</h6>
                                            <small class="text-muted">Preview halaman akan muncul setelah file dianalisis.</small>
                                        </div>
                                        <span class="badge bg-secondary" id="paKasirStatusBadge">Belum dianalisis</span>
                                    </div>
                                    <div id="paKasirResult" class="border rounded-3 bg-white p-3 pa-modal-scroll" style="min-height:430px;">
                                        <div class="text-center text-muted py-5">
                                            <i class="fas fa-file-upload fa-3x opacity-25 mb-3"></i>
                                            <div class="fw-bold">Pilih file lalu klik Analisis</div>
                                            <small>Hasil per sheet, kategori warna, dan total harga akan ditampilkan di sini.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-white border-top px-4 py-3">
                <div class="me-auto small text-muted" id="paKasirFooterInfo">Hasil analyzer belum masuk keranjang.</div>
                <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary fw-bold" id="btnPaKasirAnalyze" onclick="paKasirAnalyzeFiles()"><i class="fas fa-magic me-1"></i> Analisis</button>
                <button type="button" class="btn btn-success fw-bold" id="btnPaKasirAddCart" onclick="paKasirAddToCart()" disabled><i class="fas fa-cart-plus me-1"></i> Tambah ke Keranjang</button>
            </div>
        </div>
    </div>
</div>

<script>
let paKasirLastResult = null;
let paKasirSmoothProgressShown = 0;
let paKasirSmoothProgressTarget = 0;
let paKasirSmoothProgressRaf = null;
let paKasirSmoothProgressStartedAt = 0;
let paKasirSmoothProgressTimer = null;
let paKasirSmoothProgressEstimateSeconds = 18;
let paKasirSmoothProgressLastData = { percent:0, batch_status:'queued', done_files:0, total_files:0 };

function paKasirResetSmoothProgress(){
    paKasirStopEstimatedProgress();
    paKasirSmoothProgressShown = 0;
    paKasirSmoothProgressTarget = 0;
    paKasirSmoothProgressStartedAt = Date.now();
    paKasirSmoothProgressEstimateSeconds = 18;
    paKasirSmoothProgressLastData = { percent:0, batch_status:'queued', done_files:0, total_files:0 };
    if(paKasirSmoothProgressRaf){ cancelAnimationFrame(paKasirSmoothProgressRaf); paKasirSmoothProgressRaf = null; }
}

function paKasirFormatEstimate(seconds){
    seconds = Math.max(1, Math.round(Number(seconds) || 1));
    if(seconds < 60) return seconds + ' detik';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s ? (m + ' menit ' + s + ' detik') : (m + ' menit');
}

function paKasirEstimateAnalyzeSeconds(startData={}){
    const fileInput = document.getElementById('paKasirFiles');
    const manualFiles = fileInput && fileInput.files ? Array.from(fileInput.files) : [];
    let seconds = 0;
    manualFiles.forEach(file => {
        const name = String(file.name || '').toLowerCase();
        const mb = Math.max(0.05, (Number(file.size || 0) / 1048576));
        if(/\.(jpg|jpeg|png)$/i.test(name)) seconds += 4 + Math.min(10, mb * 1.2);
        else if(/\.pdf$/i.test(name)) seconds += 8 + Math.min(40, mb * 1.8);
        else if(/\.(doc|docx|ppt|pptx)$/i.test(name)) seconds += 14 + Math.min(55, mb * 2.4);
        else seconds += 10 + Math.min(35, mb * 2);
    });
    const waFiles = document.querySelectorAll('#paKasirWaFilesHolder input[name="wa_files[]"]');
    if(waFiles && waFiles.length){
        waFiles.forEach(inp => {
            const name = String(inp.value || '').toLowerCase();
            if(/\.(jpg|jpeg|png)$/i.test(name)) seconds += 6;
            else if(/\.pdf$/i.test(name)) seconds += 12;
            else if(/\.(doc|docx|ppt|pptx)$/i.test(name)) seconds += 18;
            else seconds += 12;
        });
    }
    const totalFiles = Math.max(parseInt(startData.total_files || 0, 10), manualFiles.length + (waFiles ? waFiles.length : 0));
    if(seconds <= 0) seconds = Math.max(10, totalFiles * 12);
    // Batas dibuat pendek-menengah agar file kecil tidak diam di 1% terlalu lama.
    return Math.max(7, Math.min(180, Math.round(seconds)));
}

function paKasirPrepareSmoothEstimate(startData={}){
    paKasirSmoothProgressStartedAt = Date.now();
    paKasirSmoothProgressEstimateSeconds = paKasirEstimateAnalyzeSeconds(startData);
    paKasirSmoothProgressLastData = {
        percent: parseInt(startData.percent || 0, 10) || 0,
        batch_status: startData.batch_status || 'queued',
        done_files: parseInt(startData.done_files || 0, 10) || 0,
        total_files: parseInt(startData.total_files || 0, 10) || 0
    };
}

function paKasirComputeSmoothTarget(realPercent, status){
    const p = Math.max(0, Math.min(100, parseInt(realPercent || 0, 10)));
    if(status === 'done') return 100;
    if(status === 'failed') return Math.max(1, paKasirSmoothProgressShown || p || 1);
    if(!paKasirSmoothProgressStartedAt) paKasirSmoothProgressStartedAt = Date.now();

    const elapsed = Math.max(0, (Date.now() - paKasirSmoothProgressStartedAt) / 1000);
    const estimate = Math.max(7, Number(paKasirSmoothProgressEstimateSeconds) || 18);
    const last = paKasirSmoothProgressLastData || {};
    const totalFiles = Math.max(0, parseInt(last.total_files || 0, 10));
    const doneFiles = Math.max(0, parseInt(last.done_files || 0, 10));

    // Target utama mengikuti perkiraan waktu proses, sehingga tetap bergerak saat request server sedang lama.
    let timeBased = 1 + (elapsed / estimate) * 88;
    if(elapsed > estimate){
        // Setelah waktu perkiraan habis, naik pelan dan tahan di bawah 98% sampai server benar-benar selesai.
        timeBased = 89 + Math.min(8, Math.log1p(elapsed - estimate) * 2.2);
    }

    let fileBased = 1;
    if(totalFiles > 0 && doneFiles > 0){
        fileBased = 8 + Math.min(86, (doneFiles / totalFiles) * 86);
    }

    let serverBased = p > 0 ? p : 1;
    let target = Math.max(timeBased, fileBased, serverBased, paKasirSmoothProgressShown || 1);
    return Math.max(1, Math.min(98, target));
}

function paKasirSetSmoothProgressTarget(target){
    paKasirSmoothProgressTarget = Math.max(1, Math.min(100, Number(target) || 1));
    const step = () => {
        const bar = document.getElementById('paKasirSmoothProgressBar');
        const label = document.getElementById('paKasirSmoothProgressPercent');
        if(!bar || !label){ paKasirSmoothProgressRaf = null; return; }
        const diff = paKasirSmoothProgressTarget - paKasirSmoothProgressShown;
        if(Math.abs(diff) < 0.12){
            paKasirSmoothProgressShown = paKasirSmoothProgressTarget;
        } else {
            // Gerak lembut tapi responsif mengikuti estimasi waktu.
            paKasirSmoothProgressShown += diff * 0.10;
        }
        const shown = Math.max(1, Math.min(100, paKasirSmoothProgressShown));
        bar.style.width = shown.toFixed(2) + '%';
        label.textContent = Math.round(shown) + '%';
        if(Math.abs(paKasirSmoothProgressTarget - paKasirSmoothProgressShown) >= 0.12){
            paKasirSmoothProgressRaf = requestAnimationFrame(step);
        } else {
            paKasirSmoothProgressRaf = null;
        }
    };
    if(!paKasirSmoothProgressRaf) paKasirSmoothProgressRaf = requestAnimationFrame(step);
}

function paKasirStartEstimatedProgress(){
    paKasirStopEstimatedProgress();
    paKasirSmoothProgressTimer = setInterval(() => {
        const last = paKasirSmoothProgressLastData || {};
        const target = paKasirComputeSmoothTarget(last.percent || 0, last.batch_status || 'processing');
        paKasirSetSmoothProgressTarget(target);
        const badge = document.getElementById('paKasirStatusBadge');
        if(badge && (last.batch_status || 'processing') !== 'done'){
            badge.className = 'badge bg-warning text-dark';
            badge.innerText = 'Background ' + Math.round(Math.max(paKasirSmoothProgressShown, target)) + '%';
        }
    }, 220);
}

function paKasirStopEstimatedProgress(){
    if(paKasirSmoothProgressTimer){
        clearInterval(paKasirSmoothProgressTimer);
        paKasirSmoothProgressTimer = null;
    }
}
function openPrintAnalyzerModal(){ bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrintAnalyzerKasir')).show(); }


function paKasirNormalizeProfileText(v){
    return String(v || '').toLowerCase()
        .replace(/\s+/g, ' ')
        .replace(/[\-_/]+/g, ' ')
        .trim();
}
function paKasirSelectDefaultA4Hvs70SatuSisi(){
    const sel = document.getElementById('paKasirProfile');
    if(!sel) return false;
    const opts = Array.from(sel.options || []).filter(o => o.value);
    const targetName = 'a4 hvs 70 1 sisi';
    const normalized = opts.map(o => ({
        opt: o,
        name: paKasirNormalizeProfileText(o.dataset.profileName || ''),
        txt: paKasirNormalizeProfileText(o.textContent || ''),
        paperSize: paKasirNormalizeProfileText(o.dataset.paperSize || ''),
        paperType: paKasirNormalizeProfileText(o.dataset.paperType || ''),
        printSide: paKasirNormalizeProfileText(o.dataset.printSide || '')
    }));

    // Auto analyze wajib memakai profile dengan nama master yang tepat:
    // "A4 HVS 70 1 SISI". Jangan memilih profile lain seperti Fotocopy.
    let found = normalized.find(x => x.name === targetName);

    // Fallback aman kalau atribut data-profile-name tidak terbaca di browser/cache lama:
    // teks option biasanya berbentuk "A4 HVS 70 1 SISI — A4 / HVS 70 ...".
    if(!found) found = normalized.find(x => x.txt === targetName || x.txt.startsWith(targetName + ' '));

    if(!found) return false;

    sel.value = found.opt.value;
    sel.dispatchEvent(new Event('change', { bubbles:true }));
    if(typeof paKasirSyncPrintModeFromProfile === 'function') paKasirSyncPrintModeFromProfile();
    return true;
}
function paKasirSetDefaultDragDropA4Hvs70SatuSisi(){
    paKasirSelectDefaultA4Hvs70SatuSisi();
    const qty = document.getElementById('paKasirQty');
    const pps = document.getElementById('paKasirPagesPerSheet');
    const halaman = document.getElementById('paKasirHalaman');
    const orientasi = document.getElementById('paKasirOrientation');
    const finishing = document.getElementById('paKasirFinishing');
    const borderless = document.getElementById('paKasirBorderless');
    if(qty) qty.value = 1;
    if(pps) pps.value = 1;
    if(halaman) halaman.value = '';
    if(orientasi) orientasi.value = 'auto';
    if(finishing) finishing.value = '';
    if(borderless) borderless.checked = false;
}
function paKasirAnalyzeDroppedFilesAuto(fileList){
    const allowedExt = ['pdf','doc','docx','ppt','pptx','xls','xlsx','jpg','jpeg','png'];
    const dropped = Array.from(fileList || []);
    const validFiles = [];
    const invalidNames = [];
    dropped.forEach(file => {
        const ext = String(file.name || '').split('.').pop().toLowerCase();
        if (allowedExt.includes(ext)) validFiles.push(file);
        else invalidNames.push(file.name || 'file tanpa nama');
    });
    if(invalidNames.length && typeof Swal !== 'undefined'){
        Swal.fire('Format tidak didukung', 'File berikut tidak dimasukkan ke Print Analyzer:<br><b>' + invalidNames.map(n => paKasirEscape(n)).join('<br>') + '</b>', 'warning');
    }
    if(!validFiles.length) return;
    try{
        if(typeof paKasirResetModal === 'function') paKasirResetModal();
        if(typeof paKasirClearWaFiles === 'function') paKasirClearWaFiles();
        const input = document.getElementById('paKasirFiles');
        if(!input) throw new Error('Input file Print Analyzer tidak ditemukan.');
        input.disabled = false;
        input.value = '';
        if(typeof DataTransfer === 'undefined') throw new Error('Browser belum mendukung drag & drop file langsung ke input. Gunakan tombol pilih file di Print Analyzer.');
        const dt = new DataTransfer();
        validFiles.forEach(file => dt.items.add(file));
        input.files = dt.files;
        paKasirSetDefaultDragDropA4Hvs70SatuSisi();
        const profileOk = !!document.getElementById('paKasirProfile')?.value;
        const listHtml = validFiles.slice(0, 10).map(f => '<li>' + paKasirEscape(f.name) + '</li>').join('') + (validFiles.length > 10 ? '<li class="text-muted">+ ' + (validFiles.length - 10) + ' file lainnya</li>' : '');
        const footer = document.getElementById('paKasirFooterInfo');
        if(footer) footer.innerHTML = validFiles.length + ' file dari drag & drop. Default: <b>A4 / HVS 70 / 1 sisi / 1 page sheet</b>.';
        const result = document.getElementById('paKasirResult');
        if(result) result.innerHTML = '<div class="alert alert-primary mb-0"><div class="fw-bold"><i class="fas fa-bolt me-1"></i>Auto analyze dari drag & drop</div><ul class="small mt-2 mb-2 ps-3">' + listHtml + '</ul><div class="small text-muted">Setelan otomatis: A4, HVS 70, 1 sisi, Qty 1, 1 page/sheet.</div></div>';
        const badge = document.getElementById('paKasirStatusBadge');
        if(badge){ badge.className = 'badge bg-primary'; badge.innerText = 'Auto analyze'; }
        openPrintAnalyzerModal();
        setTimeout(() => {
            if(profileOk && typeof paKasirAnalyzeFiles === 'function') paKasirAnalyzeFiles();
            else if(typeof Swal !== 'undefined') Swal.fire('Profil A4 HVS 70 1 SISI belum ditemukan', 'Buat/aktifkan profil harga A4 HVS 70 1 SISI non-Fotocopy dulu, atau pilih profil manual lalu klik Analisis.', 'warning');
        }, 450);
    }catch(err){
        if(typeof Swal !== 'undefined') Swal.fire('Gagal auto analyze', err.message || String(err), 'error');
        else alert(err.message || String(err));
    }
}
(function(){
    if(window.__paKasirDragDropAutoAnalyzeInstalled) return;
    window.__paKasirDragDropAutoAnalyzeInstalled = true;
    window.addEventListener('drop', function(e){
        const hasFiles = e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
        if(!hasFiles) return;
        e.preventDefault();
        if(e.stopImmediatePropagation) e.stopImmediatePropagation();
        const overlay = document.getElementById('dragDropOverlay');
        if(overlay) overlay.style.display = 'none';
        paKasirAnalyzeDroppedFilesAuto(e.dataTransfer.files);
    }, true);
})();

function paKasirSyncPrintModeFromProfile(){
    const sel = document.getElementById('paKasirProfile');
    const opt = sel ? sel.options[sel.selectedIndex] : null;
    const isGray = opt && String(opt.dataset.printGrayscale || '0') === '1';
    const hidden = document.getElementById('paKasirGrayscale');
    if(hidden) hidden.value = isGray ? '1' : '0';
    const paperType = opt ? String(opt.dataset.printerPaperType || 'plain') : 'plain';
    const paperHidden = document.getElementById('paKasirPrinterPaperType');
    if(paperHidden) paperHidden.value = (paperType === 'photo_glossy') ? 'photo_glossy' : 'plain';
    const label = document.getElementById('paKasirPrintModeLabel');
    const note = document.getElementById('paKasirPrintModeNote');
    const paperLabel = document.getElementById('paKasirPrinterPaperTypeLabel');
    if(label) label.innerHTML = isGray ? '<i class="fas fa-adjust me-1"></i>Grayscale' : '<i class="fas fa-palette me-1"></i>Warna';
    if(paperLabel) paperLabel.innerHTML = (paperType === 'photo_glossy') ? 'Photo Paper Glossy' : 'Plain Paper';
    if(note) note.innerHTML = (isGray ? 'Harga analyzer memakai mapping BW/Hitam Putih dan Print Klien otomatis aktif grayscale.' : 'Harga analyzer memakai kategori warna sesuai hasil deteksi, Print Klien tetap mode warna/default.') + ' Paper Type ikut sinkron ke Print Klien.';
}
function paKasirClearWaFiles(){
    const holder = document.getElementById('paKasirWaFilesHolder');
    if(holder) holder.innerHTML = '';
    const fileInput = document.getElementById('paKasirFiles');
    if(fileInput) fileInput.disabled = false;
}
function paKasirSetWaFiles(files){
    const holder = document.getElementById('paKasirWaFilesHolder');
    if(!holder) return;
    holder.innerHTML = '';
    (files || []).forEach(name => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'wa_files[]';
        input.value = name;
        holder.appendChild(input);
    });
}
function openPrintAnalyzerFromWhatsApp(filename, namaAsli, kodePelanggan='', namaPelanggan='', telp='', deposit=0, utang=0){
    openPrintAnalyzerFromWhatsAppMultiple([filename], [namaAsli || filename], kodePelanggan, namaPelanggan, telp, deposit, utang);
}

function openPrintAnalyzerFromWhatsAppMultiple(files, namaAsliList=[], kodePelanggan='', namaPelanggan='', telp='', deposit=0, utang=0){
    const fileList = Array.isArray(files) ? files.filter(Boolean).map(String) : [];
    const nameList = Array.isArray(namaAsliList) ? namaAsliList.map(String) : [];
    if (!fileList.length) {
        if (typeof Swal !== 'undefined') Swal.fire('File belum dipilih', 'Pilih minimal 1 file WhatsApp.', 'warning');
        return;
    }
    paKasirResetModal();
    try {
        let marked = JSON.parse(localStorage.getItem('wa_files_marked') || '[]');
        fileList.forEach(filename => { if(!marked.includes(filename)) marked.push(filename); });
        localStorage.setItem('wa_files_marked', JSON.stringify(marked));
        if(typeof updateMarkedFilesUI === 'function') updateMarkedFilesUI();
    } catch(e) {}
    paKasirSetWaFiles(fileList);
    const fileInput = document.getElementById('paKasirFiles');
    if(fileInput){ fileInput.value = ''; fileInput.disabled = true; }

    // Default otomatis untuk file dari WhatsApp: A4 HVS 70 1 sisi, qty 1, 1 page/sheet, semua halaman.
    paKasirSetDefaultDragDropA4Hvs70SatuSisi();
    const profileOk = !!document.getElementById('paKasirProfile')?.value;

    const label = fileList.length === 1 ? paKasirEscape(nameList[0] || fileList[0]) : `${fileList.length} file WhatsApp`;
    const listHtml = fileList.slice(0, 12).map((fn, i) => `<li>${paKasirEscape(nameList[i] || fn)}</li>`).join('') + (fileList.length > 12 ? `<li class="text-muted">+ ${fileList.length - 12} file lainnya</li>` : '');
    const footer = document.getElementById('paKasirFooterInfo');
    if(footer) footer.innerHTML = `File WhatsApp auto analyze: <b>${label}</b>. Default: <b>A4 / HVS 70 / 1 sisi / 1 page sheet</b>.`;
    const result = document.getElementById('paKasirResult');
    if(result) result.innerHTML = `<div class="alert alert-success mb-0"><div class="fw-bold"><i class="fab fa-whatsapp me-1"></i>Auto analyze ${fileList.length} file WhatsApp</div><ul class="small mt-2 mb-2 ps-3">${listHtml}</ul><div class="small text-muted mt-2">Setelan otomatis: A4, HVS 70, 1 sisi, Qty 1, 1 page/sheet. Setelah hasil keluar, Qty/Page sheet/Halaman tetap bisa diganti realtime tanpa analisis ulang.</div></div>`;
    const badge = document.getElementById('paKasirStatusBadge');
    if(badge){ badge.className = 'badge bg-success'; badge.innerText = 'WA auto analyze'; }

    if(kodePelanggan && typeof setPelangganAktif === 'function'){
        try{ setPelangganAktif(kodePelanggan, namaPelanggan || kodePelanggan, telp || '', parseFloat(deposit || 0), parseFloat(utang || 0)); }catch(e){}
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPrintAnalyzerKasir')).show();
    setTimeout(() => {
        if(profileOk && typeof paKasirAnalyzeFiles === 'function') paKasirAnalyzeFiles();
        else if(typeof Swal !== 'undefined') Swal.fire('Profil A4 HVS 70 1 SISI belum ditemukan', 'Buat/aktifkan profil harga A4 HVS 70 1 SISI non-Fotocopy dulu, atau pilih profil manual lalu klik Analisis.', 'warning');
    }, 450);
}

function paKasirLabel(cat){ return ({bw:'Hitam Putih', color_25:'Warna 25%', color_50:'Warna 50%', color_75:'Warna 75%', color_100:'Warna 100%'})[cat] || cat; }
function paKasirBadge(cat){ return ({bw:'secondary', color_25:'info', color_50:'primary', color_75:'warning', color_100:'danger'})[cat] || 'secondary'; }
function paKasirOrientationLabel(v){
    v = String(v || 'auto').toLowerCase();
    if(v === 'landscape') return 'Landscape';
    if(v === 'portrait') return 'Portrait';
    return 'Auto';
}

function paKasirEscape(v){ return String(v ?? '').replace(/[&<>'"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[s])); }
function paKasirSetLoading(isLoading){
    const btn = document.getElementById('btnPaKasirAnalyze');
    const add = document.getElementById('btnPaKasirAddCart');
    if(isLoading){
        btn.disabled = true; add.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Menganalisis...';
        document.getElementById('paKasirStatusBadge').className = 'badge bg-warning text-dark';
        document.getElementById('paKasirStatusBadge').innerText = 'Proses';
    } else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic me-1"></i> Analisis'; }
}
function paKasirSetRepriceLoading(isLoading){
    const btn = document.getElementById('btnPaKasirAnalyze');
    const add = document.getElementById('btnPaKasirAddCart');
    if(isLoading){
        if(btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-sync fa-spin me-1"></i> Hitung ulang...'; }
        if(add) add.disabled = true;
        const badge = document.getElementById('paKasirStatusBadge');
        if(badge){ badge.className = 'badge bg-info text-dark'; badge.innerText = 'Hitung ulang harga'; }
    } else {
        if(btn){ btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic me-1"></i> Analisis'; }
    }
}
let paKasirRepriceTimer = null;
let paKasirRepriceSeq = 0;

function paKasirCurrentRepriceFormData(){
    const fd = new FormData();
    fd.append('batch_token', paKasirLastResult?.batch_token || '');
    fd.append('price_profile_id', document.getElementById('paKasirProfile')?.value || '');
    fd.append('qty_cetak', document.getElementById('paKasirQty')?.value || paKasirLastResult?.qty_cetak || 1);
    fd.append('pages_per_sheet', document.getElementById('paKasirPagesPerSheet')?.value || paKasirLastResult?.pages_per_sheet || 1);
    fd.append('finishing', document.getElementById('paKasirFinishing')?.value || '');
    fd.append('halaman', document.getElementById('paKasirHalaman')?.value || '');
    fd.append('borderless', document.getElementById('paKasirBorderless')?.checked ? '1' : '0');
    fd.append('print_grayscale', document.getElementById('paKasirGrayscale')?.value === '1' ? '1' : '0');
    fd.append('printer_paper_type', document.getElementById('paKasirPrinterPaperType')?.value || 'plain');
    fd.append('orientation', document.getElementById('paKasirOrientation')?.value || paKasirLastResult?.orientation || 'auto');
    return fd;
}

function paKasirScheduleReprice(reason='input', delay=450){
    if(!paKasirLastResult || !paKasirLastResult.batch_token) return;
    const profileId = document.getElementById('paKasirProfile')?.value || '';
    if(!profileId) return;
    clearTimeout(paKasirRepriceTimer);
    const addBtn = document.getElementById('btnPaKasirAddCart');
    if(addBtn) addBtn.disabled = true;
    const badge = document.getElementById('paKasirStatusBadge');
    if(badge){ badge.className = 'badge bg-info text-dark'; badge.innerText = 'Menunggu hitung ulang'; }
    const info = document.getElementById('paKasirFooterInfo');
    if(info) info.innerText = 'Perubahan terdeteksi. Total akan dihitung ulang otomatis tanpa analisis file ulang...';
    paKasirRepriceTimer = setTimeout(() => paKasirRepriceLive(reason), delay);
}

async function paKasirRepriceLive(reason='input'){
    if(!paKasirLastResult || !paKasirLastResult.batch_token) return;
    const profileId = document.getElementById('paKasirProfile')?.value || '';
    if(!profileId) return;
    const seq = ++paKasirRepriceSeq;
    const fd = paKasirCurrentRepriceFormData();
    paKasirSetRepriceLoading(true);
    const info = document.getElementById('paKasirFooterInfo');
    if(info) info.innerText = 'Menghitung ulang qty/page sheet/halaman dari hasil analisis yang sudah ada...';
    try{
        const res = await fetch('../print_analyzer/modal_reprice.php', { method:'POST', body:fd });
        const data = await res.json();
        if(seq !== paKasirRepriceSeq) return;
        if(!res.ok || data.status !== 'success') throw new Error(data.message || 'Hitung ulang harga gagal.');
        paKasirLastResult = data;
        paKasirRenderResult(data);
        document.getElementById('btnPaKasirAddCart').disabled = false;
        document.getElementById('paKasirStatusBadge').className = 'badge bg-success';
        document.getElementById('paKasirStatusBadge').innerText = 'Harga diperbarui';
        document.getElementById('paKasirFooterInfo').innerText = 'Qty/rangkap, page/sheet, halaman, dan setting print sudah diperbarui tanpa analisis ulang.';
    }catch(err){
        if(seq !== paKasirRepriceSeq) return;
        document.getElementById('paKasirStatusBadge').className = 'badge bg-danger';
        document.getElementById('paKasirStatusBadge').innerText = 'Gagal hitung ulang';
        document.getElementById('paKasirFooterInfo').innerText = 'Gagal menghitung ulang harga. Cek input halaman atau klik Analisis ulang.';
        Swal.fire('Gagal', err.message, 'error');
    }finally{
        if(seq === paKasirRepriceSeq) paKasirSetRepriceLoading(false);
    }
}

function paKasirRepriceFromProfile(){
    return paKasirRepriceLive('profil');
}

function paKasirRenderQueueProgress(data){
    const realPercent = Math.max(0, Math.min(100, parseInt(data?.percent || 0, 10)));
    const status = data?.batch_status || 'processing';
    paKasirSmoothProgressLastData = {
        percent: realPercent,
        batch_status: status,
        done_files: parseInt(data?.done_files || 0, 10) || 0,
        total_files: parseInt(data?.total_files || 0, 10) || 0
    };
    const targetPercent = paKasirComputeSmoothTarget(realPercent, status);
    if(paKasirSmoothProgressShown <= 0) paKasirSmoothProgressShown = Math.max(1, Math.min(targetPercent, 3));
    const files = Array.isArray(data?.files) ? data.files : [];
    const rows = files.map(f => {
        let badge = 'secondary';
        let label = f.status || 'queued';
        if(f.status === 'done'){ badge='success'; label='selesai'; }
        else if(f.status === 'processing'){ badge='warning text-dark'; label='proses'; }
        else if(f.status === 'failed'){ badge='danger'; label='gagal'; }
        else { label='menunggu'; }
        const msg = f.error_message || f.progress_message || '';
        return `<div class="d-flex justify-content-between gap-2 border-bottom py-2 align-items-start">
            <div class="min-w-0"><div class="fw-bold text-truncate">${paKasirEscape(f.original_filename || 'file')}</div><div class="small text-muted">${paKasirEscape(msg)}</div></div>
            <span class="badge bg-${badge}">${label}</span>
        </div>`;
    }).join('');
    const shownNow = Math.max(1, Math.min(100, paKasirSmoothProgressShown || 1));
    const statusText = status === 'done' ? 'Menyelesaikan hasil...' : (status === 'queued' ? 'Menyiapkan antrian...' : 'Analyzer masih berjalan...');
    document.getElementById('paKasirResult').innerHTML = `
        <div class="p-3">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="spinner-border text-primary"></div>
                <div>
                    <div class="fw-bold">Analyzer berjalan di background</div>
                    <div class="small text-muted">Progress mengikuti estimasi waktu proses, jadi tetap bergerak meski server masih memproses file.</div>
                </div>
            </div>
            <div class="pa-smooth-progress-wrap mb-2" aria-label="Progress analyzer">
                <div class="pa-smooth-progress-bar" id="paKasirSmoothProgressBar" style="width:${shownNow.toFixed(2)}%"><span class="pa-smooth-progress-percent" id="paKasirSmoothProgressPercent">${Math.round(shownNow)}%</span></div>
            </div>
            <div class="d-flex justify-content-between flex-wrap gap-1 mb-3">
                <div class="small text-muted">${paKasirEscape(data?.current_message || statusText)} · ${parseInt(data?.done_files || 0,10)}/${parseInt(data?.total_files || 0,10)} file selesai</div>
                <div class="pa-smooth-progress-pulse">Estimasi ${paKasirFormatEstimate(paKasirSmoothProgressEstimateSeconds)} · server ${realPercent}%</div>
            </div>
            <div class="border rounded-3 bg-white px-3">${rows || '<div class="text-muted py-3">Menunggu data file...</div>'}</div>
        </div>`;
    paKasirSetSmoothProgressTarget(targetPercent);
    const badge = document.getElementById('paKasirStatusBadge');
    if(badge){ badge.className = 'badge bg-warning text-dark'; badge.innerText = 'Background ' + Math.round(Math.max(shownNow, targetPercent)) + '%'; }
    const info = document.getElementById('paKasirFooterInfo');
    if(info) info.innerText = data?.current_message || 'Analyzer background sedang berjalan...';
}

async function paKasirFetchJson(url, options={}){
    const res = await fetch(url, options);
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); }
    catch(e){ throw new Error('Balasan server bukan JSON: ' + txt.slice(0, 240)); }
    if(!res.ok || data.status === 'error') throw new Error(data.message || 'Request gagal.');
    return data;
}

async function paKasirProcessQueueUntilDone(batchToken){
    let safety = 0;
    while(safety++ < 200){
        const fd = new FormData();
        fd.append('batch_token', batchToken);
        const data = await paKasirFetchJson('../print_analyzer/modal_queue_process.php', { method:'POST', body:fd });
        paKasirRenderQueueProgress(data);
        if(data.batch_status === 'done'){
            paKasirStopEstimatedProgress();
            paKasirSetSmoothProgressTarget(100);
            await new Promise(resolve => setTimeout(resolve, 320));
            const result = data.result || (await paKasirFetchJson('../print_analyzer/modal_queue_status.php?include_result=1&batch_token=' + encodeURIComponent(batchToken))).result;
            if(!result) throw new Error('Hasil analyzer belum tersedia.');
            paKasirLastResult = result;
            paKasirRenderResult(result);
            document.getElementById('btnPaKasirAddCart').disabled = false;
            document.getElementById('paKasirStatusBadge').className = 'badge bg-success';
            document.getElementById('paKasirStatusBadge').innerText = 'Selesai';
            document.getElementById('paKasirFooterInfo').innerText = `${result.summary.total_files} file siap ditambahkan ke keranjang.`;
            return;
        }
        if(data.batch_status === 'failed'){
            paKasirStopEstimatedProgress();
            throw new Error(data.error_message || data.current_message || 'Ada file yang gagal dianalisis.');
        }
        await new Promise(resolve => setTimeout(resolve, 350));
    }
    throw new Error('Analyzer terlalu lama berjalan. Coba cek halaman riwayat/antrian atau analisis ulang.');
}

async function paKasirAnalyzeFiles(){
    const form = document.getElementById('formPrintAnalyzerKasir');
    const files = document.getElementById('paKasirFiles').files;
    const waFiles = document.querySelectorAll('#paKasirWaFilesHolder input[name="wa_files[]"]');
    if((!files || files.length === 0) && (!waFiles || waFiles.length === 0)){ Swal.fire('File belum dipilih', 'Pilih minimal 1 file atau pilih file dari WhatsApp.', 'warning'); return; }
    if(!document.getElementById('paKasirProfile').value){ Swal.fire('Profil harga belum dipilih', 'Pilih profil harga terlebih dahulu.', 'warning'); return; }
    const fd = new FormData(form);
    paKasirLastResult = null;
    paKasirResetSmoothProgress();
    document.getElementById('btnPaKasirAddCart').disabled = true;
    document.getElementById('paKasirResult').innerHTML = `<div class="text-center text-muted py-5"><div class="spinner-border text-primary mb-3"></div><div class="fw-bold">Memasukkan file ke antrian analyzer...</div><small>Proses berat akan berjalan terpisah agar kasir tidak macet.</small></div>`;
    paKasirSetLoading(true);
    try{
        const startData = await paKasirFetchJson('../print_analyzer/modal_queue_start.php', { method:'POST', body:fd });
        paKasirPrepareSmoothEstimate(startData);
        paKasirRenderQueueProgress({
            batch_token: startData.batch_token,
            batch_status: 'queued',
            total_files: startData.total_files || 0,
            done_files: 0,
            failed_files: 0,
            percent: 0,
            current_message: startData.message || 'Antrian dibuat.',
            files: []
        });
        paKasirStartEstimatedProgress();
        await paKasirProcessQueueUntilDone(startData.batch_token);
    }catch(err){
        paKasirStopEstimatedProgress();
        document.getElementById('paKasirResult').innerHTML = `<div class="alert alert-danger mb-0"><b>Analisis gagal.</b><br>${paKasirEscape(err.message)}</div>`;
        document.getElementById('paKasirStatusBadge').className = 'badge bg-danger';
        document.getElementById('paKasirStatusBadge').innerText = 'Gagal';
        document.getElementById('paKasirFooterInfo').innerText = 'Analisis gagal. Kasir tetap bisa digunakan.';
        Swal.fire('Gagal', err.message, 'error');
    }finally{ paKasirSetLoading(false); }
}

function paKasirCategoryOptions(selected){
    const cats = {bw:'Hitam Putih', color_25:'Warna 25%', color_50:'Warna 50%', color_75:'Warna 75%', color_100:'Warna 100%'};
    return Object.entries(cats).map(([k,v]) => `<option value="${k}" ${selected===k?'selected':''}>${v}</option>`).join('');
}
function paKasirRenderResult(data){
    const s = data.summary;
    let html = `
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">File</small><b>${s.total_files}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Halaman</small><b>${s.total_pages}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Qty Cetak</small><b>${data.qty_cetak}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Page/sheet</small><b>${data.pages_per_sheet || 1}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Orientasi</small><b>${paKasirOrientationLabel(data.orientation || 'auto')}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded ${data.borderless ? 'bg-warning bg-opacity-10 border border-warning border-opacity-25' : 'bg-light border'}"><small class="text-muted d-block">Borderless</small><b>${data.borderless ? 'Ya' : 'Tidak'}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded ${data.print_grayscale ? 'bg-secondary bg-opacity-10 border border-secondary border-opacity-25' : 'bg-light border'}"><small class="text-muted d-block">Print Grayscale</small><b>${data.print_grayscale ? 'Ya' : 'Tidak'}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Paper Type</small><b>${data.printer_paper_type === 'photo_glossy' ? 'Photo Paper Glossy' : 'Plain Paper'}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Sheet</small><b>${s.total_sheets || 0}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-light border"><small class="text-muted d-block">Subtotal</small><b>${formatRupiah(s.gross_total || s.estimated_total)}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-danger bg-opacity-10 border border-danger border-opacity-25"><small class="text-muted d-block">Potongan 2 sisi</small><b class="text-danger">-${formatRupiah(s.duplex_discount_total || 0)}</b></div></div>
        <div class="col-6 col-md-3"><div class="p-2 rounded bg-success bg-opacity-10 border border-success border-opacity-25"><small class="text-muted d-block">Estimasi Akhir</small><b class="text-success">${formatRupiah(s.estimated_total)}</b></div></div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="pa-modal-summary-pill">Sheet BW: <b>${s.bw}</b></span>
        <span class="pa-modal-summary-pill">Sheet 25%: <b>${s.color_25}</b></span>
        <span class="pa-modal-summary-pill">Sheet 50%: <b>${s.color_50}</b></span>
        <span class="pa-modal-summary-pill">Sheet 75%: <b>${s.color_75}</b></span>
        <span class="pa-modal-summary-pill">Sheet 100%: <b>${s.color_100}</b></span>
    </div>`;
    html += `<div class="accordion" id="paKasirAccordion">`;
    data.jobs.forEach((job, idx) => {
        let rows = job.rows.length ? job.rows.map(r => `<tr><td><span class="badge bg-${paKasirBadge(r.category)}">${paKasirEscape(r.label)}</span></td><td class="text-end">${r.pages}</td><td class="text-end fw-bold">${r.sheets}</td><td class="text-end">${r.qty}</td><td>${paKasirEscape(r.nama_barang)}<div class="text-muted small">Harga dihitung per sheet</div></td><td class="text-end">${formatRupiah(r.subtotal)}</td></tr>`).join('') : `<tr><td colspan="6" class="text-muted text-center">Tidak ada halaman terhitung.</td></tr>`;
        let sheets = (job.sheets || []).map(sh => {
            const sheetKey = `${job.id}_${sh.sheet_number}`;
            const pageIds = (sh.page_ids || (sh.pages || []).map(p => p.id)).join(',');
            let pageCells = (sh.pages || []).map(p => `<div class="pa-sheet-page" id="paPageCard${p.id}">
                <img loading="lazy" src="${paKasirEscape(p.preview_url)}" alt="Halaman ${p.page_number}">
                <div class="pa-sheet-page-body">
                    <div class="d-flex justify-content-between align-items-center mb-1"><b>Hal. ${p.page_number}</b>${Number(p.overridden)===1?'<span class="badge bg-warning text-dark">Manual</span>':''}</div>
                    <div class="pa-modal-mini text-muted">Warna ${p.colored_pixel_percentage}% · Isi ${p.nonwhite_pixel_percentage}%</div>
                </div>
            </div>`).join('');
            const blankCount = Math.max(0, parseInt(sh.pages_per_sheet || 1, 10) - parseInt(sh.pages_count || 0, 10));
            for(let i=0;i<blankCount;i++){ pageCells += `<div class="pa-sheet-page-empty">Kosong</div>`; }
            return `<div class="pa-sheet-card" id="paSheetCard${sheetKey}">
                <div class="pa-sheet-head flex-wrap">
                    <div><b>Sheet ${sh.sheet_number}</b><div class="small text-muted">${sh.pages_count}/${sh.pages_per_sheet} page dalam 1 sheet</div></div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <select class="form-select form-select-sm" style="width:150px" id="paSheetCat${sheetKey}">${paKasirCategoryOptions(sh.category || 'bw')}</select>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="paKasirSaveSheetCategory(${job.id}, ${sh.sheet_number}, '${pageIds}')"><i class="fas fa-save me-1"></i>Simpan Sheet</button>
                    </div>
                </div>
                <div class="pa-sheet-pages pps-${sh.pages_per_sheet}">${pageCells}</div>
            </div>`;
        }).join('');
        html += `<div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button ${idx>0?'collapsed':''} py-2" type="button" data-bs-toggle="collapse" data-bs-target="#paFile${job.id}"><div class="d-flex justify-content-between w-100 me-2 gap-2"><span class="fw-bold pa-modal-file-title">${paKasirEscape(job.file_name)}</span><span class="text-muted small text-nowrap">${job.total_pages} hlm • ${job.sheet_count || 0} sheet • ${formatRupiah(job.estimated_total)}${job.duplex_discount_total > 0 ? ' • potongan ' + formatRupiah(job.duplex_discount_total) : ''}${job.borderless ? ' • borderless' : ''}${job.orientation ? ' • ' + paKasirOrientationLabel(job.orientation).toLowerCase() : ''}</span></div></button></h2>
            <div id="paFile${job.id}" class="accordion-collapse collapse ${idx===0?'show':''}" data-bs-parent="#paKasirAccordion">
                <div class="accordion-body p-2">
                    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Kategori</th><th class="text-end">Hlm</th><th class="text-end">Sheet</th><th class="text-end">Qty</th><th>Produk/Jasa</th><th class="text-end">Subtotal</th></tr></thead><tbody>${rows}</tbody></table></div>
                    <div class="fw-bold mb-1"><i class="fas fa-images me-1"></i>Preview sesuai page/sheet & koreksi per sheet</div>
                    <div class="alert alert-info py-2 small mb-2"><i class="fas fa-info-circle me-1"></i>Harga dihitung <b>per sheet</b>. Koreksi warna juga dilakukan <b>per sheet</b>; semua halaman di dalam sheet akan mengikuti kategori sheet yang Anda pilih.</div>
                    <div class="pa-modal-preview-grid">${sheets}</div>
                </div>
            </div>
        </div>`;
    });
    html += `</div>`;
    document.getElementById('paKasirResult').innerHTML = html;
}
async function paKasirSaveSheetCategory(jobId, sheetNumber, pageIdsCsv){
    if(!paKasirLastResult){ Swal.fire('Belum ada hasil', 'Analisis file terlebih dahulu.', 'warning'); return; }
    const sheetKey = `${jobId}_${sheetNumber}`;
    const sel = document.getElementById('paSheetCat' + sheetKey);
    const btnCard = document.getElementById('paSheetCard' + sheetKey);
    const fd = new FormData();
    fd.append('job_id', jobId);
    fd.append('page_ids', pageIdsCsv);
    fd.append('category', sel.value);
    fd.append('qty_cetak', document.getElementById('paKasirQty').value || paKasirLastResult.qty_cetak || 1);
    fd.append('pages_per_sheet', document.getElementById('paKasirPagesPerSheet').value || paKasirLastResult.pages_per_sheet || 1);
    fd.append('finishing', document.getElementById('paKasirFinishing').value || '');
    fd.append('halaman', document.getElementById('paKasirHalaman').value || '');
    fd.append('borderless', document.getElementById('paKasirBorderless')?.checked ? '1' : '0');
    fd.append('print_grayscale', document.getElementById('paKasirGrayscale')?.value === '1' ? '1' : '0');
    fd.append('printer_paper_type', document.getElementById('paKasirPrinterPaperType')?.value || 'plain');
    fd.append('orientation', document.getElementById('paKasirOrientation')?.value || (paKasirLastResult.orientation || 'auto'));
    if(btnCard) btnCard.style.opacity = '.55';
    try{
        const res = await fetch('../print_analyzer/modal_override.php', { method:'POST', body:fd });
        const data = await res.json();
        if(!res.ok || data.status !== 'success') throw new Error(data.message || 'Koreksi sheet gagal disimpan.');
        paKasirLastResult = data;
        paKasirRenderResult(data);
        document.getElementById('btnPaKasirAddCart').disabled = false;
        document.getElementById('paKasirFooterInfo').innerText = 'Koreksi sheet tersimpan. Total dan keranjang sudah diperbarui.';
    }catch(err){
        if(btnCard) btnCard.style.opacity = '1';
        Swal.fire('Gagal', err.message, 'error');
    }
}
function paKasirAddToCart(){
    if(!paKasirLastResult || !Array.isArray(paKasirLastResult.cart_items) || paKasirLastResult.cart_items.length === 0){ Swal.fire('Belum ada hasil', 'Analisis file terlebih dahulu.', 'warning'); return; }
    const currentQty = parseInt(document.getElementById('paKasirQty').value || '1', 10);
    const currentPagesPerSheet = parseInt(document.getElementById('paKasirPagesPerSheet').value || '1', 10);
    const currentOrientation = document.getElementById('paKasirOrientation')?.value || 'auto';
    const currentGrayscale = document.getElementById('paKasirGrayscale')?.value === '1' ? 1 : 0;
    if(currentQty !== parseInt(paKasirLastResult.qty_cetak || '1', 10) || currentPagesPerSheet !== parseInt(paKasirLastResult.pages_per_sheet || '1', 10) || currentOrientation !== String(paKasirLastResult.orientation || 'auto') || currentGrayscale !== parseInt(paKasirLastResult.print_grayscale || '0', 10) || (document.getElementById('paKasirPrinterPaperType')?.value || 'plain') !== String(paKasirLastResult.printer_paper_type || 'plain')){
        Swal.fire('Input berubah', 'Qty/rangkap, page/sheet, orientasi, grayscale, atau paper type berubah setelah analisis. Klik Analisis ulang atau simpan koreksi salah satu halaman agar total diperbarui.', 'warning');
        return;
    }
    paKasirLastResult.cart_items.forEach(item => { item.from_analyzer = true; keranjang.push(item); });
    const duplexDiscount = parseFloat(paKasirLastResult.summary?.duplex_discount_total || 0) || 0;
    if (duplexDiscount > 0) {
        const diskonInput = document.getElementById('inputDiskonGlobal');
        if (diskonInput) {
            const diskonSekarang = parseFloat(diskonInput.value || '0') || 0;
            diskonInput.value = diskonSekarang + duplexDiscount;
        }
    }
    simpanStateKeranjang();
    renderCart();
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalPrintAnalyzerKasir'));
    if(modal) modal.hide();
    Swal.fire({icon:'success', title:'Masuk Keranjang', text:`${paKasirLastResult.cart_items.length} baris item dari analyzer ditambahkan${(paKasirLastResult.summary?.duplex_discount_total || 0) > 0 ? ' + potongan 2 sisi ' + formatRupiah(paKasirLastResult.summary.duplex_discount_total) : ''}.`, timer:1800, showConfirmButton:false});
    paKasirResetModal();
}
function paKasirResetModal(){
    document.getElementById('formPrintAnalyzerKasir').reset();
    paKasirClearWaFiles();
    paKasirLastResult = null;
    document.getElementById('btnPaKasirAddCart').disabled = true;
    document.getElementById('paKasirStatusBadge').className = 'badge bg-secondary';
    document.getElementById('paKasirStatusBadge').innerText = 'Belum dianalisis';
    document.getElementById('paKasirFooterInfo').innerText = 'Hasil analyzer belum masuk keranjang.';
    document.getElementById('paKasirResult').innerHTML = `<div class="text-center text-muted py-5"><i class="fas fa-file-upload fa-3x opacity-25 mb-3"></i><div class="fw-bold">Pilih file lalu klik Analisis</div><small>Hasil per sheet, kategori warna, dan total harga akan ditampilkan di sini.</small></div>`;
    paKasirSyncPrintModeFromProfile();
}
const paKasirRealtimeIds = ['paKasirQty','paKasirPagesPerSheet','paKasirHalaman','paKasirOrientation','paKasirFinishing','paKasirBorderless','paKasirPrinterPaperType'];

document.addEventListener('change', function(e){
    if(!e.target) return;
    if(e.target.id === 'paKasirProfile'){
        paKasirSyncPrintModeFromProfile();
        if(paKasirLastResult){ paKasirScheduleReprice('profil harga', 120); }
        return;
    }
    if(paKasirRealtimeIds.includes(e.target.id) && paKasirLastResult){
        paKasirScheduleReprice(e.target.id, 250);
    }
});

document.addEventListener('input', function(e){
    if(!e.target || !paKasirLastResult) return;
    if(['paKasirQty','paKasirHalaman','paKasirFinishing'].includes(e.target.id)){
        paKasirScheduleReprice(e.target.id, 550);
    }
});
</script>
