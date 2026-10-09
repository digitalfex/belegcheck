<?php

namespace Tests\Unit\Fiskal;

use App\Fiskal\KiLesung;
use PHPUnit\Framework\TestCase;

class KiLesungTest extends TestCase
{
    private const A = "WALDEMAR\nTotal €46,00\nUmsatz 10% exkl. €23,82\nMwSt. 10% €2,88\nDatum und Zeit: 19.06.2025 09:30:59";

    private const B = "WALDEMAR\nTotal 46,00\nUmsat210% exkl 28,82\nMwSt.10% 62,88\nDatum und Zeit: 13.06.2025 09:30:59";

    private function ki(array $antwort): KiLesung
    {
        return new KiLesung($antwort + ['steuersaetze' => []], self::A, self::B, 'AT');
    }

    public function test_betrag_nur_wenn_er_im_text_steht(): void
    {
        $this->assertSame(4600, $this->ki(['gesamtbetrag' => '46,00'])->gesamtCent());
        $this->assertNull($this->ki(['gesamtbetrag' => '55,00'])->gesamtCent(), 'erfundener Betrag');
        $this->assertNull($this->ki(['gesamtbetrag' => '6,00'])->gesamtCent(), 'Teilstring von 46,00 zählt nicht');
        $this->assertNull($this->ki(['gesamtbetrag' => 'sechsundvierzig'])->gesamtCent());
    }

    public function test_tausender_und_punkt(): void
    {
        $ki = new KiLesung(['gesamtbetrag' => '1.092,60'], 'Summe 1.092,60', '');
        $this->assertSame(109260, $ki->gesamtCent());
        $ki = new KiLesung(['gesamtbetrag' => '14,99'], 'Betrag 14.99 EUR', '');
        $this->assertSame(1499, $ki->gesamtCent());
    }

    public function test_datum_muss_im_text_stehen_und_gueltig_sein(): void
    {
        $this->assertSame('2025-06-13 09:30', $this->ki(['datum' => '2025-06-13', 'uhrzeit' => '09:30'])->datumUhrzeit());
        $this->assertNull($this->ki(['datum' => '2025-06-14', 'uhrzeit' => '09:30'])->datumUhrzeit(), 'Tag steht nicht im Text');
        $this->assertNull($this->ki(['datum' => '2025-06-13', 'uhrzeit' => '11:30'])->datumUhrzeit(), 'Uhrzeit steht nicht im Text');
        $this->assertNull($this->ki(['datum' => '2025-02-30', 'uhrzeit' => '09:30'])->datumUhrzeit());
    }

    public function test_steuersaetze_brutto_aus_netto_und_steuer_nur_wenn_stimmig(): void
    {
        $ki = $this->ki(['steuersaetze' => [['satz' => '10%', 'brutto' => null, 'netto' => '28,82', 'steuer' => '2,88']]]);
        $this->assertSame(['ermaessigt1' => 3170], $ki->betraegeJeSatz());

        // 23,82 + 2,88 ist rechnerisch unstimmig → kein Wert
        $ki = $this->ki(['steuersaetze' => [['satz' => '10', 'brutto' => null, 'netto' => '23,82', 'steuer' => '2,88']]]);
        $this->assertNull($ki->betraegeJeSatz());

        $ki = new KiLesung(['steuersaetze' => [['satz' => '7,00', 'brutto' => '10,70', 'netto' => null, 'steuer' => null]]], '7% 10,00 0,70 10,70', '', 'DE');
        $this->assertSame(['ermaessigt' => 1070], $ki->betraegeJeSatz());
    }

    public function test_aussteller_und_belegnummer(): void
    {
        $ki = new KiLesung(['aussteller' => 'Wiener Hütte', 'belegnummer' => 'RG2025/8388'], "Wiener tutte\nRechnung RG2025/8388", '');
        $this->assertSame('Wiener Hütte', $ki->aussteller());
        $this->assertSame('RG2025/8388', $ki->belegnummer());
        $this->assertNull((new KiLesung(['belegnummer' => 'X999'], 'Rechnung 4711', ''))->belegnummer());
    }
}
