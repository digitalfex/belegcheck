<?php

namespace App\Forensik;

use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;
use DateTimeImmutable;

/**
 * Schicht 4 (Basis): Metadaten der Bilddatei.
 * Nur Merkmale mit wenig Fehlalarmen. Fehlende Metadaten (Screenshot, Messenger) sind KEIN Signal.
 */
final class ForensikPruefer
{
    /**
     * @param  array{ki_kennzeichen?: list<string>, metadaten?: array<string, string>, bearbeitungssoftware?: list<string>}  $f
     * @param  string|null  $belegDatum  JJJJ-MM-TT[ hh:mm]
     * @return list<PruefErgebnis>
     */
    public function pruefe(array $f, ?string $belegDatum): array
    {
        $out = [];
        $meta = $f['metadaten'] ?? [];

        // BF-01: KI-Herkunftskennzeichnung
        $ki = $f['ki_kennzeichen'] ?? [];
        $out[] = $ki === []
            ? PruefErgebnis::ok('BF-01', 'Keine KI-Herkunftskennzeichnung in der Datei.')
            : new PruefErgebnis('BF-01', Stufe::Widerspruch,
                'Die Bilddatei enthält eine Kennzeichnung, die sie als von einem KI-Bildgenerator erzeugt oder bearbeitet ausweist ('.implode(', ', $ki).'). Original-Beleg in Papierform anfordern.',
                ['kennzeichen' => $ki]);

        // BF-02: Bildbearbeitungsprogramm
        $software = $f['bearbeitungssoftware'] ?? [];
        $out[] = $software === []
            ? PruefErgebnis::ok('BF-02', 'Kein Bildbearbeitungsprogramm in den Metadaten.')
            : new PruefErgebnis('BF-02', Stufe::Auffaellig,
                sprintf('Laut Metadaten wurde die Datei mit „%s“ gespeichert. Das kann harmlos sein (z. B. Zuschneiden), aber auch auf eine Bearbeitung hinweisen. Original-Beleg ansehen.', $meta['software'] ?? implode(', ', $software)),
                ['software' => $meta['software'] ?? null]);

        // BF-03: Foto aufgenommen, bevor der Beleg ausgestellt wurde
        $out[] = $this->aufnahmeVorBeleg($meta['aufnahme'] ?? null, $belegDatum);

        return $out;
    }

    private function aufnahmeVorBeleg(?string $aufnahme, ?string $belegDatum): PruefErgebnis
    {
        if (! $aufnahme || ! $belegDatum) {
            return new PruefErgebnis('BF-03', Stufe::NichtPruefbar, 'Kein Aufnahmezeitpunkt in der Datei (z. B. Screenshot oder über Messenger verschickt).');
        }

        $foto = DateTimeImmutable::createFromFormat('Y:m:d H:i:s', substr($aufnahme, 0, 19));
        $beleg = new DateTimeImmutable(strlen($belegDatum) === 10 ? $belegDatum.' 23:59' : $belegDatum);

        if (! $foto) {
            return new PruefErgebnis('BF-03', Stufe::NichtPruefbar, 'Aufnahmezeitpunkt nicht lesbar.');
        }

        // Ein Tag Toleranz für falsch gestellte Kamerauhren und Zeitzonen
        if ($foto < $beleg->modify('-1 day')) {
            return new PruefErgebnis('BF-03', Stufe::Auffaellig,
                sprintf('Das Foto wurde laut Metadaten am %s aufgenommen, der Beleg ist aber vom %s. Einreicher um Erklärung bitten.',
                    $foto->format('d.m.Y H:i'), $beleg->format('d.m.Y')),
                ['aufnahme' => $foto->format(DATE_ATOM), 'beleg' => $belegDatum]);
        }

        return PruefErgebnis::ok('BF-03', 'Foto nach Ausstellung des Belegs aufgenommen.');
    }
}
