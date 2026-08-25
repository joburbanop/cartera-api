<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->decimal('area_m2', 15, 2)->change();
            $table->decimal('price_m2', 15, 2)->change();
            $table->decimal('list_price', 15, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->decimal('area_m2', 10, 2)->change();
            $table->decimal('price_m2', 10, 2)->change();
            $table->decimal('list_price', 10, 2)->change();
        });
    }
};
