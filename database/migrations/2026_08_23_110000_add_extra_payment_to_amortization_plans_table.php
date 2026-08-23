<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('amortization_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('amortization_plans', 'extra_payment')) {
                $table->decimal('extra_payment', 15, 2)->default(0)->after('interest_value');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amortization_plans', function (Blueprint $table) {
            if (Schema::hasColumn('amortization_plans', 'extra_payment')) {
                $table->dropColumn('extra_payment');
            }
        });
    }
};
