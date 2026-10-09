<?php

namespace App\Pruefung;

/**
 * Kurzbeschreibung jeder Prüfregel – wird im Prüfbericht neben dem Ergebnis angezeigt.
 * Codes laut Spezifikation.
 */
final class Regeln
{
    public const TITEL = [
        // Schicht 1 Österreich: Kassen-QR-Code (RKSV)
        'AT-QR-01' => 'Kassen-QR-Code vorhanden',
        'AT-QR-02' => 'QR-Code im RKSV-Format (13 Felder, Kennzeichen R1-AT…)',
        'AT-QR-03' => 'Datum/Uhrzeit im QR gültig und nicht nach dem Einreichen',
        'AT-QR-04' => 'Summe im QR = gedruckter Gesamtbetrag',
        'AT-QR-05' => 'Beträge je Steuersatz im QR = gedruckte Steuertabelle',
        'AT-QR-06' => 'Datum/Uhrzeit im QR = gedrucktes Datum/Uhrzeit',
        'AT-QR-07' => 'Kassen-ID im QR = gedruckte Kassen-ID',
        'AT-QR-08' => 'Sicherheitseinrichtung der Kasse war nicht ausgefallen',
        'AT-QR-09' => 'Kein Trainings- oder Stornobeleg',
        'AT-QR-10' => 'Kein Startbeleg der Kasse',
        'AT-QR-11' => 'Signatur, Verkettung und Umsatzzähler technisch vollständig',

        // Schicht 1 Österreich: Kassen-Gedächtnis
        'AT-KA-01' => 'Kasse gehört zum selben Lokal wie bei früheren Belegen',
        'AT-KA-02' => 'Lokal mit bekannter Kasse (neue Kasse?)',
        'AT-KA-03' => 'Signaturzertifikat der Kasse unverändert',
        'AT-KA-04' => 'Belegnummer passt zum zeitlichen Verlauf der Kasse',
        'AT-KA-05' => 'Kassenbeleg nicht schon einmal eingereicht',

        // Schicht 4: Bildforensik (Metadaten)
        'BF-01' => 'Keine KI-Herkunftskennzeichnung in der Datei',
        'BF-02' => 'Kein Bildbearbeitungsprogramm in den Metadaten',
        'BF-03' => 'Foto nach Ausstellung des Belegs aufgenommen',
    ];

    public static function titel(string $code): string
    {
        return self::TITEL[$code] ?? $code;
    }
}
