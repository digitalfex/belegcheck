<?php

namespace Tests\Feature;

use App\Jobs\PruefauftragAusfuehren;
use App\Models\ApiSchluessel;
use App\Models\Kasse;
use App\Models\Mandant;
use App\Models\Pruefauftrag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RksvTestBeleg;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private string $schluessel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mandant = Mandant::create(['name' => 'Testfirma', 'kuerzel' => 'test']);
        [, $this->schluessel] = ApiSchluessel::erzeugen($this->mandant, 'ERP');
        $this->belegleser();
    }

    /** Belegleser liefert einen Bon mit QR-Code (Summe 44,20) */
    private function belegleser(string $summe = '44,20', ?string $qr = null): void
    {
        $qr ??= RksvTestBeleg::neu()->qr();
        Http::fake(['127.0.0.1:8090/*' => Http::response(['codes' => [['text' => $qr, 'format' => 'QRCode', 'seite' => 1]], 'seiten' => 1, 'quelle' => 'ocr',
            'sicherheit' => 0.95, 'text' => "GASTHAUS ZUR LINDE\nSUMME EUR $summe\n08.10.2026 19:42:11",
            'zeilen' => [['text' => 'GASTHAUS ZUR LINDE', 'sicherheit' => 0.95], ['text' => "SUMME EUR $summe", 'sicherheit' => 0.95], ['text' => '08.10.2026 19:42:11', 'sicherheit' => 0.95]],
            'forensik' => ['ki_kennzeichen' => [], 'metadaten' => [], 'bearbeitungssoftware' => []]])]);
    }

    private function senden(array $felder = [], array $kopf = [], ?string $schluessel = null, string $datei = 'bon.jpg')
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.($schluessel ?? $this->schluessel), 'Accept' => 'application/json', ...$kopf])
            ->post('/api/v1/pruefungen', ['datei' => UploadedFile::fake()->createWithContent($datei, $felder['_inhalt'] ?? 'bild-1'), ...array_diff_key($felder, ['_inhalt' => 1])]);
    }

    public function test_ohne_gueltigen_schluessel_401_als_problem(): void
    {
        $this->postJson('/api/v1/pruefungen')->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Nicht angemeldet');
        $this->senden([], [], 'bc_abcdefgh_'.str_repeat('x', 40))->assertStatus(401);
    }

    public function test_synchrone_pruefung_liefert_score_und_daten(): void
    {
        $this->senden(['externe_referenz' => 'SP-2026-0815', 'einreicher' => 'MA-17', 'betrag' => '44,20', 'kategorie' => 'bewirtung'])
            ->assertOk()
            ->assertJsonPath('objekt', 'pruefung')
            ->assertJsonPath('status', 'fertig')
            ->assertJsonPath('externe_referenz', 'SP-2026-0815')
            ->assertJsonPath('ergebnis.ampel', 'gruen')
            ->assertJsonPath('ergebnis.score', 100)
            ->assertJsonPath('ergebnis.daten.summe_cent', 4420)
            ->assertJsonPath('ergebnis.daten.quellen.summe', 'kassen_qr')
            ->assertJsonPath('ergebnis.daten.kasse', 'KASSE-01')
            ->assertJsonPath('ergebnis.daten.branche', 'gastronomie')
            ->assertJsonStructure(['id', 'ergebnis' => ['abdeckung', 'empfehlung', 'befunde', 'bereiche'], 'regelwerk', 'links' => ['self']]);

        $this->assertNull(Pruefauftrag::first()->ablage_pfad, 'synchron wird nichts abgelegt');
    }

    public function test_eingereichter_betrag_zu_hoch_fuehrt_zu_manueller_pruefung(): void
    {
        $befunde = collect($this->senden(['betrag' => '64,20'])->assertOk()
            ->assertJsonPath('ergebnis.empfehlung', 'manuell_pruefen')->json('ergebnis.befunde'))->keyBy('code');
        $this->assertSame('auffaellig', $befunde['EA-01']['stufe']);
    }

    public function test_ungueltige_felder_422_mit_feldfehlern(): void
    {
        $this->senden(['kategorie' => 'yacht', 'betrag' => 'viel'])->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['fehler' => ['kategorie', 'betrag']]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->schluessel])->postJson('/api/v1/pruefungen', ['datei_base64' => '%%%', 'dateiname' => 'a.exe'])
            ->assertStatus(422);
    }

    public function test_base64_als_json(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->schluessel])
            ->postJson('/api/v1/pruefungen', ['datei_base64' => base64_encode('pdf-inhalt'), 'dateiname' => 'beleg.pdf'])
            ->assertOk()->assertJsonPath('status', 'fertig');
    }

    public function test_idempotenz(): void
    {
        $erste = $this->senden(['externe_referenz' => 'A'], ['Idempotency-Key' => 'k-1'])->assertOk();
        $this->senden(['externe_referenz' => 'A'], ['Idempotency-Key' => 'k-1'])->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $erste->json('id'));
        $this->senden(['externe_referenz' => 'B'], ['Idempotency-Key' => 'k-1'])->assertStatus(422)->assertJsonPath('title', 'Idempotency-Key bereits verwendet');
        $this->assertSame(1, Pruefauftrag::count());
    }

    public function test_nach_fehler_wird_mit_gleichem_schluessel_neu_geprueft(): void
    {
        Http::swap(new Factory);
        Http::fake(['127.0.0.1:8090/*' => Http::response('weg', 502)]);
        $this->senden([], ['Idempotency-Key' => 'k-2'])->assertStatus(503)->assertHeader('Retry-After', '60');

        Http::swap(new Factory);
        $this->belegleser();
        $this->senden([], ['Idempotency-Key' => 'k-2'])->assertOk()->assertJsonPath('status', 'fertig');
        $this->assertSame(1, Pruefauftrag::count());
    }

    public function test_asynchron_mit_prefer_header(): void
    {
        Queue::fake();
        $antwort = $this->senden([], ['Prefer' => 'respond-async'])->assertStatus(202)
            ->assertHeader('Preference-Applied', 'respond-async')->assertJsonPath('status', 'wartend');
        $id = $antwort->json('id');
        $antwort->assertHeader('Location', url('/api/v1/pruefungen/'.$id));
        Queue::assertPushed(PruefauftragAusfuehren::class);

        $pfad = Pruefauftrag::find($id)->ablage_pfad;
        $this->assertStringNotContainsString('bild-1', Storage::disk('local')->get($pfad), 'Datei liegt verschlüsselt');

        app()->call([new PruefauftragAusfuehren($id), 'handle']);
        $this->assertFalse(Storage::disk('local')->exists($pfad), 'Datei nach der Prüfung gelöscht');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->schluessel])->getJson('/api/v1/pruefungen/'.$id)
            ->assertOk()->assertJsonPath('status', 'fertig')->assertJsonPath('ergebnis.ampel', 'gruen');
    }

    public function test_doppelte_einreichung_wird_erkannt_ausser_gleiche_referenz(): void
    {
        $this->senden(['externe_referenz' => 'SP-1'])->assertOk();
        $this->senden(['externe_referenz' => 'SP-1'])->assertOk()->assertJsonPath('ergebnis.ampel', 'gruen'); // erneute Übertragung derselben Position

        $codes = collect($this->senden(['externe_referenz' => 'SP-2'])->assertOk()->json('ergebnis.befunde'))->pluck('code', 'code');
        $this->assertTrue($codes->has('MU-DU-01'));
    }

    public function test_webhook_mit_signatur(): void
    {
        $this->mandant->update(['webhook_url' => 'https://erp.test/belegcheck', 'webhook_geheimnis' => 'whsec_test']);
        Http::fake(['erp.test/*' => Http::response('', 204)]);
        $this->belegleser();

        $id = $this->senden()->assertOk()->json('id');

        Http::assertSent(function ($anfrage) use ($id) {
            if (! str_contains($anfrage->url(), 'erp.test')) {
                return false;
            }
            preg_match('/t=(\d+),v1=([0-9a-f]{64})/', $anfrage->header('Belegcheck-Signatur')[0] ?? '', $m);

            return $m && hash_equals(hash_hmac('sha256', $m[1].'.'.$anfrage->body(), 'whsec_test'), $m[2])
                && $anfrage['typ'] === 'pruefung.fertig' && $anfrage['daten']['id'] === $id;
        });
        $this->assertSame('zugestellt', Pruefauftrag::find($id)->webhook_status);
    }

    public function test_mandanten_sind_getrennt(): void
    {
        $id = $this->senden()->json('id');
        $anderer = Mandant::create(['name' => 'Andere', 'kuerzel' => 'andere']);
        [, $fremd] = ApiSchluessel::erzeugen($anderer, 'x');

        $this->withHeaders(['Authorization' => 'Bearer '.$fremd])->getJson('/api/v1/pruefungen/'.$id)->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer '.$fremd])->getJson('/api/v1/pruefungen')->assertOk()->assertJsonCount(0, 'daten');
    }

    public function test_liste_filter_rueckmeldung_und_loeschen(): void
    {
        $id = $this->senden(['externe_referenz' => 'SP-9'])->json('id');
        $this->senden(['externe_referenz' => 'SP-10', '_inhalt' => 'bild-2']);
        $kopf = ['Authorization' => 'Bearer '.$this->schluessel];

        $this->withHeaders($kopf)->getJson('/api/v1/pruefungen?externe_referenz=SP-9')->assertOk()
            ->assertJsonCount(1, 'daten')->assertJsonPath('daten.0.id', $id);

        Kasse::query()->delete();
        $this->withHeaders($kopf)->postJson("/api/v1/pruefungen/$id/entscheidung", ['ergebnis' => 'in_ordnung', 'kommentar' => 'passt'])
            ->assertOk()->assertJsonPath('entscheidung.ergebnis', 'in_ordnung');
        $this->assertSame(1, Kasse::count(), 'bestätigter Beleg landet im Kassen-Gedächtnis');

        $this->withHeaders($kopf)->deleteJson("/api/v1/pruefungen/$id")->assertNoContent();
        $this->withHeaders($kopf)->getJson("/api/v1/pruefungen/$id")->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_oeffentliche_beschreibung(): void
    {
        $this->get('/api/v1/gesund')->assertOk()->assertJsonPath('status', 'ok');
        $this->get('/api/v1/openapi.yaml')->assertOk()->assertSee('openapi: 3.1.0', false);
    }
}
