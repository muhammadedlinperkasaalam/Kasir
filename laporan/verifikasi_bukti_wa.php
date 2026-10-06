<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

$cfgFile = __DIR__ . '/../config/payment_ocr.php';
$ocrCfg = file_exists($cfgFile) ? require $cfgFile : [];
$ocrCfg = array_merge([
    'tesseract_bin' => 'tesseract',
    'tesseract_lang' => 'eng',
    'tesseract_tessdata_dir' => '',
    'wa_downloads_dir' => 'whatsapp/wa-engine/downloads',
    'scan_recent_days' => 14,
    'nominal_tolerance' => 10,
    // Toleransi "mendekati" untuk kasus customer bayar dibulatkan/beda tipis dari total nota
    // (mis. total Rp17.800 tapi bayar Rp18.000). Ditampilkan terpisah dari kandidat yang pas persis,
    // supaya tetap kelihatan tapi tidak otomatis dianggap 100% pasti cocok.
    'nominal_tolerance_dekat' => 3000,
    'match_days_before' => 7,
    'match_days_after' => 1,
], $ocrCfg);

// ================================================================
// FUNGSI BANTUAN UMUM
// ================================================================

// Bungkus htmlspecialchars supaya semua teks yang ditampilkan ke HTML aman
// (mencegah tampilan rusak atau celah XSS kalau ada teks OCR/nama yang aneh-aneh).
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Format angka jadi tampilan uang Indonesia, mis. 12000 -> "Rp 12.000".
function rupiah($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }

// Ganti semua backslash "\" jadi slash "/", supaya path Windows & Linux konsisten
// (server WA-engine biasanya jalan di Windows, path-nya pakai backslash).
function normPath($p) { return str_replace('\\', '/', (string)$p); }

// Ambil path folder utama aplikasi (satu tingkat di atas folder "laporan").
function projectRoot() { return realpath(__DIR__ . '/..'); }

// Susun URL dasar situs (skema + host + folder), dipakai untuk membentuk link
// gambar bukti pembayaran supaya bisa dibuka langsung dari browser.
function baseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_replace('#/laporan$#', '', $scriptDir);
    return rtrim($scheme . '://' . $host . $base, '/') . '/';
}

