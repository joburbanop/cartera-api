<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->index('customer_id', 'contracts_customer_id_index');
            $table->index('lot_id', 'contracts_lot_id_index');
            $table->index('status', 'contracts_status_index');
            $table->index(['customer_id', 'status'], 'contracts_customer_status_index');
            $table->index(['lot_id', 'status'], 'contracts_lot_status_index');
        });

        Schema::table('amortization_installments', function (Blueprint $table) {
            $table->index('contract_id', 'amortization_installments_contract_id_index');
            $table->index('status', 'amortization_installments_status_index');
            $table->index('due_date', 'amortization_installments_due_date_index');
            $table->index(['contract_id', 'status'], 'amortization_installments_contract_status_index');
            $table->index(['contract_id', 'due_date'], 'amortization_installments_contract_due_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amortization_installments', function (Blueprint $table) {
            $table->dropIndex('amortization_installments_contract_id_index');
            $table->dropIndex('amortization_installments_status_index');
            $table->dropIndex('amortization_installments_due_date_index');
            $table->dropIndex('amortization_installments_contract_status_index');
            $table->dropIndex('amortization_installments_contract_due_date_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_customer_id_index');
            $table->dropIndex('contracts_lot_id_index');
            $table->dropIndex('contracts_status_index');
            $table->dropIndex('contracts_customer_status_index');
            $table->dropIndex('contracts_lot_status_index');
        });
    }
};
