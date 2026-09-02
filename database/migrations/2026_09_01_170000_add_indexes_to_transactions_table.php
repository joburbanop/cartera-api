<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('transaction_date', 'transactions_transaction_date_index');
            $table->index('created_at', 'transactions_created_at_index');
            $table->index(['contract_id', 'created_at'], 'transactions_contract_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_transaction_date_index');
            $table->dropIndex('transactions_created_at_index');
            $table->dropIndex('transactions_contract_created_at_index');
        });
    }
};
