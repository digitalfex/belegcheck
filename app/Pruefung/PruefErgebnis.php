<?php

namespace App\Pruefung;

/**
 * Ergebnis einer Prüfregel: Code, Stufe, Begründung in Klartext und Belege (Werte) für den Prüfer.
 */
final class PruefErgebnis
{
    /**
     * @param  array<string, mixed>  $werte  die verglichenen Werte, z. B. ['qr' => '24,80', 'gedruckt' => '42,80']
     */
    public function __construct(
        public readonly string $code,
        public readonly Stufe $stufe,
        public readonly string $begruendung,
        public readonly array $werte = [],
    ) {}

    public static function ok(string $code, string $begruendung = 'Prüfung bestanden.'): self
    {
        return new self($code, Stufe::Ok, $begruendung);
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'stufe' => $this->stufe->value,
            'punkte' => $this->stufe->punkte(),
            'begruendung' => $this->begruendung,
            'werte' => $this->werte,
        ];
    }
}
