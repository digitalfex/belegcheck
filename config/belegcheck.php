<?php

return [
    // Interner Python-Dienst „Belegleser“ (QR-Dekodierung, später Texterkennung)
    'belegleser_url' => env('BELEGLESER_URL', 'http://127.0.0.1:8090'),
    'belegleser_timeout' => (int) env('BELEGLESER_TIMEOUT', 90),

    // Lokales Sprachmodell (llama.cpp, z. B. das Qwen des Bescheidwissers auf 127.0.0.1:8081).
    // Leer = aus. Nur Adressen auf diesem Rechner werden angesprochen.
    'sprachmodell' => [
        'url' => env('BELEG_SPRACHMODELL_URL'),
        'timeout' => (int) env('BELEG_SPRACHMODELL_TIMEOUT', 180),
        // false: nur fragen, wenn Werte fehlen oder nicht zum QR-Code passen; true: bei jedem Beleg
        'immer' => (bool) env('BELEG_SPRACHMODELL_IMMER', false),
    ],
];
