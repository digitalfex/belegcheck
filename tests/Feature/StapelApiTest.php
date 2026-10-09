<?php

namespace Tests\Feature;

use App\Jobs\PruefauftragAusfuehren;
use App\Models\ApiSchluessel;
use App\Models\Mandant;
use App\Models\PlzOrt;
use App\Models\Pruefauftrag;
use App\Models\Pruefstapel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StapelApiTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private string $schluessel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mandant = Mandant::create(['name' => 'Testfirma', 'kuerzel' => 'test']);
        [, $this->schluessel] = ApiSchluessel::erzeugen($this->mandant, 'ERP');
        PlzOrt::insert([
            ['land' => 'AT', 'plz' => '1010', 'ort' => 'Wien', 'lat' => 48.2085, 'lon' => 16.3721],
            ['land' => 'DE', 'plz' => '80331', 'ort' => 'München', 'lat' => 48.1374, 'lon' => 11.5755],
        ]);
    }

    /** Belegleser-Antwort ohne QR-Code: Lokal, Ort, Zeit, Summe */
    private function lesung(string $name, string $ort, string $zeit, string $summe): array
    {
        $zeilen = [$name, $ort, "Summe EUR $summe", $zeit];

        return ['codes' => [], 'seiten' => 1, 'quelle' => 'ocr', 'sicherheit' => 0.95, 'text' => implode("\n", $zeilen),
            'zeilen' => array_map(fn ($t) => ['text' => $t, 'sicherheit' => 0.95], $zeilen), 'forensik' => ['ki_kennzeichen' => [], 'metadaten' => [], 'bearbeitungssoftware' => []]];
    }

    private function kopf(array $mehr = []): array
    {
        return ['Authorization' => 'Bearer '.$this->schluessel, 'Accept' => 'application/json', ...$mehr];
    }

    public function test_stapel_per_formular_mit_ortscheck_und_einem_webhook(): void
    {
        $this->mandant->update(['webhook_url' => 'https://erp.test/hook', 'webhook_geheimnis' => 'whsec_x']);
        Http::fake([
            '127.0.0.1:8090/*' => Http::sequence()
                ->push($this->lesung('Gasthaus Linde', '1010 Wien', '08.10.2026 19:42', '44,20'))
                ->push($this->lesung('Augustiner Bräu', '80331 München', '08.10.2026 20:30', '38,90'))
                ->push($this->lesung('Café Central', '1010 Wien', '09.10.2026 09:15', '12,40')),
            'erp.test/*' => Http::response('', 204),
        ]);

        $antwort = $this->withHeaders($this->kopf(['Idempotency-Key' => 'RK-2026-17']))->post('/api/v1/stapel', [
            'externe_referenz' => 'RK-2026-17',
            'einreicher' => 'MA-17',
            'dateien' => [UploadedFile::fake()->createWithContent('a.jpg', 'a'), UploadedFile::fake()->createWithContent('b.jpg', 'b'), UploadedFile::fake()->createWithContent('c.pdf', 'c')],
            'positionen' => json_encode([['externe_referenz' => 'P1', 'betrag' => '44,20', 'kategorie' => 'bewirtung'], ['externe_referenz' => 'P2', 'betrag' => '38,90'], ['externe_referenz' => 'P3']]),
        ])->assertStatus(202)->assertJsonPath('objekt', 'stapel')->assertJsonPath('anzahl', 3);
        $id = $antwort->json('id');
        $antwort->assertHeader('Location', url('/api/v1/stapel/'.$id));

        $stapel = $this->withHeaders($this->kopf())->getJson('/api/v1/stapel/'.$id)->assertOk()
            ->assertJsonPath('status', 'fertig')
            ->assertJsonPath('fortschritt.fertig', 3)
            ->assertJsonPath('ergebnis.fertig', 3)
            ->assertJsonPath('ergebnis.summe_belege_cent', 4420 + 3890 + 1240)
            ->assertJsonPath('ergebnis.empfehlung', 'manuell_pruefen')
            ->assertJsonPath('pruefungen.0.externe_referenz', 'P1')
            ->json();

        // Wien und München am selben Abend → beide Belege bekommen MU-OZ-01, auch der zuerst geprüfte
        foreach ([0, 1] as $i) {
            $codes = collect($stapel['pruefungen'][$i]['ergebnis']['befunde'])->pluck('code')->all();
            $this->assertContains('MU-OZ-01', $codes, "Beleg $i");
        }
        $this->assertNotContains('MU-OZ-01', collect($stapel['pruefungen'][2]['ergebnis']['befunde'])->pluck('code')->all());

        // Genau ein Webhook für den Stapel, keiner je Beleg
        $hooks = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'erp.test'));
        $this->assertCount(1, $hooks);
        $this->assertSame('stapel.fertig', $hooks->first()[0]['typ']);
        $this->assertSame('zugestellt', Pruefstapel::find($id)->webhook_status);

        // Idempotenz: gleicher Stapel nochmals → derselbe
        $this->withHeaders($this->kopf(['Idempotency-Key' => 'RK-2026-17']))->post('/api/v1/stapel', [
            'externe_referenz' => 'RK-2026-17', 'einreicher' => 'MA-17',
            'dateien' => [UploadedFile::fake()->createWithContent('a.jpg', 'a'), UploadedFile::fake()->createWithContent('b.jpg', 'b'), UploadedFile::fake()->createWithContent('c.pdf', 'c')],
            'positionen' => json_encode([['externe_referenz' => 'P1', 'betrag' => '44,20', 'kategorie' => 'bewirtung'], ['externe_referenz' => 'P2', 'betrag' => '38,90'], ['externe_referenz' => 'P3']]),
        ])->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $id);
    }

    public function test_stapel_als_json_mit_feldfehlern_je_beleg(): void
    {
        $this->withHeaders($this->kopf())->postJson('/api/v1/stapel', ['belege' => [
            ['datei_base64' => base64_encode('x'), 'dateiname' => 'a.pdf'],
            ['datei_base64' => base64_encode('y'), 'dateiname' => 'b.pdf', 'kategorie' => 'yacht'],
        ]])->assertStatus(422)->assertJsonStructure(['fehler' => ['belege.1.kategorie']]);
        $this->assertSame(0, Pruefstapel::count());
    }

    public function test_fehlgeschlagener_beleg_im_stapel(): void
    {
        Queue::fake();
        Http::fake(['127.0.0.1:8090/*' => Http::response($this->lesung('Gasthaus Linde', '1010 Wien', '08.10.2026 19:42', '44,20'))]);

        $id = $this->withHeaders($this->kopf())->postJson('/api/v1/stapel', ['einreicher' => 'MA-1', 'belege' => [
            ['datei_base64' => base64_encode('x'), 'dateiname' => 'a.pdf'],
            ['datei_base64' => base64_encode('y'), 'dateiname' => 'b.jpg'],
        ]])->assertStatus(202)->assertJsonPath('status', 'laeuft')->json('id');
        Queue::assertPushed(PruefauftragAusfuehren::class, 2);

        [$erster, $zweiter] = Pruefauftrag::where('stapel_id', $id)->orderBy('position')->pluck('id')->all();
        app()->call([new PruefauftragAusfuehren($erster), 'handle']);
        $this->withHeaders($this->kopf())->getJson('/api/v1/stapel/'.$id)->assertJsonPath('status', 'laeuft')->assertJsonPath('fortschritt.fertig', 1);

        (new PruefauftragAusfuehren($zweiter))->failed(new \RuntimeException('Belegleser weg'));
        $this->withHeaders($this->kopf())->getJson('/api/v1/stapel/'.$id)
            ->assertJsonPath('status', 'fertig')->assertJsonPath('ergebnis.fehler', 1)
            ->assertJsonPath('ergebnis.empfehlung', 'manuell_pruefen')->assertJsonPath('pruefungen.1.status', 'fehler');
    }

    public function test_fremder_mandant_und_loeschen(): void
    {
        Queue::fake();
        $id = $this->withHeaders($this->kopf())->postJson('/api/v1/stapel', ['belege' => [['datei_base64' => base64_encode('x'), 'dateiname' => 'a.pdf']]])->json('id');
        $anderer = Mandant::create(['name' => 'Andere', 'kuerzel' => 'andere']);
        [, $fremd] = ApiSchluessel::erzeugen($anderer, 'x');
        $this->withHeaders(['Authorization' => 'Bearer '.$fremd])->getJson('/api/v1/stapel/'.$id)->assertNotFound();

        $this->withHeaders($this->kopf())->deleteJson('/api/v1/stapel/'.$id)->assertNoContent();
        $this->assertSame(0, Pruefauftrag::count());
    }
}
