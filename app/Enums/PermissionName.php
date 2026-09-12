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
    case BITACORA_VIEW = 'bitacora.view';
    /** Bitácora acotada a refinanciaciones de contrato (la ve el administrador). */
    case REFINANCINGS_VIEW = 'refinancings.view';

    case USERS_MANAGE = 'users.manage';
    /** Reservado: gestión de roles desde la UI (función planeada). */
    case ROLES_MANAGE = 'roles.manage';

    case PROJECTS_MANAGE = 'projects.manage';
    case LOTS_MANAGE = 'lots.manage';
    case CONTRACTS_MANAGE = 'contracts.manage';
    case CUSTOMERS_MANAGE = 'customers.manage';
    case BANK_ACCOUNTS_MANAGE = 'bank-accounts.manage';
    case PAYMENTS_REGISTER = 'payments.register';
    case PAYMENTS_REVERSE = 'payments.reverse';
    case EXTRAORDINARY_PAYMENTS_APPLY = 'extraordinary-payments.apply';
    case CONTRACTS_REFINANCE = 'contracts.refinance';
    /** Reservado: desistimiento de contrato (función planeada, UI «Próximamente»). */
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
            self::BITACORA_VIEW,
            self::REFINANCINGS_VIEW,
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
            self::PAYMENTS_REVERSE,
            self::EXTRAORDINARY_PAYMENTS_APPLY,
            self::CONTRACTS_REFINANCE,
            self::CONTRACTS_RESCIND,
            self::REFINANCINGS_VIEW,
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
