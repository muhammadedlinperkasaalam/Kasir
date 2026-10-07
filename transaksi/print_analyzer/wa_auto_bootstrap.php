<?php
// Bootstrap khusus WA Auto Analyzer. Tidak memaksa login supaya bisa dipanggil WA Gateway/komputer lokal.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
require_once __DIR__ . '/../../config/database.php';
$PA_CONFIG = require __DIR__ . '/../../config/print_analyzer.php';

if (!function_exists('pa_project_root')) {
    function pa_project_root(): string { return dirname(__DIR__, 2); }
}
if (!function_exists('pa_storage_root')) {
    function pa_storage_root(): string { return pa_project_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'print_analyzer'; }
}
if (!function_exists('pa_setting')) {
    function pa_setting(string $key, $default=null) { global $PA_CONFIG; return $PA_CONFIG[$key] ?? $default; }
}
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('rupiah')) {
    function rupiah($v): string { return 'Rp' . number_format((float)$v, 0, ',', '.'); }
}
if (!function_exists('pa_ensure_storage')) {
    function pa_ensure_storage(): void { foreach(['uploads','jobs','converted','queue'] as $d){ $p=pa_storage_root().DIRECTORY_SEPARATOR.$d; if(!is_dir($p)) mkdir($p,0775,true); } }
}
pa_ensure_storage();

require_once __DIR__ . '/app/AnalyzerSettings.php';
require_once __DIR__ . '/app/ColorAnalyzer.php';
require_once __DIR__ . '/app/DocumentConverter.php';
require_once __DIR__ . '/app/PriceCalculator.php';
require_once __DIR__ . '/app/KasirBridge.php';
require_once __DIR__ . '/app/ModalResultBuilder.php';
require_once __DIR__ . '/app/BackgroundQueue.php';
require_once __DIR__ . '/app/WhatsAppAutoAnalyzer.php';
