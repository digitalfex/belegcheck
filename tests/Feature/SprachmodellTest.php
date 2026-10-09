<?php

namespace Tests\Feature;

use App\Belegleser\BelegleserClient;
use App\Pruefung\BelegPruefService;
use App\Sprachmodell\BelegSprachmodell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RksvTestBeleg;
use Tests\TestCase;

class SprachmodellTest extends TestCase
{
    use RefreshDatabase;

    private function lesen(?string $qr, array $zeilen, string $zweitlesung): array
    {
        return ['codes' => $qr ? [['text' => $qr, 'format' => 'QRCode', 'seite' => 1]] : [], 'seiten' => 1, 'quelle' => 'ocr', 'sicherheit' => 0.9,
            'text' => implode("\n", $zeilen), 'zeilen' => array_map(fn ($t) => ['text' => $t, 'sicherheit' => 0.9], $zeilen),
            'alternativen' => [], 'zweitlesung' => $zweitlesung];
    }

    private function modellAntwort(array $felder): array
    {
        $felder += ['aussteller' => null, 'uid' => null, 'belegnummer' => null, 'datum' => null, 'uhrzeit' => null, 'gesamtbetrag' => null, 'steuersaetze' => []];

        return ['choices' => [['message' => ['content' => json_encode($felder)]]]];
    }

    private function pruefe(string $url = 'http://127.0.0.1:8081'): array
    {
        $pfad = tempnam(sys_get_temp_dir(), 'beleg');
        file_put_contents($pfad, 'x');
        $dienst = new BelegPruefService(new BelegleserClient('http://belegleser.test'), sprachmodell: new BelegSprachmodell($url));

        return $dienst->pruefeDatei($pfad);
    }

    public function test_ki_bestaetigt_qr_summe_wo_die_texterkennung_sich_verlesen_hat(): void
    {
        $qr = RksvTestBeleg::neu()->qr(); // Summe 44,20
        Http::fake([
            'belegleser.test/*' => Http::response($this->lesen($qr, ['GASTHAUS ZUR LINDE', 'SUMME EUR 49,20', '08.10.2026 19:42:11'], "GASTHAUS ZUR LINDE\nSumme EUR44.20")),
            '127.0.0.1:8081/*' => Http::response($this->modellAntwort(['aussteller' => 'Gasthaus zur Linde', 'gesamtbetrag' => '44,20'])),
        ]);

        $b = $this->pruefe();
        $this->assertSame(4420, $b['gedruckt']['gesamt_cent']);
        $this->assertSame(['gesamt'], $b['ki']['felder']);
        $this->assertSame('Gasthaus zur Linde', $b['aussteller']);
        $this->assertSame('gruen', $b['ampel']);
    }

    public function test_erfundener_ki_wert_wird_verworfen(): void
    {
        $qr = RksvTestBeleg::neu()->qr();
        Http::fake([
            'belegleser.test/*' => Http::response($this->lesen($qr, ['SUMME EUR 49,20', '08.10.2026 19:42:11'], 'Summe 49,20')),
            '127.0.0.1:8081/*' => Http::response($this->modellAntwort(['gesamtbetrag' => '44,20'])), // steht nirgends im Text
        ]);

        $b = $this->pruefe();
        $this->assertSame(4920, $b['gedruckt']['gesamt_cent']);
        $this->assertSame([], $b['ki']['felder']);
    }

    public function test_ki_fuellt_luecke_ohne_qr_nur_unsicher(): void
    {
        Http::fake([
            'belegleser.test/*' => Http::response($this->lesen(null, ['APCOA PARKING Austria GmbH', 'Datum: 02/04/24 Zeit: 19:11'], "BETRAG 8,00EUR\nDatum: 02/04/24 Zeit: 19:11")),
            '127.0.0.1:8081/*' => Http::response($this->modellAntwort(['gesamtbetrag' => '8,00', 'datum' => '2024-04-02', 'uhrzeit' => '19:11'])),
        ]);

        $b = $this->pruefe();
        $this->assertSame(800, $b['gedruckt']['gesamt_cent']);
        $this->assertSame(0.7, $b['gedruckt']['lesesicherheit']['gesamt']);
        $this->assertContains('gesamt', $b['ki']['felder']);
    }

    public function test_entferntes_modell_wird_nie_angesprochen(): void
    {
        Http::fake(['belegleser.test/*' => Http::response($this->lesen(null, ['Pizzeria'], '')), '*' => Http::response($this->modellAntwort([]))]);

        $b = $this->pruefe('https://modell.example.com');
        $this->assertFalse($b['ki']['genutzt']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'example.com'));
    }

    public function test_ohne_bedarf_wird_nicht_gefragt(): void
    {
        $qr = RksvTestBeleg::neu()->qr();
        Http::fake(['belegleser.test/*' => Http::response($this->lesen($qr, ['SUMME EUR 44,20', '08.10.2026 19:42:11'], ''))]);

        $b = $this->pruefe();
        $this->assertFalse($b['ki']['genutzt']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '8081'));
    }
}
