<?php

namespace App\Fiskal;

use App\Fiskal\Rksv\RksvBeleg;
use App\Fiskal\Rksv\RksvParser;

/**
 * Liest gedruckte Werte aus dem erkannten Text eines Belegs (Sprint 2).
 *
 * Grundsatz „QR-geführt“: Gibt es einen Kassen-QR-Code, sucht die Auswertung zuerst den QR-Wert im Text.
 * Wird er gefunden, gilt er als gedruckt bestätigt. Nur wenn er fehlt, wird der Wert aus dem Text
 * herausgezogen – mit reduzierter Lesesicherheit, wenn die Zuordnung unsicher ist. So führen Spalten
 * oder Layouts, die die Regeln nicht kennen, zu einem Hinweis statt zu einem falschen Widerspruch.
 */
final class BelegTextAuswertung
{
    /** Lesesicherheit, wenn ein Wert zwar gefunden, aber nicht sicher einer Spalte zugeordnet ist. */
    private const UNSICHERE_ZUORDNUNG = 0.6;

    private const SUMMEN_WORT = '/\b(summe|gesamt(?:betrag|summe)?|total[e]?|zu\s*zahlen|zahlbetrag|endbetrag|rechnungsbetrag|importo|montant|amount\s+due)\b/iu';

    private const SATZ_FELD = ['20' => 'normal', '10' => 'ermaessigt1', '13' => 'ermaessigt2', '0' => 'null', '19' => 'besonders', '4,9' => 'besonders'];

    /** @var list<array{text: string, sicherheit: float}> */
    private array $zeilen;

    /**
     * @param  list<array{text: string, sicherheit: float}>  $zeilen  aus dem Belegleser
     */
    public function __construct(array $zeilen)
    {
        $this->zeilen = array_values(array_filter($zeilen, fn ($z) => trim($z['text']) !== ''));
    }

    public static function ausText(string $text, float $sicherheit = 1.0): self
    {
        return new self(array_map(fn ($t) => ['text' => $t, 'sicherheit' => $sicherheit], explode("\n", $text)));
    }

    public function text(): string
    {
        return implode("\n", array_column($this->zeilen, 'text'));
    }

    /** Gedruckte Werte, bei Bedarf geführt durch den QR-Inhalt. */
    public function gedruckteWerte(?RksvBeleg $qr = null): GedruckteWerte
    {
        $sicherheit = [];

        [$gesamt, $sicherheit['gesamt']] = $this->gesamt($qr);
        [$betraege, $sicherheit['betraege']] = $this->betraegeJeSatz($qr);
        [$datum, $sicherheit['datum_uhrzeit']] = $this->datumUhrzeit($qr);
        [$kasse, $sicherheit['kassen_id']] = $this->kassenId($qr);

        return new GedruckteWerte($gesamt, $betraege, $datum, $kasse, array_filter($sicherheit, fn ($s) => $s !== null));
    }

    /** UID des Ausstellers (AT oder DE), für Schicht 2 und den Inhalts-Hash. */
    public function uid(): ?string
    {
        foreach ($this->zeilen as $z) {
            if (preg_match('/\b(ATU\s?\d{8}|DE\s?\d{9})\b/u', $z['text'], $m)) {
                return str_replace(' ', '', $m[1]);
            }
        }

        return null;
    }

    /** Erste Textzeile = meist der Name des Lokals. */
    public function aussteller(): ?string
    {
        return $this->zeilen[0]['text'] ?? null;
    }

    // ------------------------------------------------------------------

    /** @return array{0: ?int, 1: ?float} */
    private function gesamt(?RksvBeleg $qr): array
    {
        // Summenzeilen samt Betrag; steht der Betrag in einer eigenen Zeile (schiefes Foto), die Nachbarzeile nehmen
        $kandidaten = [];
        foreach ($this->zeilen as $i => $z) {
            if (! preg_match(self::SUMMEN_WORT, $z['text']) || preg_match('/\b(netto|mwst|ust|steuer|zwischen)/iu', $z['text'])) {
                continue;
            }
            $betraege = self::betraege($z['text']);
            if ($betraege === []) {
                foreach ([$i + 1, $i - 1] as $n) {
                    $nachbar = $this->zeilen[$n]['text'] ?? '';
                    if (preg_match('/^\D{0,6}-?[\d.,]+\s*(€|eur)?$/iu', trim($nachbar)) && ($b = self::betraege($nachbar)) !== []) {
                        $betraege = $b;
                        break;
                    }
                }
            }
            if ($betraege !== []) {
                $kandidaten[] = ['betraege' => $betraege, 'sicherheit' => $z['sicherheit']];
            }
        }

        if ($kandidaten === []) {
            return [null, null];
        }

        if ($qr) {
            foreach ($kandidaten as $k) {
                if (in_array($qr->summeCent(), $k['betraege'], true)) {
                    return [$qr->summeCent(), $k['sicherheit']];
                }
            }
        }

        $wert = end($kandidaten[0]['betraege']);
        $sicherheit = $kandidaten[0]['sicherheit'];

        // Weicht der gelesene Betrag nur in einer Ziffer vom QR ab, ist ein Lesefehler wahrscheinlich (0↔9, 1↔7, 3↔8 …)
        if ($qr && self::eineZifferAnders($wert, $qr->summeCent())) {
            $sicherheit = min($sicherheit, self::UNSICHERE_ZUORDNUNG);
        }

        return [$wert, $sicherheit];
    }

