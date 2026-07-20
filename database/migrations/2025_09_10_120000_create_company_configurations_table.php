<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            // Tipos de configuración utilizados por la aplicación
            $table->enum('config_type', [
                'sunat_credentials',      // Credenciales de acceso a SUNAT
                'service_endpoints',      // Endpoints por servicio y ambiente
                'tax_settings',            // Configuraciones de impuestos (IGV, ICBPER, etc.)
                'invoice_settings',        // Configuraciones específicas de facturación
                'gre_settings',            // Configuraciones específicas de guías de remisión
                'file_settings',           // Configuraciones de almacenamiento de archivos
                'document_settings',       // Configuraciones de documentos (PDF, XML)
                'summary_settings',        // Configuraciones de resúmenes diarios
                'void_settings',           // Configuraciones de comunicaciones de baja
                'notification_settings',   // Configuraciones de notificaciones
                'security_settings',       // Configuraciones de seguridad
            ]);

            // Ambiente al que aplica la configuración
            $table->enum('environment', [
                'general',      // Aplica a todos los ambientes
                'beta',         // Solo ambiente de pruebas
                'produccion',    // Solo ambiente de producción
            ])->default('general');

            // Servicio específico (para credenciales)
            $table->enum('service_type', [
                'general',              // Configuración general
                'facturacion',          // Facturas, boletas, notas
                'guias_remision',       // Guías de remisión electrónica
                'resumenes_diarios',    // Resúmenes diarios
                'comunicaciones_baja',  // Comunicaciones de baja
                'retenciones',           // Retenciones
            ])->nullable();

            // Datos de configuración en formato JSON
            $table->json('config_data');

            // Configuración activa
            $table->boolean('is_active')->default(true);

            // Descripción opcional de la configuración
            $table->string('description')->nullable();

            // Orden de prioridad (para configuraciones conflictivas)
            $table->integer('priority')->default(0);

            $table->timestamps();

            // Índices para optimizar consultas
            $table->index(['company_id', 'config_type']);
            $table->index(['company_id', 'environment']);
            $table->index(['company_id', 'config_type', 'environment']);
            $table->index(['company_id', 'service_type', 'environment']);

            // Constraint único para evitar duplicados de configuración
            $table->unique(['company_id', 'config_type', 'environment', 'service_type'], 'unique_company_config');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_configurations');
    }
};
