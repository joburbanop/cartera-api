<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
//tabla para llevar o registrar los proyectos, para poder llevar un control de los movimientos de caja y poder generar reportes de flujo de caja por proyecto
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique(); // Nombre del proyecto, único
            $table->text('description')->nullable(); // descripcion opcional del proyecto, pude incluir la etapa del proyecto o tipo de proyecto
            $table->string('location', 255); // Dirección física o zona geográfica de ubicación del proyecto
            $table->string('status', 50)->default('active'); // active, completed, inactive
            $table->softDeletes(); // deleted_at para eliminar proyectos sin perder información histórica
            $table->timestamps();//fecha de creación y actualización del proyecto
            $table->foreignId('created_by')->nullable()->constrained('users');// Relación con la tabla de usuarios para saber quién creó el proyecto
            $table->foreignId('updated_by')->nullable()->constrained('users');// Relación con la tabla de usuarios para saber quién actualizó el proyecto por última vez, puede ser nulo si no ha sido actualizado
            $table->foreignId('deleted_by')->nullable()->constrained('users'); // Quién archivó el proyecto

            });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};