<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('amortization_plan_transaction');
    }

    public function down(): void
    {
        // La pivote dependía de amortization_plans, que también fue eliminada; no se recrea.
    }
};
