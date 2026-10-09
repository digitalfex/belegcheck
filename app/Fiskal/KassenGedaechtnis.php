<?php

namespace App\Fiskal;

use App\Models\Kasse;
use App\Models\KassenBeleg;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;

/**
 * Kassen-Gedächtnis (AT-KA-01…05, DE-KA-01…05): Abgleich eines Belegs mit den bisher bestätigten Belegen
 * derselben Kasse (AT) bzw. TSE (DE).
 *
 * Lernt nur aus grünen oder vom Prüfer bestätigten Belegen (merke()), damit ein manipulierter Beleg
 * das Gedächtnis nicht „vergiftet“.
 */
final class KassenGedaechtnis
{
    /** @return list<PruefErgebnis> */
    public function pruefe(KassenDaten $d, ?string $uid, ?string $aussteller, ?string $dateiSha): array
    {
        $kasse = Kasse::where('land', $d->land)->where('kennung', $d->kennung)->first();
        $lokal = self::lokalSchluessel($uid, $aussteller);
        $out = [];

        // Gehört die Kasse zu einem anderen Lokal?
        if (! $kasse) {
            $out[] = new PruefErgebnis($d->code('lokal'), Stufe::NichtPruefbar, 'Diese Kasse ist noch nicht im Gedächtnis (erster Beleg).');
        } elseif ($uid && $kasse->uid && $uid !== $kasse->uid) {
            $out[] = new PruefErgebnis($d->code('lokal'), Stufe::Widerspruch,
                sprintf('Die %s gehört laut früheren Belegen zu %s (UID %s), dieser Beleg trägt aber die UID %s. Original-Beleg anfordern.',
                    $d->bezeichnung(), $kasse->lokal_name ?? 'einem anderen Lokal', $kasse->uid, $uid),
                ['bekannt' => $kasse->uid, 'beleg' => $uid]);
        } elseif (! $uid && $lokal && $kasse->lokal_schluessel && ! self::aehnlich($lokal, $kasse->lokal_schluessel)) {
            $out[] = new PruefErgebnis($d->code('lokal'), Stufe::Hinweis,
                sprintf('Die %s war bisher bei „%s“, der Name auf diesem Beleg lautet „%s“. Kann ein Lesefehler sein; bitte am Bild prüfen.',
                    $d->bezeichnung(), $kasse->lokal_name, $aussteller));
        } else {
            $out[] = PruefErgebnis::ok($d->code('lokal'), sprintf('Kasse bekannt (%d frühere Belege, Lokal passt).', $kasse->anzahl_belege));
        }

        // Lokal bekannt, aber mit anderer Kasse
        if (! $kasse && $uid) {
            $andere = Kasse::where('land', $d->land)->where('uid', $uid)->count();
            $out[] = $andere === 0
                ? new PruefErgebnis($d->code('neue_kasse'), Stufe::NichtPruefbar, 'Lokal noch nicht im Gedächtnis.')
                : new PruefErgebnis($d->code('neue_kasse'), Stufe::Hinweis,
                    sprintf('Das Lokal (UID %s) war bisher mit %d anderen Kasse(n) bekannt, dieser Beleg kommt von der neuen %s. Eine neue oder zweite Kasse ist möglich.',
                        $uid, $andere, $d->bezeichnung()));
        }

        if (! $kasse) {
            return $out;
        }

        // Zertifikat (AT) bzw. Kassen-Seriennummer (DE) gewechselt
        $out[] = ($kasse->zertifikat_sn && $d->zweitKennung && $kasse->zertifikat_sn !== $d->zweitKennung)
            ? new PruefErgebnis($d->code('zweitkennung'), Stufe::Hinweis, $d->land === 'AT'
                ? sprintf('Die Kasse hat bisher mit Zertifikat %s signiert, dieser Beleg mit %s. Ein Zertifikatswechsel ist möglich (z. B. neue Signaturkarte).', $kasse->zertifikat_sn, $d->zweitKennung)
                : sprintf('Die TSE war bisher an Kasse „%s“, dieser Beleg nennt Kasse „%s“. Ein Umbau ist möglich.', $kasse->zertifikat_sn, $d->zweitKennung))
            : PruefErgebnis::ok($d->code('zweitkennung'));

        // Dieselbe Beleg-/Transaktionsnummer schon bekannt
        $gleich = $kasse->belege()->where('belegnummer', $d->belegnummer)->first();
        if ($gleich) {
            $out[] = ($dateiSha && $gleich->datei_sha256 === $dateiSha)
                ? new PruefErgebnis($d->code('dublette'), Stufe::Hinweis, 'Diese Datei wurde bereits am '.$gleich->created_at->format('d.m.Y H:i').' geprüft (erneutes Hochladen).')
                : new PruefErgebnis($d->code('dublette'), Stufe::Widerspruch,
                    sprintf('Ein Beleg der %s mit Nummer „%s“ wurde bereits am %s eingereicht. Zwei Einreichungen desselben Kassenbelegs; bitte klären.',
                        $d->bezeichnung(), $d->belegnummer, $gleich->created_at->format('d.m.Y')),
                    ['erste_einreichung' => $gleich->created_at->toAtomString()]);

            return $out;
        }

        // Nummern laufen mit der Zeit vorwärts
        $out[] = $this->verlauf($kasse, $d);

        return $out;
    }

