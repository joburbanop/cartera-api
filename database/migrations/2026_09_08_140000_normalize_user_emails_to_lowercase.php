<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $collisions = DB::table('users')
            ->selectRaw('LOWER(email) as normalized_email, COUNT(*) as total')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($collisions) {
            throw new RuntimeException(
                'Hay correos que colisionan al pasarlos a minúsculas. Resuélvelos antes de correr esta migración.'
            );
        }

        DB::table('users')
            ->whereRaw('email <> LOWER(email)')
            ->update(['email' => DB::raw('LOWER(email)')]);
    }

    public function down(): void
    {
        // Irreversible: no se puede reconstruir la capitalización original.
    }
};
