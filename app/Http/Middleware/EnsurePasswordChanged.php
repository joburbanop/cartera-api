<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes cambiar tu contraseña antes de continuar.',
                'errors' => [
                    'code' => 'password_change_required',
                ],
            ], 403);
        }

        return $next($request);
    }
};
