<?php

namespace App\Fiskal\DsfinvK;

use App\Fiskal\GedruckteWerte;
use App\Fiskal\Rksv\RksvPruefer;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;
use Carbon\CarbonImmutable;

/**
 * Schicht 1 Deutschland: Regeln DE-QR-02 bis DE-QR-10 (Spezifikation, Abschnitt „Schicht 1 Deutschland“).
 */
final class DsfinvkPruefer
{
    private const PROCESS_TYPES = ['Kassenbeleg-V1', 'Bestellung-V1', 'SonstigerVorgang'];

    /** Vorgangstypen, die keinen regulären Verkauf darstellen. */
    private const KEIN_VERKAUF = [
        'AVTraining' => 'Trainingsbuchung',
        'AVBelegstorno' => 'Belegstorno',
        'AVBelegabbruch' => 'Belegabbruch',
    ];

    private const SIG_ALGORITHMEN = [
        'ecdsa-plain-SHA224', 'ecdsa-plain-SHA256', 'ecdsa-plain-SHA384', 'ecdsa-plain-SHA512',
        'ecdsa-plain-SHA3-224', 'ecdsa-plain-SHA3-256', 'ecdsa-plain-SHA3-384', 'ecdsa-plain-SHA3-512',
        'SHA256withECDSA', 'SHA384withECDSA', 'SHA512withECDSA',
    ];

    private const ZEITFORMATE = ['unixTime', 'utcTime', 'utcTimeWithSeconds', 'generalizedTime', 'generalizedTimeWithMilliseconds'];

    public function __construct(private readonly DsfinvkParser $parser = new DsfinvkParser) {}

    /** @return array{beleg: ?DsfinvkBeleg, ergebnisse: list<PruefErgebnis>} */
    public function pruefe(string $qrText, ?GedruckteWerte $gedruckt = null, ?CarbonImmutable $eingang = null): array
    {
        try {
            $b = $this->parser->parse($qrText);
        } catch (DsfinvkParseFehler $e) {
            return ['beleg' => null, 'ergebnisse' => [new PruefErgebnis('DE-QR-02', Stufe::Widerspruch,
                'Der TSE-QR-Code entspricht nicht dem Format der DSFinV-K: '.$e->getMessage().' Bitte Original-Beleg anfordern.', ['qr' => $qrText])]];
        }

        $eingang ??= CarbonImmutable::now();
        $out = [
            $this->format($b),
            $this->processData($b),
            $this->vorgangstyp($b),
            $this->summen($b, $gedruckt),
            $this->betraegeJeSatz($b, $gedruckt),
            $this->zeiten($b, $gedruckt, $eingang),
            $this->tseSeriennummer($b, $gedruckt),
            $this->technik($b),
            new PruefErgebnis('DE-QR-10', Stufe::NichtPruefbar, 'Die kryptografische Signaturprüfung ist noch nicht eingebaut.'),
        ];

        return ['beleg' => $b, 'ergebnisse' => $out];
    }

    private function format(DsfinvkBeleg $b): PruefErgebnis
    {
        if ($b->version !== 'V0' || ! in_array($b->processType, self::PROCESS_TYPES, true)) {
            return new PruefErgebnis('DE-QR-02', Stufe::Widerspruch,
                sprintf('Version „%s“ oder Vorgangsart „%s“ ist in der DSFinV-K nicht vorgesehen.', $b->version, $b->processType));
        }

        return PruefErgebnis::ok('DE-QR-02');
    }

    private function processData(DsfinvkBeleg $b): PruefErgebnis
    {
        if ($b->processType !== 'Kassenbeleg-V1') {
            return new PruefErgebnis('DE-QR-03', Stufe::NichtPruefbar, 'Kein Kassenbeleg (Vorgangsart '.$b->processType.'), Beträge nicht im Code.');
        }
        if ($b->bruttoCent === null || $b->zahlungen === null) {
            return new PruefErgebnis('DE-QR-03', Stufe::Widerspruch,
                sprintf('Die Vorgangsdaten im TSE-QR-Code („%s“) lassen sich nicht in Beträge und Zahlungen zerlegen.', $b->processData));
        }

        return PruefErgebnis::ok('DE-QR-03');
    }

