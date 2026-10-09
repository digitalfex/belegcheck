<?php

namespace App\Screening;

/**
 * Ordnet den Aussteller einer Branche zu und erkennt Compliance-Kategorien.
 *
 * Quellen in dieser Reihenfolge: Merkmale aus OpenStreetMap (amenity/shop/…), Name des Ausstellers,
 * Wörter im Belegtext. Kategorien sind wertfrei benannt; was davon im Unternehmen erlaubt ist,
 * legt die Richtlinie des Mandanten fest (erlaubt | hinweis | pruefen).
 */
final class Branche
{
    /** Branchen (für den Abgleich mit der Spesenkategorie) */
    public const BRANCHEN = [
        'gastronomie' => 'Gastronomie',
        'beherbergung' => 'Beherbergung',
        'tankstelle' => 'Tankstelle',
        'parken' => 'Parken',
        'verkehr' => 'Verkehr (Taxi, Bahn, Flug, Öffis)',
        'handel' => 'Handel',
        'werkstatt' => 'Kfz-Werkstatt',
        'post' => 'Post/Versand',
        'freizeit' => 'Freizeit/Veranstaltung',
        'gluecksspiel' => 'Glücksspiel/Wetten',
        'erwachsenenunterhaltung' => 'Erwachsenenunterhaltung',
    ];

    /** Compliance-Kategorien mit Standardrichtlinie (je Mandant überschreibbar) */
    public const COMPLIANCE = [
        'erwachsenenunterhaltung' => ['titel' => 'Erwachsenenunterhaltung (Erotik, Rotlicht)', 'standard' => 'pruefen'],
        'gluecksspiel' => ['titel' => 'Glücksspiel und Wetten', 'standard' => 'pruefen'],
        'bargeldaehnlich' => ['titel' => 'Gutscheine, Guthaben- und Geschenkkarten', 'standard' => 'pruefen'],
        'geldtransfer' => ['titel' => 'Pfandleihe, Geldwechsel, Geldtransfer', 'standard' => 'pruefen'],
        'waffen' => ['titel' => 'Waffen und Munition', 'standard' => 'pruefen'],
        'tabak' => ['titel' => 'Tabakwaren', 'standard' => 'hinweis'],
    ];

    /** OSM-Merkmal → Compliance-Kategorie */
    private const OSM_COMPLIANCE = [
        'amenity' => ['stripclub' => 'erwachsenenunterhaltung', 'brothel' => 'erwachsenenunterhaltung', 'swingerclub' => 'erwachsenenunterhaltung',
            'love_hotel' => 'erwachsenenunterhaltung', 'casino' => 'gluecksspiel', 'gambling' => 'gluecksspiel',
            'bureau_de_change' => 'geldtransfer', 'money_transfer' => 'geldtransfer'],
        'shop' => ['erotic' => 'erwachsenenunterhaltung', 'adult' => 'erwachsenenunterhaltung', 'bookmaker' => 'gluecksspiel',
            'lottery' => 'gluecksspiel', 'pawnbroker' => 'geldtransfer', 'money_lender' => 'geldtransfer',
            'weapons' => 'waffen', 'hunting' => 'waffen', 'tobacco' => 'tabak'],
        'leisure' => ['adult_gaming_centre' => 'gluecksspiel', 'amusement_arcade' => 'gluecksspiel'],
    ];

    /** Wörter im Namen des Ausstellers → Compliance-Kategorie */
    private const NAME_COMPLIANCE = [
        'erwachsenenunterhaltung' => '/\b(laufhaus|bordell|stripclub|strip\s*club|table\s*dance|tabledance|peep\s*show|erotik\w*|fkk[\s-]*club|sauna[\s-]*club|escort|nightclub\s+\w*girls)\b/iu',
        'gluecksspiel' => '/\b(wettb[üu]ro|sportwetten|wettannahme|wettlokal|tipico|admiral\s+(sportwetten|casino)|bwin|interwetten|spielhalle|spielothek|casino|automatensalon|bet[\s-]?at[\s-]?home)\b/iu',
        'geldtransfer' => '/\b(pfandleih\w*|pfandhaus|western\s+union|moneygram|wechselstube)\b/iu',
        'waffen' => '/\b(waffen\w*|b[üu]chsenmacher)\b/iu',
        'tabak' => '/\b(trafik|tabak\w*)\b/iu',
    ];

    /** Wörter in den Positionen des Belegs → Compliance-Kategorie (CO-02) */
    private const POSITION_COMPLIANCE = [
        'bargeldaehnlich' => '/\b(gutschein\w*|geschenkkarte\w*|gift\s*card|guthabenkarte|wertkarte|paysafe\w*|aufladung|prepaid)\b/iu',
        'gluecksspiel' => '/\b(wetteinsatz|spieleinsatz|wettschein|lotto\w*|rubbellos\w*|jetons?)\b/iu',
        'tabak' => '/\b(zigarett\w*|zigarre\w*|tabak\w*|iqos|heets|e-?zigarett\w*)\b/iu',
        'waffen' => '/\b(munition|patronen)\b/iu',
    ];

