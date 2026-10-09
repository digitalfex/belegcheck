<?php

namespace Tests\Feature;

use App\Screening\AusstellerPruefer;
use App\Screening\Osm;
use App\Screening\Vies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AusstellerScreeningTest extends TestCase
{
    use RefreshDatabase;

    private function beleg(array $mehr = []): array
    {
        return $mehr + ['uid' => 'ATU70462778', 'aussteller' => 'WALDEMAR', 'text' => "WALDEMAR\nWGJ Gastronomie GmbH\n1130 Wien\nUID: ATU 70462778\nTotal 46,00",
            'zweitlesung' => "WALDEMAR\nUID: ATU70462778", 'zeilen' => ['WALDEMAR', 'WGJ Gastronomie GmbH', '1130 Wien', 'Cappuccino 5,90', 'Total 46,00'],
            'ort' => ['plz' => '1130', 'ort' => 'Wien', 'lat' => 48.18, 'lon' => 16.29], 'zeitpunkt' => '2025-06-13 09:30', 'kategorie' => 'bewirtung'];
    }

    private function pruefer(): AusstellerPruefer
    {
        return new AusstellerPruefer(new Vies('https://vies.test'), new Osm('https://overpass.test/api'));
    }

    private function stufen(array $r): array
    {
        return collect($r['ergebnisse'])->mapWithKeys(fn ($e) => [$e->code => $e->stufe->value])->all();
    }

    public function test_alles_passt(): void
    {
        Http::fake([
            'vies.test/*' => Http::response(['isValid' => true, 'name' => 'WGJ Gastronomie GmbH', 'address' => 'Pallenbergstraße 20, 1130 Wien', 'userError' => 'VALID']),
            'overpass.test/*' => Http::response(['elements' => [
                ['type' => 'node', 'lat' => 48.181, 'lon' => 16.291, 'tags' => ['name' => 'Waldemar Tagesbar', 'amenity' => 'cafe', 'opening_hours' => 'Mo-Fr 08:00-22:00; Sa,Su 09:00-15:00']],
                ['type' => 'way', 'center' => ['lat' => 48.2, 'lon' => 16.3], 'tags' => ['name' => 'Waldemarweg', 'highway' => 'residential']],
            ]]),
        ]);

        $r = $this->pruefer()->pruefe($this->beleg());
        $this->assertSame(['AS-01' => 'ok', 'AS-02' => 'ok', 'AS-03' => 'ok', 'AS-04' => 'ok', 'BR-01' => 'ok', 'CO-01' => 'ok', 'CO-02' => 'ok'], $this->stufen($r));
        $this->assertSame('gastronomie', $r['daten']['branche']);

        // Zweiter Aufruf kommt aus dem Zwischenspeicher
        Http::fake(fn () => throw new \RuntimeException('keine zweite Abfrage erwartet'));
        $this->assertSame('ok', $this->stufen($this->pruefer()->pruefe($this->beleg()))['AS-03']);
    }

    public function test_ungueltige_uid_und_geschlossen(): void
    {
        Http::fake([
            'vies.test/*' => Http::response(['isValid' => false, 'userError' => 'INVALID']),
            'overpass.test/*' => Http::response(['elements' => [['type' => 'node', 'lat' => 48.18, 'lon' => 16.29,
                'tags' => ['name' => 'Waldemar', 'amenity' => 'cafe', 'opening_hours' => 'Mo-Fr 08:00-17:00']]]]),
        ]);

        $s = $this->stufen($this->pruefer()->pruefe($this->beleg(['zeitpunkt' => '2025-06-13 23:40'])));
        $this->assertSame('auffaellig', $s['AS-01'], 'UID in beiden Lesungen gleich → kein Lesefehler');
        $this->assertSame('auffaellig', $s['AS-04']);
    }

    public function test_nicht_gefunden_ist_kein_verdacht_und_register_offline(): void
    {
        Http::fake(['vies.test/*' => Http::response(['userError' => 'MS_UNAVAILABLE']), 'overpass.test/*' => Http::response(['elements' => []])]);

        $s = $this->stufen($this->pruefer()->pruefe($this->beleg()));
        $this->assertSame('nicht_pruefbar', $s['AS-01']);
        $this->assertSame('nicht_pruefbar', $s['AS-03']);
    }

    public function test_compliance_richtlinie_des_mandanten(): void
    {
        Http::fake(['vies.test/*' => Http::response(['isValid' => true, 'name' => 'Sportwetten GmbH', 'userError' => 'VALID']),
            'overpass.test/*' => Http::response(['elements' => [['type' => 'node', 'lat' => 48.18, 'lon' => 16.29, 'tags' => ['name' => 'Wettbüro Ecke', 'shop' => 'bookmaker']]]])]);
        $beleg = $this->beleg(['aussteller' => 'Wettbüro Ecke', 'zeilen' => ['Wettbüro Ecke', '1130 Wien', 'x', 'Wetteinsatz 50,00']]);

        $s = $this->stufen($this->pruefer()->pruefe($beleg));
        $this->assertSame(['auffaellig', 'auffaellig', 'hinweis'], [$s['CO-01'], $s['CO-02'], $s['BR-01']]);

        $s = $this->stufen($this->pruefer()->pruefe($beleg, true, ['gluecksspiel' => 'erlaubt']));
        $this->assertSame(['ok', 'ok'], [$s['CO-01'], $s['CO-02']]);
    }

    public function test_ohne_screening_nur_branche_und_compliance(): void
    {
        Http::fake(fn () => throw new \RuntimeException('keine Abfrage nach außen erwartet'));
        $s = $this->stufen($this->pruefer()->pruefe($this->beleg(['aussteller' => 'Laufhaus Mitte']), false));
        $this->assertArrayNotHasKey('AS-01', $s);
        $this->assertSame('auffaellig', $s['CO-01']);
    }
}
