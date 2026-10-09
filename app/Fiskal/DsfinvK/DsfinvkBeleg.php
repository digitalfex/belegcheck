<?php

namespace App\Fiskal\DsfinvK;

use Carbon\CarbonImmutable;

/**
 * Geparster Inhalt eines TSE-QR-Codes nach DSFinV-K, Anhang I (Deutschland, KassenSichV).
 *
 * Aufbau: V0;Kassen-Seriennummer;processType;processData;Transaktionsnummer;Signaturzähler;
 *         Start-Zeit;Log-Zeit;Signaturalgorithmus;Zeitformat;Signatur;Public-Key
 */
final class DsfinvkBeleg
{
    /** Reihenfolge der Bruttobeträge in processData (DSFinV-K): 19 %, 7 %, 10,7 %, 5,5 %, 0 %. */
    public const SATZ_FELDER = ['allgemein', 'ermaessigt', 'durchschnitt_10_7', 'durchschnitt_5_5', 'null'];

    /**
     * @param  array<string, int>|null  $bruttoCent  je Satz (SATZ_FELDER), null wenn processData nicht zerlegbar
     * @param  list<array{cent: int, art: string, waehrung: ?string}>|null  $zahlungen
     */
    public function __construct(
        public readonly string $rohtext,
        public readonly string $version,
        public readonly string $kassenSeriennummer,
        public readonly string $processType,
        public readonly string $processData,
        public readonly ?string $vorgangstyp,
        public readonly ?array $bruttoCent,
        public readonly ?array $zahlungen,
        public readonly string $transaktionsnummer,
        public readonly string $signaturzaehler,
        public readonly string $startZeit,
        public readonly string $logZeit,
        public readonly string $signaturAlgorithmus,
        public readonly string $zeitformat,
        public readonly string $signatur,
        public readonly string $publicKey,
    ) {}

    public function summeCent(): ?int
    {
        return $this->bruttoCent === null ? null : array_sum($this->bruttoCent);
    }

    public function zahlungenCent(): ?int
    {
        return $this->zahlungen === null ? null : array_sum(array_column($this->zahlungen, 'cent'));
    }

    /** TSE-Seriennummer = SHA-256 des öffentlichen Schlüssels (BSI TR-03153), hex. */
    public function tseSeriennummer(): ?string
    {
        $key = base64_decode($this->publicKey, true);

        return $key === false || $key === '' ? null : hash('sha256', $key);
    }

    public static function zeit(string $wert, string $format): ?CarbonImmutable
    {
        try {
            if ($format === 'unixTime' && ctype_digit($wert)) {
                return CarbonImmutable::createFromTimestampUTC((int) $wert);
            }

            return CarbonImmutable::parse($wert)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function ende(): ?CarbonImmutable
    {
        return self::zeit($this->logZeit, $this->zeitformat);
    }

    public function start(): ?CarbonImmutable
    {
        return self::zeit($this->startZeit, $this->zeitformat);
    }

    /** Ende in deutscher Lokalzeit „JJJJ-MM-TTThh:mm:ss“ (für den Abgleich mit dem Gedruckten). */
    public function endeLokal(): ?string
    {
        return $this->ende()?->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i:s');
    }

    public function toArray(): array
    {
        return [
            // einheitliche Anzeige-Felder (wie beim RKSV-Beleg)
            'datum_uhrzeit' => $this->endeLokal(),
            'kassen_id' => $this->kassenSeriennummer,
            'belegnummer' => $this->transaktionsnummer,
            'kassen_seriennummer' => $this->kassenSeriennummer,
            'tse_seriennummer' => $this->tseSeriennummer(),
            'process_type' => $this->processType,
            'vorgangstyp' => $this->vorgangstyp,
            'brutto_cent' => $this->bruttoCent,
            'summe_cent' => $this->summeCent(),
            'zahlungen' => $this->zahlungen,
            'transaktionsnummer' => $this->transaktionsnummer,
            'signaturzaehler' => $this->signaturzaehler,
            'start' => $this->startZeit,
            'ende' => $this->logZeit,
            'ende_lokal' => $this->endeLokal(),
            'signatur_algorithmus' => $this->signaturAlgorithmus,
        ];
    }
}
