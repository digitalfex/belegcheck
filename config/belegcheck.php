<?php

return [
    // Interner Python-Dienst „Belegleser“ (QR-Dekodierung, später Texterkennung)
    'belegleser_url' => env('BELEGLESER_URL', 'http://127.0.0.1:8090'),
    'belegleser_timeout' => (int) env('BELEGLESER_TIMEOUT', 30),
];
