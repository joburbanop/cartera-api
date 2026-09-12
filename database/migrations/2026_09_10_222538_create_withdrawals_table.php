<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            // Contrato relacionado
            $table->foreignId('contract_id')
                ->constrained('contracts')
                ->restrictOnDelete();

            // Tipo y solicitud
            $table->string('type', 30);
            $table->date('request_date');
            $table->string('cause', 50);
            $table->text('observations')->nullable();

            // Valores utilizados en la liquidación
            $table->decimal('sale_price', 15, 2);
            $table->decimal('contributions', 15, 2);
            $table->decimal('standard_retention_percentage', 5, 2);
            $table->decimal('authorized_retention_percentage', 5, 2);
            $table->decimal('penalty_amount', 15, 2);
            $table->decimal('refund_balance', 15, 2);

            // Excepción / modificación
            $table->text('modification_justification')->nullable();

            // Autorización
            $table->foreignId('authorized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('authorized_at')->nullable();

            // Estado
            $table->string('status', 50);

            // Auditoría de creación / modificación
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};