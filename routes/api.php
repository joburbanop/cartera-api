<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Inventory\ProjectController; // <-- Apunta a la subcarpeta

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
//ruta para crear un proyecto inmobiliario
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects', [ProjectController::class, 'index']);