    private static function eineZifferAnders(int $a, int $b): bool
    {
        $a = (string) abs($a);
        $b = (string) abs($b);
        if (strlen($a) !== strlen($b)) {
            return false;
        }

        return count(array_diff_assoc(str_split($a), str_split($b))) === 1;
    }

    /** @return array{0: ?array<string, int>, 1: ?float} */
    private function betraegeJeSatz(?RksvBeleg $qr): array
    {
        $gefunden = [];
        $sicherheiten = [];
        $unsicher = false;

        foreach ($this->zeilen as $z) {
            if (! preg_match('/(?:^|[\s:A-D])(20|10|13|19|4,9|0)\s?%/u', $z['text'], $m)) {
                continue;
            }
            $feld = self::SATZ_FELD[$m[1]];
            $betraege = self::betraege(substr($z['text'], strpos($z['text'], $m[0]) + strlen($m[0])));
            if ($betraege === [] || isset($gefunden[$feld])) {
                continue;
            }

            $qrWert = $qr?->betraegeCent[$feld] ?? null;
            if ($qrWert !== null && in_array($qrWert, $betraege, true)) {
                $gefunden[$feld] = $qrWert;
            } else {
                // Brutto steht meist rechts – Zuordnung aber unsicher (Spaltenlayouts variieren)
                $gefunden[$feld] = end($betraege);
                $unsicher = true;
            }
            $sicherheiten[] = $z['sicherheit'];
        }

        if ($gefunden === []) {
            return [null, null];
        }

        // Steuertabelle unvollständig gelesen? Dann nicht vergleichen.
        if ($qr) {
            foreach ($qr->betraegeCent as $feld => $cent) {
                if ($cent !== 0 && ! isset($gefunden[$feld])) {
                    return [null, null];
                }
            }
        }

        $sicherheit = min($sicherheiten);

        return [$gefunden, $unsicher ? min($sicherheit, self::UNSICHERE_ZUORDNUNG) : $sicherheit];
    }

    /** @return array{0: ?string, 1: ?float} Format "JJJJ-MM-TT hh:mm" */
    private function datumUhrzeit(?RksvBeleg $qr): array
    {
        $muster = '/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})\b.*?\b(\d{1,2}):(\d{2})(?::\d{2})?\b/u';
        $treffer = [];

        // Datum und Uhrzeit in einer Zeile, sonst Datum in einer und Uhrzeit in der nächsten Zeile
        foreach ($this->zeilen as $i => $z) {
            $text = $z['text'];
            if (! preg_match($muster, $text) && isset($this->zeilen[$i + 1])) {
                $text .= ' '.$this->zeilen[$i + 1]['text'];
            }
            if (preg_match($muster, $text, $m)) {
                $jahr = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];
                $treffer[] = [sprintf('%04d-%02d-%02d %02d:%02d', $jahr, $m[2], $m[1], $m[4], $m[5]), $z['sicherheit']];
            }
        }

        if ($treffer === []) {
            return [null, null];
        }

        if ($qr) {
            $qrWert = substr(str_replace('T', ' ', $qr->datumUhrzeit), 0, 16);
            foreach ($treffer as $t) {
                if ($t[0] === $qrWert) {
                    return $t;
                }
            }
        }

        return $treffer[0];
    }

    /** @return array{0: ?string, 1: ?float} */
    private function kassenId(?RksvBeleg $qr): array
    {
        if ($qr) {
            $gesucht = self::norm($qr->kassenId);
            foreach ($this->zeilen as $z) {
                if ($gesucht !== '' && str_contains(self::norm($z['text']), $gesucht)) {
                    return [$qr->kassenId, $z['sicherheit']];
                }
            }
        }

        foreach ($this->zeilen as $z) {
            if (preg_match('/kassen[\s-]?(?:id|1d|nr\.?|nummer)\s*[:#]?\s*(\S+)/iu', $z['text'], $m)) {
                // Fast gleich wie im QR (ein Zeichen anders) → wahrscheinlich Lesefehler
                $fastGleich = $qr && levenshtein(self::norm($m[1]), self::norm($qr->kassenId)) <= 1;

                return [$m[1], $fastGleich ? min($z['sicherheit'], self::UNSICHERE_ZUORDNUNG) : $z['sicherheit'] * 0.8];
            }
        }

        // Keine Kassen-ID im Text: nicht prüfbar (viele Bons drucken sie nur im QR-Code)
        return [null, null];
    }

    /** Alle Geldbeträge einer Zeile in Cent, z. B. "2 x Schnitzel 37,80" → [3780]. */
    public static function betraege(string $text): array
    {
        preg_match_all('/(?<![\d,.])-?\d{1,3}(?:\.\d{3})*,\d{2}(?![\d,])|(?<![\d,.])-?\d+\.\d{2}(?![\d.])/u', $text, $m);

        return array_map(function ($b) {
            $b = str_contains($b, ',') ? str_replace(['.', ','], ['', '.'], $b) : $b;

            return (int) round((float) $b * 100);
        }, $m[0]);
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/[\s\-_.:]+/u', '', $s));
    }

    /** Hilfsfunktion: QR-Text → RksvBeleg oder null. */
    public static function rksvAusQr(?string $qr): ?RksvBeleg
    {
        if ($qr === null) {
            return null;
        }
        try {
            return (new RksvParser)->parse($qr);
        } catch (\Throwable) {
            return null;
        }
    }
}
