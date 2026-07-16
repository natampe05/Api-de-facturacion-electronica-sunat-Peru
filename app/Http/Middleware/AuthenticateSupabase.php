<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSupabase
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authenticateInternalService($request)) {
            return $next($request);
        }

        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $supabaseKey = (string) config('services.supabase.anon_key');
        if ($supabaseUrl === '' || $supabaseKey === '') {
            report(new \RuntimeException('SUPABASE_URL y SUPABASE_ANON_KEY son obligatorios en el API SUNAT.'));
            return response()->json(['message' => 'El servicio de autenticación no está configurado.'], 503);
        }

        try {
            $authResponse = Http::acceptJson()
                ->withHeaders(['apikey' => $supabaseKey])
                ->withToken($token)
                ->timeout(8)
                ->get("{$supabaseUrl}/auth/v1/user");
        } catch (ConnectionException $exception) {
            report($exception);
            return response()->json(['message' => 'No se pudo validar la sesión.'], 503);
        }

        if (!$authResponse->successful() || !$authResponse->json('id')) {
            return response()->json(['message' => 'Sesión inválida o vencida.'], 401);
        }

        $authUserId = (string) $authResponse->json('id');
        $superAdmin = DB::table('super_admins')
            ->where('auth_user_id', $authUserId)
            ->exists();

        $profile = DB::table('perfiles_empleados')
            ->select('id', 'empresa_id', 'sucursal_id', 'rol', 'activo')
            ->where('auth_user_id', $authUserId)
            ->where('activo', true)
            ->first();

        if (!$superAdmin && !$profile) {
            return response()->json(['message' => 'La cuenta no tiene un perfil activo.'], 403);
        }

        $request->attributes->set('sunat_auth_user_id', $authUserId);
        $request->attributes->set('sunat_profile_id', $profile?->id);
        $request->attributes->set('sunat_empresa_id', $profile?->empresa_id);
        $request->attributes->set('sunat_sucursal_id', $profile?->sucursal_id);
        $request->attributes->set('sunat_role', strtolower((string) ($profile?->rol ?? 'superadmin')));
        $request->attributes->set('sunat_super_admin', $superAdmin);
        $request->attributes->set('sunat_internal_service', false);

        return $next($request);
    }

    private function authenticateInternalService(Request $request): bool
    {
        $configuredKey = (string) config('services.sunat.internal_api_key');
        $providedKey = (string) $request->header('X-Sunat-Internal-Key', '');

        if ($configuredKey === '' || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
            return false;
        }

        $request->attributes->set('sunat_auth_user_id', null);
        $request->attributes->set('sunat_profile_id', null);
        $request->attributes->set('sunat_empresa_id', null);
        $request->attributes->set('sunat_sucursal_id', null);
        $request->attributes->set('sunat_role', 'internal');
        $request->attributes->set('sunat_super_admin', true);
        $request->attributes->set('sunat_internal_service', true);

        return true;
    }
}
