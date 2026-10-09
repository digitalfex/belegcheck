<?php

namespace App\Sprachmodell;

use Illuminate\Support\Facades\Http;

/**
 * Lokales Sprachmodell (llama.cpp-Server, OpenAI-kompatibel) – derselbe Dienst wie beim Bescheidwisser
 * (Qwen, 127.0.0.1:8081). Belegdaten verlassen den Server nie: nur Adressen auf diesem Rechner sind erlaubt.
 *
 * Das Modell bekommt ausschließlich die erkannten Texte (keine Bilder, keinen QR-Inhalt) und muss
 * über ein JSON-Schema antworten. Fehler oder Zeitüberschreitung → null, die Prüfung läuft ohne KI weiter.
 */
final class BelegSprachmodell
{
    private const SYSTEM = <<<'TXT'
        Du liest Kassenbelege und Rechnungen aus Österreich und Deutschland. Du bekommst zwei unabhängige, fehlerhafte
        Texterkennungen desselben Belegs (Lesung A und Lesung B). Gib die Werte so an, wie sie auf dem Beleg gedruckt sind.

        Regeln:
        - Nichts erfinden und nichts ausrechnen. Steht ein Wert in keiner Lesung, gib null an.
        - Typische Lesefehler: „€“ als 6, $, #, E, £ oder e gelesen; O statt 0; l oder I statt 1; fehlende Leerzeichen.
          Solche Fehler darfst du beim Ablesen ausgleichen. Widersprechen sich die Lesungen bei einer Zahl, nimm die,
          die zu den übrigen Zahlen des Belegs passt (z. B. Netto + Steuer = Brutto, Positionen ergeben die Summe).
        - gesamtbetrag: der als Summe/Total/Gesamt/zu zahlen ausgewiesene Endbetrag des Belegs (nicht Netto, nicht eine
          Teilsumme, nicht Rückgeld). Format 1234,56.
        - datum/uhrzeit: Zeitpunkt des Belegs (Rechnungs-/Belegdatum). Nicht Ein- oder Ausfahrtszeit beim Parken,
          nicht Ablaufdatum einer Karte, nicht Leistungs- oder Auftragsdatum, wenn ein Belegdatum gedruckt ist.
        - aussteller: Name des Geschäfts oder Lokals wie im Kopf gedruckt (Marke, sonst Firma). Offensichtliche
          Lesefehler im Namen darfst du korrigieren, wenn der richtige Name an anderer Stelle steht.
          Nicht der Kunde (z. B. „Firma …“ als Rechnungsempfänger).
        - steuersaetze: je gedrucktem Steuersatz die gedruckten Beträge (brutto, netto, steuer), fehlende als null.
        TXT;

    public function __construct(
        private readonly ?string $url = null,
        private readonly int $timeout = 180,
    ) {}

    public static function ausConfig(): self
    {
        return new self(config('belegcheck.sprachmodell.url'), (int) config('belegcheck.sprachmodell.timeout', 180));
    }

    public function verfuegbar(): bool
    {
        return $this->url !== null && $this->url !== '' && self::lokal($this->url);
    }

    /** Nur ein Dienst auf diesem Rechner – nie ein entfernter. */
    public static function lokal(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && ($host === 'localhost' || $host === '::1'
                || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.')));
    }

    /**
     * @return array<string, mixed>|null Felder laut Schema, ungeprüft
     */
    public function lesen(string $lesungA, string $lesungB): ?array
    {
        if (! $this->verfuegbar()) {
            return null;
        }
        $nutzer = "Lesung A:\n".mb_substr($lesungA, 0, 6000)."\n\nLesung B:\n".mb_substr($lesungB !== '' ? $lesungB : '(keine)', 0, 6000);

        try {
            $antwort = Http::timeout($this->timeout)->acceptJson()
                ->post(rtrim($this->url, '/').'/v1/chat/completions', [
                    'messages' => [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $nutzer]],
                    'temperature' => 0.0,
                    'seed' => 42,
                    'max_tokens' => 600,
                    'chat_template_kwargs' => ['enable_thinking' => false], // Qwen3: ohne Denkphase
                    'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'beleg', 'schema' => self::schema()]],
                ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $antwort->successful()) {
            return null;
        }
        $inhalt = (string) $antwort->json('choices.0.message.content', '');
        $inhalt = trim(preg_replace('/<think>.*?<\/think>/s', '', $inhalt));
        $daten = json_decode($inhalt, true);

        return is_array($daten) ? $daten : null;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $text = ['type' => ['string', 'null']];
        $satz = ['type' => 'object', 'properties' => ['satz' => ['type' => 'string'], 'brutto' => $text, 'netto' => $text, 'steuer' => $text],
            'required' => ['satz', 'brutto', 'netto', 'steuer']];

        return [
            'type' => 'object',
            'properties' => [
                'aussteller' => $text, 'uid' => $text, 'belegnummer' => $text,
                'datum' => $text, 'uhrzeit' => $text, 'gesamtbetrag' => $text,
                'steuersaetze' => ['type' => 'array', 'items' => $satz, 'maxItems' => 6],
            ],
            'required' => ['aussteller', 'uid', 'belegnummer', 'datum', 'uhrzeit', 'gesamtbetrag', 'steuersaetze'],
        ];
    }
}
