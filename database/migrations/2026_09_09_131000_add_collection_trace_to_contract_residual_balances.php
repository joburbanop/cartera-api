<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad del cobro del acumulado: una transacción residual_collection
 * puede marcar varias filas (FIFO). La última puede quedar parcial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_residual_balances', function (Blueprint $table) {
            $table->foreignId('collected_transaction_id')
                ->nullable()
                ->after('status')
                ->constrained('transactions')
                ->nullOnDelete();
            $table->timestamp('collected_at')
                ->nullable()
                ->after('collected_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_residual_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_transaction_id');
            $table->dropColumn('collected_at');
        });
    }
};
