<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amortization_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('set null');
            $table->integer('version_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('recalculation_type')->default('initial_projection');
            $table->timestamps();

            $table->unique(['contract_id', 'version_number', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amortization_versions');
    }
};
