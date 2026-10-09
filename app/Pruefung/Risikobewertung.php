<?php

namespace App\Pruefung;

/**
 * Risikowert 0–100 und Ampel aus einer Liste von Prüfergebnissen.
 *
 * Regeln (Spezifikation):
 * - Summe der Punkte, höchstens 100
 * - Hinweise zählen zusammen höchstens 30 Punkte → Hinweise allein machen nie Rot
 * - Ampel: 0–14 grün, 15–49 gelb, ab 50 rot (ein Widerspruch genügt für Rot)
 */
final class Risikobewertung
{
    public const HINWEIS_DECKEL = 30;

    public const GELB_AB = 15;

    public const ROT_AB = 50;

    /** @param  list<PruefErgebnis>  $ergebnisse */
    public function __construct(public readonly array $ergebnisse) {}

    public function risikowert(): int
    {
        $hinweise = 0;
        $rest = 0;

        foreach ($this->ergebnisse as $e) {
            if ($e->stufe === Stufe::Hinweis) {
                $hinweise += $e->stufe->punkte();
            } else {
                $rest += $e->stufe->punkte();
            }
        }

        return min(100, min(self::HINWEIS_DECKEL, $hinweise) + $rest);
    }

    public function ampel(): string
    {
        $wert = $this->risikowert();

        return match (true) {
            $wert >= self::ROT_AB => 'rot',
            $wert >= self::GELB_AB => 'gelb',
            default => 'gruen',
        };
    }

    /** @return list<PruefErgebnis> nur die Ergebnisse, die ein Mensch sehen soll */
    public function auffaelligkeiten(): array
    {
        return array_values(array_filter(
            $this->ergebnisse,
            fn (PruefErgebnis $e) => $e->stufe !== Stufe::Ok,
        ));
    }
}
