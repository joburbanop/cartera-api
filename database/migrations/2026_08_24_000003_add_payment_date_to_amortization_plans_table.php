<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amortization_plans', function (Blueprint $table) {
            $table->date('payment_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('amortization_plans', function (Blueprint $table) {
            $table->dropColumn('payment_date');
        });
    }
};
