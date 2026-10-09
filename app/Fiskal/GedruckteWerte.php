<?php

namespace App\Fiskal;

/**
 * Vom Beleg gelesene (gedruckte) Werte mit Lesesicherheit 0..1.
 * In Sprint 1 von Hand oder als JSON übergeben, ab Sprint 2 aus Texterkennung + Sprachmodell.
 */
final class GedruckteWerte
{
    /** Unter dieser Lesesicherheit wird eine Abweichung nur als Hinweis gemeldet (möglicher Lesefehler). */
    public const SICHER_AB = 0.8;

    /**
     * @param  array<string, int>|null  $betraegeJeSatzCent  Schlüssel wie RksvBeleg::SATZ_FELDER bzw. DsfinvkBeleg::SATZ_FELDER
     * @param  array<string, float>  $lesesicherheit  je Feldname (gesamt, betraege, datum_uhrzeit, kassen_id)
     */
    public function __construct(
        public readonly ?int $gesamtCent = null,
        public readonly ?array $betraegeJeSatzCent = null,
        public readonly ?string $datumUhrzeit = null,   // JJJJ-MM-TT hh:mm[:ss]
        public readonly ?string $kassenId = null,
        public readonly array $lesesicherheit = [],
        public readonly ?string $tseSeriennummer = null, // nur DE: gedruckte TSE-Seriennummer
    ) {}

    public function sicher(string $feld): bool
    {
        return ($this->lesesicherheit[$feld] ?? 1.0) >= self::SICHER_AB;
    }

    public static function fromArray(array $d): self
    {
        return new self(
            gesamtCent: $d['gesamt_cent'] ?? null,
            betraegeJeSatzCent: $d['betraege_je_satz_cent'] ?? null,
            datumUhrzeit: $d['datum_uhrzeit'] ?? null,
            kassenId: $d['kassen_id'] ?? null,
            lesesicherheit: $d['lesesicherheit'] ?? [],
        );
    }
}
