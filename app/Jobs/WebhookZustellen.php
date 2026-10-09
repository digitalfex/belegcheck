<?php

namespace App\Jobs;

use App\Api\Darstellung;
use App\Models\Pruefauftrag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Benachrichtigt das ERP, dass eine Prüfung fertig (oder fehlgeschlagen) ist.
 *
 * Signatur (wie bei Stripe): Kopfzeile „Belegcheck-Signatur: t=<Unix-Zeit>,v1=<HMAC-SHA256>“ über „<t>.<Rohtext>“
 * mit dem Webhook-Geheimnis des Mandanten. Empfänger prüft Signatur und Alter (z. B. höchstens 5 Minuten).
 * Wiederholung bei Fehlern: nach 1, 5, 15, 60 Minuten und 6 Stunden.
 */
class WebhookZustellen implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [60, 300, 900, 3600, 21600];

    public function __construct(public readonly string $auftragId) {}

    public function handle(): void
    {
        $auftrag = Pruefauftrag::with('mandant')->find($this->auftragId);
        $mandant = $auftrag?->mandant;
        if (! $auftrag || ! $mandant?->webhook_url) {
            return;
        }

        $nutzlast = json_encode([
            'id' => 'evt_'.Str::ulid(),
            'typ' => $auftrag->status === 'fertig' ? 'pruefung.fertig' : 'pruefung.fehlgeschlagen',
            'erstellt_am' => now()->toIso8601String(),
            'daten' => Darstellung::pruefung($auftrag),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $zeit = time();
        $signatur = hash_hmac('sha256', $zeit.'.'.$nutzlast, (string) $mandant->webhook_geheimnis);

        $auftrag->increment('webhook_versuche');
        $antwort = Http::timeout(10)->withHeaders([
            'Content-Type' => 'application/json',
            'Belegcheck-Signatur' => "t={$zeit},v1={$signatur}",
            'User-Agent' => 'Belegcheck-Webhook/1.0',
        ])->withBody($nutzlast, 'application/json')->post($mandant->webhook_url);

        if (! $antwort->successful()) {
            throw new \RuntimeException('Webhook antwortet mit HTTP '.$antwort->status());
        }
        $auftrag->update(['webhook_status' => 'zugestellt']);
    }

    public function failed(\Throwable $fehler): void
    {
        Pruefauftrag::where('id', $this->auftragId)->update(['webhook_status' => 'fehlgeschlagen']);
    }
}
