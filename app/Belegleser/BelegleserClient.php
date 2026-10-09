<?php

namespace App\Belegleser;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Spricht mit dem internen Python-Dienst „Belegleser“.
 * Sprint 1: nur QR-Dekodierung (POST /qr, Datei als multipart).
 */
final class BelegleserClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 30,
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
}
