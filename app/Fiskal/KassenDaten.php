<?php

namespace App\Fiskal;

use App\Fiskal\DsfinvK\DsfinvkBeleg;
use App\Fiskal\Rksv\RksvBeleg;
use Carbon\CarbonImmutable;

/**
 * Was das Kassen-Gedächtnis von einem Beleg braucht – unabhängig davon, ob er aus Österreich (RKSV)
 * oder Deutschland (TSE/DSFinV-K) kommt.
 */
final class KassenDaten
{
    public function __construct(
        public readonly string $land,           // AT | DE
        public readonly string $kennung,        // AT: Kassen-ID · DE: TSE-Seriennummer
        public readonly string $belegnummer,    // AT: Belegnummer · DE: Transaktionsnummer
        public readonly CarbonImmutable $zeit,
        public readonly int $summeCent,
        public readonly ?string $zweitKennung,  // AT: Zertifikat-Seriennummer · DE: Kassen-Seriennummer
    ) {}

    public static function ausRksv(RksvBeleg $b): self
    {
        return new self('AT', $b->kassenId, $b->belegnummer, CarbonImmutable::parse($b->datumUhrzeit, 'Europe/Vienna'), $b->summeCent(), $b->zertifikatSn);
    }

    public static function ausDsfinvk(DsfinvkBeleg $b): ?self
    {
        $tse = $b->tseSeriennummer();
        $ende = $b->ende();

        return $tse && $ende
            ? new self('DE', $tse, $b->transaktionsnummer, $ende->setTimezone('Europe/Berlin'), $b->summeCent() ?? 0, $b->kassenSeriennummer)
            : null;
    }

    /** Regelcodes je Land (Spezifikation: AT-KA-01…05, DE-KA-01…05). */
    public function code(string $regel): string
    {
        $codes = [
            'AT' => ['lokal' => 'AT-KA-01', 'neue_kasse' => 'AT-KA-02', 'zweitkennung' => 'AT-KA-03', 'verlauf' => 'AT-KA-04', 'dublette' => 'AT-KA-05'],
            'DE' => ['lokal' => 'DE-KA-01', 'verlauf' => 'DE-KA-02', 'dublette' => 'DE-KA-03', 'neue_kasse' => 'DE-KA-04', 'zweitkennung' => 'DE-KA-05'],
        ];

        return $codes[$this->land][$regel];
    }

    /** Für Begründungstexte: „Kasse „X““ bzw. „TSE „abcd…““ */
    public function bezeichnung(): string
    {
        return $this->land === 'DE'
            ? 'TSE „'.substr($this->kennung, 0, 12).'…“'
            : 'Kasse „'.$this->kennung.'“';
    }
}
