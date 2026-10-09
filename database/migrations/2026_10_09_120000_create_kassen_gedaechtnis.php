<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kassen-Gedächtnis: je Registrierkasse (AT: Kassen-ID, DE: TSE-Seriennummer) das Lokal und die bisherigen Belege.
 * Gespeichert werden nur Kassendaten aus dem QR-Code – keine Bilder, keine Mitarbeiterdaten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kassen', function (Blueprint $t) {
            $t->id();
            $t->string('land', 2);
            $t->string('kennung');                      // Kassen-ID bzw. TSE-Seriennummer
            $t->string('lokal_schluessel')->nullable(); // UID, sonst normalisierter Name
            $t->string('lokal_name')->nullable();
            $t->string('uid')->nullable();
            $t->string('zertifikat_sn')->nullable();
            $t->dateTime('erster_beleg_am')->nullable();
            $t->dateTime('letzter_beleg_am')->nullable();
            $t->unsignedInteger('anzahl_belege')->default(0);
            $t->timestamps();
            $t->unique(['land', 'kennung']);
            $t->index('lokal_schluessel');
        });

        Schema::create('kassen_belege', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kasse_id')->constrained('kassen')->cascadeOnDelete();
            $t->string('belegnummer');
            $t->unsignedBigInteger('belegnummer_zahl')->nullable(); // Ziffernfolge für den Verlauf
            $t->dateTime('beleg_zeit');
            $t->integer('summe_cent');
            $t->string('datei_sha256', 64)->nullable();
            $t->timestamps();
            $t->unique(['kasse_id', 'belegnummer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kassen_belege');
        Schema::dropIfExists('kassen');
    }
};
