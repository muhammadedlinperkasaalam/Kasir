<?php
require_once __DIR__.'/wa_auto_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function pa_wa_json($arr, int $code=200): void { http_response_code($code); echo json_encode($arr, JSON_INVALID_UTF8_IGNORE); exit; }
try{
    $raw=$_POST['wa_files'] ?? $_POST['wa_file'] ?? [];
    if(is_string($raw) && $raw!=='') $raw=[$raw];
    if(!is_array($raw) || !$raw) throw new RuntimeException('File WhatsApp belum dipilih.');
    $opts=[
        'kode_pelanggan'=>trim((string)($_POST['kode_pelanggan'] ?? '')),
        'nama_pelanggan'=>trim((string)($_POST['nama_pelanggan'] ?? '')),
        'no_telp'=>trim((string)($_POST['no_telp'] ?? '')),
        'nomor_wa'=>trim((string)($_POST['nomor_wa'] ?? ($_POST['no_telp'] ?? ''))),
        'qty_cetak'=>max(1,(int)($_POST['qty_cetak'] ?? 1)),
        'pages_per_sheet'=>(int)($_POST['pages_per_sheet'] ?? 1),
        'finishing'=>trim((string)($_POST['finishing'] ?? '')),
        'halaman'=>trim((string)($_POST['halaman'] ?? '')),
        'borderless'=>!empty($_POST['borderless']) && (string)$_POST['borderless']!=='0',
        'orientation'=>trim((string)($_POST['orientation'] ?? 'auto')),
        'print_grayscale'=>!empty($_POST['print_grayscale']) && (string)$_POST['print_grayscale']!=='0',
    ];
    pa_wa_json(PA_WhatsAppAutoAnalyzer::resultForFiles($pdo,$raw,$opts));
}catch(Throwable $e){ pa_wa_json(['status'=>'error','message'=>$e->getMessage()],400); }
