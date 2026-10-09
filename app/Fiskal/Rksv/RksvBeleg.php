<?php

namespace App\Fiskal\Rksv;

/**
 * Geparster Inhalt eines RKSV-QR-Codes (Anlage RKSV, Z 5 und Z 12).
 *
 * Aufbau: _RKA_Kassen-ID_Belegnummer_Datum-Uhrzeit_Normal_Erm1_Erm2_Null_Besonders_Umsatzzaehler_Zertifikat-SN_Sig-Voriger_Signatur
 */
final class RksvBeleg
{
    /** Reihenfolge der Steuersätze im QR-Code. */
    public const SATZ_FELDER = ['normal', 'ermaessigt1', 'ermaessigt2', 'null', 'besonders'];

    /**
     * @param  array<string, int>  $betraegeCent  Beträge je Satz in Cent, Schlüssel aus SATZ_FELDER
     */
    public function __construct(
        public readonly string $rohtext,
        public readonly string $algorithmus,       // z. B. R1-AT1
        public readonly string $kassenId,
        public readonly string $belegnummer,
        public readonly string $datumUhrzeit,      // JJJJ-MM-TTThh:mm:ss, österreichische Lokalzeit
        public readonly array $betraegeCent,
        public readonly string $umsatzzaehler,     // BASE64
        public readonly string $zertifikatSn,
        public readonly string $sigVorigerBeleg,   // BASE64
        public readonly string $signatur,          // BASE64 (Standard)
    ) {}

    public function summeCent(): int
    {
        return array_sum($this->betraegeCent);
    }

    /** Vertrauensdiensteanbieter-Kennung, z. B. "AT1"; "AT0" = geschlossenes System. */
    public function vda(): ?string
    {
        return preg_match('/^R1-(AT\d+)$/', $this->algorithmus, $m) ? $m[1] : null;
    }

    public function toArray(): array
    {
        return [
            'algorithmus' => $this->algorithmus,
            'kassen_id' => $this->kassenId,
            'belegnummer' => $this->belegnummer,
            'datum_uhrzeit' => $this->datumUhrzeit,
            'betraege_cent' => $this->betraegeCent,
            'summe_cent' => $this->summeCent(),
            'umsatzzaehler' => $this->umsatzzaehler,
            'zertifikat_sn' => $this->zertifikatSn,
            'sig_voriger_beleg' => $this->sigVorigerBeleg,
            'signatur' => $this->signatur,
        ];
    }
}
