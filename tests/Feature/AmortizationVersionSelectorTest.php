<?php

use App\Models\AmortizationVersion;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('returns all amortization versions for a contract in ascending order', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('document')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->timestamps();
    });

    Schema::create('lots', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->nullable();
        $table->timestamps();
    });

    Schema::create('contracts', function (Blueprint $table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('customer_id');
        $table->unsignedBigInteger('lot_id');
        $table->string('seller_name')->nullable();
        $table->decimal('sale_price', 15, 2);
        $table->decimal('down_payment_pactada', 15, 2);
        $table->integer('term_months');
        $table->decimal('interest_rate', 5, 2)->default(1.00);
        $table->date('start_date');
        $table->date('initial_payment_date');
        $table->date('first_installment_date');
        $table->date('regular_payment_start_date');
        $table->integer('preventa_installments_count')->default(0);
        $table->string('status')->default('preventa_inactiva');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('amortization_versions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
        $table->foreignId('transaction_id')->nullable();
        $table->integer('version_number')->default(1);
        $table->boolean('is_active')->default(true);
        $table->string('recalculation_type')->default('initial_projection');
        $table->timestamps();
    });

    $user = User::create([
        'name' => 'Test User',
        'email' => 'selector@example.com',
        'password' => bcrypt('secret'),
    ]);

    $customerId = DB::table('customers')->insertGetId([
        'name' => 'Cliente selector',
        'document' => '13579246',
        'email' => 'selector@example.com',
        'phone' => '3000000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lotId = DB::table('lots')->insertGetId([
        'name' => 'LOT-VERS-1',
        'status' => 'disponible',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contract = Contract::create([
        'contract_number' => 'CTR-VERS-001',
        'customer_id' => $customerId,
        'lot_id' => $lotId,
        'seller_name' => 'Ana',
        'sale_price' => 1000000.00,
        'down_payment_pactada' => 200000.00,
        'term_months' => 12,
        'interest_rate' => 1.00,
        'start_date' => '2026-08-01',
        'initial_payment_date' => '2026-08-10',
        'first_installment_date' => '2026-09-15',
        'regular_payment_start_date' => '2026-09-15',
        'preventa_installments_count' => 0,
        'status' => 'activo',
    ]);

    AmortizationVersion::create([
        'contract_id' => $contract->id,
        'version_number' => 1,
        'is_active' => true,
        'recalculation_type' => 'initial_projection',
    ]);

    AmortizationVersion::create([
        'contract_id' => $contract->id,
        'version_number' => 2,
        'is_active' => false,
        'recalculation_type' => 'reduce_term',
    ]);

    $response = $this->actingAs($user)->getJson('/api/contracts/' . $contract->id . '/versions');

    $response->assertOk()
        ->assertJsonPath('0.version_number', 1)
        ->assertJsonPath('1.version_number', 2);
});
