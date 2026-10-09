<?php

namespace Tests\Feature;

use App\Belegleser\BelegleserClient;
use App\Pruefung\BelegPruefService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\RksvTestBeleg;
use Tests\TestCase;

class WerkbankTest extends TestCase
{
    public function test_werkbank_seite_laedt(): void
    {
        $this->get('/')->assertOk()->assertSee('Ordner wählen');
    }

    public function test_upload_liefert_pruefbericht(): void
    {
        $qr = RksvTestBeleg::neu()->qr();
        Http::fake(['*/qr' => Http::response(['codes' => [['text' => $qr, 'format' => 'QRCode', 'seite' => 1]], 'seiten' => 1])]);
        $this->app->instance(BelegPruefService::class, new BelegPruefService(new BelegleserClient('http://belegleser.test')));

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/pruefen', ['datei' => UploadedFile::fake()->image('bon.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('ampel', 'gruen')
            ->assertJsonPath('qr.kassen_id', 'KASSE-01')
            ->assertJsonPath('qr.summe_cent', 4420);
    }

    public function test_upload_lehnt_fremde_dateitypen_ab(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/pruefen', ['datei' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_nachpruefen_mit_gedrucktem_betrag(): void
    {
        $qr = RksvTestBeleg::neu()->qr();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/nachpruefen', ['qr_text' => $qr, 'gesamt' => '84,20', 'datum_uhrzeit' => '08.10.2026 19:42'])
            ->assertOk()
            ->assertJsonPath('ampel', 'rot')
            ->assertJsonPath('ergebnisse.6.code', 'AT-QR-04')
            ->assertJsonPath('ergebnisse.6.stufe', 'widerspruch');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/nachpruefen', ['qr_text' => $qr, 'gesamt' => '44,20', 'datum_uhrzeit' => '08.10.2026 19:42'])
            ->assertOk()
            ->assertJsonPath('ampel', 'gruen');
    }
}
