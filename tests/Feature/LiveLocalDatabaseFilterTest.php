<?php

use App\Services\Inventory\LotService;
use App\Services\Sales\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses()->group('live-db');

function connectLocalPostgres(): void
{
    if (collect(class_uses_recursive(test()))->contains(RefreshDatabase::class)) {
        throw new RuntimeException('Estos tests no pueden usar RefreshDatabase: tocarían cartera_db.');
    }

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql.driver' => 'pgsql',
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => '5432',
        'database.connections.pgsql.database' => 'cartera_db',
        'database.connections.pgsql.username' => 'postgres',
        'database.connections.pgsql.password' => '',
        'database.connections.pgsql.charset' => 'utf8',
        'database.connections.pgsql.prefix' => '',
        'database.connections.pgsql.search_path' => 'public',
        'database.connections.pgsql.sslmode' => 'prefer',
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');
    DB::setDefaultConnection('pgsql');
}

function postgresSqlLog(callable $callback): array
{
    $connection = DB::connection('pgsql');
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $callback();
    $sqls = array_column($connection->getQueryLog(), 'query');
    $connection->disableQueryLog();

    return $sqls;
}

beforeEach(function () {
    connectLocalPostgres();

    try {
        DB::connection('pgsql')->select('select 1');
    } catch (Throwable $e) {
        test()->markTestSkipped('Postgres local no disponible: '.$e->getMessage());
    }

    if ((int) DB::table('contracts')->whereNull('deleted_at')->count() < 1) {
        test()->markTestSkipped('La base local no tiene contratos.');
    }
});

afterEach(function () {
    DB::purge('pgsql');
    config(['database.default' => 'sqlite']);
    DB::setDefaultConnection('sqlite');
});

it('contrato: lot_number consulta lots.number contra postgres', function () {
    $sqls = [];
    $page = null;

    $sqls = postgresSqlLog(function () use (&$page) {
        $page = app(ContractService::class)->getAllContracts(50, null, [
            'lot_number' => '1',
        ]);
    });

    $blob = implode("\n", $sqls);

    expect($page->total())->toBe(14)
        ->and($blob)->toContain('"lots"."number"')
        ->and($blob)->not->toContain('lot_number');

    foreach ($page as $contract) {
        expect((string) $contract->lot->number)->toContain('1');
    }
});

it('contrato: contract_number contra postgres', function () {
    $page = app(ContractService::class)->getAllContracts(10, null, [
        'contract_number' => 'SM-LOTE-51',
    ]);

    expect($page->total())->toBe(1)
        ->and($page->first()->contract_number)->toBe('SM-LOTE-51');
});

it('contrato: customer contra postgres', function () {
    $page = app(ContractService::class)->getAllContracts(10, null, [
        'customer' => '80260496',
    ]);

    expect($page->total())->toBe(1)
        ->and($page->first()->customer->document_number)->toBe('80260496');
});

it('contrato: project_id contra postgres', function () {
    $page = app(ContractService::class)->getAllContracts(100, null, [
        'project_id' => 2,
    ]);

    expect($page->total())->toBe(55);
});

it('contrato: status contra postgres', function () {
    $page = app(ContractService::class)->getAllContracts(100, null, [
        'status' => 'activo',
    ]);

    expect($page->total())->toBe(45);
});

it('contrato: cartera mora y al_dia contra postgres', function () {
    $mora = app(ContractService::class)->getAllContracts(100, null, [
        'cartera' => 'mora',
    ]);
    $ok = app(ContractService::class)->getAllContracts(100, null, [
        'cartera' => 'al_dia',
    ]);

    expect($mora->total())->toBe(39)
        ->and($ok->total())->toBe(16)
        ->and($mora->total() + $ok->total())->toBe(55);
});

it('contrato: rango de fechas contra postgres', function () {
    $page = app(ContractService::class)->getAllContracts(10, null, [
        'start_date_from' => '2026-06-01',
        'start_date_to' => '2026-06-30',
    ]);

    expect($page->total())->toBe(1)
        ->and($page->first()->contract_number)->toBe('SM-LOTE-1');
});

it('lote: number contra postgres', function () {
    $page = app(LotService::class)->getAllLots(null, 50, ['number' => '1']);
    expect($page->total())->toBe(14);
});

it('lote: status contra postgres', function () {
    $page = app(LotService::class)->getAllLots(null, 100, ['status' => 'vendido']);
    expect($page->total())->toBe(45);
});

it('lote: project_id contra postgres', function () {
    $page = app(LotService::class)->getAllLots(null, 100, ['project_id' => 2]);
    expect($page->total())->toBe(55);
});

it('lote: plan_type contra postgres', function () {
    $lots = app(LotService::class);

    expect($lots->getAllLots(null, 100, ['plan_type' => 'special'])->total())->toBe(8)
        ->and($lots->getAllLots(null, 100, ['plan_type' => 'custom'])->total())->toBe(2)
        ->and($lots->getAllLots(null, 100, ['plan_type' => 'standard'])->total())->toBe(45)
        ->and($lots->getAllLots(null, 100, ['plan_type' => 'none'])->total())->toBe(0);
});

it('lote: cartera contra postgres', function () {
    $lots = app(LotService::class);

    expect($lots->getAllLots(null, 100, ['cartera' => 'mora'])->total())->toBe(39)
        ->and($lots->getAllLots(null, 100, ['cartera' => 'al_dia'])->total())->toBe(16);
});

it('lote: customer contra postgres', function () {
    $page = app(LotService::class)->getAllLots(null, 10, ['customer' => '80260496']);
    expect($page->total())->toBe(1);
});
