<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSunatSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('sunat_super_admin') !== true) {
            return response()->json(['message' => 'Solo un superadministrador puede realizar esta operación.'], 403);
        }

        return $next($request);
    }
}
