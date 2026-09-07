<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('amortization_installments')
            ->where('installment_number', 0)
            ->where('status', 'partial')
            ->get(['id', 'principal_value', 'quota_debt', 'principal_paid']);

        foreach ($rows as $row) {
            $pactada = (string) ($row->principal_value ?? '0.00');
            $debt = (string) ($row->quota_debt ?? '0.00');
            $current = (string) ($row->principal_paid ?? '0.00');
            $expected = bcsub($pactada, $debt, 2);

            if (bccomp($expected, '0.00', 2) !== 1) {
                continue;
            }

            if (bccomp($current, $expected, 2) === 0) {
                continue;
            }

            DB::table('amortization_installments')
                ->where('id', $row->id)
                ->update(['principal_paid' => $expected]);
        }
    }

    public function down(): void
    {
        //
    }
};
