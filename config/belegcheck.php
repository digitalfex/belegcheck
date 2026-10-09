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

    // Aussteller-Screening (Schicht 2): UID im EU-Register, Lokal und Öffnungszeiten in OpenStreetMap.
    // Es gehen nur Firmendaten (UID, Name, PLZ-Koordinaten) nach außen, nie Daten der Einreichenden.
    'screening' => [
        'aktiv' => (bool) env('BELEG_SCREENING', true),
        'vies_url' => env('BELEG_VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
        'overpass_url' => env('BELEG_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'timeout' => (int) env('BELEG_SCREENING_TIMEOUT', 8),
    ],

    // Schnittstelle /api/v1
    'api' => [
        'anfragen_je_minute' => (int) env('BELEG_API_ANFRAGEN_JE_MINUTE', 120),
        'max_mb' => (int) env('BELEG_API_MAX_MB', 25),
    ],
];
