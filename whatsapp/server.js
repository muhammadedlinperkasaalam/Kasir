/**
 * WhatsApp Engine (Baileys + Express)
 * Fix: Download Media, Smart Read & Standarisasi Nomor (@lid jadi @s.whatsapp.net)
 * Patch: Mode HP tetap bunyi - client Baileys dibuat offline/unavailable dan tidak auto-read.
 */

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    downloadMediaMessage, // <-- Kita pakai fungsi bawaan yang paling stabil
    jidNormalizedUser     // <-- FUNGSI BARU UNTUK MENCUCI NOMOR
} = require('@whiskeysockets/baileys');
const express = require('express');
const axios = require('axios'); 
const pino = require('pino');
const fs = require('fs');
const cors = require('cors'); 
const path = require('path'); 

const app = express();
app.use(express.json());
app.use(cors()); 

const PORT = 3000;
const SESSIONS_DIR = './sessions';
const DOWNLOADS_DIR = './downloads'; 

// =======================================================
// MODE NOTIFIKASI HP TETAP BUNYI
// =======================================================
// Default dibuat aman untuk toko: WhatsApp Engine tidak tampil online,
// tidak auto-centang-biru, dan tidak mengirim status mengetik.
// Jika ingin perilaku lama, jalankan dengan env:
// KEEP_PHONE_NOTIFICATIONS=0 WA_AUTO_READ=1 WA_SEND_TYPING=1 node server.js
const KEEP_PHONE_NOTIFICATIONS = process.env.KEEP_PHONE_NOTIFICATIONS !== '0';
const WA_AUTO_READ = process.env.WA_AUTO_READ === '1';
const WA_SEND_TYPING = process.env.WA_SEND_TYPING === '1';

if (!fs.existsSync(SESSIONS_DIR)) fs.mkdirSync(SESSIONS_DIR);
if (!fs.existsSync(DOWNLOADS_DIR)) fs.mkdirSync(DOWNLOADS_DIR);

const sessions = new Map(); 
const qrMap = new Map();    

