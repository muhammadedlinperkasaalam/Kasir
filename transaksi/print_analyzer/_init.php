<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header('Location: ../../auth/login.php'); exit; }
require_once __DIR__ . '/../../config/database.php';
$PA_CONFIG = require __DIR__ . '/../../config/print_analyzer.php';

function pa_project_root(): string { return dirname(__DIR__, 2); }
function pa_storage_root(): string { return pa_project_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'print_analyzer'; }
function pa_url(string $path = ''): string {
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    $root = rtrim(dirname($root), '/\\');
    return $root . ($path ? '/' . ltrim($path, '/') : '');
}
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rupiah($v): string { return 'Rp' . number_format((float)$v, 0, ',', '.'); }
function pa_flash(string $key, ?string $message=null): ?string { if ($message!==null){$_SESSION['_pa_flash'][$key]=$message;return null;} $m=$_SESSION['_pa_flash'][$key]??null; unset($_SESSION['_pa_flash'][$key]); return $m; }
function pa_csrf(): string { if(empty($_SESSION['_pa_csrf'])) $_SESSION['_pa_csrf']=bin2hex(random_bytes(32)); return $_SESSION['_pa_csrf']; }
function pa_verify_csrf(): void { if(!hash_equals($_SESSION['_pa_csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))){ http_response_code(419); exit('Token formulir tidak valid. Muat ulang halaman.'); } }
function pa_setting(string $key, $default=null) { global $PA_CONFIG; return $PA_CONFIG[$key] ?? $default; }


function pa_detect_mime(string $path): string {
    if (!is_file($path)) return 'application/octet-stream';
    $mime = 'application/octet-stream';
    if (class_exists('finfo')) {
        $detected = @((new finfo(FILEINFO_MIME_TYPE))->file($path));
        if (is_string($detected) && trim($detected) !== '') $mime = trim($detected);
    }
    return strtolower($mime ?: 'application/octet-stream');
}


function pa_file_has_zip_header(string $path): bool {
    if (!is_file($path)) return false;
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $head = (string)@fread($fh, 4);
    @fclose($fh);
    return substr($head, 0, 2) === 'PK';
}

function pa_mime_is_allowed_for_ext(string $ext, string $mime, string $path = ''): bool {
    $ext = strtolower(ltrim($ext, '.'));
    $mime = strtolower(trim($mime));
    $mimeCompact = preg_replace('/\s+/', '', $mime);

    $allowedMimes = array_map('strtolower', (array)pa_setting('allowed_mimes', []));
    if (in_array($mime, $allowedMimes, true) || in_array($mimeCompact, $allowedMimes, true)) return true;

    // Microsoft Office XML (.docx/.pptx/.xlsx) adalah file ZIP.
    // Di Windows, finfo kadang membaca MIME sebagai openxmlformats, zip, octet-stream,
    // bahkan string openxmlformats ganda/nyambung. Extension tetap wajib valid.
    $genericBinary = [
        'application/octet-stream',
        'application/zip',
        'application/x-zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
    ];

    if (in_array($ext, ['docx', 'pptx', 'xlsx'], true)) {
        if (in_array($mime, $genericBinary, true) || strpos($mime, 'zip') !== false) return true;
        if (strpos($mimeCompact, 'openxmlformats') !== false) return true;
        if (strpos($mimeCompact, 'officedocument') !== false) return true;
        if ($ext === 'docx' && strpos($mimeCompact, 'wordprocessingml.document') !== false) return true;
        if ($ext === 'pptx' && strpos($mimeCompact, 'presentationml.presentation') !== false) return true;
        if ($ext === 'xlsx' && strpos($mimeCompact, 'spreadsheetml.sheet') !== false) return true;
        // fallback aman untuk Office XML: validasi header ZIP PK.
        if ($path !== '' && pa_file_has_zip_header($path)) return true;
    }

    if ($ext === 'doc') {
        return strpos($mime, 'msword') !== false || in_array($mime, ['application/octet-stream'], true);
    }
    if ($ext === 'ppt') {
        return strpos($mime, 'powerpoint') !== false || strpos($mime, 'presentation') !== false || in_array($mime, ['application/octet-stream'], true);
    }
    if ($ext === 'pdf') {
        return strpos($mime, 'pdf') !== false || in_array($mime, ['application/octet-stream'], true);
    }
    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        return strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false || in_array($mime, ['application/octet-stream'], true);
    }
    if ($ext === 'png') {
        return strpos($mime, 'png') !== false || in_array($mime, ['application/octet-stream'], true);
    }

    return false;
}

function pa_validate_file_type(string $originalName, string $path, string $fallbackExt = ''): array {
    $orig = basename($originalName ?: 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' && $fallbackExt !== '') $ext = strtolower(ltrim($fallbackExt, '.'));

    if (!in_array($ext, (array)pa_setting('allowed_extensions', []), true)) {
        throw new RuntimeException('Format file tidak didukung: ' . $orig);
    }

    $mime = pa_detect_mime($path);
    if (!pa_mime_is_allowed_for_ext($ext, $mime, $path)) {
        throw new RuntimeException('MIME tidak diizinkan untuk ' . $orig . ': ' . $mime);
    }

    return [$ext, $mime];
}

function pa_ensure_storage(): void { foreach(['uploads','jobs','converted'] as $d){ $p=pa_storage_root().DIRECTORY_SEPARATOR.$d; if(!is_dir($p)) mkdir($p,0775,true); } }
pa_ensure_storage();

require_once __DIR__ . '/app/AnalyzerSettings.php';
require_once __DIR__ . '/app/ColorAnalyzer.php';
require_once __DIR__ . '/app/DocumentConverter.php';
require_once __DIR__ . '/app/PriceCalculator.php';
require_once __DIR__ . '/app/KasirBridge.php';
require_once __DIR__ . '/app/ModalResultBuilder.php';
