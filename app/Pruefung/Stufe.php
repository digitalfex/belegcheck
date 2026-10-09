<?php

namespace App\Pruefung;

/**
 * Ergebnisstufe einer einzelnen Prüfregel.
 * Punkte laut Spezifikation „Risikowert und Begründungstexte“.
 */
enum Stufe: string
{
    case Ok = 'ok';
    case NichtPruefbar = 'nicht_pruefbar';
    case Hinweis = 'hinweis';
    case Auffaellig = 'auffaellig';
    case Widerspruch = 'widerspruch';

    public function punkte(): int
    {
        return match ($this) {
            self::Ok, self::NichtPruefbar => 0,
            self::Hinweis => 5,
            self::Auffaellig => 15,
            self::Widerspruch => 50,
        };
    }
}
