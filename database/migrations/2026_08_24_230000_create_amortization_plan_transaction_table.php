<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_plan_transaction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amortization_plan_id')->constrained('amortization_plans')->onDelete('cascade');
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->decimal('amount_applied', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_plan_transaction');
    }
};
