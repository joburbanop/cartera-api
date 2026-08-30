<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionName: string
{
    case PROJECTS_VIEW = 'projects.view';
    case LOTS_VIEW = 'lots.view';
    case CONTRACTS_VIEW = 'contracts.view';
    case AMORTIZATION_VIEW = 'amortization.view';
    case TRANSACTIONS_VIEW = 'transactions.view';

    case USERS_MANAGE = 'users.manage';
    case ROLES_MANAGE = 'roles.manage';

    case PROJECTS_MANAGE = 'projects.manage';
    case LOTS_MANAGE = 'lots.manage';
    case CONTRACTS_MANAGE = 'contracts.manage';
    case CUSTOMERS_MANAGE = 'customers.manage';
    case BANK_ACCOUNTS_MANAGE = 'bank-accounts.manage';
    case PAYMENTS_REGISTER = 'payments.register';
    case EXTRAORDINARY_PAYMENTS_APPLY = 'extraordinary-payments.apply';
    case CONTRACTS_REFINANCE = 'contracts.refinance';
    case CONTRACTS_RESCIND = 'contracts.rescind';

    /**
     * @return list<self>
     */
    public static function socioGerencia(): array
    {
        return [
            self::PROJECTS_VIEW,
            self::LOTS_VIEW,
            self::CONTRACTS_VIEW,
            self::AMORTIZATION_VIEW,
            self::TRANSACTIONS_VIEW,
        ];
    }

    /**
     * @return list<self>
     */
    public static function adminSistema(): array
    {
        return [
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
        ];
    }

    /**
     * @return list<self>
     */
    public static function administrador(): array
    {
        return [
            self::PROJECTS_MANAGE,
            self::LOTS_MANAGE,
            self::CONTRACTS_MANAGE,
            self::CUSTOMERS_MANAGE,
            self::BANK_ACCOUNTS_MANAGE,
            self::PAYMENTS_REGISTER,
            self::EXTRAORDINARY_PAYMENTS_APPLY,
            self::CONTRACTS_REFINANCE,
            self::CONTRACTS_RESCIND,
        ];
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