async function startSession(sessionId) {
    console.log(`[Init] Memulai sesi: ${sessionId}`);
    const sessionPath = `${SESSIONS_DIR}/${sessionId}`;
    
    const { state, saveCreds } = await useMultiFileAuthState(sessionPath);
    const { version } = await fetchLatestBaileysVersion();

    const sock = makeWASocket({
        version,
        auth: state,
        printQRInTerminal: false, 
        logger: pino({ level: 'silent' }),
        browser: ["PHP Gateway", "Chrome", "1.0.0"],
        // Penting: kalau linked-device terlihat online, WhatsApp bisa menahan
        // push notification/ringtone ke HP utama. Mode ini menjaga HP tetap bunyi.
        markOnlineOnConnect: !KEEP_PHONE_NOTIFICATIONS,
    });

    sessions.set(sessionId, sock);
    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            console.log(`[QR] QR Code baru siap discan untuk: ${sessionId}`);
            qrMap.set(sessionId, qr);
        }

        if (connection === 'close') {
            const shouldReconnect = (lastDisconnect.error)?.output?.statusCode !== DisconnectReason.loggedOut;
            console.log(`[Close] Koneksi putus. Reconnect: ${shouldReconnect}`);
            if (shouldReconnect) startSession(sessionId);
            else { sessions.delete(sessionId); qrMap.delete(sessionId); }
        }

        if (connection === 'open') {
            console.log(`[Open] ${sessionId} BERHASIL TERHUBUNG!`);
            qrMap.delete(sessionId);
            if (KEEP_PHONE_NOTIFICATIONS) {
                try {
                    await sock.sendPresenceUpdate('unavailable');
                    console.log(`[Notif] Mode HP tetap bunyi aktif: presence WA Engine = unavailable/offline`);
                } catch (e) {
                    console.log(`[Notif] Gagal set unavailable: ${e.message}`);
                }
            }
        }
    });

    // ===========================================================================
    // Logika inti pemrosesan 1 pesan masuk (download media kalau ada, forward ke
    // webhook PHP). Dipisah jadi fungsi sendiri supaya bisa dipakai ulang oleh:
    // 1. Event 'messages.upsert' -- pesan yang datang langsung/real-time.
    // 2. Event 'messaging-history.set' -- pesan yang TERLEWAT TOTAL karena server
    //    ini sedang mati saat dikirim, lalu baru "disetorkan" WhatsApp begitu
    //    server nyala & konek lagi (lihat penjelasan di bawah).
    // ===========================================================================
    async function processIncomingMessage(msg) {
        if (!msg.message) return;
        if (msg.key.remoteJid === 'status@broadcast') return;

        const isFromMe = msg.key.fromMe === true;

        // FIX: STANDARISASI NOMOR (Mencuci format aneh & @lid)
        let remoteJid = msg.key.remoteJid;
        remoteJid = jidNormalizedUser(remoteJid);
        if (remoteJid.includes('@lid')) {
            remoteJid = remoteJid.replace('@lid', '@s.whatsapp.net');
        }

        let msgContent = msg.message;
        if (msgContent.ephemeralMessage) msgContent = msgContent.ephemeralMessage.message;
        if (msgContent.viewOnceMessage) msgContent = msgContent.viewOnceMessage.message;
        if (msgContent.viewOnceMessageV2) msgContent = msgContent.viewOnceMessageV2.message;
        if (msgContent.documentWithCaptionMessage) msgContent = msgContent.documentWithCaptionMessage.message;

        let savedFilePath = null;

        // FIX 3: EKSTRAKSI TEKS SUPER AMAN UNTUK WA MOBILE & WEB
        let textMessage = msgContent.conversation ||
                          (msgContent.extendedTextMessage && msgContent.extendedTextMessage.text) ||
                          (msgContent.imageMessage && msgContent.imageMessage.caption) ||
                          (msgContent.videoMessage && msgContent.videoMessage.caption) ||
                          (msgContent.documentMessage && msgContent.documentMessage.caption) ||
                          "";

        const messageType = Object.keys(msgContent)[0];

        if (['imageMessage', 'documentMessage', 'videoMessage'].includes(messageType)) {
            if (!isFromMe) {
                // FIX DOWNLOAD GAMBAR OTOMATIS
                try {
                    // Gunakan buffer dari fungsi bawaan Baileys
                    const buffer = await downloadMediaMessage(msg, 'buffer', { }, { logger: pino({ level: 'silent' }) });

                    const mime = msgContent[messageType].mimetype || '';
                    let ext = 'bin';
                    let rawFileName = msgContent[messageType].fileName || '';

                    if (rawFileName) {
                        ext = rawFileName.split('.').pop();
                    } else {
                        // Deteksi otomatis jika tidak ada nama file (kasus gambar WA)
                        if (mime.includes('image/jpeg')) ext = 'jpg';
                        else if (mime.includes('image/png')) ext = 'png';
                        else if (mime.includes('application/pdf')) ext = 'pdf';
                        else if (mime.includes('video/mp4')) ext = 'mp4';
                    }

                    const senderJid = remoteJid.split('@')[0];
                    // Namai "Gambar_WA" jika kosong
                    const cleanName = rawFileName ? rawFileName.replace(/[^a-zA-Z0-9.\-_]/g, '_') : 'Gambar_WA';
                    let finalFileName = `WA_${senderJid}_${Date.now()}_${cleanName}`;

                    if (!finalFileName.endsWith(`.${ext}`)) finalFileName += `.${ext}`;

                    savedFilePath = path.join(DOWNLOADS_DIR, finalFileName);
                    fs.writeFileSync(savedFilePath, buffer);

                    console.log(`[Media] File berhasil di-download: ${finalFileName}`);
                } catch (error) {
                    console.error("[Media Error] Gagal download file:", error.message);
                }
            }
        }

        if (!textMessage && !savedFilePath) return;

        // Jangan centang biru di sini. Kita lempar ke PHP biar PHP yang mutusin.
        try {
            await axios.post('http://localhost:8000/api/internal/webhook', {
                session_id: sessionId,
                from: remoteJid,  // <-- JID yang sudah 100% bersih @s.whatsapp.net
                fromMe: isFromMe,
                message: textMessage,
                media_path: savedFilePath, // Jalur file dikirim ke PHP
                timestamp: msg.messageTimestamp,
                msg_key: msg.key
            });
        } catch (error) {
            console.error(`[Webhook Error] Gagal kirim ke PHP.`, error.message);
        }
    }

    sock.ev.on('messages.upsert', async (m) => {
        // TERIMA 'notify' (dari Web/pelanggan) DAN 'append' (dari HP sendiri)
        if (m.type !== 'notify' && m.type !== 'append') return;

        // FIX: proses SEMUA pesan dalam 1 event upsert, bukan cuma yang pertama.
        // Sebelumnya cuma m.messages[0] yang diproses -- kalau WhatsApp mengirim
        // beberapa pesan sekaligus dalam 1 event (misal customer kirim 2 file
        // beruntun, atau ada beberapa pesan yang "numpuk" saat baru reconnect),
        // pesan ke-2 dan seterusnya diam-diam TIDAK PERNAH diproses sama sekali.
        for (const msg of m.messages) {
            try {
                await processIncomingMessage(msg);
            } catch (error) {
                console.error('[Upsert] Gagal proses salah satu pesan:', error.message);
            }
        }
    });

    // ===========================================================================
    // TANGKAP PESAN YANG TERLEWAT TOTAL (server MATI saat pesan dikirim)
    // ===========================================================================
    // Kasus: kasir lupa nyalakan server. Customer kirim file. Server baru
    // dinyalakan belakangan. Selama ini, pesan seperti itu TIDAK PERNAH sampai
    // ke aplikasi -- karena hanya event 'messages.upsert' yang didengarkan, dan
    // itu cuma untuk pesan yang benar-benar baru/real-time.
    //
    // Begitu WhatsApp berhasil konek lagi, ia mengirimkan pesan-pesan yang
    // "tertinggal" lewat event TERPISAH bernama 'messaging-history.set' (bagian
    // dari mekanisme sinkronisasi riwayat WhatsApp multi-device) -- bukan lewat
    // 'messages.upsert'. Karena event ini sebelumnya tidak didengarkan sama
    // sekali, pesan+file yang terlewat itu hilang begitu saja, tidak pernah
    // diproses ataupun di-download.
    //
    // Di bawah ini kita dengarkan event itu juga, lalu proses pesan-pesan yang:
    // - bukan dari nomor sendiri (fromMe),
    // - umurnya masih wajar (default: 24 jam terakhir -- supaya tidak tiba-tiba
    //   memproses ulang riwayat chat lama bertahun-tahun setiap kali server restart),
    // - belum pernah diproses sebelumnya (dijaga pakai daftar ID pesan di memori).
    const HISTORY_CATCHUP_MAX_AGE_MS = 72 * 60 * 60 * 1000; // 72 jam (3 hari) -- cukup buat kasus "lupa nyalain kasir semalaman/berhari"
    const processedHistoryMsgIds = new Set();

    sock.ev.on('messaging-history.set', async ({ messages }) => {
        if (!messages || !messages.length) return;
        const now = Date.now();

        for (const msg of messages) {
            try {
                if (!msg.message || !msg.key || msg.key.fromMe) continue;

                const msgId = msg.key.id;
                if (!msgId || processedHistoryMsgIds.has(msgId)) continue;

                const tsMs = msg.messageTimestamp ? Number(msg.messageTimestamp) * 1000 : 0;
                if (!tsMs || (now - tsMs) > HISTORY_CATCHUP_MAX_AGE_MS) continue; // terlalu lama, lewati

                processedHistoryMsgIds.add(msgId);
                console.log(`[History Sync] Memproses pesan yang terlewat saat server mati: ${msgId}`);
                await processIncomingMessage(msg);
            } catch (error) {
                console.error('[History Sync] Gagal proses pesan lama:', error.message);
            }
        }
    });
}

