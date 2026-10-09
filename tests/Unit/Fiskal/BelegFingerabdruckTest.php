<?php

namespace Tests\Unit\Fiskal;

use App\Hashes\BelegFingerabdruck;
use PHPUnit\Framework\TestCase;

class BelegFingerabdruckTest extends TestCase
{
    private string $text = "GASTHAUS ZUR LINDE\nHauptplatz 3, 9570 Ossiach\nUID ATU12345678\n2x Wiener Schnitzel 37,80\n1x Kaesespaetzle 14,20\n3x Zipfer Maerzen 0,5 15,30\n1x Mineral 3,40\nSumme EUR 70,70\nBar 70,70\nBeleg 4711 Kasse KASSE-01\n08.10.2026 19:42";

    public function test_inhalts_hash_gleich_bei_gleicher_rechnung(): void
    {
        $a = BelegFingerabdruck::inhalt('ATU12345678', '2026-10-08', '19:42', 7070, '4711');
        $b = BelegFingerabdruck::inhalt('atu 12345678', '2026-10-08', '19:42:11', 7070, ' 4711 ');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
    }

    public function test_inhalts_hash_anders_bei_anderem_betrag(): void
    {
        $this->assertNotSame(
            BelegFingerabdruck::inhalt('ATU12345678', '2026-10-08', '19:42', 7070, '4711'),
            BelegFingerabdruck::inhalt('ATU12345678', '2026-10-08', '19:42', 7071, '4711'),
        );
    }

    public function test_inhalts_hash_braucht_kernfelder(): void
    {
        $this->assertNull(BelegFingerabdruck::inhalt(null, '2026-10-08', null, 7070, null));
    }

    public function test_text_hash_erkennt_zweites_foto_mit_leseabweichungen(): void
    {
        // Zweites Foto: andere Zeilenumbrüche, zwei Lesefehler (0→O, ä-Umlaut), fehlende Fußzeile
        $foto2 = 'GASTHAUS ZUR LINDE Hauptplatz 3, 9570 Ossiach UID ATU12345678 2x Wiener Schnitzel 37,8O 1x Kaesespaetzle 14,20 3x Zipfer Märzen 0,5 15,30 1x Mineral 3,40 Summe EUR 70,70 Bar 70,70 Beleg 4711 Kasse KASSE-01';

        $a = BelegFingerabdruck::text($this->text);
        $b = BelegFingerabdruck::text($foto2);

        $this->assertTrue(
            BelegFingerabdruck::aehnlich($a, $b),
            'Abstand '.BelegFingerabdruck::abstand($a, $b),
        );
    }

    public function test_text_hash_unterscheidet_andere_rechnung(): void
    {
        $andere = "PIZZERIA BELLA\nSeestrasse 1, 9500 Villach\nUID ATU87654321\n1x Pizza Margherita 11,90\n2x Cola 0,33 7,80\n1x Tiramisu 6,50\nSumme EUR 26,20\nKarte 26,20\nBeleg 88 Kasse P1\n02.10.2026 12:10";

        $this->assertFalse(BelegFingerabdruck::aehnlich(
            BelegFingerabdruck::text($this->text),
            BelegFingerabdruck::text($andere),
        ));
    }
}
