<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SunatSecretService
{
    public function forEmpresa(string $empresaId, object $legacyEmpresa): array
    {
        try {
            $secretRow = DB::selectOne(
                <<<'SQL'
                select
                  clave.decrypted_secret as sunat_clave_sol,
                  certificado.decrypted_secret as sunat_certificado_pem,
                  certificado_password.decrypted_secret as sunat_certificado_password,
                  client_secret.decrypted_secret as sunat_client_secret
                from private.sunat_secret_refs refs
                left join vault.decrypted_secrets clave
                  on clave.id = refs.clave_sol_secret_id
                left join vault.decrypted_secrets certificado
                  on certificado.id = refs.certificado_pem_secret_id
                left join vault.decrypted_secrets certificado_password
                  on certificado_password.id = refs.certificado_password_secret_id
                left join vault.decrypted_secrets client_secret
                  on client_secret.id = refs.client_secret_secret_id
                where refs.empresa_id = ?
                SQL,
                [$empresaId]
            );
        } catch (\Throwable $exception) {
            Log::warning('Vault SUNAT aún no está disponible; se usa compatibilidad temporal.', [
                'empresa_id' => $empresaId,
                'error' => $exception->getMessage(),
            ]);
            $secretRow = null;
        }

        return [
            'sunat_clave_sol' => $secretRow?->sunat_clave_sol ?? ($legacyEmpresa->sunat_clave_sol ?? null),
            'sunat_certificado_pem' => $secretRow?->sunat_certificado_pem ?? ($legacyEmpresa->sunat_certificado_pem ?? null),
            'sunat_certificado_password' => $secretRow?->sunat_certificado_password ?? ($legacyEmpresa->sunat_certificado_password ?? null),
            'sunat_client_secret' => $secretRow?->sunat_client_secret ?? ($legacyEmpresa->sunat_client_secret ?? null),
        ];
    }
}
