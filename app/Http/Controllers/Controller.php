<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function authenticatedUserId(): int
    {
        $userId = auth()->id();
        if (! $userId) {
            abort(401, 'No autenticado.');
        }

        return (int) $userId;
    }
}
