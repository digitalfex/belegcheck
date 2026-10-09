<?php

namespace App\Screening;

/**
 * Öffnungszeiten im OpenStreetMap-Format („Mo-Fr 08:00-22:00; Sa,Su 09:00-15:00“, „24/7“, „Mo off“).
 * Unterstützt die gängige Teilmenge; alles Unbekannte → null (nicht prüfbar) statt einer falschen Aussage.
 * Feiertage (PH) und Ferien (SH) werden ignoriert – ein Beleg an einem Feiertag wird nicht beanstandet.
 */
final class Oeffnungszeiten
{
    private const TAGE = ['Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 7];

    /** @var array<int, list<array{0: int, 1: int}>>|null Minuten-Intervalle je Wochentag (1 = Mo), Ende darf > 1440 sein */
    private ?array $plan;

    public function __construct(string $angabe)
    {
        $this->plan = self::lesen(trim($angabe));
    }

    public function lesbar(): bool
    {
        return $this->plan !== null;
    }

    /**
     * Minuten außerhalb der Öffnungszeit (0 = geöffnet), null wenn nicht lesbar.
     */
    public function minutenAusserhalb(\DateTimeInterface $zeitpunkt): ?int
    {
        if ($this->plan === null) {
            return null;
        }
        $tag = (int) $zeitpunkt->format('N');
        $minute = (int) $zeitpunkt->format('G') * 60 + (int) $zeitpunkt->format('i');

        // Intervalle des Tages und Überhänge des Vortags (z. B. 18:00–02:00) auf eine Achse legen
        $intervalle = $this->plan[$tag] ?? [];
        $vortag = $tag === 1 ? 7 : $tag - 1;
        foreach ($this->plan[$vortag] ?? [] as [$von, $bis]) {
            if ($bis > 1440) {
                $intervalle[] = [0, $bis - 1440];
            }
        }
        if ($intervalle === []) {
            return 24 * 60; // ganzer Tag geschlossen
        }

        $abstand = PHP_INT_MAX;
        foreach ($intervalle as [$von, $bis]) {
            if ($minute >= $von && $minute <= $bis) {
                return 0;
            }
            $abstand = min($abstand, $minute < $von ? $von - $minute : $minute - $bis);
        }

        return $abstand;
    }

    /** @return array<int, list<array{0: int, 1: int}>>|null */
    private static function lesen(string $angabe): ?array
    {
        if ($angabe === '') {
            return null;
        }
        if ($angabe === '24/7') {
            return array_fill_keys(range(1, 7), [[0, 1440]]);
        }

        $plan = [];
        foreach (array_filter(array_map('trim', explode(';', $angabe))) as $regel) {
            if (preg_match('/^(PH|SH)\b/', $regel)) {
                continue; // Feiertage/Ferien nicht bewerten
            }
            // [Tage] [Zeiten|off|closed]
            if (! preg_match('/^((?:(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?,?\s*)+)?\s*(.*)$/', $regel, $m)) {
                return null;
            }
            $tage = trim($m[1] ?? '') === '' ? range(1, 7) : self::tage($m[1]);
            $zeiten = trim(preg_replace('/\bPH\b\s*,?/', '', $m[2]));
            if ($tage === null) {
                return null;
            }
            if (in_array(strtolower($zeiten), ['off', 'closed'], true)) {
                foreach ($tage as $t) {
                    $plan[$t] = [];
                }

                continue;
            }
            $intervalle = [];
            foreach (array_filter(array_map('trim', explode(',', $zeiten))) as $spanne) {
                if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})(\+)?$/', $spanne, $z)) {
                    return null; // z. B. „sunset“, Monatsangaben → lieber nicht bewerten
                }
                $von = (int) $z[1] * 60 + (int) $z[2];
                $bis = (int) $z[3] * 60 + (int) $z[4];
                if ($bis <= $von) {
                    $bis += 1440; // über Mitternacht
                }
                if (! empty($z[5])) {
                    $bis = max($bis, $von + 6 * 60); // „+“ = offenes Ende → großzügig
                }
                $intervalle[] = [$von, $bis];
            }
            if ($intervalle === []) {
                return null;
            }
            foreach ($tage as $t) {
                $plan[$t] = $intervalle; // spätere Regeln überschreiben frühere (OSM-Regel)
            }
        }

        return $plan === [] ? null : $plan;
    }

    /** @return list<int>|null */
    private static function tage(string $angabe): ?array
    {
        $tage = [];
        foreach (array_filter(array_map('trim', explode(',', $angabe))) as $teil) {
            if (preg_match('/^(\w\w)-(\w\w)$/', $teil, $m) && isset(self::TAGE[$m[1]], self::TAGE[$m[2]])) {
                $von = self::TAGE[$m[1]];
                $bis = self::TAGE[$m[2]];
                for ($t = $von; ; $t = $t % 7 + 1) {
                    $tage[] = $t;
                    if ($t === $bis) {
                        break;
                    }
                }
            } elseif (isset(self::TAGE[$teil])) {
                $tage[] = self::TAGE[$teil];
            } else {
                return null;
            }
        }

        return $tage;
    }
}
