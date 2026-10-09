<?php

namespace App\Http\Middleware;

use App\Api\Problem;
use App\Models\ApiSchluessel;
use Closure;
use Illuminate\Http\Request;

/** „Authorization: Bearer bc_…“ → Mandant an der Anfrage. */
class ApiSchluesselPruefen
{
    public function handle(Request $request, Closure $next)
    {
        $schluessel = ($token = $request->bearerToken()) ? ApiSchluessel::finden($token) : null;
        if (! $schluessel || ! $schluessel->mandant?->aktiv) {
            return Problem::antwort(401, 'Nicht angemeldet', 'Gültigen API-Schlüssel als „Authorization: Bearer …“ mitsenden.', 'nicht-angemeldet')
                ->header('WWW-Authenticate', 'Bearer realm="belegcheck"');
        }
        if (! $schluessel->zuletzt_genutzt_am || $schluessel->zuletzt_genutzt_am->lt(now()->subMinute())) {
            $schluessel->forceFill(['zuletzt_genutzt_am' => now()])->saveQuietly();
        }
        $request->attributes->set('mandant', $schluessel->mandant);

        return $next($request);
    }
}