// --- ENDPOINTS ---
app.post('/session/start', async (req, res) => {
    const { sessionId } = req.body;
    if (!sessionId) return res.status(400).json({ error: 'Session ID required' });
    if (sessions.has(sessionId)) return res.json({ status: 'ALREADY_ACTIVE' });
    startSession(sessionId);
    res.json({ status: 'INITIALIZING' });
});

app.get('/session/qr/:id', (req, res) => {
    const { id } = req.params;
    const qr = qrMap.get(id);
    if (!qr) {
        if (sessions.has(id) && !qrMap.has(id)) return res.json({ status: 'CONNECTED' });
        return res.status(404).json({ error: 'QR belum siap' });
    }
    res.json({ status: 'QR_READY', qr: qr });
});

// DIPANGGIL PHP JIKA AI AKTIF (Untuk Centang Biru & Mengetik)
app.post('/chat/mark-typing', async (req, res) => {
    const { sessionId, to, msg_key } = req.body;
    const sock = sessions.get(sessionId);
    if (!sock) return res.status(404).json({ error: 'Session not found' });
    try {
        let jid = to.includes('@') ? to : `${to}@s.whatsapp.net`;
        jid = jid.replace('@lid', '@s.whatsapp.net'); // Jaga-jaga jika PHP memanggil pakai @lid

        // Jangan auto-read/centang biru kecuali WA_AUTO_READ=1
        if (WA_AUTO_READ && msg_key) await sock.readMessages([msg_key]);

        // Jangan kirim status mengetik kecuali WA_SEND_TYPING=1
        if (WA_SEND_TYPING) {
            await sock.sendPresenceUpdate('composing', jid);
        } else if (KEEP_PHONE_NOTIFICATIONS) {
            try { await sock.sendPresenceUpdate('unavailable'); } catch(e) {}
        }

        res.json({
            status: 'SUCCESS',
            auto_read: WA_AUTO_READ ? 'ON' : 'OFF',
            typing: WA_SEND_TYPING ? 'ON' : 'OFF',
            keep_phone_notifications: KEEP_PHONE_NOTIFICATIONS ? 'ON' : 'OFF'
        });
    } catch (error) { res.status(500).json({ error: error.message }); }
});

