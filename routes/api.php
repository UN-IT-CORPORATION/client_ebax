<?php

use App\Http\Controllers\ClientController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Liste des clients paginée par 500
Route::get('/clients', [ClientController::class, 'index']);

// Liste des doublons dans la base de données clients
Route::get('/clients/duplicates', [ClientController::class, 'duplicates']);
