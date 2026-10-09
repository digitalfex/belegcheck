<?php

namespace App\Pruefung;

/**
 * Abgleich der Angaben aus der Spesenabrechnung (vom ERP mitgeschickt) mit dem Beleg:
 *  EA-01 eingereichter Betrag = Belegsumme, EA-02 eingereichtes Datum = Belegdatum.
 */
final class AngabenAbgleich
{
    /**
     * @param  array{betrag?: string|int|float|null, datum?: string|null}  $angaben
     * @return list<PruefErgebnis>
     */
    public function pruefe(array $angaben, ?int $belegCent, bool $belegSicher, ?string $belegDatum): array
    {
        $ergebnisse = [];

        if (($angaben['betrag'] ?? null) !== null && ($eingereicht = self::cent($angaben['betrag'])) !== null) {
            $ergebnisse[] = match (true) {
                $belegCent === null => new PruefErgebnis('EA-01', Stufe::NichtPruefbar, 'Belegsumme nicht lesbar – eingereichter Betrag nicht vergleichbar.'),
                $eingereicht === $belegCent => PruefErgebnis::ok('EA-01'),
                $eingereicht < $belegCent => new PruefErgebnis('EA-01', Stufe::Ok,
                    'Eingereicht wurde weniger als die Belegsumme (z. B. nur ein Teil dienstlich).', ['eingereicht' => self::eur($eingereicht), 'beleg' => self::eur($belegCent)]),
                ! $belegSicher => new PruefErgebnis('EA-01', Stufe::Hinweis,
                    'Eingereichter Betrag höher als die gelesene Belegsumme – die Summe ist aber unsicher gelesen. Bitte am Bild prüfen.', ['eingereicht' => self::eur($eingereicht), 'beleg' => self::eur($belegCent)]),
                default => new PruefErgebnis('EA-01', Stufe::Auffaellig,
                    'Eingereichter Betrag ist höher als die Summe auf dem Beleg (Trinkgeld separat vermerkt?).', ['eingereicht' => self::eur($eingereicht), 'beleg' => self::eur($belegCent)]),
            };
        }

        if (($angaben['datum'] ?? null) !== null && ($tag = self::tag((string) $angaben['datum'])) !== null) {
            $belegTag = $belegDatum ? substr($belegDatum, 0, 10) : null;
            $abstand = $belegTag ? abs((strtotime($tag) - strtotime($belegTag)) / 86400) : null;
            $ergebnisse[] = match (true) {
                $abstand === null => new PruefErgebnis('EA-02', Stufe::NichtPruefbar, 'Belegdatum nicht lesbar – eingereichtes Datum nicht vergleichbar.'),
                $abstand <= 1 => PruefErgebnis::ok('EA-02'), // Mitternacht, Zeitzonen
                default => new PruefErgebnis('EA-02', Stufe::Hinweis, "Eingereichtes Datum weicht {$abstand} Tage vom Belegdatum ab.", ['eingereicht' => $tag, 'beleg' => $belegTag]),
            };
        }

        return $ergebnisse;
    }

    /** "46,00", "46.00", 46, 46.0 → 4600; Ganzzahl über 10.000 ohne Komma gilt nicht als Cent-Angabe */
    public static function cent(string|int|float $wert): ?int
    {
        if (is_int($wert) || is_float($wert)) {
            return (int) round($wert * 100);
        }
        $w = str_replace([' ', '€', 'EUR'], '', trim($wert));
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d{1,2}$/', $w)) {
            $w = str_replace(['.', ','], ['', '.'], $w);
        } elseif (preg_match('/^-?\d+,\d{1,2}$/', $w)) {
            $w = str_replace(',', '.', $w);
        }

        return is_numeric($w) ? (int) round((float) $w * 100) : null;
    }

    private static function tag(string $datum): ?string
    {
        $ts = strtotime($datum);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function eur(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' €';
    }
}
