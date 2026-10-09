<?php

namespace App\Fiskal;

use App\Fiskal\Rksv\RksvBeleg;
use App\Fiskal\Rksv\RksvPruefer;
use App\Models\Kasse;
use App\Models\KassenBeleg;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;
use Carbon\CarbonImmutable;

/**
 * Regeln AT-KA-01 bis -05: Abgleich eines Belegs mit den bisher bestätigten Belegen derselben Kasse.
 *
 * Das Gedächtnis lernt nur aus grünen oder vom Prüfer bestätigten Belegen (merke()),
 * damit ein manipulierter Beleg es nicht „vergiftet“.
 */
final class KassenGedaechtnis
{
    /** @return list<PruefErgebnis> */
    public function pruefe(RksvBeleg $b, ?string $uid, ?string $aussteller, ?string $dateiSha): array
    {
        $kasse = Kasse::where('land', 'AT')->where('kennung', $b->kassenId)->first();
        $lokal = self::lokalSchluessel($uid, $aussteller);
        $out = [];

        // AT-KA-01: Kasse gehört laut Gedächtnis zu einem anderen Lokal
        if (! $kasse) {
            $out[] = new PruefErgebnis('AT-KA-01', Stufe::NichtPruefbar, 'Diese Kasse ist noch nicht im Gedächtnis (erster Beleg).');
        } elseif ($uid && $kasse->uid && $uid !== $kasse->uid) {
            $out[] = new PruefErgebnis('AT-KA-01', Stufe::Widerspruch,
                sprintf('Die Kasse „%s“ gehört laut früheren Belegen zu %s (UID %s), dieser Beleg trägt aber die UID %s. Original-Beleg anfordern.',
                    $b->kassenId, $kasse->lokal_name ?? 'einem anderen Lokal', $kasse->uid, $uid),
                ['bekannt' => $kasse->uid, 'beleg' => $uid]);
        } elseif (! $uid && $lokal && $kasse->lokal_schluessel && ! self::aehnlich($lokal, $kasse->lokal_schluessel)) {
            $out[] = new PruefErgebnis('AT-KA-01', Stufe::Hinweis,
                sprintf('Die Kasse „%s“ war bisher bei „%s“, der Name auf diesem Beleg lautet „%s“. Kann ein Lesefehler sein; bitte am Bild prüfen.',
                    $b->kassenId, $kasse->lokal_name, $aussteller));
        } else {
            $out[] = PruefErgebnis::ok('AT-KA-01', sprintf('Kasse bekannt (%d frühere Belege, Lokal passt).', $kasse->anzahl_belege));
        }

        // AT-KA-02: Lokal bekannt, aber mit anderer Kasse
        if (! $kasse && $uid) {
            $andere = Kasse::where('land', 'AT')->where('uid', $uid)->pluck('kennung');
            $out[] = $andere->isEmpty()
                ? new PruefErgebnis('AT-KA-02', Stufe::NichtPruefbar, 'Lokal noch nicht im Gedächtnis.')
                : new PruefErgebnis('AT-KA-02', Stufe::Hinweis,
                    sprintf('Das Lokal (UID %s) war bisher mit der Kasse „%s“ bekannt, dieser Beleg kommt von der neuen Kasse „%s“. Eine neue oder zweite Kasse ist möglich.',
                        $uid, $andere->implode('“, „'), $b->kassenId));
        }

        if (! $kasse) {
            return $out;
        }

        // AT-KA-03: Zertifikat-Seriennummer gewechselt
        $out[] = ($kasse->zertifikat_sn && $kasse->zertifikat_sn !== $b->zertifikatSn)
            ? new PruefErgebnis('AT-KA-03', Stufe::Hinweis,
                sprintf('Die Kasse hat bisher mit Zertifikat %s signiert, dieser Beleg mit %s. Ein Zertifikatswechsel ist möglich (z. B. neue Signaturkarte).', $kasse->zertifikat_sn, $b->zertifikatSn))
            : PruefErgebnis::ok('AT-KA-03');

        // AT-KA-05: dieselbe Belegnummer schon bekannt
        $gleich = $kasse->belege()->where('belegnummer', $b->belegnummer)->first();
        if ($gleich) {
            $out[] = ($dateiSha && $gleich->datei_sha256 === $dateiSha)
                ? new PruefErgebnis('AT-KA-05', Stufe::Hinweis, 'Diese Datei wurde bereits am '.$gleich->created_at->format('d.m.Y H:i').' geprüft (erneutes Hochladen).')
                : new PruefErgebnis('AT-KA-05', Stufe::Widerspruch,
                    sprintf('Ein Beleg mit Kasse „%s“ und Belegnummer „%s“ wurde bereits am %s eingereicht. Zwei Einreichungen desselben Kassenbelegs; bitte klären.',
                        $b->kassenId, $b->belegnummer, $gleich->created_at->format('d.m.Y')),
                    ['erste_einreichung' => $gleich->created_at->toAtomString()]);

            return $out;
        }

        // AT-KA-04: Belegnummern laufen mit der Zeit vorwärts
        $out[] = $this->verlauf($kasse, $b);

        return $out;
    }

