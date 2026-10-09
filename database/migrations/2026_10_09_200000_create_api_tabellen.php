<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kunde der Schnittstelle (ein ERP, eine Firma). Einstellungen: Schwellen, Compliance-Richtlinie, Webhook.
        Schema::create('mandanten', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kuerzel')->unique();
            $t->json('einstellungen')->nullable();
            $t->string('webhook_url')->nullable();
            $t->text('webhook_geheimnis')->nullable(); // verschlüsselt (Cast)
            $t->boolean('aktiv')->default(true);
            $t->timestamps();
        });

        // API-Schlüssel: gespeichert wird nur der SHA-256-Hash, angezeigt nur einmal beim Anlegen
        Schema::create('api_schluessel', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mandant_id')->constrained('mandanten')->cascadeOnDelete();
            $t->string('name');
            $t->string('praefix', 16)->index();
            $t->string('hash', 64)->unique();
            $t->timestamp('zuletzt_genutzt_am')->nullable();
            $t->timestamp('widerrufen_am')->nullable();
            $t->timestamps();
        });

        // Ein Prüfauftrag über die Schnittstelle. Die Belegdatei liegt nur bis zur Prüfung (verschlüsselt) vor.
        Schema::create('pruefauftraege', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('mandant_id')->constrained('mandanten')->cascadeOnDelete();
            $t->string('status', 16)->default('wartend'); // wartend | laeuft | fertig | fehler
            $t->string('externe_referenz')->nullable();
            $t->string('einreicher')->nullable();
            $t->string('idempotenz_schluessel')->nullable();
            $t->string('idempotenz_hash', 64)->nullable();
            $t->json('angaben')->nullable(); // erwarteter Betrag, Datum, Kategorie … aus dem ERP
            $t->string('dateiname')->nullable();
            $t->string('datei_sha256', 64)->nullable()->index();
            // Merkmale für Dubletten und Orts-/Zeitcheck über Einreichungen hinweg (keine Belegdaten im Klartext)
            $t->string('inhalt_hash', 64)->nullable()->index();
            $t->string('text_hash', 16)->nullable();
            $t->string('fiskal_schluessel', 64)->nullable()->index();
            $t->timestamp('beleg_zeit')->nullable();
            $t->decimal('ort_lat', 8, 5)->nullable();
            $t->decimal('ort_lon', 8, 5)->nullable();
            $t->string('ort_name')->nullable();
            $t->string('ablage_pfad')->nullable(); // nur solange wartend
            $t->unsignedSmallInteger('score')->nullable();
            $t->decimal('abdeckung', 4, 2)->nullable();
            $t->string('ampel', 8)->nullable();
            $t->string('empfehlung', 24)->nullable();
            $t->json('ergebnis')->nullable();
            $t->string('fehler')->nullable();
            $t->string('entscheidung', 16)->nullable(); // Rückmeldung des Prüfers: in_ordnung | beanstandet
            $t->text('entscheidung_kommentar')->nullable();
            $t->string('webhook_status', 16)->nullable(); // offen | zugestellt | fehlgeschlagen
            $t->unsignedSmallInteger('webhook_versuche')->default(0);
            $t->timestamp('fertig_am')->nullable();
            $t->timestamps();
            $t->unique(['mandant_id', 'idempotenz_schluessel']);
            $t->index(['mandant_id', 'externe_referenz']);
            $t->index(['mandant_id', 'einreicher', 'created_at']);
        });

        // Ergebnisse von Abfragen über Aussteller (VIES, OpenStreetMap) – je Aussteller, nicht je Beleg
        Schema::create('aussteller_screenings', function (Blueprint $t) {
            $t->id();
            $t->string('quelle', 16); // vies | osm
            $t->string('schluessel', 191);
            $t->json('daten')->nullable();
            $t->timestamp('abgerufen_am');
            $t->unique(['quelle', 'schluessel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aussteller_screenings');
        Schema::dropIfExists('pruefauftraege');
        Schema::dropIfExists('api_schluessel');
        Schema::dropIfExists('mandanten');
    }
};
