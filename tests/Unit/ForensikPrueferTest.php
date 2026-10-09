<?php

namespace Tests\Unit;

use App\Forensik\ForensikPruefer;
use App\Pruefung\Stufe;
use PHPUnit\Framework\TestCase;

class ForensikPrueferTest extends TestCase
{
    private function codes(array $f, ?string $datum = '2026-10-08 19:42'): array
    {
        $out = [];
        foreach ((new ForensikPruefer)->pruefe($f, $datum) as $e) {
            $out[$e->code] = $e->stufe;
        }

        return $out;
    }

    public function test_handyfoto_ohne_auffaelligkeit(): void
    {
        $c = $this->codes(['ki_kennzeichen' => [], 'bearbeitungssoftware' => [], 'metadaten' => ['modell' => 'iPhone 15', 'aufnahme' => '2026:10:08 19:50:02']]);
        $this->assertSame([Stufe::Ok, Stufe::Ok, Stufe::Ok], array_values($c));
    }

    public function test_ki_kennzeichen_ist_widerspruch(): void
    {
        $c = $this->codes(['ki_kennzeichen' => ['IPTC-Herkunftsangabe „KI-erzeugt“']]);
        $this->assertSame(Stufe::Widerspruch, $c['BF-01']);
    }

    public function test_photoshop_ist_auffaellig(): void
    {
        $c = $this->codes(['bearbeitungssoftware' => ['photoshop'], 'metadaten' => ['software' => 'Adobe Photoshop 26.1']]);
        $this->assertSame(Stufe::Auffaellig, $c['BF-02']);
    }

    public function test_foto_vor_belegdatum(): void
    {
        $c = $this->codes(['metadaten' => ['aufnahme' => '2026:09:01 10:00:00']]);
        $this->assertSame(Stufe::Auffaellig, $c['BF-03']);
    }

    public function test_ohne_metadaten_kein_signal(): void
    {
        $c = $this->codes(['metadaten' => []]);
        $this->assertSame(Stufe::NichtPruefbar, $c['BF-03']);
    }
}