app.post('/chat/send', async (req, res) => {
    const { sessionId, to, text } = req.body;
    const sock = sessions.get(sessionId);
    if (!sock) return res.status(404).json({ error: 'Session not found' });
    try {
        let jid = to.includes('@') ? to : `${to}@s.whatsapp.net`;
        jid = jid.replace('@lid', '@s.whatsapp.net');

        const sentMsg = await sock.sendMessage(jid, { text: text });
        
        try {
            if (!KEEP_PHONE_NOTIFICATIONS) {
                await sock.sendPresenceUpdate('paused', jid); // Stop Mengetik
                await sock.chatModify({ markRead: false, lastMessages: [sentMsg] }, jid); // Titik Hijau Admin
            } else {
                await sock.sendPresenceUpdate('unavailable');
            }
        } catch(e) {}

        res.json({ status: 'SUCCESS', id: sentMsg.key.id });
    } catch (error) { res.status(500).json({ error: 'Failed' }); }
});

app.post('/chat/send-media', async (req, res) => {
    const { sessionId, to, type, url, caption, filename } = req.body;
    const sock = sessions.get(sessionId);
    if (!sock) return res.status(404).json({ error: 'Session not found' });
    try {
        let jid = to.includes('@') ? to : `${to}@s.whatsapp.net`;
        jid = jid.replace('@lid', '@s.whatsapp.net');

        const mediaObject = {};
        if (type === 'image') { mediaObject.image = { url: url }; mediaObject.caption = caption || ''; } 
        else if (type === 'document') { mediaObject.document = { url: url }; mediaObject.mimetype = 'application/pdf'; mediaObject.fileName = filename || 'doc.pdf'; mediaObject.caption = caption || ''; } 
        
        const sentMsg = await sock.sendMessage(jid, mediaObject);
        
        try {
            if (!KEEP_PHONE_NOTIFICATIONS) {
                await sock.sendPresenceUpdate('paused', jid);
                await sock.chatModify({ markRead: false, lastMessages: [sentMsg] }, jid);
            } else {
                await sock.sendPresenceUpdate('unavailable');
            }
        } catch(e) {}

        res.json({ status: 'SUCCESS', id: sentMsg.key.id });
    } catch (error) { res.status(500).json({ error: 'Failed' }); }
});

app.delete('/session/logout/:id', async (req, res) => {
    const { id } = req.params;
    const sessionPath = `${SESSIONS_DIR}/${id}`;
    if (sessions.has(id)) {
        const sock = sessions.get(id);
        try { sock.logout(); } catch(e) { try { sock.end(undefined); } catch(err) {} }
        sessions.delete(id);
    }
    if (fs.existsSync(sessionPath)) fs.rmSync(sessionPath, { recursive: true, force: true });
    qrMap.delete(id);
    res.json({ status: 'SUCCESS' });
});

app.listen(PORT, () => console.log(`WA ENGINE BERJALAN DI PORT ${PORT}`));