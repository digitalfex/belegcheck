<?php

namespace Tests\Unit\Screening;

use App\Screening\Oeffnungszeiten;
use PHPUnit\Framework\TestCase;

class OeffnungszeitenTest extends TestCase
{
    private function aussen(string $zeiten, string $zeitpunkt): ?int
    {
        return (new Oeffnungszeiten($zeiten))->minutenAusserhalb(new \DateTimeImmutable($zeitpunkt));
    }

    public function test_einfache_zeiten(): void
    {
        $z = 'Mo-Fr 08:00-22:00; Sa,Su 09:00-15:00';
        $this->assertSame(0, $this->aussen($z, '2025-06-13 09:30')); // Freitag
        $this->assertSame(30, $this->aussen($z, '2025-06-13 22:30'));
        $this->assertSame(0, $this->aussen($z, '2025-06-14 14:00')); // Samstag
        $this->assertSame(60, $this->aussen($z, '2025-06-14 16:00'));
    }

    public function test_ueber_mitternacht_und_ruhetag(): void
    {
        $z = 'Tu-Sa 18:00-02:00; Mo off';
        $this->assertSame(0, $this->aussen($z, '2025-06-14 01:30')); // Samstag früh = Freitagabend
        $this->assertSame(1440, $this->aussen($z, '2025-06-16 20:00')); // Montag Ruhetag
        $this->assertSame(0, $this->aussen('24/7', '2025-06-16 03:00'));
    }

    public function test_spaetere_regel_ueberschreibt_und_mehrere_spannen(): void
    {
        $z = 'Mo-Su 11:00-14:30,17:30-23:00; Su off';
        $this->assertSame(0, $this->aussen($z, '2025-06-13 12:00'));
        $this->assertSame(90, $this->aussen($z, '2025-06-13 16:00'));
        $this->assertSame(1440, $this->aussen($z, '2025-06-15 12:00'));
    }

    public function test_unbekanntes_format_ist_nicht_lesbar(): void
    {
        $this->assertNull($this->aussen('Mo-Fr sunrise-sunset', '2025-06-13 12:00'));
        $this->assertNull($this->aussen('Jan-Mar Mo-Fr 08:00-12:00', '2025-06-13 12:00'));
        $this->assertSame(0, $this->aussen('Mo-Fr 08:00-18:00; PH off', '2025-06-13 12:00'));
    }
}
