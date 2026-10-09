<?php

namespace App\Fiskal;

/**
 * Antwort des Sprachmodells → geprüfte Werte.
 *
 * Schutz gegen erfundene Werte: Ein Betrag zählt nur, wenn er in einer der beiden Lesungen tatsächlich
 * vorkommt; ein Datum nur, wenn Tag, Monat und Uhrzeit im Text stehen. Alles andere wird verworfen.
 * KI-Werte gelten nie als sicher gelesen (Lesesicherheit 0,7) – eine Abweichung zum QR-Code
 * führt deshalb höchstens zu einem Hinweis, nie zu einem Widerspruch.
 */
final class KiLesung
{
    public const SICHERHEIT = 0.7;

    private string $ziffern;

    private string $text;

    /**
     * @param  array<string, mixed>  $antwort  vom Sprachmodell
     */
    public function __construct(private readonly array $antwort, string $lesungA, string $lesungB, private readonly ?string $land = null)
    {
        $this->text = $lesungA."\n".$lesungB;
        // Für den Betragsabgleich: Lesefehler glätten („70, 70“ → „70,70“), Punkt = Komma
        $this->ziffern = str_replace('.', ',', BelegTextAuswertung::zahlenGlaetten($this->text));
    }

    public function gesamtCent(): ?int
    {
        return $this->betrag($this->antwort['gesamtbetrag'] ?? null);
    }

    /** "JJJJ-MM-TT hh:mm" oder null */
    public function datumUhrzeit(): ?string
    {
        $datum = (string) ($this->antwort['datum'] ?? '');
        $zeit = (string) ($this->antwort['uhrzeit'] ?? '');
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $d) || ! preg_match('/^(\d{1,2}):(\d{2})/', $zeit, $z)) {
            return null;
        }
        if (! checkdate((int) $d[2], (int) $d[3], (int) $d[1]) || (int) $z[1] > 23 || (int) $z[2] > 59) {
            return null;
        }
        $tag = (int) $d[3];
        $monat = (int) $d[2];
        // Tag und Monat nebeneinander (TT.MM., TT/MM, JJJJ-MM-TT) und die Uhrzeit müssen im Text stehen
        $datumImText = preg_match('/(?<!\d)0?'.$tag.'\s?[.\/-]\s?0?'.$monat.'(?!\d)/u', $this->text)
            || preg_match('/(?<!\d)'.$d[1].'-'.$d[2].'-'.$d[3].'/u', $this->text);
        $zeitImText = preg_match('/(?<!\d)0?'.(int) $z[1].'\s?[:.]\s?'.$z[2].'(?!\d)/u', $this->text);

        return $datumImText && $zeitImText ? sprintf('%s-%s-%s %02d:%s', $d[1], $d[2], $d[3], $z[1], $z[2]) : null;
    }

    /** @return array<string, int>|null Brutto je Satzfeld (Schlüssel wie BelegTextAuswertung::SATZ_FELD/_DE) */
    public function betraegeJeSatz(): ?array
    {
        $felder = $this->land === 'DE' ? BelegTextAuswertung::SATZ_FELD_DE : BelegTextAuswertung::SATZ_FELD;
        $ergebnis = [];
        foreach ((array) ($this->antwort['steuersaetze'] ?? []) as $s) {
            $satz = str_replace('.', ',', rtrim(preg_replace('/[^\d,.]/', '', (string) ($s['satz'] ?? '')), ',.'));
            $satz = preg_replace('/,0+$/', '', $satz);
            $feld = $felder[$satz] ?? null;
            if ($feld === null) {
                continue;
            }
            $brutto = $this->betrag($s['brutto'] ?? null);
            $netto = $this->betrag($s['netto'] ?? null);
            $steuer = $this->betrag($s['steuer'] ?? null);
            $p = (float) str_replace(',', '.', $satz);
            if ($brutto === null && $netto !== null && $steuer !== null && abs($netto * $p / 100 - $steuer) <= 2) {
                $brutto = $netto + $steuer; // nur wenn rechnerisch stimmig
            }
            if ($brutto !== null) {
                $ergebnis[$feld] = $brutto;
            }
        }

        return $ergebnis !== [] ? $ergebnis : null;
    }

    public function aussteller(): ?string
    {
        $name = trim((string) ($this->antwort['aussteller'] ?? ''));

        return $name !== '' && mb_strlen($name) <= 80 ? $name : null;
    }

    public function belegnummer(): ?string
    {
        $nr = trim((string) ($this->antwort['belegnummer'] ?? ''));

        return $nr !== '' && str_contains(mb_strtolower(str_replace(' ', '', $this->text)), mb_strtolower(str_replace(' ', '', $nr))) ? $nr : null;
    }

    /** Betrag "46,00" → 4600, nur wenn er so (nach Glättung) in einer Lesung vorkommt. */
    private function betrag(mixed $wert): ?int
    {
        if (! is_string($wert) && ! is_numeric($wert)) {
            return null;
        }
        $roh = str_replace(' ', '', (string) $wert);
        if (! preg_match('/^-?(\d{1,3}(?:[.\s]?\d{3})*|\d+)[,.](\d{2})$/', $roh, $m)) {
            return null;
        }
        $euro = str_replace(['.', ' '], '', $m[1]);
        $cent = (int) $euro * 100 + (int) $m[2];
        $suche = ltrim($euro, '0').','.$m[2];
        if ($suche === ','.$m[2]) {
            $suche = '0'.$suche;
        }
        // Tausendertrennzeichen im Text sind nach der Normalisierung ebenfalls Kommas: 1.092,60 → 1,092,60
        $mitTausender = strlen($euro) > 3 ? substr($euro, 0, -3).','.substr($euro, -3).','.$m[2] : null;

        return preg_match('/(?<![\d,])'.preg_quote($suche, '/').'(?![\d])/', $this->ziffern)
            || ($mitTausender && str_contains($this->ziffern, $mitTausender)) ? $cent : null;
    }
}
