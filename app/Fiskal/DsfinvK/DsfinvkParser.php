<?php

namespace App\Fiskal\DsfinvK;

/**
 * Zerlegt einen TSE-QR-Code nach DSFinV-K (Anhang I). Prüft nur die Struktur; Inhalte prüft der DsfinvkPruefer.
 */
final class DsfinvkParser
{
    public static function istDsfinvk(string $text): bool
    {
        return str_starts_with(trim($text), 'V0;');
    }

    /** @throws DsfinvkParseFehler */
    public function parse(string $text): DsfinvkBeleg
    {
        $teile = explode(';', trim($text));
        if (count($teile) !== 12) {
            throw new DsfinvkParseFehler(sprintf('Der Code hat %d statt 12 Felder.', count($teile)));
        }

        [$version, $kasse, $typ, $daten, $tanr, $zaehler, $start, $ende, $alg, $format, $sig, $key] = $teile;

        [$vorgangstyp, $brutto, $zahlungen] = $typ === 'Kassenbeleg-V1' ? self::processData($daten) : [null, null, null];

        return new DsfinvkBeleg($text, $version, $kasse, $typ, $daten, $vorgangstyp, $brutto, $zahlungen,
            $tanr, $zaehler, $start, $ende, $alg, $format, $sig, $key);
    }

    /**
     * "Beleg^75.33_7.99_0.00_0.00_0.00^10.00:Bar_5.00:Bar:CHF_75.32:Unbar"
     *
     * @return array{0: ?string, 1: ?array<string, int>, 2: ?list<array>}
     */
    public static function processData(string $daten): array
    {
        $teile = explode('^', $daten);
        if (count($teile) !== 3) {
            return [null, null, null];
        }
        [$vorgangstyp, $betraege, $zahlungsteil] = $teile;

        $werte = explode('_', $betraege);
        if (count($werte) !== 5) {
            return [$vorgangstyp, null, null];
        }
        $brutto = [];
        foreach (array_combine(DsfinvkBeleg::SATZ_FELDER, $werte) as $feld => $wert) {
            $cent = self::cent($wert);
            if ($cent === null) {
                return [$vorgangstyp, null, null];
            }
            $brutto[$feld] = $cent;
        }

        $zahlungen = [];
        foreach ($zahlungsteil === '' ? [] : explode('_', $zahlungsteil) as $z) {
            $f = explode(':', $z);
            $cent = self::cent($f[0] ?? '');
            if ($cent === null || ! in_array($f[1] ?? '', ['Bar', 'Unbar'], true)) {
                return [$vorgangstyp, $brutto, null];
            }
            $zahlungen[] = ['cent' => $cent, 'art' => $f[1], 'waehrung' => $f[2] ?? null];
        }

        return [$vorgangstyp, $brutto, $zahlungen];
    }

    /** DSFinV-K: Punkt als Dezimalzeichen, 2 Nachkommastellen ("75.33", "-20.00"). Tausenderkomma wird toleriert. */
    public static function cent(string $wert): ?int
    {
        $wert = str_replace(',', '', $wert);
        if (! preg_match('/^(-?)(\d+)\.(\d{2})$/', $wert, $m)) {
            return null;
        }
        $cent = (int) $m[2] * 100 + (int) $m[3];

        return $m[1] === '-' ? -$cent : $cent;
    }
}
