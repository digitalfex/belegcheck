<?php

namespace Tests\Support;

/**
 * Erzeugt technisch korrekte RKSV-QR-Inhalte für Tests – mit echter ES256-Signatur
 * (eigener Testschlüssel, nicht von einem Vertrauensdiensteanbieter).
 * Dient ausschließlich zum Testen der eigenen Erkennung.
 */
final class RksvTestBeleg
{
    public string $algorithmus = 'R1-AT1';

    public string $kassenId = 'KASSE-01';

    public string $belegnummer = '4711';

    public string $datumUhrzeit = '2026-10-08T19:42:11';

    /** @var array<string, string> */
    public array $betraege = [
        'normal' => '12,40',      // 20 % Getränke
        'ermaessigt1' => '31,80', // 10 % Speisen
        'ermaessigt2' => '0,00',
        'null' => '0,00',
        'besonders' => '0,00',
    ];

    public ?string $umsatzzaehler = null;   // BASE64; null = zufällige 8 Byte

    public string $zertifikatSn = '3a7f19c2';

    public ?string $sigVoriger = null;      // BASE64; null = zufällige 8 Byte

    public bool $ausfall = false;

    public static function neu(): self
    {
        return new self;
    }

    public function mit(string $feld, mixed $wert): self
    {
        $k = clone $this;
        $k->$feld = $wert;

        return $k;
    }

    public function training(): self
    {
        return $this->mit('umsatzzaehler', base64_encode('TRA'));
    }

    public function storno(): self
    {
        return $this->mit('umsatzzaehler', base64_encode('STO'));
    }

    public function startbeleg(): self
    {
        return $this->mit('sigVoriger', base64_encode(substr(hash('sha256', $this->kassenId, true), 0, 8)));
    }

    public function qr(): string
    {
        $payload = '_'.implode('_', [
            $this->algorithmus,
            $this->kassenId,
            $this->belegnummer,
            $this->datumUhrzeit,
            ...array_values($this->betraege),
            $this->umsatzzaehler ?? base64_encode(random_bytes(8)),
            $this->zertifikatSn,
            $this->sigVoriger ?? base64_encode(random_bytes(8)),
        ]);

        $signatur = $this->ausfall ? 'Sicherheitseinrichtung ausgefallen' : self::es256($payload);

        return $payload.'_'.base64_encode($signatur);
    }

    /** ES256-Signatur im JWS-Format (r||s, 64 Byte). */
    private static function es256(string $daten): string
    {
        static $key = null;
        $key ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        openssl_sign($daten, $der, $key, OPENSSL_ALGO_SHA256);

        // DER-SEQUENCE(INTEGER r, INTEGER s) → r||s mit je 32 Byte
        $pos = 2;
        $teile = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\x00");
            $teile[] = str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $teile[0].$teile[1];
    }
}
