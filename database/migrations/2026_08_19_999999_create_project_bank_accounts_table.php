<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
//tabla pivote para relacionar las cuentas bancarias con los proyectos
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_project', function (Blueprint $table) {
            $table->id();
            
            // Conexiones con eliminación en cascada
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            // Para auditar cuándo se enlazó una cuenta a un proyecto
            $table->timestamps(); 
            $table->boolean('is_active')->default(true); // Activa/Inactiva para ESTE proyecto específico
            $table->boolean('suggest_in_promesa')->default(true); // ¿Se imprime en la promesa del proyecto?

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_project');
    }
};