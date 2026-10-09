<?php

namespace App\Console\Commands;

use App\Models\ApiSchluessel;
use App\Models\Mandant;
use Illuminate\Console\Command;

class ApiSchluesselBefehl extends Command
{
    protected $signature = 'beleg:api-schluessel {kuerzel} {--name=Standard} {--widerrufen= : Präfix eines Schlüssels} {--liste}';

    protected $description = 'API-Schlüssel eines Mandanten erzeugen, auflisten oder widerrufen';

    public function handle(): int
    {
        $mandant = Mandant::where('kuerzel', $this->argument('kuerzel'))->first();
        if (! $mandant) {
            $this->error('Mandant nicht gefunden. Zuerst: php artisan beleg:mandant '.$this->argument('kuerzel'));

            return self::FAILURE;
        }

        if ($this->option('liste')) {
            $this->table(['Präfix', 'Name', 'zuletzt genutzt', 'widerrufen'], $mandant->schluessel()->get()
                ->map(fn ($s) => [$s->praefix, $s->name, $s->zuletzt_genutzt_am?->format('Y-m-d H:i') ?? '–', $s->widerrufen_am?->format('Y-m-d H:i') ?? '–']));

            return self::SUCCESS;
        }

        if ($praefix = $this->option('widerrufen')) {
            $anzahl = $mandant->schluessel()->where('praefix', $praefix)->whereNull('widerrufen_am')->update(['widerrufen_am' => now()]);
            $this->info($anzahl ? "Schlüssel $praefix widerrufen." : 'Kein aktiver Schlüssel mit diesem Präfix.');

            return $anzahl ? self::SUCCESS : self::FAILURE;
        }

        [, $klartext] = ApiSchluessel::erzeugen($mandant, $this->option('name'));
        $this->info('Neuer API-Schlüssel – wird nur jetzt angezeigt, bitte sicher aufbewahren:');
        $this->line($klartext);

        return self::SUCCESS;
    }
}
