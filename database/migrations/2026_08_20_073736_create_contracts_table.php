<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 100)->unique(); // Número de promesa o radicado físico
            
            // Llaves foráneas obligatorias
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('lot_id')->constrained('lots')->onDelete('restrict');
            
            // El asesor simplificado como texto libre
            $table->string('seller_name', 150)->nullable(); // Lina, Andrés, Sofía
            
            // Condiciones económicas acordadas (Congeladas bajo firma)
            $table->decimal('sale_price', 15, 2);           // Precio de venta pactado real
            $table->decimal('down_payment_pactada', 15, 2); // Cuota inicial pactada
            $table->integer('term_months');                 // Plazo en meses (ej: 36, 48, 60)
            $table->decimal('interest_rate', 5, 2)->default(1.00); // Tasa mensual pactada (Default 1.00%)
            $table->date('start_date');                     // Fecha de firma o inicio de abonos
            
            // Estado de control del ciclo de vida
            $table->string('status', 50)->default('preventa_inactiva'); // preventa_inactiva, activo, terminado...
            
            // Auditoría de Seguridad
            $table->softDeletes();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('deleted_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};