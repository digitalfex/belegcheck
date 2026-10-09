<?php

namespace App\Fiskal;

use App\Fiskal\DsfinvK\DsfinvkBeleg;
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

    public const SATZ_FELD = ['20' => 'normal', '10' => 'ermaessigt1', '13' => 'ermaessigt2', '0' => 'null', '19' => 'besonders', '4,9' => 'besonders'];

    public const SATZ_FELD_DE = ['19' => 'allgemein', '7' => 'ermaessigt', '10,7' => 'durchschnitt_10_7', '5,5' => 'durchschnitt_5_5', '0' => 'null'];

    /** @var list<array{text: string, sicherheit: float}> */
    private array $zeilen;

    /**
     * @param  list<array{text: string, sicherheit: float}>  $zeilen  aus dem Belegleser
     */
    /**
     * @param  list<array{text: string, sicherheit: float}>  $alternativen  Zeilen weiterer Lesevarianten (andere Bildaufbereitung)
     */
    public function __construct(array $zeilen, private readonly array $alternativen = [])
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

    /** Gedruckte Werte, bei Bedarf geführt durch den QR-Inhalt (AT: RKSV, DE: DSFinV-K). */
    public function gedruckteWerte(RksvBeleg|DsfinvkBeleg|null $qr = null): GedruckteWerte
    {
        $f = self::fuehrung($qr);
        $w = $this->felder($f);

        // Weicht ein Feld vom QR-Code ab, aber eine andere Lesevariante hat den QR-Wert gelesen,
        // war es ein Lesefehler → Wert der anderen Variante übernehmen.
        if ($f && $this->alternativen !== []) {
            $alt = (new self($this->alternativen))->felder($f);
            $soll = [
                'gesamt' => $f['summe'],
                'betraege' => array_filter($f['betraege']),
                'datum_uhrzeit' => $f['zeit'] ? substr(str_replace('T', ' ', $f['zeit']), 0, 16) : null,
                'kassen_id' => $f['kasse'],
                'tse' => $f['tse'],
            ];
            // Steuersätze einzeln: je Satz den Wert der Variante nehmen, die den QR-Wert gelesen hat
            if ($w['betraege'][0] !== null && $alt['betraege'][0] !== null) {
                foreach ($soll['betraege'] as $satz => $cent) {
                    if (($w['betraege'][0][$satz] ?? null) !== $cent && ($alt['betraege'][0][$satz] ?? null) === $cent) {
                        $w['betraege'][0][$satz] = $cent;
                    }
                }
                if (array_filter($w['betraege'][0]) === $soll['betraege']) {
                    $w['betraege'][1] = max($w['betraege'][1], min($alt['betraege'][1], 0.9));
                }
            }
            foreach ($soll as $feld => $wert) {
                $istPrimaer = $feld === 'betraege' && $w[$feld][0] !== null ? array_filter($w[$feld][0]) : $w[$feld][0];
                $istAlt = $feld === 'betraege' && $alt[$feld][0] !== null ? array_filter($alt[$feld][0]) : $alt[$feld][0];
                if ($wert !== null && $istPrimaer !== $wert && $istAlt === $wert) {
                    $w[$feld] = $alt[$feld];
                }
            }
        }

        $sicherheit = array_filter(array_map(fn ($x) => $x[1], $w), fn ($s) => $s !== null);

        return new GedruckteWerte($w['gesamt'][0], $w['betraege'][0], $w['datum_uhrzeit'][0], $w['kassen_id'][0], $sicherheit, $w['tse'][0]);
    }

    /** @return array<string, array{0: mixed, 1: ?float}> */
    private function felder(?array $f): array
    {
        return [
            'gesamt' => $this->gesamt($f),
            'betraege' => $this->betraegeJeSatz($f),
            'datum_uhrzeit' => $this->datumUhrzeit($f),
            'kassen_id' => $this->kassenId($f),
            'tse' => $this->tseSeriennummer(),
        ];
    }

    /**
     * Neutrale „Führung“ aus dem QR-Inhalt: was im Text gesucht wird.
     *
     * @return array{summe: ?int, betraege: array<string, int>, satz_feld: array<string, string>, zeit: ?string, kasse: ?string}|null
     */
    private static function fuehrung(RksvBeleg|DsfinvkBeleg|null $qr): ?array
    {
        return match (true) {
            $qr instanceof RksvBeleg => [
                'summe' => $qr->summeCent(), 'betraege' => $qr->betraegeCent, 'satz_feld' => self::SATZ_FELD,
                'zeit' => $qr->datumUhrzeit, 'kasse' => $qr->kassenId, 'tse' => null,
            ],
            $qr instanceof DsfinvkBeleg => [
                'summe' => $qr->summeCent(), 'betraege' => $qr->bruttoCent ?? [], 'satz_feld' => self::SATZ_FELD_DE,
                'zeit' => $qr->endeLokal(), 'kasse' => $qr->kassenSeriennummer, 'tse' => $qr->tseSeriennummer(),
            ],
            default => null,
        };
    }

    /** Land aus dem Text (für Belege ohne QR-Code). */
    public function land(): ?string
    {
        $uid = $this->uid();
        if ($uid) {
            return str_starts_with($uid, 'ATU') ? 'AT' : 'DE';
        }
        $text = $this->text();
        if (preg_match('/\b(TSE|Signaturz[äa]hler|Transaktionsnummer|Steuer-?Nr\.?\s*\d{2,3}\/)/iu', $text)) {
            return 'DE';
        }
        if (preg_match('/\b(Kassen-?ID|Registrierkasse|RKSV|Austria|Österreich)\b/iu', $text) || preg_match('/\b\d{4}\s+(Wien|Graz|Linz|Salzburg|Innsbruck|Klagenfurt|Villach)\b/iu', $text)) {
            return 'AT';
        }

        return null;
    }

    /**
     * TSE-Pflichtangaben im Klartext (DE, wenn kein QR-Code): welche Felder stehen auf dem Beleg?
     *
     * @return array<string, bool>
     */
    public function tseKlartext(): array
    {
        $t = $this->text();

        return [
            'tse_seriennummer' => (bool) preg_match('/(TSE|Sicherheitsmodul)[^\n]{0,25}(Serien|SN|Nr)/iu', $t) || $this->tseSeriennummer()[0] !== null,
            'transaktionsnummer' => (bool) preg_match('/Transaktion(s|snummer|s-?nr| ?nr)/iu', $t),
            'signaturzaehler' => (bool) preg_match('/Sig(natur)?[-\s]?(z[äa]hler|counter)/iu', $t),
            'start' => (bool) preg_match('/(Start|Beginn|Vorgangsbeginn)/iu', $t),
            'ende' => (bool) preg_match('/(Ende|Stop|Vorgangsende)/iu', $t),
            'pruefwert' => (bool) preg_match('/(Pr[üu]fwert|Signatur(?![-\s]?z))/iu', $t),
        ];
    }

    /**
     * TSE-Seriennummer: 64-stellige Hex-Folge, oft über zwei Zeilen umbrochen.
     * Typische Lesefehler in Hex (O/Q→0, l/I→1, Leerzeichen) werden ausgeglichen; dann gilt der Wert als unsicher gelesen.
     *
     * @return array{0: ?string, 1: ?float}
     */
    private function tseSeriennummer(): array
    {
        foreach ($this->zeilen as $i => $z) {
            if (! preg_match('/(TSE|Seriennummer|Serien-?Nr|\bSN\b)/iu', $z['text'])) {
                continue;
            }
            // Bezeichnungszeile + bis zu zwei Folgezeilen
            $roh = preg_replace('/^.*?(?:TSE[\w-]*|Serien\w*|SN)\s*[:#]?/iu', '', $z['text']);
            $sicherheit = $z['sicherheit'];
            for ($n = 1; $n <= 2 && strlen(preg_replace('/[^0-9a-f]/i', '', $roh)) < 64; $n++) {
                $roh .= ' '.($this->zeilen[$i + $n]['text'] ?? '');
                $sicherheit = min($sicherheit, $this->zeilen[$i + $n]['sicherheit'] ?? 1.0);
            }

            $genau = strtolower(preg_replace('/\s+/', '', $roh));
            if (preg_match('/[0-9a-f]{64}/', $genau, $m)) {
                return [$m[0], $sicherheit];
            }

            $korrigiert = strtr($genau, ['o' => '0', 'q' => '0', 'l' => '1', 'i' => '1']);
            if (preg_match('/[0-9a-f]{64}/', $korrigiert, $m)) {
                return [$m[0], min($sicherheit, self::UNSICHERE_ZUORDNUNG)];
            }
        }

        return [null, null];
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

    /** Zeilen im Kopf, die nicht der Name sind: Belegart-Vermerke, Adresse, Kontakt, Kennnummern. */
    private const KEIN_NAME = '/(duplikat|kopie|tisch\s*[:#]?\s*\w|kellner|bedienung|kunden\w*|beleg|rechnung|quittung|quit+ung|bank\s*karte|willkommen|welcome|tel\.?|fax|www\.|@|\bUID\b|\bATU\s?\d|\bDE\s?\d{9}|stra(ss|ß)e\s*\d|str\.\s*\d|(gasse|weg|platz|allee|ring|markt)\s*\d|^\W*\d{4,5}\s+\p{L})/iu';

    /**
     * Name des Ausstellers: erste brauchbare Zeile im Kopf. Übersprungen werden Vermerke („DUPLIKAT“, „Kundenbeleg“),
     * Adress- und Kontaktzeilen sowie Lesereste aus Logos (zu wenige Buchstaben).
     */
    public function aussteller(): ?string
    {
        foreach (array_slice($this->zeilen, 0, 8) as $z) {
            $text = trim(preg_replace('/^([^\p{L}\p{N}]+|\p{L}\s)+|(\s[^\p{L}\p{N}]*\p{L}?[^\p{L}\p{N}]*)+$/u', '', trim($z['text'])));
            $buchstaben = preg_match_all('/\p{L}/u', $text);
            if ($buchstaben < 4 || $buchstaben < 0.6 * mb_strlen(str_replace(' ', '', $text)) || preg_match(self::KEIN_NAME, $text)) {
                continue;
            }

            return $text;
        }

        return $this->zeilen[0]['text'] ?? null;
    }

    // ------------------------------------------------------------------

    /** @return array{0: ?int, 1: ?float} */
    private function gesamt(?array $f): array
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
                $kandidaten[] = ['betraege' => $betraege, 'sicherheit' => $z['sicherheit'],
                    'endsumme' => (bool) preg_match('/(brutto|gesamt|total|zu\s*zahlen|endbetrag|summe\s+in|summe\s+eur)/iu', $z['text'])];
            }
        }

        if ($f && $f['summe'] !== null) {
            foreach ([...$kandidaten, ...$this->zahlungsZeilen()] as $k) {
                if (in_array($f['summe'], $k['betraege'], true)) {
                    return [$f['summe'], $k['sicherheit']];
                }
            }
        }

        if ($kandidaten === []) {
            // Summenzeile unleserlich: Zahlungszeile („Bar 46,00“, „Betrag EUR 12,60“) als Ersatz –
            // kann Trinkgeld enthalten, daher nur unsicher gelesen
            $zahlung = $this->zahlungsZeilen()[0] ?? null;

            return $zahlung ? [end($zahlung['betraege']), min($zahlung['sicherheit'], self::UNSICHERE_ZUORDNUNG)] : [null, null];
        }

        // Mehrere Summenzeilen („Summe Arbeiten“, „Summe Teile“, „Summe Brutto“): Endsumme bevorzugen,
        // sonst den größten Betrag – Teilsummen sind nie größer als die Gesamtsumme
        $endsummen = array_values(array_filter($kandidaten, fn ($k) => $k['endsumme']));
        $auswahl = $endsummen !== [] ? $endsummen : $kandidaten;
        usort($auswahl, fn ($a, $b) => end($b['betraege']) <=> end($a['betraege']));
        $wert = end($auswahl[0]['betraege']);
        $sicherheit = $auswahl[0]['sicherheit'];

        // Weicht der gelesene Betrag nur in einer Ziffer vom QR ab, ist ein Lesefehler wahrscheinlich (0↔9, 1↔7, 3↔8 …)
        // ebenso eine zusätzliche Ziffer davor („€46,00“ als „646,00“ gelesen)
        if ($f && $f['summe'] !== null && (self::eineZifferAnders($wert, $f['summe']) || self::zifferDavor($wert, $f['summe']))) {
            $sicherheit = min($sicherheit, self::UNSICHERE_ZUORDNUNG);
        }

        return [$wert, $sicherheit];
    }

    /** @return list<array{betraege: list<int>, sicherheit: float}> */
    private function zahlungsZeilen(): array
    {
        $kandidaten = [];
        foreach ($this->zeilen as $z) {
            if (preg_match('/\b(bar(zahlung)?|bankomat|banko|karte|kartenzahlung|kredit|maestro|mastercard|visa|betrag|bezahlt)\b/iu', $z['text'])
                && ! preg_match('/\b(netto|mwst|ust|steuer|trinkgeld|tip|r[üu]ckgeld|retour)\b/iu', $z['text'])
                && ($b = self::betraege($z['text'])) !== []) {
                $kandidaten[] = ['betraege' => $b, 'sicherheit' => $z['sicherheit']];
            }
        }

        return $kandidaten;
    }

    private static function zifferAbweichungen(string $a, string $b): int
    {
        $a = preg_replace('/\D/', '', $a);
        $b = preg_replace('/\D/', '', $b);

        return strlen($a) === strlen($b) ? count(array_diff_assoc(str_split($a), str_split($b))) : PHP_INT_MAX;
    }

    /** „646,00“ statt „46,00“: Währungszeichen als Ziffer gelesen */
    private static function zifferDavor(int $gelesen, int $soll): bool
    {
        $g = (string) abs($gelesen);
        $s = (string) abs($soll);

        return strlen($g) === strlen($s) + 1 && str_ends_with($g, $s);
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
    private function betraegeJeSatz(?array $f): array
    {
        $satzFeld = $f['satz_feld'] ?? self::SATZ_FELD;
        $saetze = implode('|', array_map(fn ($p) => preg_quote($p, '/'), array_keys($satzFeld)));

        // 1. Alle Zeilen je Steuersatz einsammeln und Netto/Steuer/Brutto zuordnen
        $teile = []; // feld => ['satz' => float, 'brutto' => ?int, 'netto' => ?int, 'steuer' => ?int, 'unklar' => ?int]
        $sicherheiten = [];
        foreach ($this->zeilen as $z) {
            if (! preg_match('/(?<![\d,.])0*('.$saetze.')(?:[,.]0+)?\s?%/u', $z['text'], $m)) {
                continue;
            }
            $feld = $satzFeld[$m[1]];
            $satz = (float) str_replace(',', '.', $m[1]);
            $betraege = self::betraege(substr($z['text'], strpos($z['text'], $m[0]) + strlen($m[0])));
            if ($betraege === []) {
                continue;
            }
            $t = $teile[$feld] ?? ['satz' => $satz, 'brutto' => null, 'netto' => null, 'steuer' => null, 'unklar' => null];
            $qrWert = $f['betraege'][$feld] ?? null;

            if ($qrWert !== null && in_array($qrWert, $betraege, true)) {
                $t['brutto'] = $qrWert;
            } elseif (($b = self::bruttoAusSpalten($betraege, $satz)) !== null) {
                $t['brutto'] ??= $b;
            } elseif (count($betraege) === 1) {
                $rolle = match (true) {
                    (bool) preg_match('/brutto/iu', $z['text']) => 'brutto',
                    (bool) preg_match('/(exkl|netto|umsatz)/iu', $z['text']) => 'netto',
                    (bool) preg_match('/(mw\.?st|ust|steuer|davon|vat)/iu', $z['text']) => 'steuer',
                    default => 'unklar',
                };
                $t[$rolle] ??= $betraege[0];
            } else {
                $t['unklar'] ??= end($betraege); // Brutto steht meist rechts – Zuordnung aber unsicher
            }
            $teile[$feld] = $t;
            $sicherheiten[] = $z['sicherheit'];
        }

        if ($teile === []) {
            return [null, null];
        }

        // 2. Brutto je Satz bestimmen; abgeleitete oder unklare Werte gelten als unsicher gelesen
        $gefunden = [];
        $unsicher = false;
        foreach ($teile as $feld => $t) {
            $qrWert = $f['betraege'][$feld] ?? null;
            $p = $t['satz'];
            if ($t['brutto'] !== null) {
                $gefunden[$feld] = $t['brutto'];
            } elseif ($t['netto'] !== null && $t['steuer'] !== null && abs($t['netto'] * $p / 100 - $t['steuer']) <= 2) {
                $gefunden[$feld] = $t['netto'] + $t['steuer'];
            } elseif ($qrWert !== null && self::passtZuBrutto($t, $qrWert)) {
                // Nur Steuer- oder Nettobetrag gedruckt („davon 10 % USt. 5,68“) oder Netto und Steuer
                // rechnerisch unstimmig gelesen (Lesefehler 3↔8) – einer davon passt aber zum QR-Brutto
                $gefunden[$feld] = $qrWert;
            } elseif ($t['netto'] !== null && $t['steuer'] !== null) {
                $gefunden[$feld] = $t['netto'] + $t['steuer'];
                $unsicher = true;
            } elseif ($p == 0.0 && ($t['netto'] ?? $t['unklar']) !== null) {
                $gefunden[$feld] = $t['netto'] ?? $t['unklar'];
            } elseif ($t['steuer'] !== null && $p > 0) {
                $gefunden[$feld] = (int) round($t['steuer'] * (100 + $p) / $p);
                $unsicher = true;
            } elseif ($t['netto'] !== null) {
                $gefunden[$feld] = (int) round($t['netto'] * (100 + $p) / 100);
                $unsicher = true;
            } else {
                $gefunden[$feld] = $t['unklar'];
                $unsicher = true;
            }
        }

        // Steuertabelle unvollständig gelesen? Dann nicht vergleichen.
        if ($f) {
            foreach ($f['betraege'] as $feld => $cent) {
                if ($cent !== 0 && ! isset($gefunden[$feld])) {
                    return [null, null];
                }
            }
        }

        $sicherheit = min($sicherheiten);

        return [$gefunden, $unsicher ? min($sicherheit, self::UNSICHERE_ZUORDNUNG) : $sicherheit];
    }

    /**
     * Brutto aus den Spalten einer Steuerzeile, wenn sich die Beträge rechnerisch bestätigen:
     * „Netto Steuer Brutto“ (67,73 6,77 74,50), „Netto Steuer“ (363,95 72,79) oder „Brutto davon Steuer“ (35,00 3,18).
     */
    private static function bruttoAusSpalten(array $b, float $satz): ?int
    {
        for ($i = 0; $i + 2 < count($b); $i++) {
            if (abs($b[$i] + $b[$i + 1] - $b[$i + 2]) <= 1) {
                return $b[$i + 2];
            }
        }
        if ($satz > 0) {
            for ($i = 0; $i + 1 < count($b); $i++) {
                [$x, $y] = [$b[$i], $b[$i + 1]];
                if ($x > 0 && abs($x * $satz / 100 - $y) <= 2) {
                    return $x + $y;
                }
                if ($x > 0 && abs($x * $satz / (100 + $satz) - $y) <= 2) {
                    return $x;
                }
            }
        }

        return null;
    }

    /** Passt ein einzeln gedruckter Steuer- oder Nettobetrag zum Brutto aus dem QR-Code (Rundung ±2 Cent)? */
    private static function passtZuBrutto(array $t, int $brutto): bool
    {
        $p = $t['satz'];
        $steuer = $p > 0 ? $brutto * $p / (100 + $p) : 0.0;
        foreach (['steuer' => $steuer, 'netto' => $brutto - $steuer, 'unklar' => $steuer] as $rolle => $soll) {
            if ($t[$rolle] !== null && ($p > 0 || $rolle === 'netto') && abs($t[$rolle] - $soll) <= 2) {
                return true;
            }
        }
        // „unklar“ kann auch der Nettobetrag sein
        return $t['unklar'] !== null && abs($t['unklar'] - ($brutto - $steuer)) <= 2;
    }

    /** @return array{0: ?string, 1: ?float} Format "JJJJ-MM-TT hh:mm" */
    private function datumUhrzeit(?array $f): array
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
                $jahr = (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]);
                $wert = sprintf('%04d-%02d-%02d %02d:%02d', $jahr, $m[2], $m[1], $m[4], $m[5]);
                // Unmögliche Daten („2675-06-13“, „29.02.97“) sind Lesefehler, keine Angaben auf dem Beleg
                $moeglich = checkdate((int) $m[2], (int) $m[1], $jahr) && $jahr >= 2000 && $jahr <= (int) date('Y') + 1
                    && (int) $m[4] < 24 && (int) $m[5] < 60;
                $treffer[] = [$wert, $z['sicherheit'], $moeglich];
            }
        }

        $qrWert = $f && $f['zeit'] ? substr(str_replace('T', ' ', $f['zeit']), 0, 16) : null;
        foreach ($treffer as $t) {
            if ($t[0] === $qrWert) {
                return [$t[0], $t[1]];
            }
        }
        foreach ($treffer as $t) {
            // Höchstens zwei Ziffern anders als im QR-Code (2025 → 2675) → wahrscheinlich Lesefehler, nur unsicher gelesen
            if ($qrWert !== null && self::zifferAbweichungen($t[0], $qrWert) <= 2) {
                return [$t[0], min($t[1], self::UNSICHERE_ZUORDNUNG)];
            }
        }
        foreach ($treffer as $t) {
            if ($t[2]) {
                return [$t[0], $t[1]];
            }
        }

        return [null, null];
    }

    /** @return array{0: ?string, 1: ?float} */
    private function kassenId(?array $f): array
    {
        if ($f && $f['kasse']) {
            $gesucht = self::norm($f['kasse']);
            foreach ($this->zeilen as $z) {
                if ($gesucht !== '' && str_contains(self::norm($z['text']), $gesucht)) {
                    return [$f['kasse'], $z['sicherheit']];
                }
            }
        }

        foreach ($this->zeilen as $z) {
            if (preg_match('/kassen[\s-]?(?:ident\w*\.?(?:[\s-]*n\w{0,4}r\.?)?|id\b|1d\b|nr\.?|nummer|seriennummer|-?sn)\s*[:#]?\s*(?![:#])(\S+)/iu', $z['text'], $m)) {
                // Fast gleich wie im QR (ein Zeichen anders) → wahrscheinlich Lesefehler
                $fastGleich = $f && $f['kasse'] && levenshtein(self::norm($m[1]), self::norm($f['kasse'])) <= 1;

                return [$m[1], $fastGleich ? min($z['sicherheit'], self::UNSICHERE_ZUORDNUNG) : $z['sicherheit'] * 0.8];
            }
        }

        // Keine Kassen-ID im Text: nicht prüfbar (viele Bons drucken sie nur im QR-Code)
        return [null, null];
    }

    /** Alle Geldbeträge einer Zeile in Cent, z. B. "2 x Schnitzel 37,80" → [3780]. */
    public static function betraege(string $text): array
    {
        $text = self::zahlenGlaetten($text);
        preg_match_all('/(?<![\d,.])-?\d{1,3}(?:\.\d{3})*,\d{2}(?![\d,])|(?<![\d,.])-?\d+\.\d{2}(?![\d.])/u', $text, $m);

        return array_map(function ($b) {
            $b = str_contains($b, ',') ? str_replace(['.', ','], ['', '.'], $b) : $b;

            return (int) round((float) $b * 100);
        }, $m[0]);
    }

    /**
     * Typische Lesefehler in Zahlen ausgleichen: O/o→0, l/I/|→1, S→5, B→8 – nur direkt neben Ziffern;
     * „70, 70“ oder „70 ,70“ → „70,70“.
     */
    public static function zahlenGlaetten(string $text): string
    {
        $ersatz = ['O' => '0', 'o' => '0', 'D' => '0', 'l' => '1', 'I' => '1', '|' => '1', 'S' => '5', 'B' => '8'];
        for ($i = 0; $i < 2; $i++) { // zweimal, damit auch „7OO,O0“ ganz korrigiert wird
            $text = preg_replace_callback('/(?<=[\d,.])[OoDlI|SB]|(?<=^|\s)[OoDlI|SB](?=\d*[,.]\d)/u',
                fn ($m) => $ersatz[$m[0]], $text);
        }

        return preg_replace('/(\d)\s*([,.])\s+(\d{2})(?!\d)/u', '$1$2$3', preg_replace('/(\d)\s+([,.])(\d{2})(?!\d)/u', '$1$2$3', $text));
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
