<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
//tabla para llevar o registrar las cuentas bancarias de los proyectos, para poder llevar un control de los movimientos de caja y poder generar reportes de flujo de caja por proyecto
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_number')->unique(); // Evita registrar la misma cuenta dos veces
            $table->string('account_type')->nullable(); // Ej: Ahorros, Corriente, Encargo Fiduciario
            $table->boolean('is_active')->default(true);
            $table->string('holder_name', 150);       // Titular (ej: Constructora San Miguel)
            
            $table->timestamps();//fecha de creación y actualización de la cuenta bancaria
            $table->softDeletes(); // deleted_at para archivar cuentas sin perder el histórico de caja
            $table->foreignId('created_by')->nullable()->constrained('users');// Relación con la tabla de usuarios para saber quién creó la cuenta bancaria
            $table->foreignId('updated_by')->nullable()->constrained('users');// Relación con la tabla de usuarios para saber quién actualizó la cuenta bancaria por última vez, puede ser nulo si no ha sido actualizado

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};