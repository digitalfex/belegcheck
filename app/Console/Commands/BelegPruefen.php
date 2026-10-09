<?php

namespace App\Console\Commands;

use App\Fiskal\GedruckteWerte;
use App\Fiskal\Rksv\RksvPruefer;
use App\Pruefung\BelegPruefService;
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

        $service = new BelegPruefService;
        $bericht = ['datei' => $datei] + ($qr ? $service->pruefeQr($qr, $gedruckt) : $service->pruefeDatei($datei, $gedruckt));
        $ergebnisse = $bericht['ergebnisse'];
        $beleg = $bericht['qr'];

        if ($this->option('json')) {
            $this->line(json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($beleg) {
            $this->info('Kassen-QR-Code');
            $this->table(['Feld', 'Wert'], [
                ['Kennzeichen', $beleg['algorithmus']],
                ['Kassen-ID', $beleg['kassen_id']],
                ['Belegnummer', $beleg['belegnummer']],
                ['Datum/Uhrzeit', $beleg['datum_uhrzeit']],
                ['Summe', RksvPruefer::eur($beleg['summe_cent'])],
            ]);
        }

        $this->info('Prüfergebnisse');
        $this->table(['Code', 'Prüfung', 'Stufe', 'Begründung'], array_map(
            fn (array $e) => [$e['code'], wordwrap($e['titel'], 40), $e['stufe'], wordwrap($e['begruendung'], 70)],
            $ergebnisse,
        ));

        $farbe = ['gruen' => 'info', 'gelb' => 'comment', 'rot' => 'error'][$bericht['ampel']];
        $this->$farbe(sprintf('Risikowert %d → %s', $bericht['risikowert'], strtoupper($bericht['ampel'])));

        return self::SUCCESS;
    }
}
