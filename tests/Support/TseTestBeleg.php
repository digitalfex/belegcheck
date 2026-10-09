<?php

namespace Tests\Support;

/**
 * Erzeugt technisch korrekte TSE-QR-Inhalte nach DSFinV-K für Tests – mit echtem P-256-Schlüssel
 * (eigener Testschlüssel, keine zertifizierte TSE). Dient ausschließlich zum Testen der eigenen Erkennung.
 */
final class TseTestBeleg
{
    public string $kassenSn = 'KASSE-MUC-1';

    public string $processType = 'Kassenbeleg-V1';

    public string $vorgangstyp = 'Beleg';

    /** Brutto 19 %, 7 %, 10,7 %, 5,5 %, 0 % */
    public array $brutto = ['18.70', '52.00', '0.00', '0.00', '0.00'];

    public ?string $zahlungen = null;   // null = Summe bar

    public string $transaktion = '1326';

    public string $zaehler = '2650';

    public string $start = '2026-10-08T17:30:02.000Z';

    public string $ende = '2026-10-08T17:42:11.000Z';   // = 19:42 deutsche Zeit (MESZ)

    public string $algorithmus = 'ecdsa-plain-SHA256';

    public string $zeitformat = 'unixTime';

    public ?string $publicKey = null;

    private static $schluessel = null;

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

    /** Öffentlicher Schlüssel als unkomprimierter EC-Punkt (65 Byte), BASE64. */
    public static function oeffentlicherSchluessel(): string
    {
        self::$schluessel ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details(self::$schluessel)['ec'];

        return base64_encode("\x04".str_pad($d['x'], 32, "\x00", STR_PAD_LEFT).str_pad($d['y'], 32, "\x00", STR_PAD_LEFT));
    }

    public static function tseSeriennummer(): string
    {
        return hash('sha256', base64_decode(self::oeffentlicherSchluessel()));
    }

    public function summe(): string
    {
        return number_format(array_sum(array_map('floatval', $this->brutto)), 2, '.', '');
    }

    public function qr(): string
    {
        $daten = $this->vorgangstyp.'^'.implode('_', $this->brutto).'^'.($this->zahlungen ?? $this->summe().':Bar');
        $key = $this->publicKey ?? self::oeffentlicherSchluessel();
        openssl_sign($daten, $sig, self::$schluessel, OPENSSL_ALGO_SHA256);

        return implode(';', ['V0', $this->kassenSn, $this->processType, $daten, $this->transaktion, $this->zaehler,
            $this->start, $this->ende, $this->algorithmus, $this->zeitformat, base64_encode($sig), $key]);
    }
}
