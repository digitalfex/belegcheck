<?php

namespace App\Belegleser;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Spricht mit dem internen Python-Dienst „Belegleser“.
 * POST /qr: nur QR-Codes; POST /lesen: QR-Codes + Text mit Lesesicherheit.
 */
final class BelegleserClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 90,
    ) {}

    public static function ausConfig(): self
    {
        return new self(config('belegcheck.belegleser_url'), config('belegcheck.belegleser_timeout'));
    }

    /**
     * @return list<array{text: string, format: string}> alle im Bild gefundenen Codes
     */
    public function qrCodes(string $pfad): array
    {
        if (! is_readable($pfad)) {
            throw new RuntimeException("Datei nicht lesbar: $pfad");
        }

        $antwort = Http::timeout($this->timeout)
            ->attach('datei', fopen($pfad, 'r'), basename($pfad))
            ->post(rtrim($this->url, '/').'/qr');

        if ($antwort->failed()) {
            throw new RuntimeException('Belegleser antwortet mit HTTP '.$antwort->status().': '.$antwort->body());
        }

        return $antwort->json('codes', []);
    }

    /**
     * QR-Codes und Text in einem Durchgang (Sprint 2).
     *
     * @return array{codes: list<array>, text: string, zeilen: list<array{text: string, sicherheit: float}>, sicherheit: float, quelle: string}
     */
    public function lesen(string $pfad): array
    {
        if (! is_readable($pfad)) {
            throw new RuntimeException("Datei nicht lesbar: $pfad");
        }

        $antwort = Http::timeout($this->timeout)
            ->attach('datei', fopen($pfad, 'r'), basename($pfad))
            ->post(rtrim($this->url, '/').'/lesen');

        if ($antwort->failed()) {
            throw new RuntimeException('Belegleser antwortet mit HTTP '.$antwort->status().': '.$antwort->body());
        }

        return $antwort->json();
    }
}
