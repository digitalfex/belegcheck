<?php

namespace Tests\Unit\Fiskal;

use App\Fiskal\BelegTextAuswertung;
use App\Fiskal\DsfinvK\DsfinvkParser;
use App\Fiskal\DsfinvK\DsfinvkPruefer;
use App\Fiskal\GedruckteWerte;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Risikobewertung;
use App\Pruefung\Stufe;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\TseTestBeleg;

class DsfinvkPrueferTest extends TestCase
{
    /** @return array<string, PruefErgebnis> */
    private function pruefe(string $qr, ?GedruckteWerte $g = null): array
    {
        $r = (new DsfinvkPruefer)->pruefe($qr, $g, CarbonImmutable::parse('2026-10-09 12:00', 'Europe/Berlin'));
        $map = [];
        foreach ($r['ergebnisse'] as $e) {
            $map[$e->code] = $e;
        }

        return $map;
    }

    private function gedruckt(int $gesamt = 7070, string $zeit = '2026-10-08 19:42', ?string $tse = null): GedruckteWerte
    {
        return new GedruckteWerte($gesamt, ['allgemein' => 1870, 'ermaessigt' => 5200], $zeit, 'KASSE-MUC-1', [], $tse ?? TseTestBeleg::tseSeriennummer());
    }

    public function test_parser(): void
    {
        $b = (new DsfinvkParser)->parse(TseTestBeleg::neu()->qr());

        $this->assertSame('Beleg', $b->vorgangstyp);
        $this->assertSame(['allgemein' => 1870, 'ermaessigt' => 5200, 'durchschnitt_10_7' => 0, 'durchschnitt_5_5' => 0, 'null' => 0], $b->bruttoCent);
        $this->assertSame(7070, $b->summeCent());
        $this->assertSame(7070, $b->zahlungenCent());
        $this->assertSame('2026-10-08T19:42:11', $b->endeLokal());
        $this->assertSame(TseTestBeleg::tseSeriennummer(), $b->tseSeriennummer());
    }

    public function test_gueltiger_beleg_ist_gruen(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->qr(), $this->gedruckt());
        foreach ($e as $code => $x) {
            $this->assertContains($x->stufe, [Stufe::Ok, Stufe::NichtPruefbar], "$code: {$x->begruendung}");
        }
        $this->assertSame('gruen', (new Risikobewertung(array_values($e)))->ampel());
    }

    public function test_falsches_format(): void
    {
        $this->assertSame(Stufe::Widerspruch, $this->pruefe('V0;nur;drei')['DE-QR-02']->stufe);
    }

    public function test_trainingsbuchung(): void
    {
        $this->assertSame(Stufe::Widerspruch, $this->pruefe(TseTestBeleg::neu()->mit('vorgangstyp', 'AVTraining')->qr())['DE-QR-04']->stufe);
    }

    public function test_bestellung_statt_beleg(): void
    {
        $this->assertSame(Stufe::Auffaellig, $this->pruefe(TseTestBeleg::neu()->mit('processType', 'Bestellung-V1')->qr())['DE-QR-04']->stufe);
    }

    public function test_zahlungen_passen_nicht_zum_umsatz(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->mit('zahlungen', '50.00:Bar')->qr());
        $this->assertSame(Stufe::Widerspruch, $e['DE-QR-05']->stufe);
    }

    public function test_gedruckter_betrag_weicht_ab(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->qr(), $this->gedruckt(gesamt: 17070));
        $this->assertSame(Stufe::Widerspruch, $e['DE-QR-05']->stufe);
        $this->assertStringContainsString('70,70 €', $e['DE-QR-05']->begruendung);
    }

    public function test_gedruckte_uhrzeit_ausserhalb_des_vorgangs(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->qr(), $this->gedruckt(zeit: '2026-10-08 21:15'));
        $this->assertSame(Stufe::Widerspruch, $e['DE-QR-07']->stufe);
    }

    public function test_gedruckter_vorgangsbeginn_ist_ok(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->qr(), $this->gedruckt(zeit: '2026-10-08 19:30'));
        $this->assertSame(Stufe::Ok, $e['DE-QR-07']->stufe);
    }

    public function test_ende_vor_start(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->mit('start', '2026-10-08T18:00:00.000Z')->qr());
        $this->assertSame(Stufe::Widerspruch, $e['DE-QR-07']->stufe);
    }

    public function test_tse_seriennummer_passt_nicht_zum_schluessel(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->qr(), $this->gedruckt(tse: str_repeat('ab', 32)));
        $this->assertSame(Stufe::Widerspruch, $e['DE-QR-08']->stufe);
    }

    public function test_kaputter_schluessel(): void
    {
        $e = $this->pruefe(TseTestBeleg::neu()->mit('publicKey', base64_encode('kein schluessel'))->qr());
        $this->assertSame(Stufe::Widerspruch, $e['DE-QR-09']->stufe);
    }

    public function test_texterkennung_deutscher_bon(): void
    {
        $text = "Brauhaus am Markt\nMarienplatz 1, 80331 München\nUSt-IdNr. DE123456789\nSumme EUR 70,70\nMwSt Brutto\nA 19% 18,70\nB 7% 52,00\n08.10.2026 19:42\nTSE-Seriennummer:\n".substr(TseTestBeleg::tseSeriennummer(), 0, 32)."\n".substr(TseTestBeleg::tseSeriennummer(), 32);
        $a = BelegTextAuswertung::ausText($text, 0.95);
        $g = $a->gedruckteWerte((new DsfinvkParser)->parse(TseTestBeleg::neu()->qr()));

        $this->assertSame('DE', $a->land());
        $this->assertSame(7070, $g->gesamtCent);
        $this->assertSame(['allgemein' => 1870, 'ermaessigt' => 5200], $g->betraegeJeSatzCent);
        $this->assertSame(TseTestBeleg::tseSeriennummer(), $g->tseSeriennummer);
    }

    public function test_tse_klartext_ohne_qr(): void
    {
        $text = "Bäckerei Huber\nDE123456789\nTSE-Seriennummer: 1234abcd\nTransaktionsnummer: 4711\nSignaturzähler: 9900\nStart: 08.10.2026 07:01\nEnde: 08.10.2026 07:02\nPrüfwert: MEUCIQ...";
        $k = BelegTextAuswertung::ausText($text)->tseKlartext();
        $this->assertSame([], array_keys(array_filter($k, fn ($v) => ! $v)));
    }
}
