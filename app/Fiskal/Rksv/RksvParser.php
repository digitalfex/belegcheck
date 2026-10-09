<?php

namespace App\Fiskal\Rksv;

/**
 * Zerlegt den Inhalt eines RKSV-QR-Codes in seine 13 Felder.
 *
 * Der Parser prüft nur die Struktur (Präfix, Feldanzahl, Betragsformat), damit er
 * überhaupt ein RksvBeleg-Objekt liefern kann. Alle inhaltlichen Prüfungen macht der RksvPruefer.
 */
final class RksvParser
{
    /** Erkennt, ob ein QR-Text vermutlich ein RKSV-Code ist (für die Landeserkennung). */
    public static function istRksv(string $text): bool
    {
        return str_starts_with(trim($text), '_R1-AT');
    }

    /**
     * @throws RksvParseFehler
     */
    public function parse(string $text): RksvBeleg
    {
        $text = trim($text);

        if (! str_starts_with($text, '_')) {
            throw new RksvParseFehler('Der Code beginnt nicht mit „_“ wie im RKSV-Format vorgesehen.');
        }

        $teile = explode('_', $text);

        // Führender Unterstrich ergibt ein leeres erstes Element → 14 Teile für 13 Felder.
        if (count($teile) !== 14) {
            throw new RksvParseFehler(sprintf(
                'Der Code hat %d statt 13 Felder.',
                count($teile) - 1,
            ));
        }

        [, $rka, $kassenId, $belegnr, $datum, $b1, $b2, $b3, $b4, $b5, $zaehler, $zertSn, $sigVoriger, $signatur] = $teile;

        $betraege = [];
        foreach (array_combine(RksvBeleg::SATZ_FELDER, [$b1, $b2, $b3, $b4, $b5]) as $feld => $wert) {
            $cent = self::betragInCent($wert);
            if ($cent === null) {
                throw new RksvParseFehler(sprintf('Betrag „%s“ (Satz %s) ist keine Zahl mit 2 Kommastellen.', $wert, $feld));
            }
            $betraege[$feld] = $cent;
        }

        return new RksvBeleg(
            rohtext: $text,
            algorithmus: $rka,
            kassenId: $kassenId,
            belegnummer: $belegnr,
            datumUhrzeit: $datum,
            betraegeCent: $betraege,
            umsatzzaehler: $zaehler,
            zertifikatSn: $zertSn,
            sigVorigerBeleg: $sigVoriger,
            signatur: $signatur,
        );
    }

    /**
     * "12,50" → 1250; "-3,00" → -300. Akzeptiert laut Verordnung nur Komma mit genau 2 Stellen.
     * Ein Punkt als Dezimalzeichen wird toleriert (an echten Belegen zu verifizieren).
     */
    public static function betragInCent(string $wert): ?int
    {
        if (! preg_match('/^(-?)(\d+)[,.](\d{2})$/', $wert, $m)) {
            return null;
        }

        $cent = (int) $m[2] * 100 + (int) $m[3];

        return $m[1] === '-' ? -$cent : $cent;
    }
}
