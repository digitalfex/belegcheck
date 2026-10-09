<?php

namespace App\Console\Commands;

use App\Models\Mandant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MandantAnlegen extends Command
{
    protected $signature = 'beleg:mandant {kuerzel} {--name=} {--webhook= : URL für Ergebnis-Benachrichtigungen}
        {--freigabe-ab= : Score ab dem automatisch freigegeben wird (Standard 86)} {--compliance=* : z. B. gluecksspiel=pruefen}';

    protected $description = 'Mandanten für die Schnittstelle anlegen oder ändern';

    public function handle(): int
    {
        $mandant = Mandant::firstOrNew(['kuerzel' => $this->argument('kuerzel')]);
        $mandant->name = $this->option('name') ?? $mandant->name ?? $this->argument('kuerzel');
        $einstellungen = $mandant->einstellungen ?? [];

        if ($this->option('webhook') !== null) {
            $mandant->webhook_url = $this->option('webhook') ?: null;
            $mandant->webhook_geheimnis ??= 'whsec_'.Str::random(40);
        }
        if ($this->option('freigabe-ab') !== null) {
            $einstellungen['schwellen']['freigabe_ab'] = (int) $this->option('freigabe-ab');
        }
        foreach ($this->option('compliance') as $regel) {
            [$kategorie, $stufe] = array_pad(explode('=', $regel, 2), 2, null);
            if (! in_array($stufe, ['erlaubt', 'hinweis', 'pruefen'], true)) {
                $this->error("Ungültig: $regel (erlaubt | hinweis | pruefen)");

                return self::FAILURE;
            }
            $einstellungen['compliance'][$kategorie] = $stufe;
        }
        $mandant->einstellungen = $einstellungen;
        $mandant->save();

        $this->info("Mandant {$mandant->kuerzel} gespeichert (ID {$mandant->id}).");
        if ($mandant->webhook_url) {
            $this->line("Webhook: {$mandant->webhook_url}");
            $this->line("Webhook-Geheimnis (zum Prüfen der Signatur): {$mandant->webhook_geheimnis}");
        }

        return self::SUCCESS;
    }
}
