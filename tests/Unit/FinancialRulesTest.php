use App\Support\FinancialRules;

it('las constantes de negocio coinciden con el fixture dorado', function () {
    $path = dirname(__DIR__).'/fixtures/golden/financial-rules.json';
    $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect(FinancialRules::QUOTA_COMPLETION_RESIDUAL)->toBe($json['quota_completion_residual'])
        ->and(FinancialRules::ABSORBED_SURPLUS)->toBe($json['absorbed_surplus'])
        ->and(FinancialRules::IMPUTATION_DUST)->toBe($json['imputation_dust'])
        ->and($json['pmt_rounding'])->toBe('half_up_2');
});

it('redondea half-up a 2 decimales', function () {
    expect(FinancialRules::roundHalfUp2('33.995'))->toBe('34.00')
        ->and(FinancialRules::roundHalfUp2('1.225'))->toBe('1.23')
        ->and(FinancialRules::roundHalfUp2('-1.225'))->toBe('-1.23');
});

it('clasifica residual, excedente absorbible y polvo de imputación', function () {
    expect(FinancialRules::residualIsWithinCompletionTolerance('499.99'))->toBeTrue()
        ->and(FinancialRules::residualIsWithinCompletionTolerance('500.00'))->toBeFalse()
        ->and(FinancialRules::leftoverExceedsAbsorbedSurplus('2.00'))->toBeFalse()
        ->and(FinancialRules::leftoverExceedsAbsorbedSurplus('2.01'))->toBeTrue()
        ->and(FinancialRules::isImputationDust('0.50'))->toBeTrue()
        ->and(FinancialRules::isImputationDust('1.00'))->toBeFalse()
        ->and(FinancialRules::isImputationDust('0.00'))->toBeFalse();
});