    private function vorgangstyp(DsfinvkBeleg $b): PruefErgebnis
    {
        if ($b->processType === 'Bestellung-V1') {
            return new PruefErgebnis('DE-QR-04', Stufe::Auffaellig,
                'Der TSE-QR-Code gehört zu einer Bestellung, nicht zu einem abgeschlossenen Kassenbeleg. Als Spesenbeleg ist der Kassenbeleg mit Zahlung nötig.');
        }
        if ($b->vorgangstyp !== null && isset(self::KEIN_VERKAUF[$b->vorgangstyp])) {
            return new PruefErgebnis('DE-QR-04', Stufe::Widerspruch,
                sprintf('Laut TSE-QR-Code ist das ein Vorgang der Art „%s“, kein regulärer Verkauf. Als Spesenbeleg nicht geeignet; Original-Rechnung anfordern.', self::KEIN_VERKAUF[$b->vorgangstyp]),
                ['vorgangstyp' => $b->vorgangstyp]);
        }

        return PruefErgebnis::ok('DE-QR-04');
    }

    private function summen(DsfinvkBeleg $b, ?GedruckteWerte $g): PruefErgebnis
    {
        $summe = $b->summeCent();
        if ($summe === null) {
            return new PruefErgebnis('DE-QR-05', Stufe::NichtPruefbar, 'Keine Beträge im TSE-QR-Code.');
        }
        if ($b->zahlungenCent() !== null && $b->zahlungen !== [] && $b->zahlungenCent() !== $summe
            && collect($b->zahlungen)->every(fn ($z) => $z['waehrung'] === null)) {
            return new PruefErgebnis('DE-QR-05', Stufe::Widerspruch,
                sprintf('Im TSE-QR-Code passen Umsatz (%s) und Zahlungen (%s) nicht zusammen.', RksvPruefer::eur($summe), RksvPruefer::eur($b->zahlungenCent())));
        }
        if ($g?->gesamtCent === null) {
            return new PruefErgebnis('DE-QR-05', Stufe::NichtPruefbar, 'Gesamtbetrag auf dem Beleg nicht gelesen.');
        }
        if ($g->gesamtCent !== $summe) {
            $text = sprintf('Der Betrag im TSE-QR-Code (%s) weicht vom gedruckten Gesamtbetrag (%s) ab.', RksvPruefer::eur($summe), RksvPruefer::eur($g->gesamtCent));

            return $g->sicher('gesamt')
                ? new PruefErgebnis('DE-QR-05', Stufe::Widerspruch, $text.' Original-Beleg anfordern oder Einreicher um Erklärung bitten.', ['qr' => $summe, 'gedruckt' => $g->gesamtCent])
                : new PruefErgebnis('DE-QR-05', Stufe::Auffaellig, $text.' Der gedruckte Wert ist eventuell falsch gelesen; bitte am Bild prüfen.', ['qr' => $summe, 'gedruckt' => $g->gesamtCent]);
        }

        return PruefErgebnis::ok('DE-QR-05');
    }

    private function betraegeJeSatz(DsfinvkBeleg $b, ?GedruckteWerte $g): PruefErgebnis
    {
        if ($b->bruttoCent === null || $g?->betraegeJeSatzCent === null) {
            return new PruefErgebnis('DE-QR-06', Stufe::NichtPruefbar, 'Beträge je Steuersatz auf dem Beleg nicht gelesen.');
        }
        $namen = ['allgemein' => '19 %', 'ermaessigt' => '7 %', 'durchschnitt_10_7' => '10,7 %', 'durchschnitt_5_5' => '5,5 %', 'null' => '0 %'];
        $diff = [];
        foreach ($b->bruttoCent as $feld => $qr) {
            $gd = $g->betraegeJeSatzCent[$feld] ?? 0;
            if ($qr !== $gd) {
                $diff[] = sprintf('%s: QR %s, gedruckt %s', $namen[$feld], RksvPruefer::eur($qr), RksvPruefer::eur($gd));
            }
        }
        if ($diff === []) {
            return PruefErgebnis::ok('DE-QR-06');
        }
        $text = 'Die Beträge je Steuersatz im TSE-QR-Code weichen vom Gedruckten ab ('.implode('; ', $diff).').';

        return $g->sicher('betraege')
            ? new PruefErgebnis('DE-QR-06', Stufe::Widerspruch, $text.' Original-Beleg anfordern.', ['abweichungen' => $diff])
            : new PruefErgebnis('DE-QR-06', Stufe::Hinweis, $text.' Steuertabelle eventuell falsch gelesen; bitte am Bild prüfen.', ['abweichungen' => $diff]);
    }