    private function verlauf(Kasse $kasse, KassenDaten $d): PruefErgebnis
    {
        $nummer = self::ziffern($d->belegnummer);
        if ($nummer === null) {
            return new PruefErgebnis($d->code('verlauf'), Stufe::NichtPruefbar, 'Belegnummer ohne Ziffernfolge, Verlauf nicht prüfbar.');
        }

        $widerspruch = $kasse->belege()->whereNotNull('belegnummer_zahl')->get()->first(fn (KassenBeleg $k) => ($k->beleg_zeit < $d->zeit && $k->belegnummer_zahl > $nummer)
            || ($k->beleg_zeit > $d->zeit && $k->belegnummer_zahl < $nummer));

        if (! $widerspruch) {
            return PruefErgebnis::ok($d->code('verlauf'), 'Nummer passt zum bisherigen Verlauf der Kasse.');
        }

        return new PruefErgebnis($d->code('verlauf'), Stufe::Auffaellig,
            sprintf('Nummer und Zeit passen nicht zum Verlauf der Kasse: Nr. %s vom %s, aber Nr. %s vom %s. Bei einer Kasse steigen die Nummern mit der Zeit.',
                $d->belegnummer, $d->zeit->format('d.m.Y H:i'), $widerspruch->belegnummer, $widerspruch->beleg_zeit->format('d.m.Y H:i')));
    }

    /** Beleg ins Gedächtnis übernehmen (nur grün oder vom Prüfer bestätigt). */
    public function merke(KassenDaten $d, ?string $uid, ?string $aussteller, ?string $dateiSha): void
    {
        $kasse = Kasse::firstOrNew(['land' => $d->land, 'kennung' => $d->kennung]);
        $kasse->uid ??= $uid;
        $kasse->lokal_name ??= $aussteller;
        $kasse->lokal_schluessel ??= self::lokalSchluessel($uid, $aussteller);
        $kasse->zertifikat_sn = $d->zweitKennung;
        $kasse->erster_beleg_am = $kasse->erster_beleg_am && $kasse->erster_beleg_am < $d->zeit ? $kasse->erster_beleg_am : $d->zeit;
        $kasse->letzter_beleg_am = $kasse->letzter_beleg_am && $kasse->letzter_beleg_am > $d->zeit ? $kasse->letzter_beleg_am : $d->zeit;
        $kasse->save();

        $neu = KassenBeleg::firstOrCreate(
            ['kasse_id' => $kasse->id, 'belegnummer' => $d->belegnummer],
            ['belegnummer_zahl' => self::ziffern($d->belegnummer), 'beleg_zeit' => $d->zeit, 'summe_cent' => $d->summeCent, 'datei_sha256' => $dateiSha],
        );

        if ($neu->wasRecentlyCreated) {
            $kasse->increment('anzahl_belege');
        }
    }

    public static function lokalSchluessel(?string $uid, ?string $aussteller): ?string
    {
        if ($uid) {
            return $uid;
        }

        return $aussteller ? mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $aussteller)) : null;
    }

    /** "R1047649" → 1047649; keine Ziffern → null */
    public static function ziffern(string $belegnummer): ?int
    {
        return preg_match_all('/\d+/', $belegnummer, $m) ? (int) substr(implode('', $m[0]), 0, 18) : null;
    }

    private static function aehnlich(string $a, string $b): bool
    {
        similar_text($a, $b, $prozent);

        return $prozent >= 70;
    }
}
