<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amortization_version_id')->constrained('amortization_versions')->onDelete('cascade');
            $table->integer('installment_number');
            $table->date('due_date');
            $table->string('receipt_number')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->decimal('installment_value', 15, 2);
            $table->decimal('extra_payment', 15, 2)->default(0.00);
            $table->decimal('interest_value', 15, 2);
            $table->decimal('principal_value', 15, 2);
            $table->decimal('quota_debt', 15, 2)->default(0.00);
            $table->decimal('remaining_balance', 15, 2);
            $table->decimal('projected_balance', 15, 2);
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_installments');
    }
};