    private function verlauf(Kasse $kasse, RksvBeleg $b): PruefErgebnis
    {
        $nummer = self::ziffern($b->belegnummer);
        if ($nummer === null) {
            return new PruefErgebnis('AT-KA-04', Stufe::NichtPruefbar, 'Belegnummer ohne Ziffernfolge, Verlauf nicht prüfbar.');
        }
        $zeit = CarbonImmutable::parse($b->datumUhrzeit, 'Europe/Vienna');

        $widerspruch = $kasse->belege()->whereNotNull('belegnummer_zahl')->get()->first(fn (KassenBeleg $k) => ($k->beleg_zeit < $zeit && $k->belegnummer_zahl > $nummer)
            || ($k->beleg_zeit > $zeit && $k->belegnummer_zahl < $nummer));

        if (! $widerspruch) {
            return PruefErgebnis::ok('AT-KA-04', 'Belegnummer passt zum bisherigen Verlauf der Kasse.');
        }

        return new PruefErgebnis('AT-KA-04', Stufe::Auffaellig,
            sprintf('Belegnummer und Zeit passen nicht zum Verlauf der Kasse: Beleg %s vom %s, aber Beleg %s vom %s. Bei einer Kasse steigen die Nummern mit der Zeit.',
                $b->belegnummer, $zeit->format('d.m.Y H:i'), $widerspruch->belegnummer, $widerspruch->beleg_zeit->format('d.m.Y H:i')));
    }

    /** Beleg ins Gedächtnis übernehmen (nur grün oder vom Prüfer bestätigt). */
    public function merke(RksvBeleg $b, ?string $uid, ?string $aussteller, ?string $dateiSha): void
    {
        $zeit = CarbonImmutable::parse($b->datumUhrzeit, 'Europe/Vienna');

        $kasse = Kasse::firstOrNew(['land' => 'AT', 'kennung' => $b->kassenId]);
        $kasse->uid ??= $uid;
        $kasse->lokal_name ??= $aussteller;
        $kasse->lokal_schluessel ??= self::lokalSchluessel($uid, $aussteller);
        $kasse->zertifikat_sn = $b->zertifikatSn;
        $kasse->erster_beleg_am = $kasse->erster_beleg_am && $kasse->erster_beleg_am < $zeit ? $kasse->erster_beleg_am : $zeit;
        $kasse->letzter_beleg_am = $kasse->letzter_beleg_am && $kasse->letzter_beleg_am > $zeit ? $kasse->letzter_beleg_am : $zeit;
        $kasse->save();

        $neu = KassenBeleg::firstOrCreate(
            ['kasse_id' => $kasse->id, 'belegnummer' => $b->belegnummer],
            ['belegnummer_zahl' => self::ziffern($b->belegnummer), 'beleg_zeit' => $zeit, 'summe_cent' => $b->summeCent(), 'datei_sha256' => $dateiSha],
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
        return preg_match_all('/\d+/', $belegnummer, $m) ? (int) implode('', $m[0]) : null;
    }

    private static function aehnlich(string $a, string $b): bool
    {
        similar_text($a, $b, $prozent);

        return $prozent >= 70;
    }

    /** Für Begründungen. */
    public static function eur(int $cent): string
    {
        return RksvPruefer::eur($cent);
    }
}
