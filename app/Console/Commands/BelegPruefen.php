<?php

namespace App\Console\Commands;

use App\Belegleser\BelegleserClient;
use App\Fiskal\GedruckteWerte;
use App\Fiskal\Rksv\RksvParser;
use App\Fiskal\Rksv\RksvPruefer;
use App\Hashes\BelegFingerabdruck;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Risikobewertung;
use App\Pruefung\Stufe;
use Illuminate\Console\Command;

/**
 * Sprint 1: Prüfbefehl ohne Oberfläche.
 *
 *   php artisan beleg:pruefe foto.jpg
 *   php artisan beleg:pruefe --qr="_R1-AT1_..."
 *   php artisan beleg:pruefe foto.jpg --gedruckt=sollwerte.json --json
 */
class BelegPruefen extends Command
{
    protected $signature = 'beleg:pruefe
        {datei? : Foto oder PDF des Belegs}
        {--qr= : QR-Inhalt direkt angeben statt Bild}
        {--gedruckt= : JSON-Datei mit gedruckten Werten (gesamt_cent, betraege_je_satz_cent, datum_uhrzeit, kassen_id)}
        {--json : Ergebnis als JSON ausgeben}';

    protected $description = 'Prüft einen Beleg (Sprint 1: Kassen-QR-Code Österreich)';

    public function handle(): int
    {
        $datei = $this->argument('datei');
        $qr = $this->option('qr');

        if (! $datei && ! $qr) {
            $this->error('Bitte eine Datei oder --qr angeben.');

            return self::INVALID;
        }

        $gedruckt = null;
        if ($pfad = $this->option('gedruckt')) {
            $gedruckt = GedruckteWerte::fromArray(json_decode(file_get_contents($pfad), true, flags: JSON_THROW_ON_ERROR));
        }

        $bericht = ['datei' => $datei, 'datei_sha256' => $datei ? BelegFingerabdruck::datei($datei) : null];

        if (! $qr) {
            $codes = BelegleserClient::ausConfig()->qrCodes($datei);
            $bericht['gefundene_codes'] = $codes;
            $qr = collect($codes)->first(fn ($c) => RksvParser::istRksv($c['text']))['text'] ?? null;
        }

        if ($qr === null) {
            $ergebnisse = [new PruefErgebnis('AT-QR-01', Stufe::NichtPruefbar,
                'Kein österreichischer Kassen-QR-Code im Bild gefunden. (Sprint 1 prüft nur AT-QR-Codes; ob der Code fehlen darf, prüft ab Sprint 2 die Landeserkennung.)')];
            $beleg = null;
        } else {
            ['beleg' => $beleg, 'ergebnisse' => $ergebnisse] = (new RksvPruefer)->pruefe($qr, $gedruckt);
        }

        $bewertung = new Risikobewertung($ergebnisse);
        $bericht += [
            'qr' => $beleg?->toArray(),
            'risikowert' => $bewertung->risikowert(),
            'ampel' => $bewertung->ampel(),
            'ergebnisse' => array_map(fn (PruefErgebnis $e) => $e->toArray(), $ergebnisse),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($beleg) {
            $this->info('Kassen-QR-Code');
            $this->table(['Feld', 'Wert'], [
                ['Kennzeichen', $beleg->algorithmus],
                ['Kassen-ID', $beleg->kassenId],
                ['Belegnummer', $beleg->belegnummer],
                ['Datum/Uhrzeit', $beleg->datumUhrzeit],
                ['Summe', RksvPruefer::eur($beleg->summeCent())],
            ]);
        }

        $this->info('Prüfergebnisse');
        $this->table(['Code', 'Stufe', 'Begründung'], array_map(
            fn (PruefErgebnis $e) => [$e->code, $e->stufe->value, wordwrap($e->begruendung, 90)],
            $ergebnisse,
        ));

        $farbe = ['gruen' => 'info', 'gelb' => 'comment', 'rot' => 'error'][$bewertung->ampel()];
        $this->$farbe(sprintf('Risikowert %d → %s', $bewertung->risikowert(), strtoupper($bewertung->ampel())));

        return self::SUCCESS;
    }
}
