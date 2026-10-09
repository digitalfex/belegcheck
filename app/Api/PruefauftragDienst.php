<?php

namespace App\Api;

use App\Hashes\BelegFingerabdruck;
use App\Jobs\WebhookZustellen;
use App\Models\Pruefauftrag;
use App\Muster\OrtZeitPruefer;
use App\Pruefung\BelegPruefService;
use App\Pruefung\Gesamturteil;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Risikobewertung;
use App\Pruefung\Stufe;
use Illuminate\Support\Carbon;

/**
 * Führt einen Prüfauftrag der Schnittstelle aus: Beleg prüfen (alle Schichten), dann über alle Einreichungen
 * des Mandanten hinweg Dubletten (MU-DU-*) und Orte/Zeiten derselben Person (MU-OZ-01) prüfen,
 * Ergebnis speichern, Datei verwerfen, Webhook anstoßen.
 */
final class PruefauftragDienst
{
    public function __construct(private readonly BelegPruefService $pruefung) {}

    public function ausfuehren(Pruefauftrag $auftrag, string $pfad): Pruefauftrag
    {
        $mandant = $auftrag->mandant;
        $auftrag->update(['status' => 'laeuft']);

        $bericht = $this->pruefung->pruefeDatei($pfad, null, [
            'angaben' => $auftrag->angaben ?? [],
            'screening' => (bool) $mandant->einstellung('screening') && config('belegcheck.screening.aktiv'),
            'richtlinie' => (array) $mandant->einstellung('compliance', []),
            'schwellen' => (array) $mandant->einstellung('schwellen', []),
        ]);

        $zeit = $bericht['gedruckt']['datum_uhrzeit'] ?? $bericht['qr']['datum_uhrzeit'] ?? null;
        $zeit = $zeit ? str_replace('T', ' ', substr($zeit, 0, 16)) : null;
        $auftrag->fill([
            'datei_sha256' => $bericht['datei_sha256'],
            'inhalt_hash' => $bericht['inhalt_hash'] ?? null,
            'text_hash' => $bericht['text_hash'] ?? null,
            'fiskal_schluessel' => $bericht['fiskal_schluessel'] ?? null,
            'beleg_zeit' => $zeit,
            'ort_lat' => $bericht['ort']['lat'] ?? null,
            'ort_lon' => $bericht['ort']['lon'] ?? null,
            'ort_name' => isset($bericht['ort']) ? $bericht['ort']['plz'].' '.$bericht['ort']['ort'] : null,
        ]);

        $bericht = $this->neuBewerten($bericht, [...$this->dubletten($auftrag), ...$this->ortZeit($auftrag)], (array) $mandant->einstellung('schwellen', []));

        $auftrag->fill([
            'status' => 'fertig',
            'score' => $bericht['urteil']['score'],
            'abdeckung' => $bericht['urteil']['abdeckung'],
            'ampel' => $bericht['ampel'],
            'empfehlung' => $bericht['urteil']['empfehlung'],
            'ergebnis' => self::ergebnisSpeichern($bericht),
            'fertig_am' => now(),
            'webhook_status' => $mandant->webhook_url ? 'offen' : null,
        ])->save();

        if ($mandant->webhook_url) {
            WebhookZustellen::dispatch($auftrag->id);
        }

        return $auftrag;
    }

    /** Nur was die Schnittstelle ausliefert – kein Belegbild, kein Volltext, keine Vorschau. */
    private static function ergebnisSpeichern(array $b): array
    {
        return array_intersect_key($b, array_flip(['risikowert', 'ampel', 'urteil', 'ergebnisse', 'gedruckt', 'qr', 'qr_typ', 'qr_text',
            'uid', 'land', 'aussteller', 'ort', 'ki', 'aussteller_pruefung', 'text_quelle']));
    }

    /** @param  list<PruefErgebnis>  $zusatz */
    private function neuBewerten(array $bericht, array $zusatz, array $schwellen): array
    {
        if ($zusatz === []) {
            return $bericht;
        }
        $alle = [...array_map(fn ($e) => PruefErgebnis::ausArray($e), $bericht['ergebnisse']), ...$zusatz];
        $bewertung = new Risikobewertung($alle);
        $bericht['ergebnisse'] = array_map(fn (PruefErgebnis $e) => $e->toArray(), $alle);
        $bericht['risikowert'] = $bewertung->risikowert();
        $bericht['ampel'] = $bewertung->ampel();
        $urteil = new Gesamturteil($bericht);
        $bericht['urteil'] = ['score' => $urteil->score(), 'abdeckung' => $urteil->abdeckung(), 'bereiche' => $urteil->bereiche(), 'empfehlung' => $urteil->empfehlung($schwellen)];

        return $bericht;
    }

