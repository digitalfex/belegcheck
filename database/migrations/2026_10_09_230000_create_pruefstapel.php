<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ein Stapel = mehrere Belege einer Abrechnung (z. B. Reisekostenabrechnung), immer asynchron geprüft
        Schema::create('pruefstapel', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('mandant_id')->constrained('mandanten')->cascadeOnDelete();
            $t->string('status', 16)->default('laeuft'); // laeuft | fertig
            $t->string('externe_referenz')->nullable();
            $t->string('einreicher')->nullable();
            $t->string('idempotenz_schluessel')->nullable();
            $t->string('idempotenz_hash', 64)->nullable();
            $t->unsignedSmallInteger('anzahl');
            $t->json('ergebnis')->nullable(); // Zusammenfassung beim Abschluss
            $t->string('webhook_status', 16)->nullable();
            $t->unsignedSmallInteger('webhook_versuche')->default(0);
            $t->timestamp('fertig_am')->nullable();
            $t->timestamps();
            $t->unique(['mandant_id', 'idempotenz_schluessel']);
            $t->index(['mandant_id', 'externe_referenz']);
        });

        Schema::table('pruefauftraege', function (Blueprint $t) {
            $t->ulid('stapel_id')->nullable()->index();
            $t->unsignedSmallInteger('position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pruefauftraege', function (Blueprint $t) {
            $t->dropIndex(['stapel_id']);
            $t->dropColumn(['stapel_id', 'position']);
        });
        Schema::dropIfExists('pruefstapel');
    }
};
