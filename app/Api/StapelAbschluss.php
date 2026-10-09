<?php

namespace App\Api;

use App\Jobs\WebhookZustellen;
use App\Models\Pruefauftrag;
use App\Models\Pruefstapel;
use App\Muster\OrtZeitPruefer;
use App\Pruefung\Gesamturteil;
use App\Pruefung\PruefErgebnis;

/**
 * Schließt einen Stapel ab, sobald alle Belege geprüft (oder fehlgeschlagen) sind:
 * Orts-/Zeitcheck über alle Belege des Stapels, Zusammenfassung, ein Webhook „stapel.fertig“.
 */
final class StapelAbschluss
{
    public static function versuchen(string $stapelId): void
    {
        $offen = Pruefauftrag::where('stapel_id', $stapelId)->whereNotIn('status', ['fertig', 'fehler'])->exists();
        // Nur einer gewinnt den Wechsel laeuft → fertig (mehrere Worker, gleichzeitige Jobs)
        if ($offen || Pruefstapel::where('id', $stapelId)->where('status', 'laeuft')->update(['status' => 'abschluss']) !== 1) {
            return;
        }

        $stapel = Pruefstapel::with(['mandant', 'auftraege'])->find($stapelId);
        $schwellen = (array) $stapel->mandant->einstellung('schwellen', []);
        self::ortZeitImStapel($stapel, $schwellen);
        $stapel->load('auftraege');

        $stapel->update([
            'status' => 'fertig',
            'ergebnis' => self::zusammenfassung($stapel),
            'fertig_am' => now(),
            'webhook_status' => $stapel->mandant->webhook_url ? 'offen' : null,
        ]);
        if ($stapel->mandant->webhook_url) {
            WebhookZustellen::dispatch($stapel->id, 'stapel');
        }
    }

    /** Belege, die früh geprüft wurden, kannten die späteren noch nicht → jetzt über den ganzen Stapel. */
    private static function ortZeitImStapel(Pruefstapel $stapel, array $schwellen): void
    {
        $mitOrt = $stapel->auftraege->filter(fn ($a) => $a->status === 'fertig' && $a->ort_lat !== null && $a->beleg_zeit !== null);
        if ($mitOrt->count() < 2) {
            return;
        }
        $konflikte = (new OrtZeitPruefer)->pruefe($mitOrt->map(fn ($a) => [
            'id' => $a->id, 'zeit' => $a->beleg_zeit->format('Y-m-d H:i'), 'lat' => (float) $a->ort_lat, 'lon' => (float) $a->ort_lon, 'ort' => (string) $a->ort_name,
        ])->values()->all());

        foreach ($mitOrt as $a) {
            $bekannt = collect($a->ergebnis['ergebnisse'] ?? [])->where('code', 'MU-OZ-01')->pluck('werte.anderer_beleg')->filter()->all();
            $neu = array_values(array_filter($konflikte[$a->id] ?? [], fn (PruefErgebnis $e) => ! in_array($e->werte['anderer_beleg'] ?? null, $bekannt, true)));
            if ($neu !== []) {
                PruefauftragDienst::ergaenzen($a, $neu, $schwellen);
            }
        }
    }

    private static function zusammenfassung(Pruefstapel $stapel): array
    {
        $alle = $stapel->auftraege;
        $fertig = $alle->where('status', 'fertig');
        $ampelRang = ['gruen' => 0, 'gelb' => 1, 'rot' => 2];
        $schlimmsteAmpel = $fertig->sortByDesc(fn ($a) => $ampelRang[$a->ampel] ?? 0)->first()?->ampel;
        $empfehlung = $fertig->sortByDesc(fn ($a) => Gesamturteil::EMPFEHLUNG_RANG[$a->empfehlung] ?? 0)->first()?->empfehlung;
        $fehler = $alle->where('status', 'fehler')->count();

        $summe = 0;
        foreach ($fertig as $a) {
            $e = $a->ergebnis ?? [];
            $summe += (int) ($e['qr']['summe_cent'] ?? $e['gedruckt']['gesamt_cent'] ?? 0);
        }

        return [
            'anzahl' => $alle->count(),
            'fertig' => $fertig->count(),
            'fehler' => $fehler,
            'ampel' => $schlimmsteAmpel,
            'score_min' => $fertig->min('score'),
            // Fehlgeschlagene Belege müssen angesehen werden
            'empfehlung' => $fehler > 0 ? 'manuell_pruefen' : $empfehlung,
            'manuell_pruefen' => $fertig->where('empfehlung', 'manuell_pruefen')->count() + $fehler,
            'summe_belege_cent' => $summe,
        ];
    }
}
