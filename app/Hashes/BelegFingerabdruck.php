<?php

namespace App\Hashes;

/**
 * Hashes zur Erkennung von Doppel-Einreichungen (MU-DU-01, -04, -05).
 *
 * - datei():  SHA-256 der Datei → exakt dieselbe Datei
 * - inhalt(): SHA-256 über normalisierte Kernfelder → dieselbe Rechnung, egal welches Foto
 * - text():   64-Bit-SimHash über den erkannten Text → fast gleicher Text (OCR weicht je Foto leicht ab)
 */
final class BelegFingerabdruck
{
    /** Ab diesem Bit-Abstand gelten zwei Text-Hashes nicht mehr als ähnlich (von 64 Bit). */
    public const TEXT_SCHWELLE = 6;

    public static function datei(string $pfad): string
    {
        return hash_file('sha256', $pfad);
    }

    /**
     * @param  string|null  $ausstellerSchluessel  UID (bevorzugt) oder Lokalname
     * @param  string|null  $datum  JJJJ-MM-TT
     * @param  string|null  $uhrzeit  hh:mm
     */
    public static function inhalt(?string $ausstellerSchluessel, ?string $datum, ?string $uhrzeit, ?int $gesamtCent, ?string $belegnummer): ?string
    {
        // Ohne Aussteller, Datum und Betrag ist der Inhalts-Hash nicht aussagekräftig.
        if (! $ausstellerSchluessel || ! $datum || $gesamtCent === null) {
            return null;
        }

        $teile = [
            self::norm($ausstellerSchluessel),
            $datum,
            $uhrzeit ? substr($uhrzeit, 0, 5) : '',
            (string) $gesamtCent,
            $belegnummer ? self::norm($belegnummer) : '',
        ];

        return hash('sha256', implode('|', $teile));
    }

    /** 64-Bit-SimHash als 16-stelliger Hex-String; null bei zu wenig Text. */
    public static function text(string $ocrText): ?string
    {
        $norm = self::norm($ocrText, behalteLeerzeichen: true);
        $woerter = array_values(array_filter(explode(' ', $norm), fn ($w) => $w !== ''));

        if (count($woerter) < 5) {
            return null;
        }

        // Merkmale: einzelne Wörter + Wortpaare. Einzelwörter machen den Hash robust
        // gegen Zeilenumbrüche und einzelne Lesefehler, Wortpaare halten die Reihenfolge fest.
        $merkmale = $woerter;
        for ($i = 0; $i + 1 < count($woerter); $i++) {
            $merkmale[] = $woerter[$i].' '.$woerter[$i + 1];
        }

        $gewichte = array_fill(0, 64, 0);
        foreach ($merkmale as $merkmal) {
            $h = substr(hash('sha256', $merkmal, true), 0, 8);
            for ($bit = 0; $bit < 64; $bit++) {
                $byte = ord($h[intdiv($bit, 8)]);
                $gewichte[$bit] += (($byte >> ($bit % 8)) & 1) ? 1 : -1;
            }
        }

        $bytes = '';
        for ($b = 0; $b < 8; $b++) {
            $byte = 0;
            for ($k = 0; $k < 8; $k++) {
                if ($gewichte[$b * 8 + $k] > 0) {
                    $byte |= 1 << $k;
                }
            }
            $bytes .= chr($byte);
        }

        return bin2hex($bytes);
    }

    /** Anzahl unterschiedlicher Bits zweier Text-Hashes (0 = gleich, 64 = völlig verschieden). */
    public static function abstand(string $hexA, string $hexB): int
    {
        $a = hex2bin($hexA);
        $b = hex2bin($hexB);
        $diff = 0;
        for ($i = 0; $i < 8; $i++) {
            $x = ord($a[$i]) ^ ord($b[$i]);
            while ($x) {
                $diff += $x & 1;
                $x >>= 1;
            }
        }

        return $diff;
    }

    public static function aehnlich(string $hexA, string $hexB): bool
    {
        return self::abstand($hexA, $hexB) <= self::TEXT_SCHWELLE;
    }

    private static function norm(string $s, bool $behalteLeerzeichen = false): string
    {
        $s = mb_strtolower($s);
        // Umlaute vereinheitlichen und typische OCR-Verwechslung O/0 in Zahlen ausgleichen
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/(?<=\d)o|o(?=\d)/u', '0', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s);
        $s = preg_replace('/\s+/u', $behalteLeerzeichen ? ' ' : '', $s);

        return trim($s);
    }
}
