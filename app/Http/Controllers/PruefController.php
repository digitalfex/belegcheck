<?php

namespace App\Http\Controllers;

use App\Fiskal\GedruckteWerte;
use App\Forensik\ForensikPruefer;
use App\Pruefung\BelegPruefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Prüf-Werkbank (Prototyp): Belege hochladen, sofort prüfen, nichts speichern.
 * Die hochgeladene Datei liegt nur für die Dauer des Requests im Temp-Verzeichnis.
 */
class PruefController extends Controller
{
    public function index(): View
    {
        return view('werkbank');
    }

    /** Eine Datei je Request – die Oberfläche schickt Dateien nacheinander. */
    public function datei(Request $request, BelegPruefService $service): JsonResponse
    {
        $request->validate([
            'datei' => ['required', 'file', 'max:25600', 'mimes:jpg,jpeg,png,heic,heif,webp,pdf'],
        ]);

        $datei = $request->file('datei');

        try {
            return response()->json($service->pruefeDatei($datei->getRealPath()));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['fehler' => 'Beleg konnte nicht gelesen werden: '.$e->getMessage()], 422);
        }
    }

    /** Erneute Prüfung mit von Hand eingegebenen gedruckten Werten, ohne neuen Upload. */
    public function nachpruefen(Request $request, BelegPruefService $service): JsonResponse
    {
        $daten = $request->validate([
            'qr_text' => ['nullable', 'string', 'max:2000'],
            'gesamt' => ['nullable', 'string', 'max:20'],
            'datum_uhrzeit' => ['nullable', 'string', 'max:20'],
            'kassen_id' => ['nullable', 'string', 'max:100'],
            'forensik' => ['nullable', 'array'],
        ]);

        $gedruckt = new GedruckteWerte(
            gesamtCent: self::cent($daten['gesamt'] ?? null),
            datumUhrzeit: self::datum($daten['datum_uhrzeit'] ?? null),
            kassenId: ($daten['kassen_id'] ?? '') !== '' ? $daten['kassen_id'] : null,
        );

        // Ergebnisse der Bildforensik bleiben beim Nachprüfen erhalten
        $zusatz = isset($daten['forensik'])
            ? (new ForensikPruefer)->pruefe($daten['forensik'], $gedruckt->datumUhrzeit)
            : [];

        return response()->json($service->pruefeQr($daten['qr_text'] ?? null, $gedruckt, $zusatz));
    }

    /** "92,60" / "92.60" / "1.092,60" → Cent */
    private static function cent(?string $s): ?int
    {
        if ($s === null || trim($s) === '') {
            return null;
        }
        $s = str_replace([' ', '€'], '', $s);
        if (preg_match('/^(-?)([\d.]*?)(\d+)[,.](\d{2})$/', $s, $m)) {
            return (int) (($m[1] === '-' ? -1 : 1) * ((int) str_replace('.', '', $m[2].$m[3]) * 100 + (int) $m[4]));
        }

        return ctype_digit($s) ? (int) $s * 100 : null;
    }

    /** "05.10.2026 22:37" oder "2026-10-05 22:37" → "2026-10-05 22:37" */
    private static function datum(?string $s): ?string
    {
        if ($s === null || trim($s) === '') {
            return null;
        }
        $s = trim($s);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d %02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5]).(isset($m[6]) ? ':'.$m[6] : '');
        }

        return $s;
    }
}
