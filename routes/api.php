<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Inventory\ProjectController; // <-- Apunta a la subcarpeta
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\CRM\CustomerController;
use App\Http\Controllers\Financial\BankAccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Sales\ContractController;
use App\Http\Controllers\Sales\AmortizationController;
use App\Http\Controllers\Collection\CollectionController;
use App\Http\Controllers\TransactionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// RUTAS PÚBLICAS (No necesitan Token)
//Route::post('/users', [UserController::class, 'store']); // Crear usuario
Route::post('/login', [AuthController::class, 'login']); // Iniciar sesión





// RUTAS PROTEGIDAS (Exigen Token)
Route::middleware('auth:sanctum')->group(function () {
    
    // CRM
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);

    //ruta para crear un proyecto inmobiliario
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects', [ProjectController::class, 'index']);

    //ruta para crear un lote
    Route::post('/lots', [LotController::class, 'store']);
    Route::get('/lots', [LotController::class, 'index']);


    // Rutas de CRM (Gestión de Clientes)
    //Route::post('/customers', [CustomerController::class, 'store']);


    // Rutas Financieras
    Route::post('/bank-accounts', [BankAccountController::class, 'store']);
    Route::get('/bank-accounts', [BankAccountController::class, 'index']);

    Route::post('/contracts/{contractId}/transactions', [TransactionController::class, 'store']);
    Route::post('/contracts/{contractId}/transactions/down-payment', [TransactionController::class, 'store']);

    // VENTAS / CONTRATOS 
    Route::post('/contracts', [ContractController::class, 'store']);
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::get('/contracts/{contract}', [ContractController::class, 'show']);

    // AMORTIZACIONES
    Route::post('/contracts/{contract}/generate-amortization', [AmortizationController::class, 'generate']);
    Route::get('/contracts/{contract}/amortization', [AmortizationController::class, 'show']);
    Route::get('/contracts/{contract}/download-pdf', [AmortizationController::class, 'downloadPdf']);

    Route::post('/collections/cascade', [CollectionController::class, 'store']);
});