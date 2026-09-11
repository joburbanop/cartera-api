<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_residual_balances', function (Blueprint $table) {
            $table->foreignId('last_partial_transaction_id')
                ->nullable()
                ->after('collected_at')
                ->constrained('transactions')
                ->nullOnDelete();
            $table->decimal('last_partial_amount', 15, 2)
                ->nullable()
                ->after('last_partial_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_residual_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_partial_transaction_id');
            $table->dropColumn('last_partial_amount');
        });
    }
};
