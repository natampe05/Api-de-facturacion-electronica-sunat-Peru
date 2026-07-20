<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_boleta_columns_required_by_the_application_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('boletas', [
            'metodo_envio',
            'daily_summary_id',
            'mto_igv_gratuitas',
            'mto_base_ivap',
            'mto_ivap',
        ]));
    }

    public function test_configuration_reconciliation_preserves_existing_values(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'ruc' => '20123456789',
            'razon_social' => 'Empresa de prueba',
            'direccion' => 'Lima',
            'ubigeo' => '150101',
            'distrito' => 'Lima',
            'provincia' => 'Lima',
            'departamento' => 'Lima',
            'usuario_sol' => 'usuario',
            'clave_sol' => 'secreto',
            'endpoint_beta' => 'https://example.test/beta',
            'endpoint_produccion' => 'https://example.test/produccion',
            'modo_produccion' => false,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('company_configurations')->insert([
            [
                'company_id' => $companyId,
                'config_type' => 'sunat_credentials',
                'environment' => 'beta',
                'service_type' => 'facturacion',
                'config_data' => json_encode(['usuario_sol' => 'existente']),
                'is_active' => true,
                'description' => 'Credenciales existentes',
                'priority' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $companyId,
                'config_type' => 'tax_settings',
                'environment' => 'general',
                'service_type' => 'general',
                'config_data' => json_encode(['igv_porcentaje' => 10]),
                'is_active' => true,
                'description' => 'Impuesto personalizado',
                'priority' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path('migrations/2025_09_13_140000_clean_company_configurations.php');
        $migration->up();

        $this->assertDatabaseHas('company_configurations', [
            'company_id' => $companyId,
            'config_type' => 'sunat_credentials',
            'description' => 'Credenciales existentes',
        ]);
        $this->assertDatabaseHas('company_configurations', [
            'company_id' => $companyId,
            'config_type' => 'tax_settings',
            'description' => 'Impuesto personalizado',
            'priority' => 20,
        ]);
        $this->assertSame(
            5,
            DB::table('company_configurations')->where('company_id', $companyId)->count()
        );
    }
}