    private function zeiten(DsfinvkBeleg $b, ?GedruckteWerte $g, CarbonImmutable $eingang): PruefErgebnis
    {
        $start = $b->start();
        $ende = $b->ende();
        if (! $start || ! $ende) {
            return new PruefErgebnis('DE-QR-07', Stufe::Widerspruch, sprintf('Start- oder Endzeit im TSE-QR-Code ist kein gültiger Zeitpunkt („%s“, „%s“).', $b->startZeit, $b->logZeit));
        }
        if ($start->greaterThan($ende)) {
            return new PruefErgebnis('DE-QR-07', Stufe::Widerspruch, 'Laut TSE-QR-Code endet der Vorgang, bevor er beginnt. Eine TSE erzeugt solche Zeiten nicht.');
        }
        if ($ende->greaterThan($eingang->addMinutes(5))) {
            return new PruefErgebnis('DE-QR-07', Stufe::Widerspruch, 'Der Beleg ist laut TSE-QR-Code nach dem Eingang ausgestellt. Einreicher um Erklärung bitten.');
        }
        if ($g?->datumUhrzeit === null) {
            return PruefErgebnis::ok('DE-QR-07', 'Zeiten im QR-Code plausibel; gedruckte Uhrzeit nicht gelesen.');
        }

        $gedruckt = CarbonImmutable::parse($g->datumUhrzeit, 'Europe/Berlin');
        $lokal = $ende->setTimezone('Europe/Berlin');
        // Gedruckt wird Beginn oder Ende → Toleranz: zwischen Start − 2 min und Ende + 2 min
        if ($gedruckt->lt($start->subMinutes(2)) || $gedruckt->gt($ende->addMinutes(2))) {
            $text = sprintf('Die gedruckte Uhrzeit (%s) passt nicht zum Zeitraum im TSE-QR-Code (%s bis %s).',
                $gedruckt->format('d.m.Y H:i'), $start->setTimezone('Europe/Berlin')->format('d.m.Y H:i'), $lokal->format('H:i'));

            return $g->sicher('datum_uhrzeit')
                ? new PruefErgebnis('DE-QR-07', Stufe::Widerspruch, $text.' Original-Beleg anfordern.')
                : new PruefErgebnis('DE-QR-07', Stufe::Hinweis, $text.' Uhrzeit eventuell falsch gelesen; bitte am Bild prüfen.');
        }

        return PruefErgebnis::ok('DE-QR-07');
    }

    private function tseSeriennummer(DsfinvkBeleg $b, ?GedruckteWerte $g): PruefErgebnis
    {
        $aus = $b->tseSeriennummer();
        if ($aus === null) {
            return new PruefErgebnis('DE-QR-08', Stufe::Widerspruch, 'Der öffentliche Schlüssel im TSE-QR-Code ist nicht lesbar.');
        }
        if ($g?->tseSeriennummer === null) {
            return new PruefErgebnis('DE-QR-08', Stufe::NichtPruefbar, 'TSE-Seriennummer nicht gedruckt oder nicht gelesen.');
        }
        if (strtolower($g->tseSeriennummer) !== $aus) {
            return new PruefErgebnis('DE-QR-08', $g->sicher('tse') ? Stufe::Widerspruch : Stufe::Hinweis,
                'Die gedruckte TSE-Seriennummer passt nicht zum Schlüssel im QR-Code (die Seriennummer ist dessen SHA-256-Wert).',
                ['gedruckt' => $g->tseSeriennummer, 'aus_schluessel' => $aus]);
        }

        return PruefErgebnis::ok('DE-QR-08', 'Gedruckte TSE-Seriennummer passt zum Schlüssel im QR-Code.');
    }

    private function technik(DsfinvkBeleg $b): PruefErgebnis
    {
        $probleme = [];
        if (! in_array($b->signaturAlgorithmus, self::SIG_ALGORITHMEN, true)) {
            $probleme[] = 'unbekannter Signaturalgorithmus „'.$b->signaturAlgorithmus.'“';
        }
        if (! in_array($b->zeitformat, self::ZEITFORMATE, true)) {
            $probleme[] = 'unbekanntes Zeitformat „'.$b->zeitformat.'“';
        }
        $sig = base64_decode($b->signatur, true);
        if ($sig === false || strlen($sig) < 64) {
            $probleme[] = 'Signatur kein gültiger Wert';
        }
        $key = base64_decode($b->publicKey, true);
        if ($key === false || ! in_array(strlen($key), [65, 97, 133], true) || $key[0] !== "\x04") {
            $probleme[] = 'öffentlicher Schlüssel kein gültiger EC-Punkt';
        }
        if (! ctype_digit($b->transaktionsnummer) || ! ctype_digit($b->signaturzaehler)) {
            $probleme[] = 'Transaktionsnummer oder Signaturzähler keine Zahl';
        }

        return $probleme === []
            ? PruefErgebnis::ok('DE-QR-09')
            : new PruefErgebnis('DE-QR-09', Stufe::Widerspruch,
                'Der TSE-QR-Code ist technisch unvollständig: '.implode('; ', $probleme).'. Eine zertifizierte TSE erzeugt solche Werte nicht; Original-Beleg anfordern.',
                ['probleme' => $probleme]);
    }
}
