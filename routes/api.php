<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Collection\CollectionController;
use App\Http\Controllers\ContractPaymentPromiseController;
use App\Http\Controllers\CRM\CustomerController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Financial\BankAccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\Inventory\ProjectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Sales\AmortizationController;
use App\Http\Controllers\Sales\ContractController;
use App\Http\Controllers\Sales\RefinanceContractController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/search', [SearchController::class, 'index']);
    Route::get('/activity', [ActivityController::class, 'index'])
        ->middleware('permission:bitacora.view');

    Route::get('/dashboard/cartera-mora', [DashboardController::class, 'carteraMora'])
        ->middleware('permission:amortization.view|contracts.manage');
    Route::get('/dashboard/recaudo-reciente', [DashboardController::class, 'recaudoReciente'])
        ->middleware('permission:transactions.view|payments.register');
    Route::get('/dashboard/proximos-vencimientos', [DashboardController::class, 'proximosVencimientos'])
        ->middleware('permission:amortization.view|contracts.manage');
    Route::get('/dashboard/actividad-reciente', [DashboardController::class, 'actividadReciente'])
        ->middleware('permission:contracts.view|contracts.manage');
    Route::get('/dashboard/clientes-totales', [DashboardController::class, 'clientesTotales'])
        ->middleware('permission:contracts.view|contracts.manage');
    Route::get('/dashboard/proyectos-activos', [DashboardController::class, 'proyectosActivos'])
        ->middleware('permission:projects.view|projects.manage');
    Route::get('/dashboard/recaudo-mensual', [DashboardController::class, 'recaudoMensual'])
        ->middleware('permission:transactions.view|payments.register');
    Route::get('/dashboard/cartera-vencida-resumen', [DashboardController::class, 'carteraVencidaResumen'])
        ->middleware('permission:amortization.view|contracts.manage');
    Route::get('/dashboard/contratos-por-estado', [DashboardController::class, 'contratosPorEstado'])
        ->middleware('permission:contracts.view|contracts.manage');
    Route::get('/dashboard/lotes-por-estado', [DashboardController::class, 'lotesPorEstado'])
        ->middleware('permission:lots.view|lots.manage');

    Route::get('/projects', [ProjectController::class, 'index'])
        ->middleware('permission:projects.view|projects.manage');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware('permission:projects.manage');

    Route::get('/lots', [LotController::class, 'index'])
        ->middleware('permission:lots.view|lots.manage');
    Route::get('/lots/{lot}', [LotController::class, 'show'])
        ->middleware('permission:lots.view|lots.manage');
    Route::post('/lots', [LotController::class, 'store'])
        ->middleware('permission:lots.manage');

    Route::get('/contracts', [ContractController::class, 'index'])
        ->middleware('permission:contracts.view|contracts.manage');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])
        ->middleware('permission:contracts.view|contracts.manage');
    Route::post('/contracts', [ContractController::class, 'store'])
        ->middleware(['permission:contracts.manage', 'throttle:writes']);

    Route::get('/contracts/{contractId}/payment-promises', [ContractPaymentPromiseController::class, 'index'])
        ->middleware('permission:contracts.view|contracts.manage');
    Route::post('/contracts/{contractId}/payment-promises', [ContractPaymentPromiseController::class, 'store'])
        ->middleware('permission:contracts.manage');
    Route::patch('/contracts/{contract}/payment-promises/reorder', [ContractPaymentPromiseController::class, 'reorder'])
        ->middleware('permission:contracts.manage');

    Route::get('/contracts/{contract}/amortization', [AmortizationController::class, 'show'])
        ->middleware('permission:amortization.view|contracts.manage');
    Route::get('/contracts/{contract}/download-pdf', [AmortizationController::class, 'downloadPdf'])
        ->middleware('permission:amortization.view|contracts.manage');
    Route::post('/contracts/{contract}/generate-amortization', [AmortizationController::class, 'generate'])
        ->middleware('permission:contracts.manage');
    Route::patch('/contracts/{contract}/installments/{installment}/due-date', [AmortizationController::class, 'updateInstallmentDueDate'])
        ->middleware('permission:contracts.manage');
    Route::post('/contracts/{contract}/refinance', [RefinanceContractController::class, 'store'])
        ->middleware(['permission:contracts.refinance', 'throttle:writes']);

    Route::get('/transactions', [TransactionController::class, 'index'])
        ->middleware('permission:transactions.view|payments.register');
    Route::get('/contracts/{contractId}/transactions', [TransactionController::class, 'indexByContract'])
        ->middleware('permission:transactions.view|payments.register');
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt'])
        ->name('transactions.receipt')
        ->middleware('permission:transactions.view|payments.register');
    Route::post('/contracts/{contractId}/transactions', [TransactionController::class, 'store'])
        ->middleware(['permission:payments.register', 'throttle:writes']);
    Route::post('/contracts/{contractId}/transactions/down-payment', [TransactionController::class, 'store'])
        ->middleware(['permission:payments.register', 'throttle:writes']);

    Route::post('/collections/cascade', [CollectionController::class, 'store'])
        ->middleware(['permission:payments.register|extraordinary-payments.apply', 'throttle:writes']);

    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('permission:customers.manage');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:customers.manage');
    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.manage');

    Route::get('/bank-accounts', [BankAccountController::class, 'index'])
        ->middleware('permission:bank-accounts.manage');
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])
        ->middleware('permission:bank-accounts.manage');

    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.manage');
    Route::post('/users', [UserController::class, 'store'])
        ->middleware(['permission:users.manage', 'throttle:writes']);
    Route::put('/users/{user}', [UserController::class, 'update'])
        ->middleware(['permission:users.manage', 'throttle:writes']);
    Route::patch('/users/{user}', [UserController::class, 'update'])
        ->middleware(['permission:users.manage', 'throttle:writes']);
    Route::put('/users/{user}/role', [UserController::class, 'assignRole'])
        ->middleware(['permission:users.manage', 'throttle:writes']);
    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware(['permission:users.manage', 'throttle:writes']);
});
