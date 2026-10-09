<?php

namespace Tests\Unit\Fiskal;

use App\Fiskal\BelegTextAuswertung;
use App\Fiskal\Rksv\RksvParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\RksvTestBeleg;

class BelegTextAuswertungTest extends TestCase
{
    // Text, wie ihn die Texterkennung aus einem Thermobon liefert
    private const BON = "GASTHAUS ZUR LINDE\nHauptplatz 3, 9570 Ossiach\nUID: ATU12345678\n2 x Wiener Schnitzel 37,80\n1 x Kaesespaetzle 14,20\n3 x Zipfer Maerzen 0,5 15,30\n1 x Mineral 3,40\nSUMME EUR 70,70\nBar 70,70\nMwSt Netto Steuer Brutto\n10% 47,27 4,73 52,00\n20% 15,58 3,12 18,70\nKassen-ID: KASSE-01\nBeleg-Nr.: 4711\n08.10.2026 19:42:11";

    private function qr(string $normal = '18,70', string $erm1 = '52,00')
    {
        $b = RksvTestBeleg::neu();
        $b->betraege['normal'] = $normal;
        $b->betraege['ermaessigt1'] = $erm1;

        return (new RksvParser)->parse($b->qr());
    }

    public function test_betraege_einer_zeile(): void
    {
        $this->assertSame([3780], BelegTextAuswertung::betraege('2 x Wiener Schnitzel 37,80'));
        $this->assertSame([150, 1530], BelegTextAuswertung::betraege('3 x Zipfer 1,50 15,30'));
        $this->assertSame([109260], BelegTextAuswertung::betraege('Summe 1.092,60'));
        $this->assertSame([9260], BelegTextAuswertung::betraege('TOTAL EUR 92.60'));
    }

    public function test_ohne_qr_liest_werte_aus_dem_text(): void
    {
        $g = BelegTextAuswertung::ausText(self::BON, 0.93)->gedruckteWerte();

        $this->assertSame(7070, $g->gesamtCent);
        $this->assertSame('2026-10-08 19:42', $g->datumUhrzeit);
        $this->assertSame('KASSE-01', $g->kassenId);
        $this->assertSame(['ermaessigt1' => 5200, 'normal' => 1870], $g->betraegeJeSatzCent);
    }

    public function test_qr_gefuehrt_bestaetigt_werte(): void
    {
        $g = BelegTextAuswertung::ausText(self::BON, 0.93)->gedruckteWerte($this->qr());

        $this->assertSame(7070, $g->gesamtCent);
        $this->assertTrue($g->sicher('gesamt'));
        $this->assertTrue($g->sicher('betraege'));
    }

    public function test_abweichung_in_steuertabelle_ist_unsicher(): void
    {
        // QR sagt 20 % = 28,70 – gedruckt 18,70: wird gelesen, aber nur als unsicher gewertet
        $g = BelegTextAuswertung::ausText(self::BON, 0.95)->gedruckteWerte($this->qr('28,70'));

        $this->assertSame(1870, $g->betraegeJeSatzCent['normal']);
        $this->assertFalse($g->sicher('betraege'));
    }

    public function test_summenzeile_ohne_netto_zeilen(): void
    {
        $text = "Pizzeria\nZwischensumme netto 22,00\nGesamt EUR 26,40\n";
        $this->assertSame(2640, BelegTextAuswertung::ausText($text)->gedruckteWerte()->gesamtCent);
    }

    public function test_datum_und_uhrzeit_in_zwei_zeilen(): void
    {
        $text = "Datum: 05.10.26\nZeit: 22:37\nSumme 92,60";
        $this->assertSame('2026-10-05 22:37', BelegTextAuswertung::ausText($text)->gedruckteWerte()->datumUhrzeit);
    }

    public function test_uid_und_aussteller(): void
    {
        $a = BelegTextAuswertung::ausText(self::BON);
        $this->assertSame('ATU12345678', $a->uid());
        $this->assertSame('GASTHAUS ZUR LINDE', $a->aussteller());
    }

    public function test_kassen_id_nur_im_qr_ist_nicht_pruefbar(): void
    {
        $text = "Restaurant\nSumme 44,20\n08.10.2026 19:42";
        $this->assertNull(BelegTextAuswertung::ausText($text)->gedruckteWerte($this->qr())->kassenId);
    }

    public function test_betrag_in_nachbarzeile_bei_schiefem_foto(): void
    {
        $text = "GASTHAUS ZUR LINDE\n70,70\nSUMME EUR\n70,70\nBar";
        $this->assertSame(7070, BelegTextAuswertung::ausText($text)->gedruckteWerte()->gesamtCent);
    }

    public function test_eine_ziffer_anders_ist_wahrscheinlich_lesefehler(): void
    {
        // QR 70,70 – gelesen 79,70: nur Hinweis, kein Widerspruch
        $text = "SUMME EUR 79,70\n08.10.2026 19:42";
        $g = BelegTextAuswertung::ausText($text, 0.95)->gedruckteWerte($this->qr());
        $this->assertSame(7970, $g->gesamtCent);
        $this->assertFalse($g->sicher('gesamt'));

        // deutlich anderer Betrag bleibt sicher gelesen → Widerspruch möglich
        $g = BelegTextAuswertung::ausText('SUMME EUR 120,70', 0.95)->gedruckteWerte($this->qr());
        $this->assertTrue($g->sicher('gesamt'));
    }

    public function test_italienischer_beleg(): void
    {
        $text = "TRATTORIA DA MARIO\nP.IVA 01234567890\nTOTALE EUR 12,00\n07/10/2026 13:05";
        $g = BelegTextAuswertung::ausText($text)->gedruckteWerte();
        $this->assertSame(1200, $g->gesamtCent);
        $this->assertSame('2026-10-07 13:05', $g->datumUhrzeit);
    }

    public function test_typische_ziffern_lesefehler(): void
    {
        $this->assertSame([7070], BelegTextAuswertung::betraege('SUMME EUR 7O,70'));
        $this->assertSame([7070], BelegTextAuswertung::betraege('Summe 70, 70'));
        $this->assertSame([11350], BelegTextAuswertung::betraege('Bar 1l3,5O'));
        $this->assertSame('SUMME EUR', BelegTextAuswertung::zahlenGlaetten('SUMME EUR'));
    }

    public function test_andere_lesevariante_bestaetigt_qr_wert(): void
    {
        // Hauptvariante liest 79,70 (Lesefehler), zweite Bildaufbereitung liest richtig 70,70
        $primaer = [['text' => 'SUMME EUR 79,70', 'sicherheit' => 0.95], ['text' => '08.10.2026 19:42', 'sicherheit' => 0.95]];
        $alternativ = [['text' => 'SUMME EUR 70,70', 'sicherheit' => 0.88]];

        $g = (new BelegTextAuswertung($primaer, $alternativ))->gedruckteWerte($this->qr());
        $this->assertSame(7070, $g->gesamtCent);

        // Ohne bestätigende Variante bleibt die Abweichung stehen
        $g = (new BelegTextAuswertung($primaer, [['text' => 'SUMME EUR 79,70', 'sicherheit' => 0.9]]))->gedruckteWerte($this->qr());
        $this->assertSame(7970, $g->gesamtCent);
    }
}
