<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLZ-Verzeichnis AT/DE mit Koordinaten (GeoNames, CC BY 4.0) für den Regionscheck – offline, ohne Cloud-Dienst. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plz_orte', function (Blueprint $t) {
            $t->id();
            $t->string('land', 2);
            $t->string('plz', 10);
            $t->string('ort');
            $t->decimal('lat', 9, 6);
            $t->decimal('lon', 9, 6);
            $t->index(['land', 'plz']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plz_orte');
    }
};
