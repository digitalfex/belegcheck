<?php

namespace App\Jobs;

use App\Api\Eingang;
use App\Api\PruefauftragDienst;
use App\Models\Pruefauftrag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruefauftragAusfuehren implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $auftragId) {}

    public function handle(PruefauftragDienst $dienst): void
    {
        $auftrag = Pruefauftrag::with('mandant')->find($this->auftragId);
        if (! $auftrag || $auftrag->status === 'fertig' || ! $auftrag->ablage_pfad) {
            return;
        }
        $datei = Eingang::auspacken($auftrag->ablage_pfad, (string) $auftrag->dateiname);
        try {
            $dienst->ausfuehren($auftrag, $datei);
            Eingang::loeschen($auftrag->ablage_pfad);
            $auftrag->update(['ablage_pfad' => null]);
        } finally {
            @unlink($datei);
        }
    }

    public function failed(\Throwable $fehler): void
    {
        $auftrag = Pruefauftrag::with('mandant')->find($this->auftragId);
        if (! $auftrag) {
            return;
        }
        Eingang::loeschen($auftrag->ablage_pfad);
        $auftrag->update(['status' => 'fehler', 'fehler' => 'Prüfung fehlgeschlagen (Belegleser nicht erreichbar oder Datei nicht lesbar).', 'ablage_pfad' => null,
            'webhook_status' => $auftrag->mandant?->webhook_url ? 'offen' : null]);
        if ($auftrag->mandant?->webhook_url) {
            WebhookZustellen::dispatch($auftrag->id);
        }
    }
}
