<?php

namespace Tests\Feature;

use App\Fiskal\KassenGedaechtnis;
use App\Fiskal\Rksv\RksvParser;
use App\Pruefung\Stufe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RksvTestBeleg;
use Tests\TestCase;

class KassenGedaechtnisTest extends TestCase
{
    use RefreshDatabase;

    private function beleg(string $nr, string $zeit, string $kasse = 'KASSE-01', string $zert = '3a7f19c2')
    {
        return (new RksvParser)->parse(RksvTestBeleg::neu()->mit('belegnummer', $nr)->mit('datumUhrzeit', $zeit)->mit('kassenId', $kasse)->mit('zertifikatSn', $zert)->qr());
    }

    /** @return array<string, Stufe> */
    private function pruefe($b, ?string $uid = 'ATU12345678', string $aussteller = 'GASTHAUS ZUR LINDE', ?string $sha = 'a'): array
    {
        $out = [];
        foreach ((new KassenGedaechtnis)->pruefe($b, $uid, $aussteller, $sha ? str_repeat($sha, 64) : null) as $e) {
            $out[$e->code] = $e->stufe;
        }

        return $out;
    }

    private function merke($b, ?string $uid = 'ATU12345678', string $sha = 'a'): void
    {
        (new KassenGedaechtnis)->merke($b, $uid, 'GASTHAUS ZUR LINDE', str_repeat($sha, 64));
    }

    public function test_neue_kasse_ist_nicht_pruefbar(): void
    {
        $this->assertSame(Stufe::NichtPruefbar, $this->pruefe($this->beleg('100', '2026-10-01T12:00:00'))['AT-KA-01']);
    }

    public function test_bekannte_kasse_mit_passendem_verlauf(): void
    {
        $this->merke($this->beleg('100', '2026-10-01T12:00:00'));
        $e = $this->pruefe($this->beleg('150', '2026-10-05T12:00:00'));

        $this->assertSame(Stufe::Ok, $e['AT-KA-01']);
        $this->assertSame(Stufe::Ok, $e['AT-KA-04']);
    }

    public function test_kasse_eines_anderen_lokals(): void
    {
        $this->merke($this->beleg('100', '2026-10-01T12:00:00'));
        $e = $this->pruefe($this->beleg('150', '2026-10-05T12:00:00'), 'ATU99999999');

        $this->assertSame(Stufe::Widerspruch, $e['AT-KA-01']);
    }

    public function test_belegnummer_laeuft_rueckwaerts(): void
    {
        $this->merke($this->beleg('R1000500', '2026-10-01T12:00:00'));
        $e = $this->pruefe($this->beleg('R1000100', '2026-10-05T12:00:00'));

        $this->assertSame(Stufe::Auffaellig, $e['AT-KA-04']);
    }

    public function test_dublette_aus_anderer_datei(): void
    {
        $this->merke($this->beleg('100', '2026-10-01T12:00:00'), sha: 'a');

        $this->assertSame(Stufe::Widerspruch, $this->pruefe($this->beleg('100', '2026-10-01T12:00:00'), sha: 'b')['AT-KA-05']);
        $this->assertSame(Stufe::Hinweis, $this->pruefe($this->beleg('100', '2026-10-01T12:00:00'), sha: 'a')['AT-KA-05']);
    }

    public function test_zertifikatswechsel(): void
    {
        $this->merke($this->beleg('100', '2026-10-01T12:00:00'));
        $e = $this->pruefe($this->beleg('150', '2026-10-05T12:00:00', zert: 'ffff0000'));

        $this->assertSame(Stufe::Hinweis, $e['AT-KA-03']);
    }

    public function test_neue_kasse_bei_bekanntem_lokal(): void
    {
        $this->merke($this->beleg('100', '2026-10-01T12:00:00'));
        $e = $this->pruefe($this->beleg('5', '2026-10-05T12:00:00', 'KASSE-02'));

        $this->assertSame(Stufe::Hinweis, $e['AT-KA-02']);
    }
}