// Buat tabel penyimpanan bukti pembayaran WA kalau belum ada (jalan otomatis
// tiap halaman ini dibuka, aman dipanggil berkali-kali karena "IF NOT EXISTS").
function ensurePaymentProofTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_proofs_wa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wa_jid VARCHAR(80) NULL,
        phone_number VARCHAR(50) NULL,
        sender_name VARCHAR(150) NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_hash VARCHAR(64) NOT NULL,
        received_at DATETIME NULL,
        ocr_text MEDIUMTEXT NULL,
        detected_amount DECIMAL(15,2) DEFAULT NULL,
        detected_date DATE NULL,
        detected_method VARCHAR(50) DEFAULT NULL,
        status ENUM('pending','matched','verified','rejected') NOT NULL DEFAULT 'pending',
        matched_no_penjualan TEXT NULL,
        verified_at DATETIME NULL,
        verified_by VARCHAR(20) NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_payment_proof_hash (file_hash),
        KEY idx_status (status),
        KEY idx_amount_date (detected_amount, detected_date),
        KEY idx_phone (phone_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Ambil nomor WA dari nama file gambar, formatnya "WA_<nomor>_<timestamp>_....jpg".
function extractPhoneFromWaFile($fileName) {
    if (preg_match('/^WA_([^_]+)_/', $fileName, $m)) return preg_replace('/[^0-9]/', '', $m[1]);
    return '';
}
// Seragamkan format nomor HP: kalau diawali "0" (format lokal), ganti jadi "62" (format internasional).
function normalizePhone($phone) {
    $n = preg_replace('/[^0-9]/', '', (string)$phone);
    if (substr($n, 0, 1) === '0') $n = '62' . substr($n, 1);
    return $n;
}
// Ubah path file gambar di server jadi URL yang bisa dibuka langsung di browser.
function waImageUrl($relativePath) {
    $rp = ltrim(normPath($relativePath), '/');
    return baseUrl() . str_replace('%2F', '/', rawurlencode($rp));
}
// Cari & baca tanggal dari teks OCR. Mendukung 3 format: "DD/MM/YYYY", "YYYY-MM-DD",
// dan format tanggal Indonesia dengan nama bulan (mis. "3 Juni 2026"). Hasilnya selalu
// dikembalikan dalam format standar "YYYY-MM-DD" supaya gampang dipakai query SQL.
function parseIndoDate($text) {
    $text = strtolower((string)$text);
    $months = [
        'jan'=>'01','januari'=>'01','feb'=>'02','februari'=>'02','mar'=>'03','maret'=>'03','apr'=>'04','april'=>'04','mei'=>'05','jun'=>'06','juni'=>'06','jul'=>'07','juli'=>'07','agu'=>'08','agustus'=>'08','sep'=>'09','sept'=>'09','september'=>'09','okt'=>'10','oktober'=>'10','nov'=>'11','november'=>'11','des'=>'12','desember'=>'12'
    ];
    if (preg_match('/\b(\d{1,2})[\s\-\/]+(\d{1,2})[\s\-\/]+(20\d{2})\b/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/\b(20\d{2})[\s\-\/]+(\d{1,2})[\s\-\/]+(\d{1,2})\b/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/\b(\d{1,2})\s+([a-z]+)\s+(20\d{2})\b/u', $text, $m)) {
        $mon = $months[$m[2]] ?? null;
        if ($mon) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$mon, (int)$m[1]);
    }
    return null;
}
// Perbaiki pola-pola salah baca OCR yang SERING muncul di struk pembayaran,
// sebelum teks ini diproses lebih lanjut untuk mencari nominal/tanggal.
// Contoh: Tesseract kadang baca "Rp0" jadi "RpO" (huruf O), atau "IDR" yang
// seharusnya dibaca sebagai "Rp".
function normalizeOcrMoneyText($text) {
    $t = (string)$text;
    // Perbaiki salah baca OCR yang umum pada bukti pembayaran.
    $map = [
        'Rpo' => 'Rp0', 'RpO' => 'Rp0', 'RP0' => 'Rp0', 'rpO' => 'Rp0',
        'R p' => 'Rp', 'r p' => 'Rp', 'IDR' => 'Rp', 'idr' => 'Rp',
        'Jumlah Transaks!' => 'Jumlah Transaksi', 'Total Transaks!' => 'Total Transaksi',
        'NominaI' => 'Nominal', 'Nomina1' => 'Nominal',
    ];
    $t = strtr($t, $map);
    $t = preg_replace('/\bR\s*[pP]\b/u', 'Rp', $t);
    $t = preg_replace('/[|]/u', '1', $t);
    return $t;
}
// Ubah teks angka mentah hasil OCR jadi angka PHP yang valid (float).
// Ini fungsi inti yang menangani semua "kenakalan" OCR pada angka:
// - huruf yang mirip angka (O/o/D/Q/q->0, I/l->1, S/s->5, Z/z->2)
// - pemisah ribuan ala Indonesia pakai titik (12.000) vs pemisah desimal pakai koma (12.000,50)
// - noise/teks nyambung dari baris lain yang ikut kebaca
function parseMoney($raw) {
    $s = trim((string)$raw);
    // Normalisasi karakter OCR yang sering tertukar di area nominal.
    $s = strtr($s, ['O'=>'0','o'=>'0','D'=>'0','Q'=>'0','q'=>'0','I'=>'1','l'=>'1','S'=>'5','s'=>'5','Z'=>'2','z'=>'2']);
    // Sisakan angka dan pemisah uang saja. Jangan biarkan huruf dari baris berikutnya ikut terbaca.
    $s = preg_replace('/[^0-9,\.]/', '', $s);
    if ($s === '') return 0;

    // Kalau OCR menghasilkan angka panjang karena gabung dengan teks/noise, ambil pola uang pertama yang wajar.
    // Contoh aman: 2700, 2.700, 8.200,00, 9600.
    if (preg_match('/^(\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?/', $s, $m)) {
        $s = $m[1];
    } elseif (preg_match('/^(\d{3,9})(?:[,.]\d{1,2})?/', $s, $m)) {
        $s = $m[1];
    }

    // Tebak mana yang pemisah ribuan dan mana yang pemisah desimal, tergantung
    // ada tidaknya titik & koma sekaligus, dan posisi mana yang muncul terakhir.
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        // Ada titik DAN koma sekaligus (mis. "8.200,00") -> yang muncul PALING BELAKANG
        // dianggap pemisah desimal, sisanya pemisah ribuan yang dibuang.
        if (strrpos($s, ',') > strrpos($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif (strpos($s, ',') !== false) {
        // Cuma ada koma -> kalau 3 digit di belakang koma, itu pemisah ribuan (12,000 -> 12000);
        // kalau bukan 3 digit, anggap itu pemisah desimal (12,5 -> 12.5).
        $parts = explode(',', $s);
        $last = end($parts);
        if (strlen($last) === 3) $s = str_replace(',', '', $s);
        else $s = str_replace(',', '.', $s);
    } elseif (strpos($s, '.') !== false) {
        // Cuma ada titik -> kalau 3 digit di belakang titik, itu pemisah ribuan ala Indonesia (12.000 -> 12000).
        $parts = explode('.', $s);
        $last = end($parts);
        if (count($parts) > 1 && strlen($last) === 3) $s = str_replace('.', '', $s);
    }
    return (float)$s;
}
// Pola regex untuk "bentuk angka uang", termasuk karakter yang sering salah
// dibaca OCR sebagai pengganti digit (huruf O/o/I/l/D/S/s/Z/z/Q/q dianggap
// sebagai kemungkinan digit, nanti dibersihkan oleh parseMoney()).
function moneyRegex() {
    // Pola nominal uang yang tidak melebar ke teks/baris berikutnya.
    // Mendukung: 700, 2700, 2.700, 8.200,00, 9.600,00.
    return '((?:[0-9OoIlDSsZzQq]{1,3}(?:[\.\s][0-9OoIlDSsZzQq]{3})+(?:[,\.][0-9OoIlDSsZzQq]{1,2})?)|(?:[0-9OoIlDSsZzQq]{3,9}(?:[,\.][0-9OoIlDSsZzQq]{1,2})?))';
}
// Cari semua kemungkinan nominal uang dalam SATU potongan teks (biasanya 1 baris,
// atau gabungan beberapa baris kalau label & angkanya terpisah baris).
// Ada 2 cara pencarian: (1) angka yang langsung diawali "Rp"/"IDR", dan
// (2) angka polos di baris yang ada kata kunci seperti "Total"/"Nominal".
function moneyCandidatesFromTextSegment($segment) {
    $segment = normalizeOcrMoneyText((string)$segment);
    // Ubah newline menjadi spasi, tapi batasi regex agar tidak mengambil kata dari baris berikutnya.
    $segment = preg_replace('/\s+/u', ' ', $segment);
    $out = [];
    $money = moneyRegex();

    // Format: Rp4.650, Rp 8.200,00, Rp 700, IDR 9600
    if (preg_match_all('/(?:rp|idr)\s*[:\-]?\s*' . $money . '/iu', $segment, $m)) {
        foreach ($m[1] as $raw) {
            $val = parseMoney($raw);
            if ($val >= 100 && $val <= 50000000) $out[] = (int)round($val);
        }
    }

    // Format label tanpa Rp, misalnya: Total Transaksi 2700 / Nominal 8200.
    if (preg_match('/\b(total\s*transaksi|jumlah\s*transaksi|jumlah\s*total|nominal\s*transaksi|nominal|amount|total)\b/iu', $segment)) {
        if (preg_match_all('/\b' . $money . '\b/iu', $segment, $m2)) {
            foreach ($m2[1] as $raw) {
                $val = parseMoney($raw);
                if ($val >= 100 && $val <= 50000000) $out[] = (int)round($val);
            }
        }
    }
    return array_values(array_unique($out));
}
// Beri "nilai keyakinan" (skor) untuk sebuah angka yang ditemukan di suatu baris teks.
// Skor tinggi = kemungkinan besar itu memang nominal pembayaran (baris ada kata
// "Total Transaksi"/"Nominal"/dst). Skor rendah/minus = kemungkinan itu cuma nomor
// referensi, nomor rekening, atau tahun yang kebetulan berupa angka juga.
// Skor inilah yang dipakai untuk memilih 1 angka paling meyakinkan sebagai nominal final.
function scoreAmountLine($line, $amount) {
    $l = strtolower((string)$line);
    $score = 0;

    if (preg_match('/total\s*transaksi/i', $l)) $score += 180;
    if (preg_match('/jumlah\s*transaksi/i', $l)) $score += 170;
    if (preg_match('/jumlah\s*total/i', $l)) $score += 160;
    if (preg_match('/nominal\s*transaksi/i', $l)) $score += 155;
    if (preg_match('/\bnominal\b/i', $l)) $score += 140;
    if (preg_match('/\btotal\b/i', $l)) $score += 130;
    if (preg_match('/berhasil\s*bayar|pembayaran\s*diterima|hasil\s*transfer|pembayaran\s*berhasil/i', $l)) $score += 90;
    if (preg_match('/\brp\s*/i', $l)) $score += 80;

    // Hindari nomor referensi/panjang yang bukan nominal pembayaran.
    if (preg_match('/tips|coin|coins|saldo|rekening|sumber|terminal|mpan|cpan|rrn|ref|referensi|id\s*transaksi|no\.?\s*transaksi|nomor\s*struk|acquirer|pengakuisisi|merchant\s*pan|customer\s*pan/i', $l)) $score -= 150;
    if (preg_match('/biaya\s*transaksi|gratis/i', $l)) $score -= 100;
    if (preg_match('/\b20\d{2}\b|\d{1,2}[\-\/]\d{1,2}[\-\/]20\d{2}/', $l)) $score -= 30;

    // Nominal kecil seperti Rp 700 tetap valid.
    if ($amount >= 100 && $amount < 1000) $score += 40;
    if ($amount > 10000000) $score -= 80;
    return $score;
}
// INI FUNGSI PALING PENTING dari sisi pembacaan nominal: mengumpulkan SEMUA
// kemungkinan angka nominal dari teks OCR (lewat 3 cara berbeda: per baris,
// pola global "Rp...", dan baris di dekat kata "Total/Nominal"), lalu tiap
// kandidat diberi skor lewat scoreAmountLine(). Angka yang sama yang muncul
// berkali-kali di teks (biasanya karena nominal memang disebut lebih dari
// sekali di struk) dapat bonus skor tambahan di bagian akhir fungsi ini.
// Hasil akhirnya diurutkan dari skor tertinggi -> kandidat paling meyakinkan di depan.
function extractAmountCandidatesFromOcr($text) {
    $text = normalizeOcrMoneyText((string)$text);
    $lines = preg_split('/\R+/', $text);
    $candidates = [];

    // Helper kecil: masukkan 1 kandidat ke daftar, sambil buang angka yang
    // jelas-jelas tidak masuk akal sebagai nominal (kurang dari Rp100, lebih
    // dari Rp50 juta, atau kebetulan sama dengan angka tahun 2023-2026).
    $push = function($amount, $score, $line, $idx) use (&$candidates) {
        $amount = (int)round((float)$amount);
        if ($amount < 100 || $amount > 50000000) return;
        if (in_array($amount, [2023,2024,2025,2026], true)) return;
        $candidates[] = ['amount'=>$amount, 'score'=>(int)$score, 'line'=>(string)$line, 'idx'=>(int)$idx];
    };

    // 1) Baca kandidat per baris. Ini paling aman supaya angka referensi di baris lain tidak ikut.
    foreach ($lines as $idx => $line) {
        $line = trim($line);
        if ($line === '') continue;

        foreach (moneyCandidatesFromTextSegment($line) as $val) {
            $push($val, scoreAmountLine($line, $val), $line, $idx);
        }

        // Jika label dan nominal terpisah beberapa baris/kolom: "Total Transaksi" lalu "Rp 2.700".
        if (preg_match('/\b(total\s*transaksi|jumlah\s*transaksi|jumlah\s*total|nominal\s*transaksi|nominal|jumlah\s*pembayaran)\b/iu', $line)) {
            for ($j = 1; $j <= 6; $j++) {
                if (!isset($lines[$idx + $j])) continue;
                $nearLine = trim($lines[$idx + $j]);
                foreach (moneyCandidatesFromTextSegment($line . ' ' . $nearLine) as $val) {
                    $push($val, scoreAmountLine($line, $val) + 90 - ($j * 5), $line . ' ' . $nearLine, $idx);
                }
            }
        }
    }

    // 2) Fallback global super kuat: cari semua pola Rp di seluruh teks, termasuk OCR yang memecah spasi.
    // Mendukung: Rp4.650, Rp 2.700, Rp 2 700, Rp 8.200,00, RP9.600,00.
    $flat = preg_replace('/\s+/u', ' ', $text);
    if (preg_match_all('/(?:r\s*p|rp|idr)\s*[:\-]?\s*([0-9OoIlDSsZzQq](?:[0-9OoIlDSsZzQq\s\.,]{0,18}[0-9OoIlDSsZzQq])?)/iu', $flat, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $hit) {
            $raw = $hit[0];
            $pos = (int)$hit[1];
            $amount = parseMoney($raw);
            if ($amount < 100 || $amount > 50000000) continue;
            $ctx = substr($flat, max(0, $pos - 90), 220);
            $score = 70 + scoreAmountLine($ctx, $amount);
            // Beri prioritas tinggi untuk label nominal/total di sekitar angka.
            if (preg_match('/total\s*transaksi|jumlah\s*transaksi|jumlah\s*total|nominal\s*transaksi|nominal/i', $ctx)) $score += 120;
            // Hindari Rp 0, tips, biaya, saldo, koin, dsb.
            if (preg_match('/tips|biaya|gratis|saldo|coin|coins|gopay\s*coins/i', $ctx)) $score -= 120;
            $push($amount, $score, $ctx, 0);
        }
    }

    // 3) Fallback khusus label tanpa Rp pada baris yang sama/dekat.
    foreach ($lines as $idx => $line) {
        if (!preg_match('/total\s*transaksi|jumlah\s*transaksi|jumlah\s*total|nominal\s*transaksi|nominal/i', $line)) continue;
        $near = $line;
        for ($j=1; $j<=4; $j++) if (isset($lines[$idx+$j])) $near .= ' ' . trim($lines[$idx+$j]);
        if (preg_match_all('/\b([0-9]{1,3}(?:[\.\s][0-9]{3})+|[0-9]{3,9})\b/u', $near, $m2)) {
            foreach ($m2[1] as $raw) {
                $amount = parseMoney($raw);
                $push($amount, scoreAmountLine($line, $amount) + 80, $near, $idx);
            }
        }
    }

    if (!$candidates) return [];

    // Gabungkan kandidat sama. Kandidat yang muncul berulang di struk diberi bonus.
    $merged = [];
    foreach ($candidates as $c) {
        $a = (int)$c['amount'];
        if (!isset($merged[$a])) $merged[$a] = ['amount'=>$a, 'score'=>$c['score'], 'count'=>0, 'line'=>$c['line']];
        $merged[$a]['score'] = max($merged[$a]['score'], $c['score']);
        $merged[$a]['count']++;
        if (strlen($merged[$a]['line']) < strlen($c['line'])) $merged[$a]['line'] = $c['line'];
    }
    foreach ($merged as &$c) {
        $c['score'] += min(80, max(0, $c['count'] - 1) * 30);
        // Nominal 0 tidak dimasukkan sejak awal, nominal kecil tetap valid.
        if ($c['amount'] >= 100 && $c['amount'] < 1000) $c['score'] += 15;
    }
    unset($c);

    usort($merged, function($a, $b) {
        if ($a['score'] === $b['score']) return $b['amount'] <=> $a['amount'];
        return $b['score'] <=> $a['score'];
    });
    return array_values($merged);
}
// Ambil jawaban akhir: kandidat dengan skor tertinggi dari extractAmountCandidatesFromOcr().
// Inilah nominal yang akhirnya disimpan sebagai detected_amount.
function extractAmountFromOcr($text) {
    $candidates = extractAmountCandidatesFromOcr($text);
    return $candidates ? (int)$candidates[0]['amount'] : null;
}
// Tebak metode pembayaran dari kata kunci yang muncul di teks OCR.
// Dompet digital (GoPay/Dana/OVO/ShopeePay/LinkAja) & QRIS dianggap "QRIS",
// nama-nama bank dianggap "Transfer". Kalau tidak ketemu kata kuncinya sama sekali,
// default-nya "QRIS" (paling umum dipakai customer untuk bayar).
function detectMethodFromText($text) {
    $t = strtolower((string)$text);
    if (preg_match('/gopay|go-pay|qris|qr\s*is|merchant/i', $t)) return 'QRIS';
    if (preg_match('/bca|bri|mandiri|bni|cimb|bank|transfer/i', $t)) return 'Transfer';
    if (preg_match('/dana|ovo|shopeepay|linkaja/i', $t)) return 'QRIS';
    return 'QRIS';
}
// Cek apakah teks OCR mengandung kata yang menandakan transaksi BERHASIL
// (dipakai untuk menyaring gambar yang ternyata bukan bukti pembayaran sukses,
// misal screenshot transaksi gagal/pending, atau foto struk belanja lain).
function isSuccessProof($text) {
    return preg_match('/berhasil|sukses|success|successful|completed|transaksi berhasil|pembayaran berhasil/i', (string)$text) === 1;
}

// ================================================================
// MENJALANKAN OCR (BACA TEKS DARI GAMBAR PAKAI TESSERACT)
// ================================================================

// Susun perintah command-line untuk menjalankan Tesseract. Kalau folder
// "tessdata" (data bahasa OCR) perlu diarahkan manual, perintahnya dibungkus
// supaya set variabel environment TESSDATA_PREFIX dulu -- caranya beda antara
// Windows ("set VAR=... &&") dan Linux ("VAR=... perintah").
function buildTesseractCommand($bin, $args, $tessdataDir = '') {
    $binCmd = escapeshellarg($bin);
    $tessdataDir = trim((string)$tessdataDir);
    if ($tessdataDir === '') return $binCmd . ' ' . $args;
    $tessdataDir = str_replace('\\', '/', $tessdataDir);
    if (stripos(PHP_OS, 'WIN') === 0) {
        return 'set "TESSDATA_PREFIX=' . str_replace('"', '', $tessdataDir) . '" && ' . $binCmd . ' ' . $args;
    }
    return 'TESSDATA_PREFIX=' . escapeshellarg($tessdataDir) . ' ' . $binCmd . ' ' . $args;
}

// Fungsi utama yang menjalankan Tesseract OCR pada 1 file gambar dan
// mengembalikan teks hasil bacaannya (mentah, belum diproses parseMoney dkk).
// Alurnya: coba mode "legacy" dulu (lebih stabil untuk struk berkualitas rendah) ->
// kalau hasilnya kosong, coba mode satunya (LSTM/modern) sebagai cadangan ->
// preprocessing gambar (ubah ke hitam-putih via ImageMagick) HANYA dijalankan
// kalau diaktifkan manual di config ('ocr_use_preprocess'), karena defaultnya
// dimatikan supaya proses OCR harian tetap cepat.
function runOcr($absolutePath, $cfg) {
    // Mode cepat: hanya 1x OCR, tanpa banyak PSM, tanpa banyak preprocessing,
    // dan tanpa diagnosa panjang. Cocok untuk operasional harian.
    $bin = $cfg['tesseract_bin'] ?? 'tesseract';
    $lang = $cfg['tesseract_lang'] ?? 'eng';
    $tessdataDir = $cfg['tesseract_tessdata_dir'] ?? '';
    $psm = (int)($cfg['ocr_psm'] ?? 6);
    if ($psm <= 0) $psm = 6;

    $debug = [];
    $debugMode = !empty($cfg['ocr_debug']);
    $legacy = array_key_exists('tesseract_legacy', $cfg) ? (bool)$cfg['tesseract_legacy'] : true;
    $usePreprocess = !empty($cfg['ocr_use_preprocess']);

    if (!function_exists('shell_exec')) {
        return "[ERROR OCR]\nshell_exec() tidak aktif di PHP.";
    }
    if (!is_file($absolutePath)) {
        return "[ERROR OCR]\nFile bukti pembayaran tidak ditemukan: " . $absolutePath;
    }

    $runLegacy = function($file) use ($bin, $lang, $psm, $tessdataDir, &$debug, $debugMode) {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_fast_' . uniqid('', true);
        $args = escapeshellarg($file) . ' ' . escapeshellarg($base) . ' -l ' . escapeshellarg($lang) . ' -psm ' . (int)$psm;
        $cmd = buildTesseractCommand($bin, $args, $tessdataDir) . ' 2>&1';
        $msg = trim((string)@shell_exec($cmd));
        $txtFile = $base . '.txt';
        $out = is_file($txtFile) ? trim((string)file_get_contents($txtFile)) : '';
        @unlink($txtFile);
        if ($debugMode) {
            $debug[] = 'Legacy command: ' . $cmd;
            $debug[] = 'OCR length: ' . strlen($out);
            if ($msg !== '') $debug[] = 'OCR message: ' . substr($msg, 0, 400);
        }
        return $out;
    };

    $runModern = function($file) use ($bin, $lang, $psm, $tessdataDir, &$debug, $debugMode) {
        $args = escapeshellarg($file) . ' stdout -l ' . escapeshellarg($lang) . ' --psm ' . (int)$psm;
        $cmd = buildTesseractCommand($bin, $args, $tessdataDir) . ' 2>&1';
        $out = trim((string)@shell_exec($cmd));
        if ($debugMode) {
            $debug[] = 'Modern command: ' . $cmd;
            $debug[] = 'OCR length: ' . strlen($out);
        }
        return $out;
    };

    $out = $legacy ? $runLegacy($absolutePath) : $runModern($absolutePath);

    // Fallback otomatis: kalau config legacy salah, coba mode satunya sekali saja.
    if ($out === '' || stripos($out, 'read_params_file') !== false || stripos($out, 'Error') === 0) {
        $fallback = $legacy ? $runModern($absolutePath) : $runLegacy($absolutePath);
        if (trim($fallback) !== '' && stripos($fallback, 'read_params_file') === false) {
            $out = $fallback;
        }
    }

    // Preprocess hanya kalau diaktifkan di config. Default dimatikan agar cepat.
    if (($out === '' || strlen($out) < 10) && $usePreprocess) {
        $magick = $cfg['imagemagick_bin'] ?? 'magick';
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_fast_pay_' . uniqid('', true) . '.png';
        $opt = '-auto-orient -resize 1600x1600^> -colorspace Gray -normalize';
        $cmdPrep = escapeshellarg($magick) . ' ' . escapeshellarg($absolutePath) . ' ' . $opt . ' ' . escapeshellarg($tmp) . ' 2>&1';
        @shell_exec($cmdPrep);
        if (is_file($tmp) && filesize($tmp) > 0) {
            $out2 = $legacy ? $runLegacy($tmp) : $runModern($tmp);
            if (trim($out2) !== '') $out = $out2;
            @unlink($tmp);
        }
    }

    $out = trim((string)$out);
    if ($debugMode) {
        $out .= "\n\n[DEBUG OCR CEPAT]\n" . implode("\n", $debug);
    }

    if ($out === '') {
        return "[OCR KOSONG]\nTesseract sudah dipanggil, tetapi teks tidak terbaca.\nPath tesseract: " . (string)$bin . "\nTessdata: " . (string)$tessdataDir . "\nCoba isi nominal manual atau aktifkan ocr_use_preprocess di config/payment_ocr.php.";
    }
    return $out;
}
// ================================================================
// PENCOCOKAN KE NOTA PENJUALAN
// ================================================================

// Cari data pelanggan berdasarkan nomor WA. Dicocokkan pakai 10 digit
// TERAKHIR saja (bukan nomor penuh), supaya tetap ketemu walau format
// nomornya beda-beda (mis. "+6289..." vs "089..." vs "6289...").
function findPelangganByPhone(PDO $pdo, $phone) {
    $n = normalizePhone($phone);
    if (!$n) return null;
    $stmt = $pdo->prepare("SELECT * FROM pelanggan WHERE REPLACE(REPLACE(REPLACE(no_telepon, '+', ''), ' ', ''), '-', '') LIKE ? OR REPLACE(REPLACE(REPLACE(wa_lid, '+', ''), ' ', ''), '-', '') LIKE ? LIMIT 1");
    $stmt->execute(['%' . substr($n, -10), '%' . substr($n, -10)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Potongan ekspresi SQL untuk menghitung nilai total 1 nota: pakai kolom
// total_omzet kalau sudah terisi, atau kalau kosong (0), hitung ulang dari
// jumlah subtotal semua item di nota tersebut.
function transactionValueExpr() {
    return "CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM(pi.subtotal), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END";
}
// Cari nota-nota penjualan yang mungkin cocok dengan 1 bukti pembayaran.
// Langkah-langkahnya:
// 1. Ambil semua nota yang BELUM lunas, dalam rentang tanggal sekitar tanggal
//    di bukti (default: 7 hari sebelum s/d 1 hari sesudah).
// 2. Kalau nomor WA pengirim dikenali sebagai pelanggan tertentu, pencarian
//    dipersempit ke nota pelanggan itu saja (supaya tidak salah cocok ke nota orang lain).
// 3. Nota yang nominalnya PAS (selisih <= toleransi ketat, default Rp10) masuk ke "single".
// 4. Nota yang nominalnya MENDEKATI tapi tidak pas (selisih <= toleransi longgar,
//    default Rp3.000) masuk ke "near" -- ini untuk kasus customer bayar dibulatkan,
//    mis. total nota Rp17.800 tapi yang dibayar Rp18.000.
// 5. Dicoba juga kemungkinan gabungan 2-3 nota sekaligus lewat findTransactionCombo().
function getCandidates(PDO $pdo, $amount, $date, $phone, $cfg) {
    $amount = (float)$amount;
    if ($amount <= 0) return ['single'=>[], 'near'=>[], 'combo'=>null, 'pelanggan'=>null];
    $tol = (float)($cfg['nominal_tolerance'] ?? 10);
    $tolDekat = max($tol, (float)($cfg['nominal_tolerance_dekat'] ?? 3000));
    $baseDate = $date ?: date('Y-m-d');
    $from = date('Y-m-d', strtotime($baseDate . ' -' . (int)$cfg['match_days_before'] . ' days'));
    $to = date('Y-m-d', strtotime($baseDate . ' +' . (int)$cfg['match_days_after'] . ' days'));
    $pelanggan = findPelangganByPhone($pdo, $phone);
    $value = transactionValueExpr();
    $params = [$from, $to];
    $wherePhone = '';
    if ($pelanggan && !empty($pelanggan['kode_pelanggan'])) {
        $wherePhone = " AND p.kode_pelanggan = ? ";
        $params[] = $pelanggan['kode_pelanggan'];
    }
    $sql = "SELECT p.no_penjualan, p.tgl_penjualan, p.jam, p.kode_pelanggan, COALESCE(pl.nama_pelanggan, 'Umum / Tanpa Nama') nama_pelanggan,
                   p.pelunasan, p.metode_pembayaran, p.uang_bayar, p.nominal_deposit, $value AS total_nota,
                   ($value - COALESCE(p.nominal_deposit,0)) AS sisa_tagihan
            FROM penjualan p
            LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
            WHERE p.tgl_penjualan BETWEEN ? AND ?
              AND (p.pelunasan IS NULL OR p.pelunasan <> 'Y' OR COALESCE(p.uang_bayar,0) < ($value - COALESCE(p.nominal_deposit,0)))
              $wherePhone
            ORDER BY p.tgl_penjualan DESC, p.jam DESC
            LIMIT 80";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $single = [];
    $near = [];
    foreach ($rows as $r) {
        $sisa = (float)$r['sisa_tagihan'];
        $total = (float)$r['total_nota'];
        // Selisih paling kecil antara nominal bukti vs sisa tagihan / total nota (dipakai untuk
        // menentukan apakah masuk kategori "pas" atau "mendekati").
        $selisih = min(abs($sisa - $amount), abs($total - $amount));
        // Selisih BERTANDA terhadap sisa tagihan (bukan nilai absolut) -- dipakai untuk kasih tahu
        // kasir apakah ini kelebihan bayar (positif, -> nanti masuk deposit) atau kekurangan bayar
        // (negatif, -> nanti sisanya jadi piutang), sesuai perhitungan yang sama di verifyTransactions().
        $selisihBertanda = $amount - $sisa;
        if ($selisih <= $tol) {
            $r['selisih'] = $selisih;
            $r['selisih_bertanda'] = $selisihBertanda;
            $single[] = $r;
        } elseif ($selisih <= $tolDekat) {
            // Cocok "mendekati", bukan pas persis — mis. total Rp17.800 tapi bayar Rp18.000.
            // Ditampilkan terpisah supaya kasir tetap sadar ada selisih sebelum verifikasi.
            $r['selisih'] = $selisih;
            $r['selisih_bertanda'] = $selisihBertanda;
            $near[] = $r;
        }
    }
    // Urutkan yang paling dekat nominalnya duluan.
    usort($near, fn($a, $b) => $a['selisih'] <=> $b['selisih']);

    $combo = findTransactionCombo($rows, $amount, $tol);
    return ['single'=>$single, 'near'=>$near, 'combo'=>$combo, 'pelanggan'=>$pelanggan];
}
// Untuk kasus customer bayar SEKALIGUS untuk beberapa nota (mis. nebus 2-3 nota
// piutang lama dalam 1x transfer). Coba cari kombinasi 2 nota dulu (lebih cepat,
// selalu dicoba), baru kalau tidak ketemu & jumlah nota tidak terlalu banyak
// (<=35 nota, supaya tidak berat), coba kombinasi 3 nota sekaligus.
// Catatan: kombinasi ini masih pakai toleransi KETAT ($tol), belum memakai
// toleransi "mendekati" -- jadi untuk gabungan nota, nominalnya harus pas.
function findTransactionCombo($rows, $target, $tol) {
    $items = [];
    foreach ($rows as $r) {
        $val = max(0, (float)$r['sisa_tagihan']);
        if ($val > 0 && $val <= $target + $tol) { $r['combo_val'] = $val; $items[] = $r; }
    }
    $n = count($items);
    // Coba semua pasangan 2 nota.
    for ($i=0; $i<$n; $i++) for ($j=$i+1; $j<$n; $j++) {
        $sum = $items[$i]['combo_val'] + $items[$j]['combo_val'];
        if (abs($sum - $target) <= $tol) return [$items[$i], $items[$j]];
    }
    // Coba semua kombinasi 3 nota (dibatasi max 35 nota supaya tidak terlalu berat -- 35^3 ≈ 42 ribu kombinasi).
    if ($n <= 35) {
        for ($i=0; $i<$n; $i++) for ($j=$i+1; $j<$n; $j++) for ($k=$j+1; $k<$n; $k++) {
            $sum = $items[$i]['combo_val'] + $items[$j]['combo_val'] + $items[$k]['combo_val'];
            if (abs($sum - $target) <= $tol) return [$items[$i], $items[$j], $items[$k]];
        }
    }
    return null;
}

// Ambil detail lengkap 1 nota (header + daftar item barangnya), dipakai untuk
// menampilkan modal "Lihat Nota" supaya kasir bisa cek dulu sebelum verifikasi.
function getNotaDetail(PDO $pdo, $noPenjualan) {
    $stmt = $pdo->prepare("SELECT p.*, COALESCE(pl.nama_pelanggan, 'Umum / Tanpa Nama') AS nama_pelanggan, COALESCE(pl.no_telepon, '') AS no_telepon
                           FROM penjualan p
                           LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                           WHERE p.no_penjualan = ? LIMIT 1");
    $stmt->execute([$noPenjualan]);
    $nota = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$nota) return null;
    $items = $pdo->prepare("SELECT pi.*, COALESCE(b.nama_barang, pi.kode_barang) AS nama_barang
                            FROM penjualan_item pi
                            LEFT JOIN barang b ON pi.kode_barang = b.kode_barang
                            WHERE pi.no_penjualan = ?
                            ORDER BY pi.kode_barang ASC, pi.keterangan ASC");
    $items->execute([$noPenjualan]);
    $nota['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    return $nota;
}
// Buat ID unik untuk elemen modal HTML per nota (dipakai atribut "id" & "data-bs-target"),
// karakter yang bukan huruf/angka/underscore/strip diganti underscore supaya aman jadi ID HTML.
function modalIdForNota($noPenjualan) {
    return 'notaModal_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$noPenjualan);
}
// Render HTML modal "Lihat Nota" untuk 1 nota tertentu. Ada penjaga static $rendered
// supaya kalau nota yang sama muncul di beberapa kandidat sekaligus, modalnya
// cuma dicetak sekali saja ke HTML (menghindari duplikat ID yang bisa bikin modal salah muncul).
function renderNotaModal(PDO $pdo, $noPenjualan) {
    static $rendered = [];
    $noPenjualan = trim((string)$noPenjualan);
    if ($noPenjualan === '' || isset($rendered[$noPenjualan])) return '';
    $rendered[$noPenjualan] = true;
    $nota = getNotaDetail($pdo, $noPenjualan);
    if (!$nota) return '';
    $modalId = modalIdForNota($noPenjualan);
    $totalItem = 0;
    $rows = '';
    foreach (($nota['items'] ?? []) as $it) {
        $subtotal = isset($it['subtotal']) ? (float)$it['subtotal'] : ((float)$it['harga_jual'] * (float)$it['jumlah'] - (float)$it['diskon']);
        $totalItem += $subtotal;
        $ket = trim((string)($it['keterangan'] ?? ''));
        $rows .= '<tr>'
            . '<td><div class="fw-semibold">' . h($it['nama_barang']) . '</div>' . ($ket !== '' ? '<div class="text-muted small">' . h($ket) . '</div>' : '') . '</td>'
            . '<td class="text-end">' . h(rtrim(rtrim(number_format((float)$it['jumlah'], 2, ',', '.'), '0'), ',')) . '</td>'
            . '<td class="text-end">' . h(rupiah($it['harga_jual'])) . '</td>'
            . '<td class="text-end fw-semibold">' . h(rupiah($subtotal)) . '</td>'
            . '</tr>';
    }
    $totalNota = (float)($nota['total_omzet'] ?? 0);
    if ($totalNota <= 0) $totalNota = $totalItem;
    $status = (($nota['pelunasan'] ?? '') === 'Y') ? 'Lunas' : 'Piutang / Belum Lunas';
    ob_start();
    ?>
    <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Detail Nota <?= h($noPenjualan) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">No Nota</label>
                            <input class="form-control form-control-sm" value="<?= h($nota['no_penjualan']) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Tanggal / Jam</label>
                            <input class="form-control form-control-sm" value="<?= h(($nota['tgl_penjualan'] ?? '-') . ' ' . substr((string)($nota['jam'] ?? ''),0,5)) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <input class="form-control form-control-sm" value="<?= h($status) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Pelanggan</label>
                            <input class="form-control form-control-sm" value="<?= h($nota['nama_pelanggan']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">No Telepon</label>
                            <input class="form-control form-control-sm" value="<?= h($nota['no_telepon'] ?: '-') ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Metode Bayar</label>
                            <input class="form-control form-control-sm" value="<?= h($nota['metode_pembayaran'] ?: '-') ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Uang Bayar</label>
                            <input class="form-control form-control-sm" value="<?= h(rupiah($nota['uang_bayar'] ?? 0)) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Total Nota</label>
                            <input class="form-control form-control-sm fw-bold" value="<?= h(rupiah($totalNota)) ?>" readonly>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end" style="width:90px">Qty</th>
                                    <th class="text-end" style="width:120px">Harga</th>
                                    <th class="text-end" style="width:130px">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody><?= $rows ?: '<tr><td colspan="4" class="text-center text-muted">Item tidak ditemukan.</td></tr>' ?></tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Total Item</th>
                                    <th class="text-end"><?= h(rupiah($totalItem)) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="3" class="text-end">Diskon Global</th>
                                    <th class="text-end"><?= h(rupiah($nota['diskon_global'] ?? 0)) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="3" class="text-end">Ongkir</th>
                                    <th class="text-end"><?= h(rupiah($nota['ongkir'] ?? 0)) ?></th>
                                </tr>
                                <tr class="table-light">
                                    <th colspan="3" class="text-end">Total Nota</th>
                                    <th class="text-end"><?= h(rupiah($totalNota)) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if (!empty($nota['keterangan'])): ?>
                    <div class="mt-3">
                        <label class="form-label small text-muted mb-1">Keterangan</label>
                        <textarea class="form-control form-control-sm" rows="2" readonly><?= h($nota['keterangan']) ?></textarea>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-outline-primary" target="_blank" href="../transaksi/penjualan/cetak.php?no_penjualan=<?= urlencode($noPenjualan) ?>"><i class="fas fa-print me-1"></i> Buka Nota</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Pastikan tabel log_deposit ada (dipakai untuk mencatat jejak kelebihan bayar
// yang dimasukkan ke deposit pelanggan). Tabel yang sama persis dipakai juga
// oleh fitur "Bayar Cicilan Piutang" (transaksi/penjualan/bayar_cicilan.php).
function verifWaEnsureLogDepositTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS log_deposit (
        id int(11) NOT NULL AUTO_INCREMENT,
        tgl_deposit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        kode_pelanggan varchar(20) NOT NULL,
        nominal double NOT NULL,
        keterangan text,
        kode_user varchar(10) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Hitung ulang cache "sisa_utang" 1 pelanggan dari semua nota-nya yang belum lunas.
// Query ini SAMA PERSIS dengan hitungSisaPiutangPelanggan() di bayar_cicilan.php,
// supaya angka piutang pelanggan konsisten di semua halaman (tidak dobel logika beda hasil).
function verifWaHitungSisaUtangPelanggan(PDO $pdo, $kodePelanggan) {
    $sql = "SELECT COALESCE(SUM(" . transactionValueExpr() . " - COALESCE(p.uang_bayar,0) - COALESCE(p.nominal_deposit,0)), 0) AS sisa
            FROM penjualan p WHERE p.pelunasan = 'N' AND p.kode_pelanggan = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$kodePelanggan]);
    return max(0, (float)$stmt->fetchColumn());
}

// ================================================================
// EKSEKUSI VERIFIKASI (kasir klik "Verifikasi Nota Ini" / "Verifikasi Gabungan")
// ================================================================
// Menandai 1 atau beberapa nota sebagai LUNAS berdasarkan bukti pembayaran yang
// sudah dicocokkan. Bisa lebih dari 1 nota sekaligus (kasus gabungan/combo).
// Semua nota diproses dalam SATU database transaction: kalau salah satu nota
// gagal diproses, SEMUA dibatalkan (tidak ada nota yang "setengah lunas").
//
// KHUSUS untuk verifikasi 1 nota (bukan gabungan), nominal bukti WA dibandingkan
// dengan tagihan nota -- mengikuti pola yang sama seperti fitur "Bayar Cicilan Piutang":
//   - Bayar LEBIH (mis. tagihan Rp17.800, bayar Rp18.000) -> nota tetap lunas penuh,
//     dan KELEBIHANNYA masuk ke saldo deposit pelanggan (tercatat juga di log_deposit & arus_kas).
//   - Bayar KURANG (mis. tagihan Rp17.800, bayar Rp17.000) -> nota TIDAK ditandai lunas,
//     uang_bayar diisi sesuai yang benar-benar diterima, sisanya otomatis jadi PIUTANG/UTANG
//     (utang bukan kolom yang diisi manual, tapi otomatis kehitung dari selisih tagihan vs uang_bayar).
// Untuk verifikasi GABUNGAN (2-3 nota sekaligus), perilaku lama dipertahankan (lunas penuh semua),
// karena kombinasinya memang sudah dicari yang totalnya pas (lihat findTransactionCombo()).
function verifyTransactions(PDO $pdo, $proofId, $targets, $method, $tglVerif, $userId) {
    $targets = array_values(array_filter(array_map('trim', explode(',', (string)$targets))));
    if (!$targets) throw new Exception('Target nota kosong.');
    $isSingle = (count($targets) === 1);

    // Ambil nominal yang tertulis di bukti WA (dari hasil OCR/isian manual), dipakai
    // untuk mendeteksi selisih lebih/kurang bayar -- hanya relevan untuk verifikasi 1 nota.
    $amountPaid = 0.0;
    if ($isSingle) {
        $stmtProof = $pdo->prepare("SELECT detected_amount FROM payment_proofs_wa WHERE id = ?");
        $stmtProof->execute([$proofId]);
        $amountPaid = (float)($stmtProof->fetchColumn() ?: 0);
    }

    $pdo->beginTransaction();
    try {
        $kelebihanUntukDeposit = 0.0;
        $kodePelangganTerdampak = null;

        foreach ($targets as $no) {
            // "FOR UPDATE" mengunci baris nota ini, supaya aman kalau ada 2 orang
            // yang kebetulan memverifikasi nota yang sama di waktu bersamaan.
            $stmtGet = $pdo->prepare("SELECT p.*, " . transactionValueExpr() . " AS nilai_real FROM penjualan p WHERE p.no_penjualan = ? FOR UPDATE");
            $stmtGet->execute([$no]);
            $row = $stmtGet->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $sisaTagihan = max(0, (float)$row['nilai_real'] - (float)$row['nominal_deposit']);
            $selisih = $isSingle && $amountPaid > 0 ? round($amountPaid - $sisaTagihan) : 0;

            $lunas = 'Y';
            $uangBayarBaru = $sisaTagihan; // default: perilaku lama, dianggap lunas persis sesuai tagihan.

            if ($selisih > 0) {
                // Bayar LEBIH: nota tetap lunas penuh sesuai tagihannya, kelebihan disisihkan
                // untuk dimasukkan ke deposit pelanggan (di luar loop, sekali saja).
                $uangBayarBaru = $sisaTagihan;
                $lunas = 'Y';
                $kelebihanUntukDeposit = $selisih;
                $kodePelangganTerdampak = $row['kode_pelanggan'];
            } elseif ($selisih < 0) {
                // Bayar KURANG: nota belum lunas. uang_bayar diisi sesuai yang BENAR-BENAR
                // diterima -- sisanya otomatis jadi piutang/utang karena sisa_utang dihitung
                // live dari (tagihan - uang_bayar - deposit), bukan kolom yang diisi manual.
                $uangBayarBaru = max(0, (float)$amountPaid);
                $lunas = 'N';
                $kodePelangganTerdampak = $row['kode_pelanggan'];
            }

            $ket = (string)($row['keterangan'] ?? '');
            // Tandai di keterangan nota bahwa pelunasan ini berasal dari verifikasi
            // bukti WA (bukan input manual kasir), untuk jejak audit.
            if (strpos($ket, '[Verif:BuktiWA]') === false) $ket .= ' [Verif:BuktiWA]';
            if ($selisih > 0) $ket .= ' (lebih bayar Rp' . number_format($selisih, 0, ',', '.') . ' -> masuk deposit)';
            if ($selisih < 0) $ket .= ' (kurang bayar Rp' . number_format(abs($selisih), 0, ',', '.') . ' -> sisa jadi piutang)';

            $pdo->prepare("UPDATE penjualan SET pelunasan=?, metode_pembayaran=?, uang_bayar=?, is_verif_qris='Y', tgl_verif_qris=?, tgl_transaksi=COALESCE(tgl_transaksi, tgl_penjualan), keterangan=? WHERE no_penjualan=?")
                ->execute([$lunas, $method, $uangBayarBaru, $tglVerif, $ket, $no]);
            $pdo->prepare("UPDATE penjualan_item SET pelunasan=? WHERE no_penjualan=?")->execute([$lunas, $no]);
            // Pastikan arus kas ada, agar laporan cash/riwayat ikut sinkron.
            // Dicatat sejumlah yang BENAR-BENAR diterima untuk nota ini (uang_bayar baru),
            // bukan sejumlah kelebihan yang nanti dipisah ke deposit.
            $cek = $pdo->prepare("SELECT COUNT(*) FROM arus_kas WHERE no_penjualan=? AND jumlah_masuk > 0");
            $cek->execute([$no]);
            if ((int)$cek->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO arus_kas (tanggal, jenis, keterangan, no_penjualan, metode_pembayaran, jumlah_masuk, jumlah_keluar, kode_user) VALUES (?, 'pemasukan', ?, ?, ?, ?, 0, ?)")
                    ->execute([$tglVerif, 'Pembayaran bukti WA ' . $no, $no, $method, $uangBayarBaru, $userId]);
            }
        }

        // Kelebihan bayar (kalau ada, dan pelanggannya bukan "UMUM") dimasukkan ke saldo
        // deposit pelanggan -- pola & tabel log-nya sama persis dengan fitur "Bayar Cicilan Piutang".
        if ($kelebihanUntukDeposit > 0 && $kodePelangganTerdampak && strtoupper($kodePelangganTerdampak) !== 'UMUM') {
            verifWaEnsureLogDepositTable($pdo);
            $pdo->prepare("UPDATE pelanggan SET saldo_deposit = COALESCE(saldo_deposit,0) + ? WHERE kode_pelanggan = ?")
                ->execute([$kelebihanUntukDeposit, $kodePelangganTerdampak]);
            $pdo->prepare("INSERT INTO log_deposit (tgl_deposit, kode_pelanggan, nominal, keterangan, kode_user) VALUES (?, ?, ?, ?, ?)")
                ->execute([$tglVerif . ' ' . date('H:i:s'), $kodePelangganTerdampak, $kelebihanUntukDeposit, 'Kelebihan bayar via verifikasi bukti WA ' . implode(',', $targets), $userId]);
            try {
                $pdo->prepare("INSERT INTO arus_kas (tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user, no_penjualan, metode_pembayaran) VALUES (?, 'pemasukan', ?, ?, 0, ?, ?, ?)")
                    ->execute([$tglVerif, 'Deposit kelebihan bayar bukti WA ' . implode(',', $targets), $kelebihanUntukDeposit, $userId, implode(',', $targets), $method]);
            } catch (Throwable $e) {}
        }

        // Setelah semua nota target selesai diproses, tandai bukti WA-nya sebagai "verified"
        // dan simpan nota mana saja yang jadi tujuannya (bisa lebih dari 1, dipisah koma).
        $pdo->prepare("UPDATE payment_proofs_wa SET status='verified', matched_no_penjualan=?, verified_at=NOW(), verified_by=?, detected_method=COALESCE(?, detected_method) WHERE id=?")
            ->execute([implode(',', $targets), $userId, $method, $proofId]);
        $pdo->commit();

        // Refresh cache "sisa_utang" pelanggan setelah transaksi selesai (di luar transaction
        // utama, supaya kalau langkah ini gagal, verifikasi pembayarannya tetap tersimpan).
        if ($kodePelangganTerdampak && strtoupper($kodePelangganTerdampak) !== 'UMUM') {
            try {
                $sisaBaru = verifWaHitungSisaUtangPelanggan($pdo, $kodePelangganTerdampak);
                $pdo->prepare("UPDATE pelanggan SET sisa_utang=? WHERE kode_pelanggan=?")->execute([$sisaBaru, $kodePelangganTerdampak]);
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ================================================================
// PASTIKAN TABEL ADA, LALU PROSES AKSI (kalau ada form yang di-submit lewat POST)
// ================================================================
// Semua tombol di halaman ini (Scan, OCR, Simpan Manual, Verifikasi, Tolak)
// mengirim form biasa (bukan AJAX), jadi tiap kali diklik = reload halaman penuh
// dan masuk ke blok if di bawah ini sesuai nilai $_POST['action'].
ensurePaymentProofTable($pdo);
$flash = '';
$error = '';
// Pesan sukses/error dari aksi sebelumnya (kalau tadi diarahkan lewat redirect, lihat
// alasannya di bagian "AKSI SCAN" di bawah) dititipkan lewat session sebentar, karena
// setelah redirect variabel PHP biasa ($flash/$error) sudah hilang.
if (isset($_SESSION['vwa_flash'])) { $flash = $_SESSION['vwa_flash']; unset($_SESSION['vwa_flash']); }
if (isset($_SESSION['vwa_error'])) { $error = $_SESSION['vwa_error']; unset($_SESSION['vwa_error']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'scan') {
            // AKSI "SCAN GAMBAR": baca folder tempat wa-engine menyimpan gambar masuk,
            // daftarkan file-file baru ke tabel payment_proofs_wa.
            // "INSERT IGNORE" + file_hash sebagai unique key -> file yang sama tidak akan
            // didaftarkan dua kali walau tombol Scan dipencet berkali-kali (aman di-scan ulang).
            //
            // Defaultnya cuma scan N hari terakhir ("scan_recent_days" di config) supaya cepat.
            // Tapi kalau kasir mengisi tanggal "Dari" / "Sampai" di form Scan, dipakai rentang
            // tanggal itu -- jadi bisa scan gambar dari tanggal-tanggal sebelumnya juga
            // (mis. ada bukti WA lama yang lewat belum ke-scan).
            $root = projectRoot();
            $dir = $root . '/' . trim(normPath($ocrCfg['wa_downloads_dir']), '/');
            if (!is_dir($dir)) throw new Exception('Folder WhatsApp downloads tidak ditemukan: ' . $dir);
            $allowed = ['jpg','jpeg','png','webp'];

            $scanFrom = trim($_POST['scan_from'] ?? '');
            $scanTo = trim($_POST['scan_to'] ?? '');
            if ($scanFrom !== '') {
                // Rentang tanggal manual diisi kasir.
                $minTime = strtotime($scanFrom . ' 00:00:00');
                $maxTime = $scanTo !== '' ? strtotime($scanTo . ' 23:59:59') : strtotime($scanFrom . ' 23:59:59');
                if ($maxTime < $minTime) { [$minTime, $maxTime] = [$maxTime, $minTime]; } // jaga-jaga kalau kebalik
            } else {
                // Default lama: N hari terakhir sampai sekarang.
                $minTime = time() - ((int)$ocrCfg['scan_recent_days'] * 86400);
                $maxTime = time();
            }

            $count = 0;
            foreach (new DirectoryIterator($dir) as $file) {
                if ($file->isDot() || !$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $allowed, true)) continue;
                $mtime = $file->getMTime();
                if ($mtime < $minTime || $mtime > $maxTime) continue;
                $abs = $file->getPathname();
                $rel = trim(normPath($ocrCfg['wa_downloads_dir']), '/') . '/' . $file->getFilename();
                $hash = hash_file('sha256', $abs);
                $phone = extractPhoneFromWaFile($file->getFilename());
                $received = date('Y-m-d H:i:s', $mtime);
                $stmt = $pdo->prepare("INSERT IGNORE INTO payment_proofs_wa (wa_jid, phone_number, file_name, file_path, file_hash, received_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$phone ? $phone . '@s.whatsapp.net' : null, $phone, $file->getFilename(), $rel, $hash, $received]);
                $count += $stmt->rowCount();
            }
            $rangeInfo = $scanFrom !== '' ? (' (' . date('d/m/Y', $minTime) . ' - ' . date('d/m/Y', $maxTime) . ')') : '';
            $flash = "Scan selesai$rangeInfo. Data baru: $count gambar.";
            if ($scanFrom !== '') {
                // PENTING: form-form aksi (OCR, Verifikasi, dst) di halaman ini tidak punya atribut
                // "action", jadi otomatis submit ke alamat URL yang sedang tampil di address bar
                // browser. Supaya filter "Semua Tanggal" ini betul-betul nempel (tidak balik ke
                // hari ini lagi begitu kasir klik OCR di bukti tanggal lampau), kita REDIRECT
                // sungguhan ke URL yang tgl-nya dikosongkan -- bukan cuma ubah variabel PHP,
                // supaya address bar browser ikut berubah dan diwarisi oleh form-form berikutnya.
                $_SESSION['vwa_flash'] = $flash;
                // Arahkan ke rentang tanggal yang BARU SAJA DI-SCAN persis (bukan "Semua Tanggal"),
                // supaya daftar yang tampil cuma periode itu -- tidak ikut menampilkan bukti-bukti
                // lama di luar periode yang sedang dicari kasir.
                $qsRedirect = http_build_query([
                    'status' => $_GET['status'] ?? 'all',
                    'tgl_dari' => date('Y-m-d', $minTime),
                    'tgl_sampai' => date('Y-m-d', $maxTime),
                    'q' => $_GET['q'] ?? '',
                ]);
                header('Location: ?' . $qsRedirect . '#proof-list');
                exit;
            }
        } elseif ($action === 'ocr') {
            // AKSI "OCR / BACA ULANG": baca 1 gambar bukti pakai Tesseract, lalu ekstrak
            // nominal/tanggal/metode dari teksnya, dan simpan hasilnya ke database.
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("SELECT * FROM payment_proofs_wa WHERE id=?");
            $stmt->execute([$id]);
            $proof = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proof) throw new Exception('Data bukti tidak ditemukan.');
            $abs = projectRoot() . '/' . ltrim(normPath($proof['file_path']), '/');
            if (!file_exists($abs)) throw new Exception('File bukti tidak ditemukan: ' . $abs);
            $text = runOcr($abs, $ocrCfg);
            $amount = extractAmountFromOcr($text);
            $date = parseIndoDate($text) ?: date('Y-m-d', strtotime($proof['received_at'] ?: 'now'));
            $method = detectMethodFromText($text);
            $status = 'pending'; // jangan pindahkan ke matched supaya tetap terlihat setelah OCR
            $pdo->prepare("UPDATE payment_proofs_wa SET ocr_text=?, detected_amount=?, detected_date=?, detected_method=?, status=IF(status='verified', status, ?) WHERE id=?")
                ->execute([$text, $amount, $date, $method, $status, $id]);
            $opts = extractAmountCandidatesFromOcr($text);
            $flash = $amount ? 'OCR selesai. Nominal terdeteksi: ' . rupiah($amount) : ('OCR selesai, tapi nominal utama belum terdeteksi. Panjang teks OCR: ' . strlen($text) . ' karakter. Kandidat angka: ' . count($opts) . '. Klik Lihat teks OCR / tombol kandidat, atau isi manual.');
        } elseif ($action === 'save_manual') {
            // AKSI "SIMPAN NOMINAL" (manual): dipakai kalau OCR gagal/salah baca,
            // kasir isi sendiri nominal/tanggal/metode-nya lewat form.
            $id = (int)$_POST['id'];
            $amount = parseMoney($_POST['manual_amount'] ?? '0');
            $date = $_POST['manual_date'] ?: date('Y-m-d');
            $method = $_POST['manual_method'] ?: 'QRIS';
            $pdo->prepare("UPDATE payment_proofs_wa SET detected_amount=?, detected_date=?, detected_method=?, status=IF(status='verified', status, 'matched') WHERE id=?")
                ->execute([$amount, $date, $method, $id]);
            $flash = 'Nominal/tanggal bukti berhasil disimpan.';
        } elseif ($action === 'verify') {
            // AKSI "VERIFIKASI": panggil verifyTransactions() untuk melunaskan nota
            // (bisa 1 nota atau beberapa nota sekaligus untuk kasus gabungan).
            verifyTransactions($pdo, (int)$_POST['id'], $_POST['targets'] ?? '', $_POST['method'] ?? 'QRIS', $_POST['tgl_verif'] ?: date('Y-m-d'), $_SESSION['user_id']);
            $flash = 'Bukti pembayaran berhasil diverifikasi.';
        } elseif ($action === 'reject') {
            // AKSI "TOLAK": tandai gambar ini BUKAN bukti pembayaran (mis. customer
            // kirim foto lain yang bukan struk transfer), supaya tidak terus muncul di daftar pending.
            $pdo->prepare("UPDATE payment_proofs_wa SET status='rejected', note=? WHERE id=?")->execute([$_POST['note'] ?? 'Bukan bukti pembayaran', (int)$_POST['id']]);
            $flash = 'Bukti ditandai bukan pembayaran.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

// ================================================================
// AMBIL DAFTAR BUKTI UNTUK DITAMPILKAN (filter dari querystring: status, tanggal, pencarian)
// ================================================================
$status = $_GET['status'] ?? 'all';
$q = trim($_GET['q'] ?? '');
// Filter tanggal sekarang berupa RENTANG (dari - sampai), bukan cuma 1 tanggal persis.
// Kalau kedua-duanya tidak diisi sama sekali di URL (kunjungan pertama tanpa filter),
// default-nya tetap tampilkan HARI INI saja (perilaku lama). Kalau salah satu/kedua
// diisi (baik manual oleh kasir, maupun otomatis oleh redirect setelah "Scan Tanggal
// Lain"), dipakai rentang itu apa adanya -- termasuk kalau sengaja dikosongkan untuk
// tampilkan semua tanggal.
if (!isset($_GET['tgl_dari']) && !isset($_GET['tgl_sampai'])) {
    $tglDari = date('Y-m-d');
    $tglSampai = date('Y-m-d');
} else {
    $tglDari = trim($_GET['tgl_dari'] ?? '');
    $tglSampai = trim($_GET['tgl_sampai'] ?? '');
}
$where = [];
$params = [];
if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(file_name LIKE ? OR phone_number LIKE ? OR sender_name LIKE ? OR matched_no_penjualan LIKE ?)'; $params = array_merge($params, array_fill(0,4,'%'.$q.'%')); }
if ($tglDari !== '') { $where[] = 'DATE(received_at) >= ?'; $params[] = $tglDari; }
if ($tglSampai !== '') { $where[] = 'DATE(received_at) <= ?'; $params[] = $tglSampai; }
// Urutan tampil: pending dulu (perlu ditindaklanjuti), lalu matched, verified, rejected paling akhir.
// Dibatasi 100 data terbaru supaya halaman tidak terlalu berat.
$sql = "SELECT * FROM payment_proofs_wa" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY FIELD(status,'pending','matched','verified','rejected'), received_at DESC, id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$proofs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verifikasi Bukti Pembayaran WA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
html,body{min-height:100%}body{background:#f4f6f9;font-family:'Segoe UI',sans-serif;display:flex;align-items:flex-start}.sidebar-container{flex:0 0 260px}.main-content{flex:1;min-width:0;margin-left:0;padding:14px 24px 24px}.proof-img{max-width:210px;max-height:260px;border-radius:12px;border:1px solid #dee2e6;object-fit:contain;background:white;cursor:zoom-in}.proof-modal-img{max-width:100%;max-height:75vh;object-fit:contain;background:#111;border-radius:10px}.card-proof{border:0;border-radius:18px;box-shadow:0 8px 24px rgba(0,0,0,.07)}.ocr-box{font-family:Consolas,monospace;font-size:12px;max-height:160px;overflow:auto;background:#111827;color:#e5e7eb;border-radius:10px;padding:10px}.candidate{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:10px;margin-bottom:8px}.badge-soft{background:#eef2ff;color:#3730a3}.sticky-actions{position:sticky;bottom:0;background:#fff;padding:10px;border-top:1px solid #eee}.small-muted{font-size:.82rem;color:#6b7280}.page-topbar{position:sticky;top:0;z-index:900;background:#f4f6f9;padding:8px 0 10px;margin-top:0}.compact-card .card-body{padding:12px 14px}.page-title h3{font-size:1.35rem}.page-note{font-size:.83rem}.jump-list{white-space:nowrap}.wa-filter-card{margin-bottom:12px!important}.alert-compact{padding:8px 12px;margin-bottom:12px}@media(max-width:992px){body{display:block}.main-content{margin-left:0;padding:8px 10px 18px}.proof-img{max-width:100%;width:100%}.page-title h3{font-size:1.1rem}.page-title .text-muted{font-size:.8rem}.page-topbar{padding-top:6px}.wa-filter-card .form-label{margin-bottom:2px}.wa-filter-card .form-control,.wa-filter-card .form-select,.wa-filter-card .btn{min-height:42px}}
</style>
</head>
<body>
<?php include '../sidebar.php'; ?>
<div class="main-content">
    <div class="page-topbar">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2 page-title">
        <div>
            <h3 class="fw-bold mb-1"><i class="fab fa-whatsapp text-success me-2"></i>Verifikasi Bukti Pembayaran WA</h3>
            <div class="text-muted">OCR gambar bukti pembayaran dari WhatsApp, lalu cocokkan ke transaksi kasir.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-start">
            <a href="#proof-list" class="btn btn-outline-primary shadow-sm jump-list"><i class="fas fa-arrow-down me-1"></i> Ke Daftar</a>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="scan">
                <button class="btn btn-success shadow-sm"><i class="fas fa-sync me-1"></i> Scan Gambar</button>
            </form>
            <button type="button" class="btn btn-outline-secondary shadow-sm" data-bs-toggle="collapse" data-bs-target="#scanTanggalLain">
                <i class="fas fa-calendar-alt me-1"></i> Scan Tanggal Lain
            </button>
        </div>
    </div>
    <div class="collapse mb-2" id="scanTanggalLain">
        <div class="card card-proof compact-card">
            <div class="card-body">
                <div class="small-muted mb-2"><i class="fas fa-info-circle me-1"></i>Tombol "Scan Gambar" biasa cuma mengambil gambar <?= (int)$ocrCfg['scan_recent_days'] ?> hari terakhir. Pakai ini kalau perlu ambil gambar bukti pembayaran dari tanggal-tanggal sebelumnya (mis. ada yang lewat belum ke-scan).</div>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="scan">
                    <div class="col-auto">
                        <label class="form-label small mb-1">Dari tanggal</label>
                        <input type="date" name="scan_from" class="form-control" required value="<?= h(date('Y-m-d', strtotime('-30 days'))) ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Sampai tanggal</label>
                        <input type="date" name="scan_to" class="form-control" value="<?= h(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-secondary"><i class="fas fa-search me-1"></i> Scan Rentang Tanggal Ini</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert alert-success alert-dismissible fade show alert-compact"><?= h($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show alert-compact"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="card card-proof compact-card wa-filter-card">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label small fw-bold">Status</label><select name="status" class="form-select"><option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option><option value="matched" <?= $status==='matched'?'selected':'' ?>>Terdeteksi</option><option value="verified" <?= $status==='verified'?'selected':'' ?>>Terverifikasi</option><option value="rejected" <?= $status==='rejected'?'selected':'' ?>>Ditolak</option><option value="all" <?= $status==='all'?'selected':'' ?>>Semua</option></select></div>
                <div class="col-md-2"><label class="form-label small fw-bold">Dari tanggal</label><input type="date" name="tgl_dari" value="<?= h($tglDari) ?>" class="form-control"></div>
                <div class="col-md-2"><label class="form-label small fw-bold">Sampai tanggal</label><input type="date" name="tgl_sampai" value="<?= h($tglSampai) ?>" class="form-control"></div>
                <div class="col-md-4"><label class="form-label small fw-bold">Cari</label><input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="nama file / nomor WA / no nota"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Filter</button></div>
                <div class="col-12 d-flex gap-2 flex-wrap mt-2">
                    <a class="btn btn-sm btn-outline-success" href="?status=<?= h($status) ?>&tgl_dari=<?= date('Y-m-d') ?>&tgl_sampai=<?= date('Y-m-d') ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><i class="fas fa-calendar-day me-1"></i> Hari Ini</a>
                    <a class="btn btn-sm btn-outline-secondary" href="?status=<?= h($status) ?>&tgl_dari=&tgl_sampai=<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><i class="fas fa-list me-1"></i> Semua Tanggal</a>
                    <span class="small text-muted align-self-center">Kosongkan "Dari"/"Sampai" untuk tidak membatasi tanggal itu. Default kalau belum difilter: hari ini.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-info small alert-compact page-note">
        <b>Catatan:</b> OCR bisa salah baca. Cek preview dulu sebelum verifikasi. Jika nominal tidak terbaca, isi manual lalu klik <b>Simpan Nominal</b>.
    </div>
    </div><!-- /.page-topbar -->

    <div id="proof-list"></div>
    <?php if (!$proofs): ?>
        <div class="card card-proof"><div class="card-body text-center text-muted py-5">Belum ada bukti pembayaran. Klik <b>Scan Gambar WhatsApp</b> untuk mengambil gambar terbaru dari folder WhatsApp.</div></div>
    <?php endif; ?>

    <?php foreach ($proofs as $proof): 
        // Untuk SETIAP bukti yang tampil di daftar ini, dicari kandidat notanya
        // secara langsung di sini (bukan disimpan/di-cache) -- artinya query
        // pencarian kandidat ini jalan ulang tiap kali halaman dibuka/direfresh.
        $amount = (float)$proof['detected_amount'];
        $date = $proof['detected_date'] ?: ($proof['received_at'] ? date('Y-m-d', strtotime($proof['received_at'])) : date('Y-m-d'));
        $matches = $amount > 0 ? getCandidates($pdo, $amount, $date, $proof['phone_number'], $ocrCfg) : ['single'=>[], 'near'=>[], 'combo'=>null, 'pelanggan'=>null];
        $imgUrl = waImageUrl($proof['file_path']);
        $statusBadge = ['pending'=>'secondary','matched'=>'info','verified'=>'success','rejected'=>'danger'][$proof['status']] ?? 'secondary';
    ?>
    <div class="card card-proof mb-3" id="proof-<?= (int)$proof['id'] ?>">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-3">
                    <?php $proofModalId = 'proofImgModal_' . (int)$proof['id']; ?>
                    <button type="button" class="p-0 border-0 bg-transparent" data-bs-toggle="modal" data-bs-target="#<?= h($proofModalId) ?>" title="Klik untuk melihat gambar besar">
                        <img src="<?= h($imgUrl) ?>" class="proof-img" alt="Bukti WA">
                    </button>
                    <div class="small-muted mt-2 text-break"><?= h($proof['file_name']) ?></div>
                    <div class="mt-2 d-flex gap-2 justify-content-center flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= h($proofModalId) ?>">
                            <i class="fas fa-image me-1"></i> Lihat Gambar
                        </button>
                        <a href="<?= h($imgUrl) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-external-link-alt me-1"></i> Tab Baru
                        </a>
                    </div>
                    <div class="modal fade" id="<?= h($proofModalId) ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="fas fa-image me-2"></i>Bukti Pembayaran WA</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body bg-dark text-center">
                                    <img src="<?= h($imgUrl) ?>" class="proof-modal-img" alt="Bukti Pembayaran WA">
                                </div>
                                <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                                    <div class="small text-muted text-break text-start">
                                        <?= h($proof['file_name']) ?><br>
                                        Nominal OCR: <b><?= $amount ? rupiah($amount) : '-' ?></b> • Tanggal: <b><?= h($proof['detected_date'] ?: '-') ?></b>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="<?= h($imgUrl) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">Buka Tab Baru</a>
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2"><span class="badge bg-<?= $statusBadge ?>"><?= h(strtoupper($proof['status'])) ?></span></div>
                </div>
                <div class="col-lg-4">
                    <div class="mb-2"><b>Nomor WA:</b> <?= h($proof['phone_number'] ?: '-') ?></div>
                    <div class="mb-2"><b>Waktu WA:</b> <?= h($proof['received_at'] ?: '-') ?></div>
                    <div class="mb-2"><b>Nominal OCR:</b> <span class="fw-bold text-success"><?= $amount ? rupiah($amount) : '-' ?></span></div>
                    <?php if (!$amount): ?><div class="alert alert-warning py-2 small mb-2">Nominal belum terbaca. Klik <b>Lihat teks OCR</b>. Kalau teks OCR berisi diagnosa/error, berarti Tesseract belum terbaca oleh PHP. Jika OCR kosong, isi nominal manual dulu sebagai cadangan.</div><?php endif; ?>
                    <?php $ocrNominalOptions = (!empty($proof['ocr_text'])) ? array_slice(extractAmountCandidatesFromOcr($proof['ocr_text']), 0, 5) : []; ?>
                    <?php if ($ocrNominalOptions): ?>
                        <div class="mb-2 p-2 border rounded bg-light">
                            <div class="small fw-bold mb-1">Kandidat nominal dari teks OCR:</div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($ocrNominalOptions as $opt): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="save_manual">
                                        <input type="hidden" name="id" value="<?= (int)$proof['id'] ?>">
                                        <input type="hidden" name="manual_amount" value="<?= (int)$opt['amount'] ?>">
                                        <input type="hidden" name="manual_date" value="<?= h($date) ?>">
                                        <input type="hidden" name="manual_method" value="<?= h($proof['detected_method'] ?: 'QRIS') ?>">
                                        <button class="btn btn-sm btn-outline-success" title="<?= h($opt['line']) ?>">Pakai <?= rupiah($opt['amount']) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="mb-2"><b>Tanggal OCR:</b> <?= h($proof['detected_date'] ?: '-') ?></div>
                    <div class="mb-2"><b>Metode:</b> <?= h($proof['detected_method'] ?: '-') ?></div>
                    <?php if ($matches['pelanggan']): ?><div class="alert alert-light border py-2 mb-2"><i class="fas fa-user me-1"></i> Pelanggan terdeteksi: <b><?= h($matches['pelanggan']['nama_pelanggan']) ?></b></div><?php endif; ?>
                    <?php if (empty($proof['ocr_text'])): ?><div class="alert alert-secondary py-2 mb-2 small">Teks OCR masih kosong. Pastikan Tesseract OCR sudah terpasang dan path di <code>config/payment_ocr.php</code> benar.</div><?php elseif (strpos($proof['ocr_text'], '[DIAGNOSA OCR]') !== false): ?><div class="alert alert-danger py-2 mb-2 small"><b>OCR belum jalan.</b> Klik <b>Lihat teks OCR</b> untuk melihat penyebabnya. Biasanya path Tesseract salah atau Tesseract belum terinstall.</div><?php endif; ?>

                    <form method="post" class="row g-2 mt-2">
                        <input type="hidden" name="action" value="save_manual">
                        <input type="hidden" name="id" value="<?= (int)$proof['id'] ?>">
                        <div class="col-6"><label class="form-label small">Nominal manual</label><input class="form-control form-control-sm" name="manual_amount" value="<?= $amount ? h((int)$amount) : '' ?>" placeholder="11200"></div>
                        <div class="col-6"><label class="form-label small">Tanggal</label><input type="date" class="form-control form-control-sm" name="manual_date" value="<?= h($date) ?>"></div>
                        <div class="col-6"><select name="manual_method" class="form-select form-select-sm"><option <?= ($proof['detected_method']==='QRIS')?'selected':'' ?>>QRIS</option><option <?= ($proof['detected_method']==='Transfer')?'selected':'' ?>>Transfer</option><option>GoPay</option></select></div>
                        <div class="col-6"><button class="btn btn-sm btn-outline-primary w-100">Simpan Nominal</button></div>
                    </form>
                </div>
                <div class="col-lg-5">
                    <div class="d-flex gap-2 mb-2">
                        <form method="post"><input type="hidden" name="action" value="ocr"><input type="hidden" name="id" value="<?= (int)$proof['id'] ?>"><button class="btn btn-sm btn-dark"><i class="fas fa-eye me-1"></i> OCR / Baca Ulang</button></form>
                        <?php if ($proof['status'] !== 'verified'): ?><form method="post" onsubmit="return confirm('Tandai gambar ini bukan bukti pembayaran?')"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$proof['id'] ?>"><button class="btn btn-sm btn-outline-danger">Tolak</button></form><?php endif; ?>
                    </div>

                    <h6 class="fw-bold">Kandidat Transaksi</h6>
                    <?php if ($proof['status'] === 'verified'): ?>
                        <div class="alert alert-success py-2">Sudah diverifikasi: <b><?= h($proof['matched_no_penjualan']) ?></b></div>
                    <?php elseif ($amount <= 0): ?>
                        <div class="alert alert-warning py-2">Nominal belum terbaca. Klik <b>OCR / Baca Ulang</b>, cek <b>Kandidat nominal</b>, atau isi nominal manual.</div>
                    <?php else: ?>
                        <?php if ($matches['single']): foreach ($matches['single'] as $cand): ?>
                            <div class="candidate">
                                <div class="d-flex justify-content-between"><b><?= h($cand['no_penjualan']) ?></b><span><?= rupiah($cand['sisa_tagihan']) ?></span></div>
                                <div class="small-muted"><?= h($cand['nama_pelanggan']) ?> • <?= h($cand['tgl_penjualan']) ?> <?= h(substr($cand['jam'],0,5)) ?> • <?= $cand['pelunasan']==='Y'?'Lunas':'Piutang' ?></div>
                                <div class="mt-2 d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= h(modalIdForNota($cand['no_penjualan'])) ?>"><i class="fas fa-receipt me-1"></i> Lihat Nota</button>
                                </div>
                                <?= renderNotaModal($pdo, $cand['no_penjualan']) ?>
                                <form method="post" class="mt-2" onsubmit="return confirm('Verifikasi bukti ini untuk nota <?= h($cand['no_penjualan']) ?>?')">
                                    <input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= (int)$proof['id'] ?>"><input type="hidden" name="targets" value="<?= h($cand['no_penjualan']) ?>"><input type="hidden" name="method" value="<?= h($proof['detected_method'] ?: 'QRIS') ?>"><input type="hidden" name="tgl_verif" value="<?= h($date) ?>">
                                    <button class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i> Verifikasi Nota Ini</button>
                                </form>
                            </div>
                        <?php endforeach; endif; ?>

                        <?php if ($matches['combo']): $targets = implode(',', array_map(fn($r)=>$r['no_penjualan'], $matches['combo'])); $sum = array_sum(array_map(fn($r)=>(float)$r['sisa_tagihan'], $matches['combo'])); ?>
                            <div class="candidate border-success">
                                <div class="fw-bold text-success"><i class="fas fa-layer-group me-1"></i> Nota Gabungan</div>
                                <?php foreach ($matches['combo'] as $r): ?>
                                    <div class="small d-flex justify-content-between gap-2 flex-wrap">
                                        <span>• <?= h($r['no_penjualan']) ?> - <?= h($r['nama_pelanggan']) ?> - <?= rupiah($r['sisa_tagihan']) ?></span>
                                        <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#<?= h(modalIdForNota($r['no_penjualan'])) ?>">Lihat Nota</button>
                                    </div>
                                    <?= renderNotaModal($pdo, $r['no_penjualan']) ?>
                                <?php endforeach; ?>
                                <div class="fw-bold mt-1">Total: <?= rupiah($sum) ?></div>
                                <form method="post" class="mt-2" onsubmit="return confirm('Verifikasi bukti ini untuk nota gabungan?')">
                                    <input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= (int)$proof['id'] ?>"><input type="hidden" name="targets" value="<?= h($targets) ?>"><input type="hidden" name="method" value="<?= h($proof['detected_method'] ?: 'QRIS') ?>"><input type="hidden" name="tgl_verif" value="<?= h($date) ?>">
                                    <button class="btn btn-success btn-sm"><i class="fas fa-check-double me-1"></i> Verifikasi Gabungan</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if ($matches['near']): ?>
                            <div class="small fw-bold text-warning-emphasis mt-2 mb-1"><i class="fas fa-exclamation-triangle me-1"></i> Kandidat Mendekati (nominal tidak pas persis)</div>
                            <?php foreach ($matches['near'] as $cand): $lebih = $cand['selisih_bertanda'] > 0; ?>
                                <div class="candidate border-warning">
                                    <div class="d-flex justify-content-between"><b><?= h($cand['no_penjualan']) ?></b><span><?= rupiah($cand['sisa_tagihan']) ?></span></div>
                                    <div class="small-muted"><?= h($cand['nama_pelanggan']) ?> • <?= h($cand['tgl_penjualan']) ?> <?= h(substr($cand['jam'],0,5)) ?> • <?= $cand['pelunasan']==='Y'?'Lunas':'Piutang' ?></div>
                                    <?php if ($lebih): ?>
                                        <div class="small text-success fw-bold mt-1"><i class="fas fa-piggy-bank me-1"></i>Bayar lebih Rp <?= number_format($cand['selisih'],0,',','.') ?> → otomatis masuk <b>deposit pelanggan</b></div>
                                    <?php else: ?>
                                        <div class="small text-danger fw-bold mt-1"><i class="fas fa-hand-holding-usd me-1"></i>Bayar kurang Rp <?= number_format($cand['selisih'],0,',','.') ?> → sisanya otomatis jadi <b>piutang</b> (nota belum lunas)</div>
                                    <?php endif; ?>
                                    <div class="mt-2 d-flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= h(modalIdForNota($cand['no_penjualan'])) ?>"><i class="fas fa-receipt me-1"></i> Lihat Nota</button>
                                    </div>
                                    <?= renderNotaModal($pdo, $cand['no_penjualan']) ?>
                                    <form method="post" class="mt-2" onsubmit="return confirm('<?= $lebih ? ('Nasabah bayar LEBIH Rp' . number_format($cand['selisih'],0,',','.') . '. Nota ' . h($cand['no_penjualan']) . ' akan ditandai LUNAS dan kelebihannya otomatis masuk ke DEPOSIT pelanggan. Lanjutkan?') : ('Nasabah bayar KURANG Rp' . number_format($cand['selisih'],0,',','.') . ' dari tagihan nota ' . h($cand['no_penjualan']) . '. Nota akan dicatat BELUM LUNAS dan sisanya jadi PIUTANG. Lanjutkan?') ?>')">
                                        <input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= (int)$proof['id'] ?>"><input type="hidden" name="targets" value="<?= h($cand['no_penjualan']) ?>"><input type="hidden" name="method" value="<?= h($proof['detected_method'] ?: 'QRIS') ?>"><input type="hidden" name="tgl_verif" value="<?= h($date) ?>">
                                        <button class="btn btn-warning btn-sm">
                                            <i class="fas fa-check me-1"></i>
                                            <?= $lebih ? ('Verifikasi (lebih Rp'.number_format($cand['selisih'],0,',','.').' → Deposit)') : ('Verifikasi (kurang Rp'.number_format($cand['selisih'],0,',','.').' → Piutang)') ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!$matches['single'] && !$matches['combo'] && !$matches['near']): ?>
                            <div class="alert alert-danger py-2">Tidak ada kandidat cocok, termasuk yang mendekati. Coba koreksi nominal/tanggal manual.</div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($proof['ocr_text'])): ?>
                        <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#ocr<?= (int)$proof['id'] ?>">Lihat teks OCR</button>
                        <div class="collapse mt-2" id="ocr<?= (int)$proof['id'] ?>"><div class="ocr-box"><?= h($proof['ocr_text']) ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Pastikan saat membuka menu halaman dimulai dari bagian atas/filter, bukan posisi scroll lama browser.
if (!location.hash) {
    window.addEventListener('load', function(){ setTimeout(function(){ window.scrollTo({top:0,left:0,behavior:'instant'}); }, 0); });
}
</script>
</body>
</html>
