<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo pago → cuota pactada del cronograma comercial (lotes 6 y 45).
 * Es un ledger paralelo al de amortización: no forma parte de la suma
 * banco = transaction_allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_promise_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_promise_id')
                ->constrained('contract_payment_promises')
                ->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['payment_promise_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promise_allocations');
    }
};
