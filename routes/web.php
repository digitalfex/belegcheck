<?php

use App\Http\Controllers\PruefController;
use Illuminate\Support\Facades\Route;

// Prüf-Werkbank (Prototyp). Nur über SSH-Tunnel erreichbar, siehe docs/WERKBANK.md
Route::get('/', [PruefController::class, 'index']);
Route::post('/pruefen', [PruefController::class, 'datei']);
Route::post('/nachpruefen', [PruefController::class, 'nachpruefen']);
