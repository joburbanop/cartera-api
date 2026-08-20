<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            
            // Relación con el proyecto - Si se borra el proyecto, se bloquea si hay lotes asociados
            $table->foreignId('project_id')->constrained('projects')->onDelete('restrict');
            
            $table->string('number', 50); // Número de lote (ej: V3, 121, 33) [2, 5, 6]
            $table->decimal('area_m2', 10, 2); // Área superficial [5, 7, 9]
            $table->decimal('price_m2', 15, 2); // Valor m2 de referencia [7, 9]
            $table->decimal('list_price', 15, 2); // Precio de lista comercial vigente [7, 9, 10]
            
            // Estados controlados
            $table->string('status', 50)->default('disponible'); // disponible, preventa, vendido, abogado [1, 11]
            $table->string('type', 50)->default('residential'); // residential, commercial [13]
            
            // Información legal del lote (Nulable en preventa)
            $table->string('folio_matricula', 100)->unique()->nullable(); // [5, 6]
            $table->string('ficha_catastral', 100)->nullable(); // [5]
            
            // Linderos específicos para autogeneración de promesas [5, 6]
            $table->text('boundaries_north')->nullable();
            $table->text('boundaries_south')->nullable();
            $table->text('boundaries_east')->nullable();
            $table->text('boundaries_west')->nullable();
            
            // SoftDeletes y Auditoría (Módulo 1) [1, 16]
            $table->softDeletes(); 
            $table->timestamps();
            
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            
            // Validación única: Evita registrar el mismo número de lote dos veces en el mismo proyecto
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};