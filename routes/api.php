<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Inventory\ProjectController; // <-- Apunta a la subcarpeta
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\CRM\CustomerController;
use App\Http\Controllers\Financial\BankAccountController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//ruta para crear un proyecto inmobiliario
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects', [ProjectController::class, 'index']);

//ruta para crear un lote
Route::post('/lots', [LotController::class, 'store']);
Route::get('/lots', [LotController::class, 'index']);


// Rutas de CRM (Gestión de Clientes)
Route::post('/customers', [CustomerController::class, 'store']);


// Rutas Financieras
Route::post('/bank-accounts', [BankAccountController::class, 'store']);
Route::get('/bank-accounts', [BankAccountController::class, 'index']);