    private const OSM_BRANCHE = [
        'amenity' => ['restaurant' => 'gastronomie', 'cafe' => 'gastronomie', 'bar' => 'gastronomie', 'pub' => 'gastronomie',
            'fast_food' => 'gastronomie', 'biergarten' => 'gastronomie', 'food_court' => 'gastronomie', 'ice_cream' => 'gastronomie',
            'fuel' => 'tankstelle', 'parking' => 'parken', 'taxi' => 'verkehr', 'car_rental' => 'verkehr',
            'nightclub' => 'freizeit', 'cinema' => 'freizeit', 'theatre' => 'freizeit', 'post_office' => 'post',
            'casino' => 'gluecksspiel', 'gambling' => 'gluecksspiel', 'stripclub' => 'erwachsenenunterhaltung',
            'brothel' => 'erwachsenenunterhaltung', 'swingerclub' => 'erwachsenenunterhaltung'],
        'tourism' => ['hotel' => 'beherbergung', 'guest_house' => 'beherbergung', 'hostel' => 'beherbergung', 'motel' => 'beherbergung', 'apartment' => 'beherbergung'],
        'shop' => ['car_repair' => 'werkstatt', 'tyres' => 'werkstatt', 'bookmaker' => 'gluecksspiel', 'lottery' => 'gluecksspiel',
            'erotic' => 'erwachsenenunterhaltung', 'adult' => 'erwachsenenunterhaltung'],
        'leisure' => ['adult_gaming_centre' => 'gluecksspiel', 'amusement_arcade' => 'gluecksspiel'],
        'railway' => ['station' => 'verkehr'],
        'aeroway' => ['aerodrome' => 'verkehr'],
    ];

    private const TEXT_BRANCHE = [
        'tankstelle' => '/\b(tankstelle|super\s*(95|98|plus)?|diesel|liter|zapfs[äa]ule|s[äa]ule\s*\d)\b/iu',
        'parken' => '/\b(parkticket|parkdauer|kurzparkticket|parkhaus|garage|einfahrt|ausfahrt)\b/iu',
        'beherbergung' => '/\b(zimmer|übernachtung|[üu]bernachtung|n[äa]chtigung|ortstaxe|city\s*tax|logis|fr[üu]hst[üu]ck\s+inkl)\b/iu',
        'verkehr' => '/\b(taxi|fahrpreis|fahrkarte|ticket\s+(wien|bahn)|[öo]bb|westbahn|flug|boarding|fahrt\s+von)\b/iu',
        'post' => '/\b(paket|brief|sendungsnummer|einschreiben)\b/iu',
        'werkstatt' => '/\b(reifen|werkstatt|service\s+inspektion|[öo]lwechsel|montage)\b/iu',
        'gastronomie' => '/\b(gasthaus|gasthof|restaurant|caf[eé]|pizzeria|trattoria|beisl|heuriger|wirtshaus|bistro|tisch\s*#?\d|kellner|bedient|speisen|getr[äa]nke|cola|bier|wein|espresso|cappuccino|schnitzel|burger|pizza|men[üu]|trinkgeld)\b/iu',
    ];

    /** Spesenkategorie (vom ERP) → passende Branchen */
    public const KATEGORIE_BRANCHEN = [
        'bewirtung' => ['gastronomie', 'beherbergung', 'freizeit'],
        'verpflegung' => ['gastronomie', 'beherbergung', 'handel', 'tankstelle'],
        'uebernachtung' => ['beherbergung'],
        'tanken' => ['tankstelle'],
        'parken' => ['parken'],
        'fahrt' => ['verkehr', 'parken', 'tankstelle'],
        'kfz' => ['werkstatt', 'tankstelle'],
        'porto' => ['post'],
    ];

    /**
     * @param  array<string, string>  $osmTags  Merkmale des gefundenen OSM-Objekts (leer, wenn keins gefunden)
     * @return array{branche: ?string, branche_quelle: ?string, kategorien: array<string, string>} kategorien: Kategorie → Quelle (osm | name)
     */
    public static function aussteller(array $osmTags, ?string $name, string $text): array
    {
        $branche = null;
        $quelle = null;
        foreach (self::OSM_BRANCHE as $schluessel => $werte) {
            if (isset($osmTags[$schluessel], $werte[$osmTags[$schluessel]])) {
                [$branche, $quelle] = [$werte[$osmTags[$schluessel]], 'osm'];
                break;
            }
        }
        if ($branche === null) {
            // Beleginhalt: Branche mit den meisten Treffern
            $treffer = [];
            foreach (self::TEXT_BRANCHE as $b => $muster) {
                $treffer[$b] = preg_match_all($muster, $text);
            }
            arsort($treffer);
            if (reset($treffer) > 0) {
                [$branche, $quelle] = [array_key_first($treffer), 'text'];
            }
        }

        $kategorien = [];
        foreach (self::OSM_COMPLIANCE as $schluessel => $werte) {
            if (isset($osmTags[$schluessel], $werte[$osmTags[$schluessel]])) {
                $kategorien[$werte[$osmTags[$schluessel]]] = 'osm';
            }
        }
        foreach (self::NAME_COMPLIANCE as $kategorie => $muster) {
            if ($name && preg_match($muster, $name)) {
                $kategorien[$kategorie] ??= 'name';
            }
        }

        return ['branche' => $branche, 'branche_quelle' => $quelle, 'kategorien' => $kategorien];
    }

    /**
     * Compliance-Kategorien in den Positionen (ohne die ersten Kopfzeilen mit Name/Adresse).
     *
     * @param  list<string>  $zeilen
     * @return array<string, string> Kategorie → gefundene Zeile
     */
    public static function positionen(array $zeilen): array
    {
        $gefunden = [];
        foreach (array_slice($zeilen, 3) as $zeile) {
            foreach (self::POSITION_COMPLIANCE as $kategorie => $muster) {
                if (! isset($gefunden[$kategorie]) && preg_match($muster, $zeile)) {
                    $gefunden[$kategorie] = trim($zeile);
                }
            }
        }

        return $gefunden;
    }
}
