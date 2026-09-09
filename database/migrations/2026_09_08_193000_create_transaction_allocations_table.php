<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reparto interno de una transacción. El banco ve un solo movimiento por el
 * total de la transacción; estas filas cuentan a dónde fue cada peso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            // 'down_payment', 'installment' o 'capital'.
            $table->string('target', 20);
            $table->foreignId('amortization_installment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('principal', 15, 2)->default(0.00);
            $table->decimal('interest', 15, 2)->default(0.00);
            $table->timestamps();

            $table->index(['transaction_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_allocations');
    }
};
