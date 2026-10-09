<?php

namespace Tests\Unit;

use App\Pruefung\AngabenAbgleich;
use App\Pruefung\Gesamturteil;
use PHPUnit\Framework\TestCase;

class GesamturteilTest extends TestCase
{
    private function bericht(int $risiko, array $stufen, bool $qr = true): array
    {
        return ['risikowert' => $risiko, 'qr_typ' => $qr ? 'rksv' : null, 'datei_sha256' => 'x', 'forensik' => [],
            'gedruckt' => ['gesamt_cent' => 4600],
            'ergebnisse' => array_map(fn ($c, $s) => ['code' => $c, 'stufe' => $s], array_keys($stufen), $stufen)];
    }

    public function test_voll_abgedeckt_und_unauffaellig_wird_freigegeben(): void
    {
        $u = new Gesamturteil($this->bericht(0, ['AS-01' => 'ok', 'AS-03' => 'ok']));
        $this->assertSame([100, 1.0, 'automatisch_freigeben'], [$u->score(), $u->abdeckung(), $u->empfehlung()]);
    }

    public function test_unauffaellig_aber_kaum_pruefbar_ist_stichprobe(): void
    {
        $u = new Gesamturteil($this->bericht(5, ['AT-QR-01' => 'hinweis', 'AS-01' => 'nicht_pruefbar'], qr: false));
        $this->assertSame(95, $u->score());
        $this->assertLessThan(0.6, $u->abdeckung());
        $this->assertSame('stichprobe', $u->empfehlung());
        $this->assertSame('automatisch_freigeben', $u->empfehlung(['min_abdeckung' => 0.2]), 'Schwelle je Mandant');
    }

    public function test_gelb_und_widerspruch_manuell(): void
    {
        $this->assertSame('manuell_pruefen', (new Gesamturteil($this->bericht(15, ['CO-01' => 'auffaellig'])))->empfehlung());
        $this->assertSame('manuell_pruefen', (new Gesamturteil($this->bericht(50, ['AT-QR-04' => 'widerspruch'])))->empfehlung());
    }

    public function test_angaben_abgleich(): void
    {
        $a = new AngabenAbgleich;
        $this->assertSame('ok', $a->pruefe(['betrag' => '46,00', 'datum' => '2025-06-13'], 4600, true, '2025-06-13 09:30')[0]->stufe->value);
        $this->assertSame('auffaellig', $a->pruefe(['betrag' => 64.0], 4600, true, null)[0]->stufe->value);
        $this->assertSame('hinweis', $a->pruefe(['betrag' => '64,00'], 4600, false, null)[0]->stufe->value);
        $this->assertSame('ok', $a->pruefe(['betrag' => '30,00'], 4600, true, null)[0]->stufe->value, 'weniger eingereicht ist in Ordnung');
        $this->assertSame('hinweis', $a->pruefe(['datum' => '2025-06-20'], null, true, '2025-06-13 09:30')[0]->stufe->value);
        $this->assertSame(109260, AngabenAbgleich::cent('1.092,60'));
    }
}
