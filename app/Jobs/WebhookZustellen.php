<?php

namespace App\Jobs;

use App\Api\Darstellung;
use App\Models\Pruefauftrag;
use App\Models\Pruefstapel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Benachrichtigt das ERP, dass eine Prüfung oder ein Stapel fertig ist.
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

    /** @param  string  $art  pruefung | stapel */
    public function __construct(public readonly string $id, public readonly string $art = 'pruefung') {}

    public function handle(): void
    {
        $objekt = $this->art === 'stapel' ? Pruefstapel::with(['mandant', 'auftraege'])->find($this->id) : Pruefauftrag::with('mandant')->find($this->id);
        $mandant = $objekt?->mandant;
        if (! $objekt || ! $mandant?->webhook_url) {
            return;
        }

        [$typ, $daten] = $this->art === 'stapel'
            ? ['stapel.fertig', Darstellung::stapel($objekt)]
            : [$objekt->status === 'fertig' ? 'pruefung.fertig' : 'pruefung.fehlgeschlagen', Darstellung::pruefung($objekt)];
        $nutzlast = json_encode(['id' => 'evt_'.Str::ulid(), 'typ' => $typ, 'erstellt_am' => now()->toIso8601String(), 'daten' => $daten],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $zeit = time();
        $signatur = hash_hmac('sha256', $zeit.'.'.$nutzlast, (string) $mandant->webhook_geheimnis);

        $objekt->increment('webhook_versuche');
        $antwort = Http::timeout(15)->withHeaders([
            'Belegcheck-Signatur' => "t={$zeit},v1={$signatur}",
            'User-Agent' => 'Belegcheck-Webhook/1.0',
        ])->withBody($nutzlast, 'application/json')->post($mandant->webhook_url);

        if (! $antwort->successful()) {
            throw new \RuntimeException('Webhook antwortet mit HTTP '.$antwort->status());
        }
        $objekt->update(['webhook_status' => 'zugestellt']);
    }

    public function failed(\Throwable $fehler): void
    {
        ($this->art === 'stapel' ? Pruefstapel::query() : Pruefauftrag::query())->where('id', $this->id)->update(['webhook_status' => 'fehlgeschlagen']);
    }
}