    /** @return list<PruefErgebnis> */
    private function dubletten(Pruefauftrag $a): array
    {
        $frueher = Pruefauftrag::where('mandant_id', $a->mandant_id)->where('id', '!=', $a->id)->where('status', 'fertig')
            ->where('created_at', '>=', now()->subDays(400));
        // Erneute Übertragung derselben Spesenposition ist keine Doppeleinreichung
        $andere = fn ($q) => $a->externe_referenz ? $q->where(fn ($w) => $w->whereNull('externe_referenz')->orWhere('externe_referenz', '!=', $a->externe_referenz)) : $q;
        $beschreibung = fn (Pruefauftrag $p) => 'Prüfung '.$p->id.' vom '.$p->created_at->format('d.m.Y')
            .($p->externe_referenz ? ', Referenz '.$p->externe_referenz : '').($p->einreicher && $p->einreicher !== $a->einreicher ? ', andere Person' : '');

        $ergebnisse = [];
        $gleicheDatei = $andere((clone $frueher)->where('datei_sha256', $a->datei_sha256))->latest()->first();
        $ergebnisse[] = $gleicheDatei
            ? new PruefErgebnis('MU-DU-01', Stufe::Auffaellig, 'Dieselbe Datei wurde bereits eingereicht ('.$beschreibung($gleicheDatei).').', ['fruehere_pruefung' => $gleicheDatei->id])
            : PruefErgebnis::ok('MU-DU-01');

        $gleicherBeleg = null;
        if (! $gleicheDatei && ($a->inhalt_hash || $a->fiskal_schluessel)) {
            $gleicherBeleg = $andere((clone $frueher)->where(fn ($q) => $q
                ->when($a->inhalt_hash, fn ($w) => $w->orWhere('inhalt_hash', $a->inhalt_hash))
                ->when($a->fiskal_schluessel, fn ($w) => $w->orWhere('fiskal_schluessel', $a->fiskal_schluessel))))->latest()->first();
        }
        $ergebnisse[] = $gleicherBeleg
            ? new PruefErgebnis('MU-DU-02', Stufe::Auffaellig, 'Derselbe Beleg (anderes Foto/Scan) wurde bereits eingereicht ('.$beschreibung($gleicherBeleg).').', ['fruehere_pruefung' => $gleicherBeleg->id])
            : PruefErgebnis::ok('MU-DU-02');

        if (! $gleicheDatei && ! $gleicherBeleg && $a->text_hash) {
            $aehnlich = $andere((clone $frueher)->whereNotNull('text_hash'))->latest()->limit(3000)->get(['id', 'text_hash', 'created_at', 'externe_referenz', 'einreicher'])
                ->first(fn ($p) => BelegFingerabdruck::aehnlich($p->text_hash, $a->text_hash));
            $ergebnisse[] = $aehnlich
                ? new PruefErgebnis('MU-DU-03', Stufe::Hinweis, 'Sehr ähnlicher Beleg bereits eingereicht ('.$beschreibung($aehnlich).') – bitte vergleichen.', ['fruehere_pruefung' => $aehnlich->id])
                : PruefErgebnis::ok('MU-DU-03');
        }

        return $ergebnisse;
    }

    /** @return list<PruefErgebnis> */
    private function ortZeit(Pruefauftrag $a): array
    {
        if (! $a->einreicher || ! $a->beleg_zeit || $a->ort_lat === null) {
            return [];
        }
        $zeit = $a->beleg_zeit instanceof \DateTimeInterface ? $a->beleg_zeit : new \DateTimeImmutable($a->beleg_zeit);
        $nachbarn = Pruefauftrag::where('mandant_id', $a->mandant_id)->where('einreicher', $a->einreicher)->where('id', '!=', $a->id)
            ->where('status', 'fertig')->whereNotNull('ort_lat')
            ->whereBetween('beleg_zeit', [(clone $zeit)->modify('-1 day'), (clone $zeit)->modify('+1 day')])->limit(50)->get();
        if ($nachbarn->isEmpty()) {
            return [];
        }
        $belege = [['id' => $a->id, 'zeit' => $zeit->format('Y-m-d H:i'), 'lat' => (float) $a->ort_lat, 'lon' => (float) $a->ort_lon, 'ort' => (string) $a->ort_name],
            ...$nachbarn->map(fn ($n) => ['id' => $n->id, 'zeit' => Carbon::parse($n->beleg_zeit)->format('Y-m-d H:i'),
                'lat' => (float) $n->ort_lat, 'lon' => (float) $n->ort_lon, 'ort' => (string) $n->ort_name])->all()];

        return (new OrtZeitPruefer)->pruefe($belege)[$a->id] ?? [PruefErgebnis::ok('MU-OZ-01')];
    }
}
