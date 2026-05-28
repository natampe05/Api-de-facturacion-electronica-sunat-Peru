<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\BoletaController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\CompanyConfigController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CorrelativeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultaCpeController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\UbigeoController;
use App\Http\Controllers\Api\FacturacionController;

// ========================
// RUTAS PÚBLICAS (SIN AUTENTICACIÓN)
// ========================

// Facturación electrónica SUNAT
Route::post('/facturar', [FacturacionController::class, 'facturar']);

// Información del sistema
Route::get('/system/info', [AuthController::class, 'systemInfo']);
Route::get('/debug-env', function() {
    return [
        'DB_CONNECTION' => env('DB_CONNECTION'),
        'DB_HOST' => env('DB_HOST'),
        'DB_PORT' => env('DB_PORT'),
        'DB_DATABASE' => env('DB_DATABASE'),
        'DB_USERNAME' => env('DB_USERNAME'),
        'has_password' => !empty(env('DB_PASSWORD')),
    ];
});
Route::get('/test-db', function() {
    try {
        $count = DB::table('empresas')->count();
        return [
            'success' => true,
            'empresas_count' => $count,
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
});
Route::get('/test-cert', function() {
    try {
        $empresa = DB::table('empresas')->where('id', '2246b9a3-41fd-4e92-8124-11bea071dc77')->first();
        if (!$empresa) return ['error' => 'Empresa not found'];
        
        $pem = $empresa->sunat_certificado_pem;
        if (!$pem) return ['error' => 'Certificate pem is null'];
        
        $certificadoLimpio = trim($pem);
        $certificadoLimpio = str_replace(["\r\n", "\r"], "\n", $certificadoLimpio);
        $lines = explode("\n", $certificadoLimpio);
        $lines = array_map('trim', $lines);
        $certificadoLimpio = implode("\n", $lines);
        
        $hasPrivateKey = strpos($certificadoLimpio, '-----BEGIN PRIVATE KEY-----') !== false && 
                        strpos($certificadoLimpio, '-----END PRIVATE KEY-----') !== false;
        
        $hasCertificate = strpos($certificadoLimpio, '-----BEGIN CERTIFICATE-----') !== false && 
                         strpos($certificadoLimpio, '-----END CERTIFICATE-----') !== false;
        
        $hasRsaPrivateKey = strpos($certificadoLimpio, '-----BEGIN RSA PRIVATE KEY-----') !== false && 
                           strpos($certificadoLimpio, '-----END RSA PRIVATE KEY-----') !== false;
                           
        return [
            'has_private_key' => $hasPrivateKey,
            'has_certificate' => $hasCertificate,
            'has_rsa_private_key' => $hasRsaPrivateKey,
        ];
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
});

// Setup del sistema
Route::prefix('setup')->group(function () {
    Route::post('/migrate', [SetupController::class, 'migrate']);
    Route::post('/seed', [SetupController::class, 'seed']);
    Route::get('/status', [SetupController::class, 'status']);
});

// Inicialización del sistema
Route::post('/auth/initialize', [AuthController::class, 'initialize']);

// Autenticación
Route::post('/auth/login', [AuthController::class, 'login']);

// ========================
// RUTAS PROTEGIDAS (CON AUTENTICACIÓN)
// ========================
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // ========================
    // AUTENTICACIÓN Y USUARIO
    // ========================
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/create-user', [AuthController::class, 'createUser']);
    
    // Usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ========================
    // SETUP AVANZADO
    // ========================
    Route::prefix('setup')->group(function () {
        Route::post('/complete', [SetupController::class, 'setup']);
        Route::post('/configure-sunat', [SetupController::class, 'configureSunat']);
    });

    // ========================
    // GESTIÓN DE UBIGEOS
    // ========================
    Route::prefix('ubigeos')->group(function () {
        Route::get('/regiones', [UbigeoController::class, 'getRegiones']);
        Route::get('/provincias', [UbigeoController::class, 'getProvincias']);
        Route::get('/distritos', [UbigeoController::class, 'getDistritos']);
        Route::get('/search', [UbigeoController::class, 'searchUbigeo']);
        Route::get('/{id}', [UbigeoController::class, 'getUbigeoById']);
    });

    // ========================
    // EMPRESAS Y CONFIGURACIONES
    // ========================
    
    // Empresas
    Route::apiResource('companies', CompanyController::class);
    Route::post('/companies/{company}/activate', [CompanyController::class, 'activate']);
    Route::post('/companies/{company}/toggle-production', [CompanyController::class, 'toggleProductionMode']);

    // Configuraciones de empresas
    Route::prefix('companies/{company_id}/config')->group(function () {
        Route::get('/', [CompanyConfigController::class, 'show']);
        Route::get('/{section}', [CompanyConfigController::class, 'getSection']);
        Route::put('/{section}', [CompanyConfigController::class, 'updateSection']);
        Route::get('/validate/services', [CompanyConfigController::class, 'validateServices']);
        Route::post('/reset', [CompanyConfigController::class, 'resetToDefaults']);
        Route::post('/migrate', [CompanyConfigController::class, 'migrateCompany']);
        Route::delete('/cache', [CompanyConfigController::class, 'clearCache']);
    });

    // Configuraciones generales
    Route::prefix('config')->group(function () {
        Route::get('/defaults', [CompanyConfigController::class, 'getDefaults']);
        Route::get('/summary', [CompanyConfigController::class, 'getSummary']);
    });

    // ========================
    // SUCURSALES
    // ========================
    Route::apiResource('branches', BranchController::class);
    Route::post('/branches/{branch}/activate', [BranchController::class, 'activate']);
    Route::get('/companies/{company}/branches', [BranchController::class, 'getByCompany']);

    // ========================
    // CLIENTES
    // ========================
    Route::apiResource('clients', ClientController::class);
    Route::post('/clients/{client}/activate', [ClientController::class, 'activate']);
    Route::get('/companies/{company}/clients', [ClientController::class, 'getByCompany']);
    Route::post('/clients/search-by-document', [ClientController::class, 'searchByDocument']);

    // ========================
    // CORRELATIVOS
    // ========================
    Route::get('/branches/{branch}/correlatives', [CorrelativeController::class, 'index']);
    Route::post('/branches/{branch}/correlatives', [CorrelativeController::class, 'store']);
    Route::put('/branches/{branch}/correlatives/{correlative}', [CorrelativeController::class, 'update']);
    Route::delete('/branches/{branch}/correlatives/{correlative}', [CorrelativeController::class, 'destroy']);
    Route::post('/branches/{branch}/correlatives/batch', [CorrelativeController::class, 'createBatch']);
    Route::post('/branches/{branch}/correlatives/{correlative}/increment', [CorrelativeController::class, 'increment']);
    
    // Catálogos de correlativos
    Route::get('/correlatives/document-types', [CorrelativeController::class, 'getDocumentTypes']);

    // ========================
    // DOCUMENTOS ELECTRÓNICOS SUNAT
    // ========================

    // PDF Formatos
    Route::prefix('pdf')->group(function () {
        Route::get('/formats', [PdfController::class, 'getAvailableFormats']);
    });

    // Facturas
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
        Route::post('/{id}/send-sunat', [InvoiceController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [InvoiceController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [InvoiceController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [InvoiceController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [InvoiceController::class, 'generatePdf']);
    });

    // Boletas
    Route::prefix('boletas')->group(function () {
        Route::get('/', [BoletaController::class, 'index']);
        Route::post('/', [BoletaController::class, 'store']);
        Route::get('/{id}', [BoletaController::class, 'show']);
        Route::post('/{id}/send-sunat', [BoletaController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [BoletaController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [BoletaController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [BoletaController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [BoletaController::class, 'generatePdf']);
    });

    // ========================
    // CONSULTA DE COMPROBANTES ELECTRÓNICOS (CPE)
    // ========================
    Route::prefix('consulta-cpe')->group(function () {
        // Consultas individuales por tipo de documento
        Route::post('/factura/{id}', [ConsultaCpeController::class, 'consultarFactura']);
        Route::post('/boleta/{id}', [ConsultaCpeController::class, 'consultarBoleta']);

        // Consulta masiva
        Route::post('/masivo', [ConsultaCpeController::class, 'consultarDocumentosMasivo']);

        // Estadísticas de consultas
        Route::get('/estadisticas', [ConsultaCpeController::class, 'estadisticasConsultas']);
    });
});
