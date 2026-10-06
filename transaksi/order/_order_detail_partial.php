                <div class="col-md-8 col-lg-9 col-detail">
                    <?php if($sel): ?>
                    
                    <div class="bg-white p-3 rounded-3 shadow-sm border-0 mb-3">
                        <div class="row align-items-center g-3">
                            
                            <div class="col-12 col-sm-7 col-md-7 col-lg-8" style="min-width: 0;">
                                <div class="text-muted small fw-bold mb-1 text-uppercase">Detail Orderan</div>
                                <h4 class="fw-bolder text-dark mb-0 text-truncate" title="<?= htmlspecialchars($sel['nama_pelanggan']??'UMUM') ?>">
                                    <?= htmlspecialchars($sel['nama_pelanggan']??'UMUM') ?>
                                </h4>
                                <span class="text-primary fw-bold d-inline-block mt-2 bg-primary bg-opacity-10 px-2 py-1 rounded" style="font-size: 0.85rem;">
                                    <i class="fas fa-receipt me-1"></i><?= $sel['no_penjualan'] ?>
                                </span>
                            </div>
                            
                            <div class="col-12 col-sm-5 col-md-5 col-lg-4">
                                <div class="d-flex align-items-center gap-2 bg-light p-2 rounded-3 border justify-content-sm-end h-100">
                                    <form method="POST" class="m-0 w-100" id="formOrderStatus" data-no="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
                                        <select name="status_order" id="statusOrderSelect" class="form-select border-0 shadow-sm fw-bold w-100" style="cursor:pointer;">
                                            <option value="Antri" <?= $st_aktif=='Antri'?'selected':'' ?>>⏳ Antri</option>
                                            <option value="Proses" <?= $st_aktif=='Proses'?'selected':'' ?>>⚙️ Proses</option>
                                            <option value="Selesai" <?= $st_aktif=='Selesai'?'selected':'' ?>>✅ Selesai</option>
                                        </select>
                                        <input type="hidden" name="update_status_order" value="1">
                                    </form>
                                    
                                    <?php if($has_wa): ?>
                                        <button class="btn btn-success shadow-sm flex-shrink-0" onclick="kirimWaAjax('<?= $id ?>')" title="Kirim Notif WA">
                                            <i class="fab fa-whatsapp fs-5"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary opacity-50 shadow-sm flex-shrink-0" title="No WA Tidak Ada" disabled>
                                            <i class="fab fa-whatsapp fs-5"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                        </div>
                    </div>

                    <div class="bg-white p-3 rounded-3 shadow-sm border-0 mb-3 d-flex align-items-center gap-3">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="fw-bold text-muted text-uppercase"><i class="fas fa-chart-line text-primary me-1"></i> Total Progress Kerja</small>
                                <span class="badge bg-success rounded-pill px-2 py-1" id="lblPersen"><?= $progress_pct ?>%</span>
                            </div>
                            <div class="progress shadow-sm border" style="height: 12px; border-radius: 10px; background-color: #e9ecef;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="progressBar" style="width: <?= $progress_pct ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <?php 
                        // Hitung kerjaan yang belum selesai untuk ditampilkan di Tab
                        $unfinished_tasks = $tot_tasks - $done_tasks; 
                        $unfinished_files = $tot_files - $done_files; 
                    ?>

                        <ul class="nav nav-pills mb-3 bg-white p-2 rounded-3 shadow-sm border" id="pills-tab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active fw-bold d-flex align-items-center" id="pills-file-tab" data-bs-toggle="pill" data-bs-target="#pills-file" type="button" role="tab" aria-selected="true">
                                    <i class="fas fa-print me-2"></i> File Produksi
                                    <?php if($unfinished_files > 0): ?>
                                        <span class="badge bg-danger ms-2 rounded-pill" id="badge-tab-file"><?= $unfinished_files ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item ms-2" role="presentation">
                                <button class="nav-link fw-bold d-flex align-items-center" id="pills-progress-tab" data-bs-toggle="pill" data-bs-target="#pills-progress" type="button" role="tab" aria-selected="false">
                                    <i class="fas fa-check-double me-2"></i> Tugas Finishing
                                    <?php if($unfinished_tasks > 0): ?>
                                        <span class="badge bg-danger ms-2 rounded-pill" id="badge-tab-task"><?= $unfinished_tasks ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="pills-tabContent">
                            
                            <div class="tab-pane fade show active" id="pills-file" role="tabpanel">
                                <div class="card shadow-sm border-0 rounded-3 mb-3">
                                    <div class="card-header bg-white fw-bolder py-3 d-flex justify-content-between align-items-center">
                                        <span class="text-dark"><i class="fas fa-print text-primary me-2"></i>Daftar File & Upload</span>
                                    </div>
                                    
                                    <div class="table-responsive bg-white" id="live-file-list" style="max-height: 400px; overflow-y:auto;">
                                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                            <thead class="table-light text-muted" style="position: sticky; top: 0; z-index: 10;">
                                                <tr>
                                                    <th class="ps-4 border-0">Nama File</th>
                                                    <th class="border-0">Spesifikasi Cetak</th>
                                                    <th class="border-0">Finishing & Hal</th>
                                                    <th class="text-center border-0">Status</th>
                                                    <th class="text-center pe-4 border-0">Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if(empty($files)): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center py-5 text-muted">
                                                            <i class="fas fa-file-excel fa-2x opacity-25 mb-2"></i>
                                                            <div class="small fw-bold">Belum ada file desain</div>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach($files as $f): 
                                                        $ext = strtolower(pathinfo($f['path_file'], PATHINFO_EXTENSION));
                                                        $is_done = ($f['status_baca'] == 'Sudah');
                                                        $cur_count = (int)$f['printed_count'];
                                                        $target = max(1, (int)$f['qty_cetak']);
                                                        $status_baca_text = trim((string)($f['status_baca'] ?? ''));
                                                        if ($is_done) {
                                                            $badge_html = '<span class="badge bg-success rounded-pill" style="font-size:0.7rem;">Selesai ('.$cur_count.'/'.$target.')</span>';
                                                        } elseif (strcasecmp($status_baca_text, 'Proses Print Halaman Genap') === 0 || stripos($status_baca_text, 'Genap') !== false) {
                                                            $badge_html = '<span class="badge bg-info text-dark rounded-pill" style="font-size:0.7rem;">Proses print halaman genap</span>';
                                                        } elseif (strcasecmp($status_baca_text, 'Proses Print Halaman Ganjil') === 0 || stripos($status_baca_text, 'Ganjil') !== false) {
                                                            $badge_html = '<span class="badge bg-info text-dark rounded-pill" style="font-size:0.7rem;">Proses print halaman ganjil</span>';
                                                        } elseif ($cur_count > 0) {
                                                            $badge_html = '<span class="badge bg-warning text-dark rounded-pill" style="font-size:0.7rem;">Diprint ('.$cur_count.'/'.$target.')</span>';
                                                        } else {
                                                            $badge_html = '<span class="badge bg-light text-secondary border rounded-pill" style="font-size:0.7rem;">Belum Cetak</span>';
                                                        }
                                                        $file_id_safe = md5($f['path_file']); 
                                                        $nama_file_tampil = !empty($f['nama_file']) ? $f['nama_file'] : (!empty($f['path_file']) ? basename(str_replace('\\', '/', $f['path_file'])) : 'File_Cetak.'.$ext);
                                                        $file_url = pa_file_url($f['path_file']);
                                                        $file_url_attr = htmlspecialchars($file_url, ENT_QUOTES);
                                                        $page_count_for_print = pa_effective_page_count($f);
                                                    ?>
                                                    <tr>
                                                        <td class="ps-4" style="max-width: 250px;">
                                                            <div class="fw-bolder text-primary text-wrap" style="word-break: break-word; line-height: 1.3;">
                                                                <i class="fas fa-file-image text-muted me-1 opacity-50"></i><?= htmlspecialchars($nama_file_tampil) ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-column gap-1 align-items-start">
                                                                <span class="badge bg-light text-dark border px-2 py-1"><i class="fas fa-scroll opacity-50 me-1"></i><?= $f['jenis_kertas'] ?></span>
                                                                <span class="text-muted fw-bold" style="font-size: 0.75rem;"><i class="fas fa-expand opacity-50 me-1"></i><?= $f['ukuran_kertas'] ?> &bull; <i class="fas fa-copy opacity-50 ms-1 me-1"></i><?= $target ?> Lbr</span>
                                                                <span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.7rem;"><i class="fas fa-columns me-1 opacity-50"></i><?= (int)($f['page_per_sheet'] ?? 1) ?> page/sheet &bull; <?= strtolower((string)($f['print_orientation'] ?? 'auto')) === 'landscape' ? 'Landscape' : (strtolower((string)($f['print_orientation'] ?? 'auto')) === 'portrait' ? 'Portrait' : 'Auto') ?></span>
                                                                <?php if(!empty($f['print_grayscale'])): ?><span class="badge bg-secondary px-2 py-1" style="font-size:0.7rem;"><i class="fas fa-adjust me-1"></i>Grayscale</span><?php endif; ?>
                                                                <span class="badge bg-light text-dark border px-2 py-1" style="font-size:0.7rem;"><i class="fas fa-print me-1 opacity-50"></i><?= (($f['printer_paper_type'] ?? 'plain') === 'photo_glossy') ? 'Photo Paper Glossy' : 'Plain Paper' ?></span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-column gap-1 align-items-start">
                                                                <span class="badge bg-info text-dark px-2 py-1" style="font-size: 0.7rem;"><i class="fas fa-layer-group me-1"></i><?= htmlspecialchars($f['finishing'] ?? '1 Sisi') ?></span>
                                                                <span class="badge bg-secondary px-2 py-1" style="font-size: 0.7rem;"><i class="fas fa-file-alt me-1"></i>Hal: <?= htmlspecialchars($f['halaman'] ?? 'All') ?></span>
                                                                <?php if($page_count_for_print > 0): ?>
                                                                    <span class="badge bg-primary px-2 py-1" style="font-size: 0.7rem;"><i class="fas fa-list-ol me-1"></i>Total: <?= $page_count_for_print ?> hal</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td class="text-center" id="badge-file-<?= $file_id_safe ?>">
                                                            <?= $badge_html ?>
                                                            <?php if(!empty($f['last_print_client_name']) || !empty($f['last_print_printer_name']) || !empty($f['last_print_status'])): ?>
                                                                <div class="mt-1 small text-muted lh-sm" style="font-size:0.68rem;" id="print-info-<?= $file_id_safe ?>">
                                                                    <?php if(!empty($f['last_print_status'])): ?>
                                                                        <?php $lpStatus = strtolower((string)$f['last_print_status']); ?>
                                                                        <?php if($lpStatus === 'failed'): ?>
                                                                            <span class="badge bg-danger mb-1"><i class="fas fa-times-circle me-1"></i>Print gagal</span><br>
                                                                        <?php elseif($lpStatus === 'processing'): ?>
                                                                            <span class="badge bg-info text-dark mb-1"><i class="fas fa-spinner me-1"></i>Sedang print</span><br>
                                                                        <?php elseif($lpStatus === 'pending'): ?>
                                                                            <span class="badge bg-secondary mb-1"><i class="fas fa-clock me-1"></i>Menunggu client</span><br>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-success mb-1"><i class="fas fa-check-circle me-1"></i>Print berhasil</span><br>
                                                                        <?php endif; ?>
                                                                    <?php endif; ?>
                                                                    <i class="fas fa-desktop me-1"></i><?= htmlspecialchars($f['last_print_client_name'] ?: '-') ?>
                                                                    <?php if(!empty($f['last_print_printer_name'])): ?>
                                                                        <br><i class="fas fa-print me-1"></i><?= htmlspecialchars($f['last_print_printer_name']) ?>
                                                                    <?php endif; ?>
                                                                    <?php if(!empty($f['last_print_page_data'])): ?>
                                                                        <br><i class="fas fa-file-alt me-1"></i><?= htmlspecialchars(strlen((string)$f['last_print_page_data']) > 90 ? substr((string)$f['last_print_page_data'], 0, 90) . '...' : (string)$f['last_print_page_data']) ?>
                                                                    <?php endif; ?>
                                                                    <?php if(!empty($f['last_print_error'])): ?>
                                                                        <br><span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars(strlen((string)$f['last_print_error']) > 90 ? substr((string)$f['last_print_error'], 0, 90) . '...' : (string)$f['last_print_error']) ?></span>
                                                                    <?php endif; ?>
                                                                    <?php if(!empty($f['last_print_at'])): ?>
                                                                        <br><i class="fas fa-clock me-1"></i><?= date('d/m H:i', strtotime($f['last_print_at'])) ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center pe-4">
                                                            <div class="d-flex gap-1 justify-content-center">
                                                                <?php if(in_array($ext, ['jpg','jpeg','png','pdf','doc','docx'])): ?>
                                                                    <button class="btn btn-sm btn-light border text-primary shadow-sm" onclick="previewFile('<?= $file_url_attr ?>', '<?= $ext ?>')" title="Lihat Desain"><i class="fas fa-eye"></i></button>
                                                                <?php endif; ?>
                                                                <button type="button" class="btn btn-sm <?= $is_done ? 'btn-warning text-dark' : 'btn-dark' ?> shadow-sm btn-agent-print" onclick="openPrintAgentModal(<?= (int)$f['id'] ?>, '<?= htmlspecialchars(addslashes($nama_file_tampil), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($id), ENT_QUOTES) ?>', '<?= $file_id_safe ?>', '<?= htmlspecialchars(addslashes($f['path_file']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($f['finishing'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($f['print_orientation'] ?? 'auto'), ENT_QUOTES) ?>', <?= (int)$target ?>, <?= (int)$cur_count ?>, <?= (int)$page_count_for_print ?>, '<?= htmlspecialchars(addslashes($f['halaman'] ?? 'All'), ENT_QUOTES) ?>', <?= !empty($f['print_grayscale']) ? 1 : 0 ?>, '<?= htmlspecialchars(addslashes($f['printer_paper_type'] ?? 'plain'), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($f['ukuran_kertas'] ?? ''), ENT_QUOTES) ?>', this)" title="<?= $is_done ? 'Reprint file ini' : 'Print langsung via Print Agent' ?>">
                                                                    <i class="fas <?= $is_done ? 'fa-redo' : 'fa-print' ?>"></i>
                                                                </button>
                                                                <a href="<?= $file_url_attr ?>" download class="btn btn-sm btn-outline-dark shadow-sm btn-download-mark" data-file-id="<?= (int)$f['id'] ?>" data-file-safe="<?= $file_id_safe ?>" data-nota="<?= htmlspecialchars($id, ENT_QUOTES) ?>" data-target="<?= (int)$target ?>" title="Download dan tandai terprint">
                                                                    <i class="fas fa-download"></i>
                                                                </a>
                                                                <a href="?id=<?= $id ?>&del_file=<?= urlencode($f['path_file']) ?><?= $url_params ?>" class="btn btn-sm btn-light border text-danger shadow-sm" onclick="return confirm('Hapus file ini?')" title="Hapus"><i class="fas fa-trash-alt"></i></a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="p-3 border-top bg-light">
                                        <form method="POST" enctype="multipart/form-data" class="form-order-upload" data-no="<?= htmlspecialchars($id, ENT_QUOTES) ?>">
                                            
                                            <!-- Pilihan File WA & Upload (Layout Diperbarui) -->
                                            <div class="row g-2 mb-3">
                                                
                                                <!-- File WA (Dibuat Full Lebar: col-12) -->
                                                <div class="col-12">
                                                    <label class="form-label fw-bold mb-1" style="font-size: 0.9rem;">Ambil File dari WA:</label>
                                                    <div class="border rounded p-2 bg-white" style="max-height: 150px; overflow-y: auto;">
                                                        
                                                        <?php if (!empty($wa_list)): foreach($wa_list as $index => $w): ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="wa_file[]" value="<?= $w['name'] ?>" id="waFile<?= $index ?>">
                                                            <label class="form-check-label" for="waFile<?= $index ?>" style="font-size: 0.85rem;">
                                                                <?= $w['display'] ?>
                                                            </label>
                                                        </div>
                                                        <?php endforeach; else: ?>
                                                            <small class="text-muted fst-italic">Belum ada file masuk dari WA.</small>
                                                        <?php endif; ?>

                                                    </div>
                                                    <small class="text-muted" style="font-size: 0.75rem;">Pilih satu atau beberapa file</small>
                                                </div>

                                                <!-- Upload File (Pindah ke bawah, Full Lebar: col-12) -->
                                                <div class="col-12 mt-2">
                                                    <label class="form-label fw-bold mb-1" style="font-size: 0.9rem;">Atau Upload File:</label>
                                                    <input type="file" name="files[]" class="form-control bg-white" multiple>
                                                </div>
                                                
                                            </div>

                                            <div class="row g-3">
                                                <!-- Jenis Kertas (Radio Buttons) -->
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold mb-2" style="font-size: 0.9rem;">Jenis Kertas:</label>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas1" value="HVS 70" checked>
                                                            <label class="form-check-label" for="kertas1" style="font-size: 0.85rem;">HVS 70</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas2" value="HVS 80">
                                                            <label class="form-check-label" for="kertas2" style="font-size: 0.85rem;">HVS 80</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas3" value="HVS 100">
                                                            <label class="form-check-label" for="kertas3" style="font-size: 0.85rem;">HVS 100</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas4" value="Artpaper 120">
                                                            <label class="form-check-label" for="kertas4" style="font-size: 0.85rem;">AP 120</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas5" value="Artpaper 150">
                                                            <label class="form-check-label" for="kertas5" style="font-size: 0.85rem;">AP 150</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas6" value="Artpaper 210">
                                                            <label class="form-check-label" for="kertas6" style="font-size: 0.85rem;">AP 210</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas7" value="Artpaper 260">
                                                            <label class="form-check-label" for="kertas7" style="font-size: 0.85rem;">AP 260</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas8" value="Sticker Bontak">
                                                            <label class="form-check-label" for="kertas8" style="font-size: 0.85rem;">Bontak</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="jenis_kertas" id="kertas9" value="Vinyl">
                                                            <label class="form-check-label" for="kertas9" style="font-size: 0.85rem;">Vinyl</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Ukuran Kertas (Radio Buttons) -->
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold mb-2" style="font-size: 0.9rem;">Ukuran Kertas:</label>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur1" value="A3+">
                                                            <label class="form-check-label" for="ukur1" style="font-size: 0.85rem;">A3+</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur2" value="A3">
                                                            <label class="form-check-label" for="ukur2" style="font-size: 0.85rem;">A3</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur3" value="A4" checked>
                                                            <label class="form-check-label" for="ukur3" style="font-size: 0.85rem;">A4</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur4" value="A5">
                                                            <label class="form-check-label" for="ukur4" style="font-size: 0.85rem;">A5</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur5" value="A6">
                                                            <label class="form-check-label" for="ukur5" style="font-size: 0.85rem;">A6</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur6" value="B5">
                                                            <label class="form-check-label" for="ukur6" style="font-size: 0.85rem;">B5</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="ukuran_kertas" id="ukur7" value="F4">
                                                            <label class="form-check-label" for="ukur7" style="font-size: 0.85rem;">F4</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <hr class="my-3 text-muted">

                                            <!-- Finishing, Halaman, Qty, dan Tombol -->
                                            <div class="row align-items-end g-2">
                                                <!-- Finishing (Radio Buttons) -->
                                                <div class="col-md-4">
                                                    <label class="form-label fw-bold mb-1" style="font-size: 0.9rem;">Sisi Cetak:</label>
                                                    <div class="d-flex gap-3">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="finishing" id="sisi1" value="1 Sisi" checked>
                                                            <label class="form-check-label" for="sisi1" style="font-size: 0.85rem;">1 Sisi</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="finishing" id="sisi2" value="2 Sisi">
                                                            <label class="form-check-label" for="sisi2" style="font-size: 0.85rem;">2 Sisi</label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-4 col-md-3">
                                                    <label class="form-label fw-bold mb-1" style="font-size: 0.9rem;">Halaman:</label>
                                                    <input type="text" name="halaman" class="form-control px-2" placeholder="Cth: 1-5" value="All" style="font-size: 0.85rem;">
                                                </div>
                                                
                                                <div class="col-4 col-md-2">
                                                    <label class="form-label fw-bold mb-1" style="font-size: 0.9rem;">Qty:</label>
                                                    <input type="number" name="qty_cetak" class="form-control text-center px-1 fw-bold" value="1" min="1" placeholder="Qty" style="font-size: 0.85rem;">
                                                </div>
                                                
                                                <div class="col-12 col-md-3 mt-3 mt-md-0">
                                                    <button name="upload_file" class="btn btn-primary w-100 fw-bold shadow-sm" style="height: 38px;"><i class="fas fa-upload me-1"></i> Upload</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                                <div class="tab-pane fade" id="pills-progress" role="tabpanel">
                                        <div class="card shadow-sm border-0 rounded-3 mb-3">
                                            <div class="card-header bg-dark text-white py-3">
                                                <h6 class="fw-bold mb-0"><i class="fas fa-tasks text-warning me-2"></i>Daftar Tugas Finishing</h6>
                                            </div>
                                            <div class="card-body bg-light p-0">
                                                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y:auto;">
                                                    <?php if(empty($tasks)): ?> 
                                                        <div class="text-center text-muted small py-4 px-3 bg-white">
                                                            <i class="fas fa-clipboard-check fa-2x opacity-25 mb-2"></i><br>
                                                            Tidak ada tugas Finishing (Jilid/Edit/Laminating).
                                                        </div> 
                                                    <?php else: ?>
                                                        <?php foreach($tasks as $t): $done = ($t['status_task']=='Selesai'); ?>
                                                        <div class="list-group-item d-flex justify-content-between align-items-center p-3 task-row bg-white" id="task-<?= $t['id'] ?>" onclick="toggleTask(<?= $t['id'] ?>, '<?= $id ?>')">
                                                            <span class="fw-bold small <?= $done?'task-done':'' ?> text-task-name"><?= $t['nama_task'] ?></span>
                                                            <div style="width: 22px; height: 22px; border-radius: 50%; display: flex; align-items:center; justify-content:center;" class="icon-task-container <?= $done ? 'bg-success text-white' : 'bg-light border text-muted' ?>">
                                                                <i class="fas <?= $done?'fa-check':'fa-stop' ?> icon-task" style="font-size:0.6rem;"></i>
                                                            </div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                            
                        </div>
                    
                    <?php else: ?>
                    <div class="d-flex flex-column justify-content-center align-items-center h-100 text-muted bg-white rounded-3 shadow-sm border py-5">
                        <i class="fas fa-tasks fa-4x opacity-25 mb-4 text-primary"></i>
                        <h5 class="fw-bolder text-dark">Tidak Ada Orderan Dipilih</h5>
                        <p>Pilih pesanan dari daftar antrian di sebelah kiri untuk melihat detail.</p>
                    </div>
                    <?php endif; ?>
                </div>
