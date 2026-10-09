<?php

namespace Tests\Feature;

use App\Models\PlzOrt;
use App\Muster\Ortsbestimmung;
use App\Muster\OrtZeitPruefer;
use App\Pruefung\Stufe;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionscheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Auszug im GeoNames-Format
        $geo = implode("\n", [
            "AT\t1130\tWien, Hietzing\tWien\t09\t\t\t\t\t48.1833\t16.2833\t4",
            "AT\t1010\tWien, Innere Stadt\tWien\t09\t\t\t\t\t48.2085\t16.3721\t4",
            "AT\t9570\tOssiach\tKärnten\t02\t\t\t\t\t46.6741\t13.9800\t4",
            "DE\t80331\tMünchen\tBayern\tBY\t\t\t\t\t48.1372\t11.5755\t4",
            "DE\t10115\tBerlin\tBerlin\tBE\t\t\t\t\t52.5323\t13.3846\t4",
        ]);
        $datei = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($datei, $geo);
        $this->artisan('beleg:plz-import', ['--datei' => $datei])->assertSuccessful();
    }

    public function test_import(): void
    {
        $this->assertSame(5, PlzOrt::count());
    }

    public function test_ort_aus_belegkopf(): void
    {
        $o = (new Ortsbestimmung)->bestimme(['BRAUHAUS AM MARKT', 'Marienplatz 1, 80331 München', 'USt-IdNr. DE123456789'], null);
        $this->assertSame('München', $o['ort']);
        $this->assertSame('DE', $o['land']);

        $o = (new Ortsbestimmung)->bestimme(['GASTHAUS ZUR LINDE', 'Hauptplatz 3, A-9570 Ossiach'], null);
        $this->assertSame('Ossiach', $o['ort']);
    }

    public function test_entfernung_wien_muenchen(): void
    {
        $km = Ortsbestimmung::entfernungKm(48.2085, 16.3721, 48.1372, 11.5755);
        $this->assertEqualsWithDelta(355, $km, 10);
    }

    public function test_wien_und_muenchen_am_selben_abend(): void
    {
        $k = (new OrtZeitPruefer)->pruefe([
            ['id' => 1, 'zeit' => '2026-10-08 19:00', 'lat' => 48.2085, 'lon' => 16.3721, 'ort' => 'Wien'],
            ['id' => 2, 'zeit' => '2026-10-08 21:00', 'lat' => 48.1372, 'lon' => 11.5755, 'ort' => 'München'],
            ['id' => 3, 'zeit' => '2026-10-08 12:00', 'lat' => 48.1833, 'lon' => 16.2833, 'ort' => 'Wien'],
        ]);

        $this->assertSame(Stufe::Auffaellig, $k[1][0]->stufe);
        $this->assertSame(Stufe::Auffaellig, $k[2][0]->stufe);
        $this->assertArrayNotHasKey(3, $k, 'Wien 12:00 → München 21:00 ist machbar');
    }

    public function test_innerhalb_einer_stadt_nie(): void
    {
        $this->assertSame([], (new OrtZeitPruefer)->pruefe([
            ['id' => 1, 'zeit' => '2026-10-08 19:00', 'lat' => 48.2085, 'lon' => 16.3721, 'ort' => 'Wien'],
            ['id' => 2, 'zeit' => '2026-10-08 19:05', 'lat' => 48.1833, 'lon' => 16.2833, 'ort' => 'Wien'],
        ]));
    }

    public function test_flug_wien_berlin_moeglich(): void
    {
        // 520 km: Flug mit Vorlauf ≈ 3,2 h → 4 h Abstand ist plausibel
        $this->assertSame([], (new OrtZeitPruefer)->pruefe([
            ['id' => 1, 'zeit' => '2026-10-08 08:00', 'lat' => 48.2085, 'lon' => 16.3721, 'ort' => 'Wien'],
            ['id' => 2, 'zeit' => '2026-10-08 12:00', 'lat' => 52.5323, 'lon' => 13.3846, 'ort' => 'Berlin'],
        ]));
    }

    public function test_stapel_endpunkt(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)->postJson('/stapel', ['belege' => [
            ['id' => 'a', 'zeit' => '2026-10-08 19:00', 'lat' => 48.2085, 'lon' => 16.3721, 'ort' => 'Wien'],
            ['id' => 'b', 'zeit' => '2026-10-08 21:00', 'lat' => 48.1372, 'lon' => 11.5755, 'ort' => 'München'],
        ]])->assertOk()->assertJsonPath('a.0.code', 'MU-OZ-01')->assertJsonPath('b.0.stufe', 'auffaellig');
    }
}
