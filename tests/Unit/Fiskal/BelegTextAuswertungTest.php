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

    public function test_abweichung_in_steuertabelle(): void
    {
        // QR sagt 20 % = 28,70 – gedruckt 15,58 + 3,12 = 18,70: rechnerisch stimmig gelesen → sicher
        $g = BelegTextAuswertung::ausText(self::BON, 0.95)->gedruckteWerte($this->qr('28,70'));
        $this->assertSame(1870, $g->betraegeJeSatzCent['normal']);
        $this->assertTrue($g->sicher('betraege'));

        // nur eine Zahl je Satz, Rolle unklar → unsicher
        $text = "10% 52,00\n20% 18,70";
        $g = BelegTextAuswertung::ausText($text, 0.95)->gedruckteWerte($this->qr('28,70'));
        $this->assertSame(1870, $g->betraegeJeSatzCent['normal']);
        $this->assertFalse($g->sicher('betraege'));
    }

    // ---------- Fälle aus echten Belegen (Testlauf Oktober 2026) ----------

    public function test_steuertabelle_nur_steuerbetraege(): void
    {
        // „davon 10% USt. 5,68“ ist der Steuerbetrag, nicht Brutto – passt zu 62,50 brutto
        $text = "Barzahlung 75,50 EUR\ndavon 10% USt. 5,68\ndavon 20% USt. 2,17";
        $g = BelegTextAuswertung::ausText($text, 0.9)->gedruckteWerte($this->qr('13,00', '62,50'));
        $this->assertSame(['ermaessigt1' => 6250, 'normal' => 1300], $g->betraegeJeSatzCent);
        $this->assertTrue($g->sicher('betraege'));
        $this->assertSame(7550, $g->gesamtCent, 'Zahlungszeile bestätigt die QR-Summe');
    }

    public function test_steuertabelle_netto_und_steuer_in_getrennten_zeilen(): void
    {
        $text = "Umsatz 10% exkl. €28,82\nMwSt. 10% €2,88\nUmsatz 20% exkl. €8,67\nMwSt. 20% €1,73\nUmsatz 00% exkl. €3,90\nMwSt. 00% €0,00";
        $g = BelegTextAuswertung::ausText($text)->gedruckteWerte();
        $this->assertSame(['ermaessigt1' => 3170, 'normal' => 1040, 'null' => 390], $g->betraegeJeSatzCent);
    }

    public function test_steuertabelle_brutto_davon_steuer_und_netto_von(): void
    {
        $this->assertSame(['ermaessigt1' => 3500],
            BelegTextAuswertung::ausText('MwSt10% aus 35.00 3,18')->gedruckteWerte()->betraegeJeSatzCent);
        $this->assertSame(['normal' => 43674],
            BelegTextAuswertung::ausText('MWSt. 20,00 % von 363,95 72,79')->gedruckteWerte()->betraegeJeSatzCent);
    }

    public function test_unmoegliches_datum_ist_lesefehler(): void
    {
        $text = "Datum und Zeit: 13.06.2675 09:30:59\nBeleg 97.02.29 08:43";
        $this->assertNull(BelegTextAuswertung::ausText($text)->gedruckteWerte()->datumUhrzeit);

        // mit QR: eine Ziffer anders → gelesen, aber unsicher (Hinweis statt Widerspruch)
        $b = RksvTestBeleg::neu();
        $b->datumUhrzeit = '2025-06-13T09:30:59';
        $qr = (new RksvParser)->parse($b->qr());
        $g = BelegTextAuswertung::ausText($text, 0.95)->gedruckteWerte($qr);
        $this->assertSame('2675-06-13 09:30', $g->datumUhrzeit);
        $this->assertFalse($g->sicher('datum_uhrzeit'));
    }

    public function test_zahlungszeile_als_ersatz_fuer_unleserliche_summe(): void
    {
        $g = BelegTextAuswertung::ausText("Pizzeria\nSunne ii 6 | 16; 00\nBETRAG: EUR 12,60", 0.9)->gedruckteWerte();
        $this->assertSame(1260, $g->gesamtCent);
        $this->assertFalse($g->sicher('gesamt'));
    }

    public function test_endsumme_vor_teilsummen(): void
    {
        $text = "Summe Arbeiten 158,33\nSumme Teile 200,25\nSumme Sonstige 5,37\nExcl. MWSt. 363,95\nSumme Brutto 436,74";
        $this->assertSame(43674, BelegTextAuswertung::ausText($text)->gedruckteWerte()->gesamtCent);
        $this->assertSame(20025, BelegTextAuswertung::ausText("Summe Arbeiten 158,33\nSumme Teile 200,25")->gedruckteWerte()->gesamtCent);
    }

    public function test_waehrungszeichen_als_ziffer_ist_lesefehler(): void
    {
        // QR 70,70 – RapidOCR liest „€70,70“ als „670,70“ → nur unsicher (Hinweis statt Widerspruch)
        $g = BelegTextAuswertung::ausText("Total 670,70\n08.10.2026 19:42", 0.97)->gedruckteWerte($this->qr());
        $this->assertSame(67070, $g->gesamtCent);
        $this->assertFalse($g->sicher('gesamt'));

        // Gegenlesung von Tesseract hat „€70,70“ richtig → QR-Wert bestätigt
        $g = (new BelegTextAuswertung([['text' => 'Total 670,70', 'sicherheit' => 0.97]], [['text' => 'Total €70,70', 'sicherheit' => 0.8]]))
            ->gedruckteWerte($this->qr());
        $this->assertSame(7070, $g->gesamtCent);
    }

    public function test_steuersaetze_einzeln_aus_anderer_lesevariante(): void
    {
        // Hauptvariante liest 3↔8 falsch (23,82 statt 28,82), zweite Variante liest den 10-%-Satz richtig
        $primaer = array_map(fn ($t) => ['text' => $t, 'sicherheit' => 0.9],
            ['Umsatz 10% exkl. €23,82', 'MwSt. 10% €2,88', 'Umsatz 20% exkl. €8,67', 'MwSt. 20% €1,73']);
        $alternativ = array_map(fn ($t) => ['text' => $t, 'sicherheit' => 0.85],
            ['Umsatz 10% exkl. €28,82', 'MwSt. 10% €2,88', 'Umsatz 20% exkl. €3,67', 'MwSt. 20% €1,73']);

        $g = (new BelegTextAuswertung($primaer, $alternativ))->gedruckteWerte($this->qr('10,40', '31,70'));
        $this->assertSame(['ermaessigt1' => 3170, 'normal' => 1040], $g->betraegeJeSatzCent);
    }

    public function test_unstimmige_netto_steuer_zeile_entscheidet_der_steuerbetrag(): void
    {
        // Alle Lesevarianten lesen 23,82 statt 28,82; 23,82 × 10 % ≠ 2,88 → Steuerbetrag 2,88 passt zum QR-Brutto 31,70
        $text = "Umsatz 10% exkl. €23,82\nMwSt. 10% €2,88\nUmsatz 20% exkl. €3,67\nMwSt. 20% €1,73";
        $g = BelegTextAuswertung::ausText($text, 0.9)->gedruckteWerte($this->qr('10,40', '31,70'));
        $this->assertSame(['ermaessigt1' => 3170, 'normal' => 1040], $g->betraegeJeSatzCent);
        $this->assertTrue($g->sicher('betraege'));

        // ohne QR: Summe bleibt, aber unsicher
        $g = BelegTextAuswertung::ausText($text, 0.9)->gedruckteWerte();
        $this->assertFalse($g->sicher('betraege'));
    }

    public function test_kassenidentifikationsnummer(): void
    {
        $this->assertSame('1', BelegTextAuswertung::ausText('Kassenidentifik.nr. : 1')->gedruckteWerte()->kassenId);
        $this->assertSame('rk-01', BelegTextAuswertung::ausText('Kassenident-Nr rk-01')->gedruckteWerte()->kassenId);
    }

    public function test_aussteller_ueberspringt_vermerke_und_adressen(): void
    {
        $this->assertSame('Florianigarage', BelegTextAuswertung::ausText("DUPLIKAT\nFlorianigarage\n1080 WIEN")->aussteller());
        $this->assertSame('Taxicenter GabH', BelegTextAuswertung::ausText("wen KUNDENDEIEG\nTaxicenter GabH\n1100 Wien")->aussteller());
        $this->assertSame('APCOA PARKING Austria GnbH', BelegTextAuswertung::ausText("BANK KARTE QUITIUNG\nAPCOA PARKING Austria GnbH")->aussteller());
        $this->assertSame('Wiener tutte', BelegTextAuswertung::ausText("> } L\nWiener tutte\nRechnung RG2025/8388")->aussteller());
        $this->assertSame('Bu Le Burger', BelegTextAuswertung::ausText("Bu Le Burger _ N\nAuhof Center")->aussteller());
        $this->assertSame('Österreichische Post AG', BelegTextAuswertung::ausText("Österreichische Post AG\nUID-Nr: ATU46674503")->aussteller());
        $this->assertSame('Wiener Hütte', BelegTextAuswertung::ausText("Tisch: Terrasse 11\nWiener Hütte")->aussteller());
        $this->assertSame('AT', BelegTextAuswertung::ausText("Florianigarage\n1080 WIEN")->land());
        $this->assertSame('Messe Wien', BelegTextAuswertung::ausText("Hadikgasse 128-134\n= 1140 Wien\na Tel. 01/895 10 55\nMesse Wien")->aussteller());
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
