<?php

namespace App\Api;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Zwischenablage für asynchrone Prüfaufträge: Belegdatei verschlüsselt (APP_KEY) bis zur Prüfung,
 * danach sofort gelöscht. Synchron geprüfte Belege werden nie abgelegt.
 */
final class Eingang
{
    public static function ablegen(string $id, string $inhalt): string
    {
        $pfad = 'api-eingang/'.$id;
        Storage::disk('local')->put($pfad, Crypt::encryptString(base64_encode($inhalt)));

        return $pfad;
    }

    /** Entschlüsselt in eine temporäre Datei; Aufrufer löscht sie. */
    public static function auspacken(string $pfad, string $dateiname): string
    {
        $inhalt = base64_decode(Crypt::decryptString(Storage::disk('local')->get($pfad)));
        $endung = strtolower(pathinfo($dateiname, PATHINFO_EXTENSION)) ?: 'bin';
        $ziel = tempnam(sys_get_temp_dir(), 'beleg').'.'.preg_replace('/[^a-z0-9]/', '', $endung);
        file_put_contents($ziel, $inhalt);

        return $ziel;
    }

    public static function loeschen(?string $pfad): void
    {
        if ($pfad) {
            Storage::disk('local')->delete($pfad);
        }
    }
}
