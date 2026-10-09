<?php

namespace App\Http\Controllers\Api;

use App\Api\Darstellung;
use App\Api\Eingang;
use App\Api\Problem;
use App\Http\Controllers\Controller;
use App\Jobs\PruefauftragAusfuehren;
use App\Models\Mandant;
use App\Models\Pruefauftrag;
use App\Models\Pruefstapel;
use App\Screening\Branche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * /api/v1/stapel – mehrere Belege einer Abrechnung, immer asynchron (202 + Webhook „stapel.fertig“).
 *
 * Formular: dateien[] (Dateien) + positionen (JSON-Liste, gleiche Reihenfolge, je Beleg Angaben)
 * JSON:     {"belege": [{"datei_base64": "…", "dateiname": "a.pdf", "betrag": "46,00", …}, …]}
 */
class StapelController extends Controller
{
    private const ENDUNGEN = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'pdf'];

    public function store(Request $request): JsonResponse
    {
        /** @var Mandant $mandant */
        $mandant = $request->attributes->get('mandant');
        $maxKb = (int) config('belegcheck.api.max_mb') * 1024;
        $maxBelege = (int) config('belegcheck.api.max_belege_je_stapel', 100);

        $kopf = Validator::make($request->all(), [
            'externe_referenz' => ['nullable', 'string', 'max:191'],
            'einreicher' => ['nullable', 'string', 'max:191'],
        ])->validate();

        // Belege einsammeln: [inhalt, dateiname, angaben]
        $belege = [];
        if ($request->hasFile('dateien')) {
            $positionen = json_decode((string) $request->input('positionen', '[]'), true);
            if (! is_array($positionen)) {
                return Problem::antwort(422, 'Ungültige Anfrage', '„positionen“ muss eine JSON-Liste sein.', 'ungueltige-anfrage');
            }
            Validator::make(['dateien' => $request->file('dateien')], [
                'dateien' => ['required', 'array', 'max:'.$maxBelege],
                'dateien.*' => ['file', 'max:'.$maxKb, 'mimes:'.implode(',', self::ENDUNGEN)],
            ], [], ['dateien.*' => 'Datei'])->validate();
            foreach (array_values($request->file('dateien')) as $i => $datei) {
                $belege[] = ['inhalt' => file_get_contents($datei->getRealPath()), 'dateiname' => $datei->getClientOriginalName(), 'angaben' => (array) ($positionen[$i] ?? [])];
            }
        } else {
            Validator::make($request->all(), [
                'belege' => ['required', 'array', 'min:1', 'max:'.$maxBelege],
                'belege.*.datei_base64' => ['required', 'string'],
                'belege.*.dateiname' => ['required', 'string', 'max:200', 'regex:/\.('.implode('|', self::ENDUNGEN).')$/i'],
            ])->validate();
            foreach ($request->input('belege') as $i => $b) {
                $inhalt = base64_decode((string) $b['datei_base64'], true);
                if ($inhalt === false || $inhalt === '' || strlen($inhalt) > $maxKb * 1024) {
                    return Problem::antwort(422, 'Ungültige Datei', "Beleg $i: datei_base64 ist kein gültiges Base64 oder zu groß.", 'ungueltige-datei');
                }
                $belege[] = ['inhalt' => $inhalt, 'dateiname' => basename($b['dateiname']), 'angaben' => array_diff_key($b, array_flip(['datei_base64', 'dateiname']))];
            }
        }

        // Angaben je Beleg prüfen
        $regeln = [
            'externe_referenz' => ['nullable', 'string', 'max:191'],
            'betrag' => ['nullable', 'regex:/^-?\d{1,7}([.,]\d{1,2})?$/'],
            'datum' => ['nullable', 'date'],
            'kategorie' => ['nullable', Rule::in(array_keys(Branche::KATEGORIE_BRANCHEN))],
        ];
        $fehler = [];
        foreach ($belege as $i => $b) {
            $v = Validator::make($b['angaben'], $regeln);
            foreach ($v->errors()->messages() as $feld => $meldungen) {
                $fehler["belege.$i.$feld"] = $meldungen;
            }
        }
        if ($fehler !== []) {
            return Problem::antwort(422, 'Ungültige Anfrage', 'Mindestens eine Angabe ist ungültig.', 'ungueltige-anfrage', ['fehler' => $fehler]);
        }

        // Idempotenz auf Stapelebene
        $idemSchluessel = $request->header('Idempotency-Key');
        $idemHash = hash('sha256', json_encode([$kopf, array_map(fn ($b) => [hash('sha256', $b['inhalt']), $b['angaben']], $belege)]));
        if ($idemSchluessel !== null) {
            $vorhanden = Pruefstapel::where('mandant_id', $mandant->id)->where('idempotenz_schluessel', $idemSchluessel)->first();
            if ($vorhanden) {
                return $vorhanden->idempotenz_hash === $idemHash
                    ? $this->antwort($vorhanden, $vorhanden->status === 'fertig' ? 200 : 202)->header('Idempotent-Replayed', 'true')
                    : Problem::antwort(422, 'Idempotency-Key bereits verwendet', 'Dieser Schlüssel gehört zu einer anderen Anfrage.', 'idempotenz');
            }
        }

        $stapel = DB::transaction(function () use ($mandant, $kopf, $belege, $idemSchluessel, $idemHash) {
            $stapel = Pruefstapel::create([
                'mandant_id' => $mandant->id, 'status' => 'laeuft', 'anzahl' => count($belege),
                'externe_referenz' => $kopf['externe_referenz'] ?? null, 'einreicher' => $kopf['einreicher'] ?? null,
                'idempotenz_schluessel' => $idemSchluessel, 'idempotenz_hash' => $idemSchluessel !== null ? $idemHash : null,
            ]);
            foreach ($belege as $i => $b) {
                $angaben = array_filter(array_intersect_key($b['angaben'], array_flip(['betrag', 'datum', 'kategorie'])), fn ($w) => $w !== null);
                $auftrag = Pruefauftrag::create([
                    'mandant_id' => $mandant->id, 'stapel_id' => $stapel->id, 'position' => $i, 'status' => 'wartend',
                    'externe_referenz' => $b['angaben']['externe_referenz'] ?? null,
                    'einreicher' => $kopf['einreicher'] ?? null, // ein Stapel = eine Person → Orts-/Zeitcheck
                    'angaben' => $angaben ?: null, 'dateiname' => $b['dateiname'],
                ]);
                $auftrag->update(['ablage_pfad' => Eingang::ablegen($auftrag->id, $b['inhalt'])]);
            }

            return $stapel;
        });

        foreach ($stapel->auftraege()->pluck('id') as $id) {
            PruefauftragAusfuehren::dispatch($id);
        }

        return $this->antwort($stapel->fresh(), 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $stapel = $this->finden($request, $id);

        return $stapel ? $this->antwort($stapel, 200) : Problem::antwort(404, 'Stapel nicht gefunden', null, 'nicht-gefunden');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $stapel = $this->finden($request, $id);
        if (! $stapel) {
            return Problem::antwort(404, 'Stapel nicht gefunden', null, 'nicht-gefunden');
        }
        foreach ($stapel->auftraege as $a) {
            Eingang::loeschen($a->ablage_pfad);
            $a->delete();
        }
        $stapel->delete();

        return new JsonResponse(null, 204);
    }

    private function finden(Request $request, string $id): ?Pruefstapel
    {
        return Pruefstapel::where('mandant_id', $request->attributes->get('mandant')->id)->with('auftraege')->find($id);
    }

    private function antwort(Pruefstapel $stapel, int $status): JsonResponse
    {
        $antwort = new JsonResponse(Darstellung::stapel($stapel), $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($status === 202) {
            $antwort->header('Location', url('/api/v1/stapel/'.$stapel->id))->header('Retry-After', (string) min(300, 15 * $stapel->anzahl));
        }

        return $antwort;
    }
}
