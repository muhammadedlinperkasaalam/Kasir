<?php
/**
 * Konfigurasi OCR bukti pembayaran WhatsApp.
 * Setelah install ulang Windows, file ini bisa langsung auto-detect Tesseract.
 */

function addinta_payment_ocr_find_tesseract() {
    $candidates = [
        getenv('TESSERACT_BIN') ?: '',
        'C:/Program Files/Tesseract-OCR/tesseract.exe',
        'C:/Program Files (x86)/Tesseract-OCR/tesseract.exe',
        'D:/Program Files/Tesseract-OCR/tesseract.exe',
    ];
    foreach ($candidates as $p) {
        if ($p && is_file($p)) return str_replace('\\', '/', $p);
    }
    if (function_exists('shell_exec')) {
        $cmd = stripos(PHP_OS, 'WIN') === 0 ? 'where tesseract 2>NUL' : 'command -v tesseract 2>/dev/null';
        $out = trim((string)@shell_exec($cmd));
        if ($out !== '') {
            $first = preg_split('/\R+/', $out)[0] ?? '';
            if ($first !== '') return str_replace('\\', '/', trim($first));
        }
    }
    return 'tesseract';
}

function addinta_payment_ocr_find_tessdata($tesseractBin) {
    $base = dirname(str_replace('\\', '/', (string)$tesseractBin));
    $candidates = [
        getenv('TESSDATA_PREFIX') ?: '',
        $base . '/tessdata',
        'C:/Program Files/Tesseract-OCR/tessdata',
        'C:/Program Files (x86)/Tesseract-OCR/tessdata',
        'D:/Program Files/Tesseract-OCR/tessdata',
    ];
    foreach ($candidates as $p) {
        if ($p && is_dir($p)) return str_replace('\\', '/', $p);
    }
    return '';
}

$tesseractBin = addinta_payment_ocr_find_tesseract();
$tessdataDir = addinta_payment_ocr_find_tessdata($tesseractBin);

return [
    // Kalau auto-detect belum pas, isi manual, contoh:
    // 'tesseract_bin' => 'C:/Program Files/Tesseract-OCR/tesseract.exe',
    'tesseract_bin' => $tesseractBin,

    // Folder tessdata. Biasanya otomatis: C:/Program Files/Tesseract-OCR/tessdata
    // Pastikan ada file eng.traineddata di dalam folder ini.
    'tesseract_tessdata_dir' => $tessdataDir,

    'tesseract_lang' => 'eng',
    'wa_downloads_dir' => 'whatsapp/wa-engine/downloads',
    'scan_recent_days' => 14,
    'nominal_tolerance' => 10,
    'match_days_before' => 7,
    'match_days_after' => 1,

    // false cocok untuk Tesseract 4/5. Kalau pakai Tesseract 3.02 lama, ubah ke true.
    // Jika salah, verifikasi_bukti_wa.php tetap mencoba fallback otomatis.
    'tesseract_legacy' => false,
    'ocr_psm' => 6,
    'ocr_debug' => false,
    'ocr_use_preprocess' => false,

    // Opsional jika ingin preprocess gambar.
    // 'imagemagick_bin' => 'C:/Program Files/ImageMagick-7.1.1-Q16-HDRI/magick.exe',
];
