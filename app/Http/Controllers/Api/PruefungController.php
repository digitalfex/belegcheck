<?php

namespace App\Http\Controllers\Api;

use App\Api\Darstellung;
use App\Api\Eingang;
use App\Api\Problem;
use App\Api\PruefauftragDienst;
use App\Http\Controllers\Controller;
use App\Jobs\PruefauftragAusfuehren;
use App\Models\Mandant;
use App\Models\Pruefauftrag;
use App\Pruefung\BelegPruefService;
use App\Screening\Branche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * /api/v1/pruefungen – siehe docs/openapi.yaml
 */
class PruefungController extends Controller
{
    private const ENDUNGEN = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'pdf'];

    public function store(Request $request, PruefauftragDienst $dienst): JsonResponse
    {
        /** @var Mandant $mandant */
        $mandant = $request->attributes->get('mandant');
        $maxKb = (int) config('belegcheck.api.max_mb') * 1024;

        $daten = Validator::make($request->all(), [
            'datei' => ['required_without:datei_base64', 'file', 'max:'.$maxKb, 'mimes:'.implode(',', self::ENDUNGEN)],
            'datei_base64' => ['required_without:datei', 'string'],
            'dateiname' => ['required_with:datei_base64', 'string', 'max:200', 'regex:/\.('.implode('|', self::ENDUNGEN).')$/i'],
            'externe_referenz' => ['nullable', 'string', 'max:191'],
            'einreicher' => ['nullable', 'string', 'max:191'],
            'betrag' => ['nullable', 'regex:/^-?\d{1,7}([.,]\d{1,2})?$/'],
            'datum' => ['nullable', 'date'],
            'kategorie' => ['nullable', Rule::in(array_keys(Branche::KATEGORIE_BRANCHEN))],
        ], [], ['datei' => 'Datei', 'datei_base64' => 'Datei (Base64)'])->validate();

        // Datei aus Formular-Upload oder Base64 (JSON)
        if (isset($daten['datei'])) {
            /** @var UploadedFile $datei */
            $datei = $daten['datei'];
            $inhalt = file_get_contents($datei->getRealPath());
            $dateiname = $datei->getClientOriginalName();
        } else {
            $inhalt = base64_decode($daten['datei_base64'], true);
            if ($inhalt === false || $inhalt === '' || strlen($inhalt) > $maxKb * 1024) {
                return Problem::antwort(422, 'Ungültige Datei', 'datei_base64 ist kein gültiges Base64 oder zu groß.', 'ungueltige-datei');
            }
            $dateiname = basename($daten['dateiname']);
        }

        $angaben = array_filter(['betrag' => $daten['betrag'] ?? null, 'datum' => $daten['datum'] ?? null, 'kategorie' => $daten['kategorie'] ?? null],
            fn ($w) => $w !== null);
        $asynchron = str_contains(strtolower((string) $request->header('Prefer')), 'respond-async');

        // Idempotenz: gleicher Schlüssel + gleiche Anfrage → bestehende Prüfung; gleicher Schlüssel + andere Anfrage → Fehler
        $idemSchluessel = $request->header('Idempotency-Key');
        $idemHash = hash('sha256', hash('sha256', $inhalt).'|'.json_encode([$angaben, $daten['externe_referenz'] ?? null, $daten['einreicher'] ?? null]));
        if ($idemSchluessel !== null) {
            if (strlen($idemSchluessel) > 191) {
                return Problem::antwort(400, 'Idempotency-Key zu lang', 'Höchstens 191 Zeichen.', 'idempotenz');
            }
            $vorhanden = Pruefauftrag::where('mandant_id', $mandant->id)->where('idempotenz_schluessel', $idemSchluessel)->first();
            // Fehlgeschlagene Prüfung mit gleichem Schlüssel: neu versuchen statt den Fehler zu wiederholen
            if ($vorhanden && $vorhanden->status === 'fehler' && $vorhanden->idempotenz_hash === $idemHash) {
                Eingang::loeschen($vorhanden->ablage_pfad);
                $vorhanden->delete();
                $vorhanden = null;
            }
            if ($vorhanden) {
                return $vorhanden->idempotenz_hash === $idemHash
                    ? $this->antwort($vorhanden, $vorhanden->status === 'fertig' ? 200 : 202)->header('Idempotent-Replayed', 'true')
                    : Problem::antwort(422, 'Idempotency-Key bereits verwendet', 'Dieser Schlüssel gehört zu einer anderen Anfrage.', 'idempotenz');
            }
        }

        $auftrag = Pruefauftrag::create([
            'mandant_id' => $mandant->id,
            'status' => 'wartend',
            'externe_referenz' => $daten['externe_referenz'] ?? null,
            'einreicher' => $daten['einreicher'] ?? null,
            'idempotenz_schluessel' => $idemSchluessel,
            'idempotenz_hash' => $idemSchluessel !== null ? $idemHash : null,
            'angaben' => $angaben ?: null,
            'dateiname' => $dateiname,
        ]);
        $auftrag->setRelation('mandant', $mandant);

        if ($asynchron) {
            $auftrag->update(['ablage_pfad' => Eingang::ablegen($auftrag->id, $inhalt)]);
            PruefauftragAusfuehren::dispatch($auftrag->id);

            return $this->antwort($auftrag->fresh(), 202)->header('Preference-Applied', 'respond-async');
        }

        $temp = tempnam(sys_get_temp_dir(), 'beleg').'.'.strtolower(pathinfo($dateiname, PATHINFO_EXTENSION));
        file_put_contents($temp, $inhalt);
        try {
            $dienst->ausfuehren($auftrag, $temp);
        } catch (\Throwable $fehler) {
            report($fehler);
            $auftrag->update(['status' => 'fehler', 'fehler' => 'Prüfung fehlgeschlagen (Belegleser nicht erreichbar oder Datei nicht lesbar).']);

            return Problem::antwort(503, 'Prüfung derzeit nicht möglich', 'Bitte später erneut senden (gleicher Idempotency-Key) oder asynchron mit „Prefer: respond-async“.',
                'pruefung-fehlgeschlagen', ['pruefung' => $auftrag->id])->header('Retry-After', '60');
        } finally {
            @unlink($temp);
        }

        return $this->antwort($auftrag->fresh(), 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $auftrag = $this->finden($request, $id);

        return $auftrag ? $this->antwort($auftrag, 200) : $this->nichtGefunden();
    }

    public function index(Request $request): JsonResponse
    {
        $daten = Validator::make($request->query(), [
            'externe_referenz' => ['nullable', 'string'], 'einreicher' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['wartend', 'laeuft', 'fertig', 'fehler'])],
            'empfehlung' => ['nullable', Rule::in(['automatisch_freigeben', 'stichprobe', 'manuell_pruefen'])],
            'seit' => ['nullable', 'date'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string'],
        ])->validate();

        $seite = Pruefauftrag::where('mandant_id', $request->attributes->get('mandant')->id)
            ->when($daten['externe_referenz'] ?? null, fn ($q, $w) => $q->where('externe_referenz', $w))
            ->when($daten['einreicher'] ?? null, fn ($q, $w) => $q->where('einreicher', $w))
            ->when($daten['status'] ?? null, fn ($q, $w) => $q->where('status', $w))
            ->when($daten['empfehlung'] ?? null, fn ($q, $w) => $q->where('empfehlung', $w))
            ->when($daten['seit'] ?? null, fn ($q, $w) => $q->where('created_at', '>=', $w))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($daten['limit'] ?? 25));

        return new JsonResponse([
            'objekt' => 'liste',
            'daten' => array_map(fn ($a) => Darstellung::pruefung($a), $seite->items()),
            'naechster_cursor' => $seite->nextCursor()?->encode(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Löschen (DSGVO, Ende der Aufbewahrung). Dubletten-Merkmale verschwinden mit. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $auftrag = $this->finden($request, $id);
        if (! $auftrag) {
            return $this->nichtGefunden();
        }
        Eingang::loeschen($auftrag->ablage_pfad);
        $auftrag->delete();

        return new JsonResponse(null, 204);
    }

    /**
     * Rückmeldung der Prüferin/des Prüfers aus dem ERP. „in_ordnung“ übernimmt die Kasse ins Kassen-Gedächtnis,
     * damit künftige Belege dieser Kasse besser beurteilt werden.
     */
    public function entscheidung(Request $request, string $id, BelegPruefService $pruefung): JsonResponse
    {
        $auftrag = $this->finden($request, $id);
        if (! $auftrag) {
            return $this->nichtGefunden();
        }
        if ($auftrag->status !== 'fertig') {
            return Problem::antwort(409, 'Prüfung noch nicht fertig', null, 'nicht-fertig');
        }
        $daten = Validator::make($request->all(), [
            'ergebnis' => ['required', Rule::in(['in_ordnung', 'beanstandet'])],
            'kommentar' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $auftrag->update(['entscheidung' => $daten['ergebnis'], 'entscheidung_kommentar' => $daten['kommentar'] ?? null]);
        $e = $auftrag->ergebnis ?? [];
        if ($daten['ergebnis'] === 'in_ordnung' && ($e['qr_text'] ?? null)) {
            $pruefung->bestaetige($e['qr_text'], $e['uid'] ?? null, $e['aussteller'] ?? null, $auftrag->datei_sha256);
        }

        return $this->antwort($auftrag, 200);
    }

    private function finden(Request $request, string $id): ?Pruefauftrag
    {
        return Pruefauftrag::where('mandant_id', $request->attributes->get('mandant')->id)->find($id);
    }

    private function nichtGefunden(): JsonResponse
    {
        return Problem::antwort(404, 'Prüfung nicht gefunden', null, 'nicht-gefunden');
    }

    private function antwort(Pruefauftrag $auftrag, int $status): JsonResponse
    {
        $antwort = new JsonResponse(Darstellung::pruefung($auftrag), $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($status === 202) {
            $antwort->header('Location', url('/api/v1/pruefungen/'.$auftrag->id))->header('Retry-After', '10');
        }

        return $antwort;
    }
}
