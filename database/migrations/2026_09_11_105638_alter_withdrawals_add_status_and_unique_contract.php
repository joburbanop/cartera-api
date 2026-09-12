<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('status', 50)
                ->default('pending')
                ->change();

            $table->unique('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropUnique(['contract_id']);

            $table->string('status', 50)
                ->default(null)
                ->change();
        });
    }
};