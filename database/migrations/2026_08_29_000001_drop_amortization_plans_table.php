<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('amortization_plans');
    }

    public function down(): void
    {
        // El plan versionado fue reemplazado por amortization_installments; no se recrea.
    }
};
