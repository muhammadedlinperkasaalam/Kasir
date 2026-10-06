<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/android_clipboard_lib.php';
android_clipboard_ensure_table($pdo);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Android Clipboard Customer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f3f4f6;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
.wrap{max-width:520px;margin:0 auto;padding:14px;}
.card-main{border:0;border-radius:20px;box-shadow:0 6px 22px rgba(15,23,42,.12);overflow:hidden;}
.name-box{font-size:1.6rem;font-weight:900;line-height:1.2;word-break:break-word;color:#111827;}
.big-copy{font-size:1.25rem;font-weight:900;padding:16px;border-radius:16px;}
.status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;background:#22c55e;margin-right:6px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="fw-black mb-0">Clipboard Android</h4>
      <div class="text-muted small">Biarkan halaman ini terbuka di HP</div>
    </div>
    <span class="badge bg-success"><span class="status-dot"></span>Aktif</span>
  </div>

  <div class="card card-main">
    <div class="card-body p-4 text-center">
      <div class="text-muted fw-bold small text-uppercase mb-2">Nama Customer Terbaru</div>
      <div id="namaBox" class="name-box mb-2">Menunggu transaksi...</div>
      <div id="notaBox" class="text-muted small mb-3">Simpan transaksi dari PC</div>
      <button id="btnCopy" class="btn btn-primary w-100 big-copy" disabled>
        <i class="fas fa-copy me-2"></i>Copy Manual (cadangan)
      </button>
      <div id="copyInfo" class="mt-3 small fw-bold text-muted">Auto refresh setiap 2 detik.</div>
      <div id="pollStatus" class="mt-1 small text-muted"></div>
    </div>
  </div>

  <div class="alert alert-info mt-3 small mb-0">
    Setelah kasir PC menyimpan transaksi non-Umum, nama customer muncul di sini. <b>Cukup sentuh layar di mana saja</b> — nama otomatis tersalin ke clipboard, tidak perlu cari tombol.
  </div>
</div>
<script>
let lastId = Number(localStorage.getItem('android_clipboard_last_id') || '0');
let currentItem = null;
let copiedIds = new Set(); // supaya item yang sama tidak dicoba-copy berkali-kali setelah berhasil
const namaBox = document.getElementById('namaBox');
const notaBox = document.getElementById('notaBox');
const btnCopy = document.getElementById('btnCopy');
const copyInfo = document.getElementById('copyInfo');
const pollStatus = document.getElementById('pollStatus');

async function copyToClipboard(text){
  try{
    if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(text); return true; }
  }catch(e){}
  try{
    const ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length); const ok=document.execCommand('copy'); ta.remove(); return !!ok;
  }catch(e){ return false; }
}
async function markDone(id){
  try{ await fetch('android_clipboard_done.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})}); }catch(e){}
}
function showCopiedFeedback(nama){
  copyInfo.className='mt-3 small fw-bold text-success';
  copyInfo.textContent='Otomatis tersalin: ' + nama;
  btnCopy.disabled = true;
  document.body.style.transition = 'background-color .15s';
  document.body.style.backgroundColor = '#dcfce7';
  setTimeout(() => { document.body.style.backgroundColor = ''; }, 350);
  if(navigator.vibrate) navigator.vibrate(60);
}
// Dipanggil dari sentuhan/klik APAPUN di halaman ini. Browser cuma mengizinkan
// penulisan ke clipboard kalau dipicu oleh interaksi user (tap/klik/keyboard) —
// jadi trik di sini: sentuhan di mana saja di layar dipakai sebagai "izin"
// itu, bukan cuma tombol Copy Nama. Data yang sudah berhasil disalin tidak
// dicoba ulang supaya tidak berulang-ulang menyalin nama yang sama.
async function tryAutoCopyFromGesture(){
  if(!currentItem || copiedIds.has(currentItem.id)) return;
  const item = currentItem;
  const ok = await copyToClipboard(item.nama_pelanggan || '');
  if(ok){
    copiedIds.add(item.id);
    showCopiedFeedback(item.nama_pelanggan);
    await markDone(item.id);
  }else{
    // Diblokir browser (jarang terjadi kalau memang dari sentuhan asli) — biarkan tombol manual jadi cadangan.
    copyInfo.className='mt-3 small fw-bold text-danger';
    copyInfo.textContent='Gagal auto-copy, tap tombol Copy Nama sebagai cadangan.';
  }
}
document.addEventListener('pointerdown', tryAutoCopyFromGesture, { passive: true });
document.addEventListener('keydown', tryAutoCopyFromGesture);

async function poll(){
  try{
    const r = await fetch('android_clipboard_feed.php?after=' + encodeURIComponent(lastId) + '&_=' + Date.now());
    const j = await r.json();
    if(pollStatus) pollStatus.textContent = 'Polling aktif: ' + new Date().toLocaleTimeString();
    if(j.status === 'success' && j.item){
      currentItem = j.item;
      lastId = Number(j.item.id || lastId);
      localStorage.setItem('android_clipboard_last_id', String(lastId));
      namaBox.textContent = j.item.nama_pelanggan || '-';
      notaBox.textContent = (j.item.no_penjualan ? ('Nota: ' + j.item.no_penjualan + ' · ') : '') + (j.item.created_at || '');
      btnCopy.disabled = false;
      copyInfo.className = 'mt-3 small fw-bold text-primary';
      copyInfo.textContent = 'Data baru masuk. Sentuh layar untuk auto-copy.';
      if(navigator.vibrate) navigator.vibrate([80,50,80]);
      // Coba langsung juga (kalau kebetulan masih dalam jendela aktivasi dari sentuhan sebelumnya, ini akan berhasil tanpa perlu sentuh lagi).
      tryAutoCopyFromGesture();
    }
  }catch(e){ copyInfo.textContent='Koneksi polling gagal, mencoba lagi...'; }
}
btnCopy.addEventListener('click', async function(){
  if(!currentItem) return;
  const ok = await copyToClipboard(currentItem.nama_pelanggan || '');
  if(ok){
    copiedIds.add(currentItem.id);
    showCopiedFeedback(currentItem.nama_pelanggan);
    await markDone(currentItem.id);
  }else{
    copyInfo.className='mt-3 small fw-bold text-danger';
    copyInfo.textContent='Gagal copy. Coba tekan lagi.';
  }
});
setInterval(poll, 2000);
poll();
</script>
</body>
</html>
