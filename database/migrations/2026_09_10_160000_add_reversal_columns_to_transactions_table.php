<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('payment_option');
            $table->foreignId('reversed_by')
                ->nullable()
                ->after('reversed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('reversal_transaction_id')
                ->nullable()
                ->after('reversed_by')
                ->constrained('transactions')
                ->nullOnDelete();
            $table->string('reversal_reason', 40)->nullable()->after('reversal_transaction_id');
            $table->text('reversal_notes')->nullable()->after('reversal_reason');

            $table->index(['contract_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'reversed_at']);
            $table->dropConstrainedForeignId('reversal_transaction_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason', 'reversal_notes']);
        });
    }
};
