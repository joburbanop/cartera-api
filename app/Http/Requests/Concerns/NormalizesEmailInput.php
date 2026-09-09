<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;

trait NormalizesEmailInput
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (! is_string($email)) {
            return;
        }

        $normalized = User::normalizeEmail($email);
        if ($normalized !== null) {
            $this->merge(['email' => $normalized]);
        }
    }
}
