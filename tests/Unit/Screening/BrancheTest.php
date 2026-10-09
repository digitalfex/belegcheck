<?php

namespace Tests\Unit\Screening;

use App\Screening\Branche;
use PHPUnit\Framework\TestCase;

class BrancheTest extends TestCase
{
    public function test_branche_aus_osm_vor_text(): void
    {
        $b = Branche::aussteller(['amenity' => 'restaurant'], 'Waldemar', 'Diesel 40 Liter');
        $this->assertSame(['gastronomie', 'osm'], [$b['branche'], $b['branche_quelle']]);
        $b = Branche::aussteller([], 'BP', "Super 95\nLiter 22,5\nSäule 3");
        $this->assertSame(['tankstelle', 'text'], [$b['branche'], $b['branche_quelle']]);
    }

    public function test_compliance_aus_osm_und_name(): void
    {
        $this->assertSame(['gluecksspiel' => 'osm'], Branche::aussteller(['shop' => 'bookmaker'], 'Ecke', '')['kategorien']);
        $this->assertSame(['gluecksspiel' => 'name'], Branche::aussteller([], 'Wettbüro Stadionblick', '')['kategorien']);
        $this->assertSame(['erwachsenenunterhaltung' => 'name'], Branche::aussteller([], 'Laufhaus Mitte', '')['kategorien']);
        $this->assertSame([], Branche::aussteller(['amenity' => 'nightclub'], 'Flex', '')['kategorien'], 'Nachtclub allein ist keine Erwachsenenunterhaltung');
    }

    public function test_positionen(): void
    {
        $zeilen = ['Billa', '1010 Wien', 'ATU12345678', 'Milch 1,49', 'Geschenkkarte 50,00', 'Marlboro Zigaretten 7,20'];
        $this->assertSame(['bargeldaehnlich', 'tabak'], array_keys(Branche::positionen($zeilen)));
        $this->assertSame([], Branche::positionen(['Trafik Meier', '1010 Wien', 'x', 'Zeitung 2,20']), 'Kopfzeilen zählen nicht als Position');
    }
}
