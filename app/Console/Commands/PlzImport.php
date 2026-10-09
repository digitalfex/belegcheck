<?php

namespace App\Console\Commands;

use App\Models\PlzOrt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Lädt das PLZ-Verzeichnis von GeoNames (CC BY 4.0, https://www.geonames.org) für AT und DE.
 * Einmalig am Server ausführen; danach läuft der Regionscheck ohne Internet.
 */
class PlzImport extends Command
{
    protected $signature = 'beleg:plz-import {--land=AT,DE : Länder} {--datei= : lokale GeoNames-Textdatei statt Download}';

    protected $description = 'Importiert PLZ mit Koordinaten (GeoNames) für den Regionscheck';

    public function handle(): int
    {
        if ($datei = $this->option('datei')) {
            $this->importiere(file_get_contents($datei));

            return self::SUCCESS;
        }

        foreach (explode(',', $this->option('land')) as $land) {
            $land = strtoupper(trim($land));
            $this->info("Lade $land …");
            $antwort = Http::timeout(120)->get("https://download.geonames.org/export/zip/$land.zip");
            if ($antwort->failed()) {
                $this->error("Download fehlgeschlagen: HTTP {$antwort->status()}");

                return self::FAILURE;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'plz');
            file_put_contents($tmp, $antwort->body());
            $zip = new ZipArchive;
            $zip->open($tmp);
            $inhalt = $zip->getFromName("$land.txt");
            $zip->close();
            unlink($tmp);

            $this->importiere($inhalt);
        }

        return self::SUCCESS;
    }

    /** GeoNames-Format (Tab): Land, PLZ, Ort, Bundesland, …, Breite (Spalte 10), Länge (Spalte 11) */
    private function importiere(string $inhalt): void
    {
        $zeilen = [];
        foreach (explode("\n", trim($inhalt)) as $zeile) {
            $f = explode("\t", $zeile);
            if (count($f) < 11) {
                continue;
            }
            $zeilen[] = ['land' => $f[0], 'plz' => $f[1], 'ort' => $f[2], 'lat' => (float) $f[9], 'lon' => (float) $f[10]];
        }

        $laender = array_unique(array_column($zeilen, 'land'));
        DB::transaction(function () use ($zeilen, $laender) {
            PlzOrt::whereIn('land', $laender)->delete();
            foreach (array_chunk($zeilen, 500) as $teil) {
                PlzOrt::insert($teil);
            }
        });

        $this->info(count($zeilen).' Einträge importiert ('.implode(', ', $laender).'). Quelle: GeoNames, CC BY 4.0.');
    }
}
