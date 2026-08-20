<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            
            $table->integer('version')->default(1);
            $table->integer('installment_number');
            $table->date('due_date');
            
            $table->decimal('installment_value', 15, 2);
            $table->decimal('principal_value', 15, 2);
            $table->decimal('interest_value', 15, 2);
            $table->decimal('remaining_balance', 15, 2);
            
            // Lo mantenemos como string por flexibilidad en DB, el Enum de PHP hará el trabajo duro
            $table->string('status', 50)->default('sin_pagar'); 
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_plans');
    }
};