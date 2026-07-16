<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSunatTenant
{
    public function handle(Request $request, Closure $next, string $permission = 'operate'): Response
    {
        if ($request->attributes->get('sunat_super_admin') === true) {
            return $next($request);
        }

        $role = strtolower((string) $request->attributes->get('sunat_role'));
        $allowedRoles = match ($permission) {
            'void', 'summary' => ['admin', 'cajero'],
            'read', 'operate' => ['admin', 'cajero', 'mesero'],
            default => ['admin'],
        };

        if (!in_array($role, $allowedRoles, true)) {
            return response()->json(['message' => 'No tienes permisos para esta operación.'], 403);
        }

        $orderId = $request->input('orden_id') ?: $request->route('id');
        $requestedEmpresaId = $request->input('empresa_id');
        $order = null;

        if ($orderId) {
            $order = DB::table('ordenes')
                ->select('id', 'empresa_id', 'sucursal_id')
                ->where('id', $orderId)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Orden no encontrada.'], 404);
            }

            $requestedEmpresaId = $order->empresa_id;
            $request->attributes->set('sunat_order', $order);
        }

        if (!$requestedEmpresaId) {
            return response()->json(['message' => 'No se pudo determinar la empresa de la operación.'], 422);
        }

        $profileEmpresaId = (string) $request->attributes->get('sunat_empresa_id');
        if ($profileEmpresaId === '' || !hash_equals($profileEmpresaId, (string) $requestedEmpresaId)) {
            return response()->json(['message' => 'La operación no pertenece a tu empresa.'], 403);
        }

        $profileSucursalId = (string) $request->attributes->get('sunat_sucursal_id');
        if ($order && $profileSucursalId !== '' && $order->sucursal_id && !hash_equals($profileSucursalId, (string) $order->sucursal_id)) {
            return response()->json(['message' => 'La operación no pertenece a tu sucursal.'], 403);
        }

        return $next($request);
    }
}
