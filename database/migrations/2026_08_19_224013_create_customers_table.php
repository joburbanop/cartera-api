<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            
            // Datos de identificación
            $table->string('document_type', 50)->default('CC');
            $table->string('document_number', 50)->unique(); 
            
            // Información personal y canales
            $table->string('name', 150);
            $table->string('phone', 50);
            
            // Ubicación y otros contactos
            $table->string('email', 150)->unique()->nullable(); 
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            
            // Auditoría y SoftDeletes
            $table->softDeletes(); 
            $table->timestamps();
            
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};