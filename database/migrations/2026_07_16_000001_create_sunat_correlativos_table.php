<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sunat_correlativos')) {
            $driver = DB::getDriverName();

            Schema::create('sunat_correlativos', function (Blueprint $table) use ($driver) {
                $id = $table->uuid('id')->primary();

                if ($driver === 'pgsql') {
                    $id->default(DB::raw('uuid_generate_v4()'));
                } elseif ($driver === 'mysql') {
                    $id->default(DB::raw('(UUID())'));
                } elseif ($driver === 'sqlite') {
                    // SQLite no incluye una función UUID nativa. El valor
                    // aleatorio conserva unicidad suficiente para CI.
                    $id->default(DB::raw('(lower(hex(randomblob(16))))'));
                }

                $table->uuid('empresa_id');
                $table->string('tipo_comprobante', 24);
                $table->string('serie', 16);
                $table->unsignedBigInteger('ultimo_numero')->default(0);
                $table->timestampsTz();
                $table->unique(
                    ['empresa_id', 'tipo_comprobante', 'serie'],
                    'sunat_correlativos_empresa_tipo_serie_unique'
                );
            });
        }

        // Some installations keep orders in Supabase while this Laravel
        // database only stores invoicing data. The backfill is optional, but
        // remains unchanged when the legacy table is present.
        if (Schema::hasTable('ordenes')) {
            DB::table('ordenes')
                ->select(
                    'empresa_id',
                    'tipo_comprobante',
                    'comprobante_serie',
                    DB::raw('MAX(comprobante_numero) as ultimo_numero')
                )
                ->whereNotNull('empresa_id')
                ->whereNotNull('comprobante_serie')
                ->whereNotNull('comprobante_numero')
                ->groupBy('empresa_id', 'tipo_comprobante', 'comprobante_serie')
                ->orderBy('empresa_id')
                ->each(function ($row) {
                    DB::table('sunat_correlativos')->updateOrInsert(
                        [
                            'empresa_id' => $row->empresa_id,
                            'tipo_comprobante' => $row->tipo_comprobante,
                            'serie' => $row->comprobante_serie,
                        ],
                        [
                            'ultimo_numero' => (int) $row->ultimo_numero,
                            'created_at' => now('UTC'),
                            'updated_at' => now('UTC'),
                        ]
                    );
                });

            if (! Schema::hasIndex('ordenes', 'ordenes_sunat_correlativo_unique')) {
                Schema::table('ordenes', function (Blueprint $table) {
                    $table->unique(
                        ['empresa_id', 'tipo_comprobante', 'comprobante_serie', 'comprobante_numero'],
                        'ordenes_sunat_correlativo_unique'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('ordenes')
            && Schema::hasIndex('ordenes', 'ordenes_sunat_correlativo_unique')
        ) {
            Schema::table('ordenes', function (Blueprint $table) {
                $table->dropUnique('ordenes_sunat_correlativo_unique');
            });
        }
        Schema::dropIfExists('sunat_correlativos');
    }
};
