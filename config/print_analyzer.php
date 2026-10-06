<?php
// Konfigurasi Print Analyzer terintegrasi kasir.
// Sesuaikan path binary sesuai komputer/server Anda.
return [
    'soffice_bin' => 'C:\Program Files\LibreOffice\program\soffice.exe',
    'ghostscript_bin' => 'C:\Program Files\gs\gs10.07.1\bin\gswin64c.exe',
    'imagemagick_bin' => 'C:\Program Files\ImageMagick-7.1.2-Q16-HDRI\magick.exe',
    'render_dpi' => 120,
    'max_upload_mb' => 300,
    'allowed_extensions' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'],
    'allowed_mimes' => [
        'application/pdf', 'application/x-pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png', 'application/octet-stream', 'application/zip', 'application/x-zip', 'application/x-zip-compressed', 'multipart/x-zip',
    ],
    'categories' => [
        'bw' => 'Hitam Putih',
        'color_25' => 'Warna 25%',
        'color_50' => 'Warna 50%',
        'color_75' => 'Warna 75%',
        'color_100' => 'Warna 100%',
    ],
];
