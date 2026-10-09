<?php

namespace App\Console\Commands;

use App\Models\Kasse;
use Illuminate\Console\Command;

class GedaechtnisLeeren extends Command
{
    protected $signature = 'beleg:gedaechtnis-leeren {--force : ohne Rückfrage}';

    protected $description = 'Löscht das Kassen-Gedächtnis (für Testläufe)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Kassen-Gedächtnis wirklich löschen?')) {
            return self::SUCCESS;
        }
        $anzahl = Kasse::count();
        Kasse::query()->delete();
        $this->info("$anzahl Kassen gelöscht.");

        return self::SUCCESS;
    }
}
