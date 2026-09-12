<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Residuales menores condonados al cerrar una cuota (Regla 2).
 * Una fila por residual detectado; el acumulado es siempre SUM(pendiente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_residual_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amortization_installment_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('amortization_installment_id');
            $table->index(['contract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_residual_balances');
    }
};
