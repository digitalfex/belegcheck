<?php

use App\Api\Darstellung;
use App\Http\Controllers\Api\PruefungController;
use App\Http\Middleware\ApiSchluesselPruefen;
use Illuminate\Support\Facades\Route;

// Öffentliche Schnittstellenbeschreibung und Erreichbarkeit
Route::prefix('v1')->group(function () {
    Route::get('openapi.yaml', fn () => response(file_get_contents(base_path('docs/openapi.yaml')), 200, ['Content-Type' => 'application/yaml; charset=utf-8']));
    Route::get('gesund', fn () => response()->json(['status' => 'ok', 'regelwerk' => Darstellung::REGELWERK]));
});

Route::prefix('v1')->middleware([ApiSchluesselPruefen::class, 'throttle:belegcheck-api'])->group(function () {
    Route::post('pruefungen', [PruefungController::class, 'store']);
    Route::get('pruefungen', [PruefungController::class, 'index']);
    Route::get('pruefungen/{id}', [PruefungController::class, 'show']);
    Route::delete('pruefungen/{id}', [PruefungController::class, 'destroy']);
    Route::post('pruefungen/{id}/entscheidung', [PruefungController::class, 'entscheidung']);
});
