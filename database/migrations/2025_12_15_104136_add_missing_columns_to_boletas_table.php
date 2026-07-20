<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('boletas', 'metodo_envio')) {
            Schema::table('boletas', function (Blueprint $table) {
                $table->string('metodo_envio', 20)->default('individual')->after('moneda');
            });
        }

        if (! Schema::hasColumn('boletas', 'daily_summary_id')) {
            Schema::table('boletas', function (Blueprint $table) {
                $table->unsignedBigInteger('daily_summary_id')->nullable()->after('client_id')->index();
            });
        }

        // Las columnas tributarias existen en migraciones anteriores. Estas
        // comprobaciones solo cubren bases históricas incompletas.
        if (! Schema::hasColumn('boletas', 'mto_igv_gratuitas')) {
            Schema::table('boletas', function (Blueprint $table) {
                $table->decimal('mto_igv_gratuitas', 12, 2)->default(0)->after('mto_oper_gratuitas');
            });
        }

        if (! Schema::hasColumn('boletas', 'mto_base_ivap')) {
            Schema::table('boletas', function (Blueprint $table) {
                $table->decimal('mto_base_ivap', 12, 2)->default(0)->after('mto_igv');
            });
        }

        if (! Schema::hasColumn('boletas', 'mto_ivap')) {
            Schema::table('boletas', function (Blueprint $table) {
                $table->decimal('mto_ivap', 12, 2)->default(0)->after('mto_base_ivap');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropColumn([
                'metodo_envio',
                'daily_summary_id',
            ]);
        });
    }
};
