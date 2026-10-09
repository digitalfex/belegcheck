<?php

namespace App\Pruefung;

/**
 * Verdichtet einen Prüfbericht zu drei Angaben für ERP-Systeme:
 *
 *  - score (0–100): 100 − Risikowert. Grenzen wie die Ampel: grün ab 86, rot bis 50.
 *    100 heißt „keine Auffälligkeit gefunden“, nicht „echt“.
 *  - abdeckung (0–1): wie viel überhaupt geprüft werden konnte. Ein Beleg mit Kassen-QR-Code und
 *    bestätigtem Aussteller ist gut abgedeckt; ein handschriftlicher Beleg kaum.
 *  - empfehlung: automatisch_freigeben | stichprobe | manuell_pruefen. Es gibt bewusst kein „ablehnen“ –
 *    die Entscheidung trifft immer ein Mensch.
 */
final class Gesamturteil
{
    /** Gewichte der Prüfbereiche für die Abdeckung (Summe 1) */
    public const BEREICHE = ['fiskal' => 0.45, 'druck' => 0.15, 'aussteller' => 0.2, 'dubletten' => 0.1, 'bild' => 0.1];

    public function __construct(private readonly array $bericht) {}

    public function score(): int
    {
        return max(0, 100 - (int) ($this->bericht['risikowert'] ?? 0));
    }

    /** @return array<string, float> Anteil je Bereich (0–1) */
    public function bereiche(): array
    {
        $codes = collect($this->bericht['ergebnisse'] ?? [])->keyBy('code');
        $stufe = fn (string $code) => $codes[$code]['stufe'] ?? null;
        $geprueft = fn (?string $s) => $s !== null && $s !== Stufe::NichtPruefbar->value;
        $gedruckt = $this->bericht['gedruckt'] ?? [];

        $fiskal = match (true) {
            ($this->bericht['qr_typ'] ?? null) !== null => 1.0,
            $stufe('DE-TX-01') === Stufe::Ok->value => 0.6, // TSE-Angaben im Klartext
            default => 0.0,
        };
        $druck = match (true) {
            ($this->bericht['qr_typ'] ?? null) !== null && ($gedruckt['gesamt_cent'] ?? null) !== null => 1.0,
            ($gedruckt['gesamt_cent'] ?? null) !== null => 0.3, // gelesen, aber nichts zum Vergleichen
            default => 0.0,
        };
        $aussteller = ($geprueft($stufe('AS-01')) ? 0.6 : 0.0) + ($geprueft($stufe('AS-03')) && $stufe('AS-03') !== Stufe::Hinweis->value ? 0.4 : 0.0);

        return [
            'fiskal' => $fiskal,
            'druck' => $druck,
            'aussteller' => min(1.0, $aussteller),
            'dubletten' => isset($this->bericht['datei_sha256']) ? 1.0 : 0.0,
            'bild' => isset($this->bericht['forensik']) ? 1.0 : 0.0,
        ];
    }

    public function abdeckung(): float
    {
        $summe = 0.0;
        foreach ($this->bereiche() as $bereich => $anteil) {
            $summe += self::BEREICHE[$bereich] * $anteil;
        }

        return round($summe, 2);
    }

    /**
     * @param  array{freigabe_ab?: int, min_abdeckung?: float}  $schwellen  je Mandant
     */
    public function empfehlung(array $schwellen = []): string
    {
        $widerspruch = collect($this->bericht['ergebnisse'] ?? [])->contains('stufe', Stufe::Widerspruch->value);

        return self::empfehlungAus($this->score(), $this->abdeckung(), $widerspruch, $schwellen);
    }

    public static function empfehlungAus(int $score, float $abdeckung, bool $widerspruch, array $schwellen = []): string
    {
        return match (true) {
            $widerspruch || $score < (int) ($schwellen['freigabe_ab'] ?? 86) => 'manuell_pruefen',
            $abdeckung >= (float) ($schwellen['min_abdeckung'] ?? 0.6) => 'automatisch_freigeben',
            default => 'stichprobe', // unauffällig, aber wenig prüfbar
        };
    }

    /** Rangfolge für Zusammenfassungen (Stapel): die strengste Empfehlung gewinnt */
    public const EMPFEHLUNG_RANG = ['automatisch_freigeben' => 0, 'stichprobe' => 1, 'manuell_pruefen' => 2];
}
