<?php

namespace App\Api;

use App\Models\Pruefauftrag;
use App\Models\Pruefstapel;

/**
 * JSON-Darstellung eines Prüfauftrags für die Schnittstelle (stabiles Format, Version v1).
 */
final class Darstellung
{
    public const REGELWERK = '2026.10.4';

    public static function stapel(Pruefstapel $s): array
    {
        $auftraege = $s->relationLoaded('auftraege') ? $s->auftraege : $s->auftraege()->get();
        $fertig = $s->status === 'fertig';

        return [
            'id' => $s->id,
            'objekt' => 'stapel',
            'status' => $fertig ? 'fertig' : 'laeuft',
            'erstellt_am' => $s->created_at?->toIso8601String(),
            'fertig_am' => $s->fertig_am?->toIso8601String(),
            'externe_referenz' => $s->externe_referenz,
            'einreicher' => $s->einreicher,
            'anzahl' => $s->anzahl,
            'fortschritt' => ['fertig' => $auftraege->whereIn('status', ['fertig', 'fehler'])->count(), 'gesamt' => $s->anzahl],
            'ergebnis' => $fertig ? $s->ergebnis : null,
            'pruefungen' => $auftraege->map(fn (Pruefauftrag $a) => ['position' => $a->position, ...self::pruefung($a)])->values()->all(),
            'regelwerk' => self::REGELWERK,
            'links' => ['self' => url('/api/v1/stapel/'.$s->id)],
        ];
    }

    public static function pruefung(Pruefauftrag $a): array
    {
        $e = $a->ergebnis ?? [];
        $fertig = $a->status === 'fertig';

        return [
            'id' => $a->id,
            'objekt' => 'pruefung',
            'status' => $a->status,
            'erstellt_am' => $a->created_at?->toIso8601String(),
            'fertig_am' => $a->fertig_am?->toIso8601String(),
            'externe_referenz' => $a->externe_referenz,
            'einreicher' => $a->einreicher,
            'angaben' => $a->angaben ?: null,
            'ergebnis' => $fertig ? [
                'score' => $a->score,
                'abdeckung' => $a->abdeckung,
                'ampel' => $a->ampel,
                'empfehlung' => $a->empfehlung,
                'risikowert' => $e['risikowert'] ?? null,
                'bereiche' => $e['urteil']['bereiche'] ?? null,
                'befunde' => array_values(array_map(fn ($b) => array_intersect_key($b, array_flip(['code', 'titel', 'stufe', 'punkte', 'begruendung', 'werte'])),
                    array_filter($e['ergebnisse'] ?? [], fn ($b) => $b['stufe'] !== 'ok'))),
                'pruefungen' => count($e['ergebnisse'] ?? []),
                'daten' => self::daten($e),
            ] : null,
            'fehler' => $a->status === 'fehler' ? $a->fehler : null,
            'entscheidung' => $a->entscheidung ? ['ergebnis' => $a->entscheidung, 'kommentar' => $a->entscheidung_kommentar] : null,
            'stapel' => $a->stapel_id,
            'regelwerk' => self::REGELWERK,
            'links' => ['self' => url('/api/v1/pruefungen/'.$a->id)],
        ];
    }

    /** Brutto je Steuersatz mit Prozentwert als Schlüssel („10“ => 3170), leere Sätze weggelassen */
    private static function saetze(?array $felder): ?array
    {
        if (! $felder) {
            return null;
        }
        $namen = ['normal' => '20', 'ermaessigt1' => '10', 'ermaessigt2' => '13', 'null' => '0', 'besonders' => 'besonders',
            'allgemein' => '19', 'ermaessigt' => '7', 'durchschnitt_10_7' => '10,7', 'durchschnitt_5_5' => '5,5'];
        $aus = [];
        foreach ($felder as $feld => $cent) {
            if ($cent) {
                $aus[$namen[$feld] ?? $feld] = $cent;
            }
        }

        return $aus ?: null;
    }

    private static function daten(array $e): array
    {
        $g = $e['gedruckt'] ?? [];
        $qr = $e['qr'] ?? [];
        $ki = $e['ki']['felder'] ?? [];
        $summeQr = $qr['summe_cent'] ?? null;
        $quelle = fn (string $feld, bool $ausQr) => match (true) {
            $ausQr => 'kassen_qr',
            in_array($feld, $ki, true) => 'sprachmodell',
            default => ($e['text_quelle'] ?? 'ocr') === 'pdf-text' ? 'pdf_text' : 'texterkennung',
        };
        $summe = $summeQr ?? $g['gesamt_cent'] ?? null;
        $datum = $qr['datum_uhrzeit'] ?? $g['datum_uhrzeit'] ?? null;
        $vies = $e['aussteller_pruefung']['vies'] ?? null;
        $osm = $e['aussteller_pruefung']['osm'] ?? null;

        return [
            'aussteller' => $e['aussteller'] ?? null,
            'uid' => $e['uid'] ?? null,
            'uid_gueltig' => $vies['gueltig'] ?? null,
            'firmenname_register' => $vies['name'] ?? null,
            'land' => $e['land'] ?? null,
            'ort' => isset($e['ort']) ? ['plz' => $e['ort']['plz'], 'ort' => $e['ort']['ort']] : null,
            'datum' => $datum ? str_replace(' ', 'T', $datum) : null,
            'summe_cent' => $summe,
            'waehrung' => 'EUR',
            'steuersaetze_cent' => self::saetze($qr['betraege_cent'] ?? $qr['brutto_cent'] ?? $g['betraege_je_satz_cent'] ?? null),
            'kasse' => $qr['kassen_id'] ?? null,
            'belegnummer' => $qr['belegnummer'] ?? null,
            'kassen_qr' => $e['qr_typ'] ?? null,
            'branche' => $e['aussteller_pruefung']['branche'] ?? null,
            'compliance' => $e['aussteller_pruefung']['compliance'] ?? [],
            'lokal' => ($osm['gefunden'] ?? false) ? ['name' => $osm['name'], 'art' => $osm['tags']['amenity'] ?? $osm['tags']['shop'] ?? $osm['tags']['tourism'] ?? null,
                'oeffnungszeiten' => $osm['tags']['opening_hours'] ?? null, 'website' => $osm['tags']['website'] ?? null] : null,
            'quellen' => [
                'summe' => $summe === null ? null : $quelle('gesamt', $summeQr !== null),
                'datum' => $datum === null ? null : $quelle('datum_uhrzeit', isset($qr['datum_uhrzeit'])),
                'aussteller' => ($e['ki']['aussteller'] ?? null) ? 'sprachmodell' : 'texterkennung',
            ],
        ];
    }
}
