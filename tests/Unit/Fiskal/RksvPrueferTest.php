<?php

namespace Tests\Unit\Fiskal;

use App\Fiskal\GedruckteWerte;
use App\Fiskal\Rksv\RksvParser;
use App\Fiskal\Rksv\RksvPruefer;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Risikobewertung;
use App\Pruefung\Stufe;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\RksvTestBeleg;

class RksvPrueferTest extends TestCase
{
    private function eingang(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 12:00:00', new DateTimeZone('Europe/Vienna'));
    }

    /** @return array<string, PruefErgebnis> */
    private function pruefe(string $qr, ?GedruckteWerte $g = null): array
    {
        $r = (new RksvPruefer)->pruefe($qr, $g, $this->eingang());
        $map = [];
        foreach ($r['ergebnisse'] as $e) {
            $map[$e->code] = $e;
        }

        return $map;
    }

    private function passendGedruckt(): GedruckteWerte
    {
        return new GedruckteWerte(
            gesamtCent: 4420,
            betraegeJeSatzCent: ['normal' => 1240, 'ermaessigt1' => 3180],
            datumUhrzeit: '2026-10-08 19:42',
            kassenId: 'KASSE-01',
        );
    }

    public function test_gueltiger_beleg_ist_gruen(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->qr(), $this->passendGedruckt());

        foreach ($e as $code => $ergebnis) {
            $this->assertSame(Stufe::Ok, $ergebnis->stufe, "$code: {$ergebnis->begruendung}");
        }
        $this->assertSame('gruen', (new Risikobewertung(array_values($e)))->ampel());
    }

    public function test_parser_liest_alle_felder(): void
    {
        $b = (new RksvParser)->parse(RksvTestBeleg::neu()->qr());

        $this->assertSame('R1-AT1', $b->algorithmus);
        $this->assertSame('AT1', $b->vda());
        $this->assertSame('KASSE-01', $b->kassenId);
        $this->assertSame('4711', $b->belegnummer);
        $this->assertSame('2026-10-08T19:42:11', $b->datumUhrzeit);
        $this->assertSame(1240, $b->betraegeCent['normal']);
        $this->assertSame(3180, $b->betraegeCent['ermaessigt1']);
        $this->assertSame(4420, $b->summeCent());
    }

    public function test_falsche_feldanzahl_ist_widerspruch(): void
    {
        $e = $this->pruefe('_R1-AT1_KASSE-01_4711_2026-10-08T19:42:11_12,40');
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-02']->stufe);
    }

    public function test_kein_rksv_code(): void
    {
        $this->assertFalse(RksvParser::istRksv('https://example.com/beleg'));
        $e = $this->pruefe('https://example.com/beleg');
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-02']->stufe);
    }

    public function test_unbekanntes_algorithmuskennzeichen(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->mit('algorithmus', 'R9-XX1')->qr());
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-02']->stufe);
    }

    public function test_betrag_ohne_zwei_kommastellen(): void
    {
        $b = RksvTestBeleg::neu();
        $b->betraege['normal'] = '12,4';
        $e = $this->pruefe($b->qr());
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-02']->stufe);
    }

    public function test_ungueltiges_datum(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->mit('datumUhrzeit', '2026-02-30T12:00:00')->qr());
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-03']->stufe);
    }

    public function test_datum_nach_eingang(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->mit('datumUhrzeit', '2026-10-10T12:00:00')->qr());
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-03']->stufe);
    }

    public function test_ausfall_der_sicherheitseinrichtung_ist_hinweis(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->mit('ausfall', true)->qr());
        $this->assertSame(Stufe::Hinweis, $e['AT-QR-08']->stufe);
        $this->assertSame(Stufe::Ok, $e['AT-QR-11']->stufe, 'Ausfall ist keine Längenverletzung');
    }

    public function test_trainingsbeleg(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->training()->qr());
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-09']->stufe);
        $this->assertStringContainsString('Trainingsbeleg', $e['AT-QR-09']->begruendung);
        $this->assertSame(Stufe::Ok, $e['AT-QR-11']->stufe);
    }

    public function test_stornobeleg(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->storno()->qr());
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-09']->stufe);
    }

    public function test_startbeleg(): void
    {
        $e = $this->pruefe(RksvTestBeleg::neu()->startbeleg()->qr());
        $this->assertSame(Stufe::Auffaellig, $e['AT-QR-10']->stufe);
    }

    public function test_zu_kurze_signatur(): void
    {
        $qr = RksvTestBeleg::neu()->qr();
        $teile = explode('_', $qr);
        $teile[13] = base64_encode(random_bytes(20));
        $e = $this->pruefe(implode('_', $teile));
        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-11']->stufe);
    }

    public function test_betrag_qr_weicht_vom_gedruckten_ab(): void
    {
        $g = new GedruckteWerte(gesamtCent: 8420, datumUhrzeit: '2026-10-08 19:42', kassenId: 'KASSE-01');
        $e = $this->pruefe(RksvTestBeleg::neu()->qr(), $g);

        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-04']->stufe);
        $this->assertStringContainsString('44,20 €', $e['AT-QR-04']->begruendung);
        $this->assertStringContainsString('84,20 €', $e['AT-QR-04']->begruendung);
        $this->assertSame('rot', (new Risikobewertung(array_values($e)))->ampel());
    }

    public function test_unsicher_gelesener_betrag_ist_nur_hinweis(): void
    {
        $g = new GedruckteWerte(gesamtCent: 8420, lesesicherheit: ['gesamt' => 0.5]);
        $e = $this->pruefe(RksvTestBeleg::neu()->qr(), $g);

        $this->assertSame(Stufe::Auffaellig, $e['AT-QR-04']->stufe);
        $this->assertStringContainsString('am Bild prüfen', $e['AT-QR-04']->begruendung);
        $this->assertSame('gelb', (new Risikobewertung(array_values($e)))->ampel());
    }

    public function test_beleg_mit_kopiertem_qr_anderer_zeitpunkt(): void
    {
        $g = $this->passendGedruckt();
        $g = new GedruckteWerte($g->gesamtCent, $g->betraegeJeSatzCent, '2026-10-07 13:05', $g->kassenId);
        $e = $this->pruefe(RksvTestBeleg::neu()->qr(), $g);

        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-06']->stufe);
    }

    public function test_kassen_id_weicht_ab(): void
    {
        $g = $this->passendGedruckt();
        $g = new GedruckteWerte($g->gesamtCent, $g->betraegeJeSatzCent, $g->datumUhrzeit, 'KASSE-99');
        $e = $this->pruefe(RksvTestBeleg::neu()->qr(), $g);

        $this->assertSame(Stufe::Widerspruch, $e['AT-QR-07']->stufe);
    }

    public function test_begruendungen_enthalten_keine_vorwuerfe(): void
    {
        $varianten = [
            RksvTestBeleg::neu()->training()->qr(),
            RksvTestBeleg::neu()->startbeleg()->qr(),
            RksvTestBeleg::neu()->mit('ausfall', true)->qr(),
            '_R1-AT1_kaputt',
        ];

        foreach ($varianten as $qr) {
            foreach ($this->pruefe($qr, new GedruckteWerte(gesamtCent: 1)) as $e) {
                $this->assertDoesNotMatchRegularExpression('/fälsch|betrug|manipul|verdächtig/iu', $e->begruendung);
            }
        }
    }
}
