<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GreenterService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SunatCompany extends Company
{
    public $invoiceEndpointOverride;
    
    public function getInvoiceEndpoint(): string
    {
        return $this->invoiceEndpointOverride ?: ($this->modo_produccion 
            ? 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService' 
            : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService');
    }
    
    public function getSunatServiceConfig(string $service): array
    {
        return [
            'endpoint' => $this->getInvoiceEndpoint(),
            'wsdl' => str_replace('billService', 'billService?wsdl', $this->getInvoiceEndpoint()),
            'timeout' => 30
        ];
    }
}

class SunatDocument
{
    public $fecha_emision;
    public $numero_completo;
    public $tipo_documento;
}

class FacturacionController extends Controller
{
    protected FileService $fileService;
    
    public function __construct(FileService $fileService)
    {
        $this->fileService = $fileService;
    }
    
    public function facturar(Request $request)
    {
        $request->validate([
            'orden_id' => 'required|string',
        ]);
        
        $ordenId = $request->input('orden_id');
        
        try {
            // 1. Obtener la orden de la base de datos
            $orden = DB::table('ordenes')->where('id', $ordenId)->first();
            if (!$orden) {
                return response()->json([
                    'success' => false,
                    'message' => 'Orden no encontrada.'
                ], 404);
            }
            
            // 2. Obtener la empresa
            $empresa = DB::table('empresas')->where('id', $orden->empresa_id)->first();
            if (!$empresa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empresa de la orden no encontrada.'
                ], 404);
            }
            
            // Si el comprobante ya fue enviado y aceptado por SUNAT, no hacer nada más
            if ($orden->sunat_estado === 'enviado') {
                return response()->json([
                    'success' => true,
                    'comprobante' => $orden->comprobante_serie . '-' . str_pad($orden->comprobante_numero, 8, '0', STR_PAD_LEFT),
                    'message' => 'El comprobante ya fue enviado previamente.'
                ]);
            }
            
            // 3. Obtener cliente
            $clientDoc = '00000000';
            $clientName = 'PÚBLICO EN GENERAL';
            $clientAddress = '-';
            
            if ($orden->cliente_id) {
                $cliente = DB::table('clientes')->where('id', $orden->cliente_id)->first();
                if ($cliente) {
                    $clientDoc = $cliente->dni ?: '00000000';
                    $clientName = $cliente->nombre ?: 'PÚBLICO EN GENERAL';
                    $clientAddress = $cliente->direccion ?: '-';
                }
            }
            
            $clientDocType = '0';
            if (strlen($clientDoc) === 8) {
                $clientDocType = '1'; // DNI
            } elseif (strlen($clientDoc) === 11) {
                $clientDocType = '6'; // RUC
            }
            
            // 4. Asignar serie y correlativo
            $serie = $orden->comprobante_serie;
            if (!$serie) {
                $serie = $orden->tipo_comprobante === 'factura' 
                    ? ($empresa->sunat_serie_factura ?: 'F001') 
                    : ($empresa->sunat_serie_boleta ?: 'B001');
            }
            
            $numero = $orden->comprobante_numero;
            if (!$numero) {
                $maxNumero = DB::table('ordenes')
                    ->where('empresa_id', $empresa->id)
                    ->where('tipo_comprobante', $orden->tipo_comprobante)
                    ->where('comprobante_serie', $serie)
                    ->max('comprobante_numero');
                $numero = ($maxNumero ?: 0) + 1;
            }
            
            $numeroCompleto = $serie . '-' . str_pad($numero, 8, '0', STR_PAD_LEFT);
            
            // 5. Instanciar y configurar la empresa virtual para Greenter
            $company = new SunatCompany();
            $company->ruc = $empresa->ruc ?: '20000000001';
            $company->razon_social = $empresa->razon_social ?: $empresa->nombre;
            $company->nombre_comercial = $empresa->nombre;
            $company->direccion = $empresa->direccion_fiscal ?: 'AV. PRINCIPAL S/N';
            $company->ubigeo = $empresa->ubigeo ?: '150101';
            $company->departamento = $empresa->departamento ?: 'LIMA';
            $company->provincia = $empresa->provincia ?: 'LIMA';
            $company->distrito = $empresa->distrito ?: 'LIMA';
            $company->usuario_sol = $empresa->sunat_usuario_sol ?: 'MODDATOS';
            $company->clave_sol = $empresa->sunat_clave_sol ?: 'MODDATOS';
            $company->certificado_pem = $empresa->sunat_certificado_pem;
            $company->certificado_password = $empresa->sunat_certificado_password;
            $company->modo_produccion = (bool)($empresa->sunat_modo_produccion ?? false);
            
            // Escribir certificado a disco
            if ($company->certificado_pem) {
                $certPath = storage_path('app/public/certificado/certificado.pem');
                if (!file_exists(dirname($certPath))) {
                    mkdir(dirname($certPath), 0755, true);
                }
                file_put_contents($certPath, $company->certificado_pem);
            }
            
            // 6. Mapear items
            $itemsRaw = is_string($orden->items) ? json_decode($orden->items, true) : (array)$orden->items;
            $detalles = [];
            $valorVenta = 0;
            $mtoIgv = 0;
            
            foreach ($itemsRaw as $index => $item) {
                $precioConIgv = (float)($item['precio'] ?? ($item['subtotal'] / ($item['cantidad'] ?: 1)));
                $cantidad = (float)($item['cantidad'] ?: 1);
                $porcentajeIgv = 18.00;
                
                $mtoValorUnitario = round($precioConIgv / (1 + ($porcentajeIgv / 100)), 4);
                $mtoValorVenta = round($cantidad * $mtoValorUnitario, 2);
                $igv = round($mtoValorVenta * ($porcentajeIgv / 100), 2);
                $totalImpuestos = $igv;
                $mtoPrecioUnitario = round(($mtoValorVenta + $igv) / $cantidad, 2);
                
                $valorVenta += $mtoValorVenta;
                $mtoIgv += $igv;
                
                $detalles[] = [
                    'codigo' => $item['producto_id'] ?? $item['id'] ?? ('PROD' . ($index + 1)),
                    'descripcion' => $item['nombre'] ?? 'PRODUCTO',
                    'unidad' => 'NIU',
                    'cantidad' => $cantidad,
                    'mto_valor_unitario' => $mtoValorUnitario,
                    'mto_valor_venta' => $mtoValorVenta,
                    'mto_base_igv' => $mtoValorVenta,
                    'porcentaje_igv' => $porcentajeIgv,
                    'igv' => $igv,
                    'tip_afe_igv' => '10',
                    'total_impuestos' => $totalImpuestos,
                    'mto_precio_unitario' => $mtoPrecioUnitario
                ];
            }
            
            $totalImpuestos = $mtoIgv;
            $mtoImpVenta = round($valorVenta + $mtoIgv, 2);
            
            // Generar leyenda del total en letras
            $leyendaTexto = $this->convertNumberToWords($mtoImpVenta, 'PEN');
            
            // Documento de datos para Greenter
            $documentData = [
                'tipo_documento' => $orden->tipo_comprobante === 'factura' ? '01' : '03',
                'serie' => $serie,
                'correlativo' => str_pad($numero, 8, '0', STR_PAD_LEFT),
                'fecha_emision' => date('Y-m-d\TH:i:sP', strtotime($orden->created_at)),
                'moneda' => 'PEN',
                'client' => [
                    'tipo_documento' => $clientDocType,
                    'numero_documento' => $clientDoc,
                    'razon_social' => $clientName,
                    'direccion' => $clientAddress
                ],
                'tipo_operacion' => '0101',
                'mto_oper_gravadas' => $valorVenta,
                'mto_oper_exoneradas' => 0,
                'mto_oper_inafectas' => 0,
                'mto_oper_gratuitas' => 0,
                'mto_igv_gratuitas' => 0,
                'mto_igv' => $mtoIgv,
                'total_impuestos' => $totalImpuestos,
                'valor_venta' => $valorVenta,
                'sub_total' => $valorVenta,
                'mto_imp_venta' => $mtoImpVenta,
                'detalles' => $detalles,
                'leyendas' => [
                    [
                        'code' => '1000',
                        'value' => $leyendaTexto
                    ]
                ]
            ];
            
            // 7. Instanciar GreenterService y procesar
            $greenterService = new GreenterService($company);
            $greenterDocument = $greenterService->createInvoice($documentData);
            
            if (!$greenterDocument) {
                throw new Exception('No se pudo crear el documento para Greenter.');
            }
            
            // Enviar a SUNAT
            $result = $greenterService->sendDocument($greenterDocument);
            
            // 8. Crear un documento virtual para FileService
            $docObj = new SunatDocument();
            $docObj->fecha_emision = $orden->created_at;
            $docObj->numero_completo = $numeroCompleto;
            $docObj->tipo_documento = $orden->tipo_comprobante === 'factura' ? '01' : '03';
            
            $xmlPath = null;
            $cdrPath = null;
            
            if ($result['xml']) {
                $xmlPath = $this->fileService->saveXml($docObj, $result['xml']);
            }
            
            if ($result['success'] && $result['cdr_zip']) {
                $cdrPath = $this->fileService->saveCdr($docObj, $result['cdr_zip']);
            }
            
            // Extraer el hash del XML firmado
            $sunatHash = null;
            $xmlSigned = $greenterService->getXmlSigned($greenterDocument);
            if ($xmlSigned) {
                $sunatHash = $this->extractHashFromXml($xmlSigned);
            }
            
            // Determinar estados y mensajes
            $sunatEstado = $result['success'] ? 'enviado' : 'error';
            $sunatMensaje = '';
            
            if ($result['success'] && $result['cdr_response']) {
                $sunatMensaje = $result['cdr_response']->getDescription();
            } elseif ($result['error']) {
                $sunatMensaje = $result['error']->message ?? 'Error SUNAT desconocido';
            }
            
            // Actualizar la tabla 'ordenes' en Supabase
            DB::table('ordenes')
                ->where('id', $ordenId)
                ->update([
                    'tipo_comprobante' => $orden->tipo_comprobante,
                    'comprobante_serie' => $serie,
                    'comprobante_numero' => $numero,
                    'sunat_estado' => $sunatEstado,
                    'sunat_hash' => $sunatHash,
                    'sunat_mensaje' => $sunatMensaje,
                    'sunat_xml_url' => $xmlPath ? url('/storage/' . $xmlPath) : null,
                    'sunat_cdr_url' => $cdrPath ? url('/storage/' . $cdrPath) : null,
                    'updated_at' => now()
                ]);
                
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'comprobante' => $numeroCompleto,
                    'message' => 'Comprobante emitido y enviado correctamente.'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'comprobante' => $numeroCompleto,
                    'message' => 'Error SUNAT: ' . $sunatMensaje
                ], 400);
            }
            
        } catch (Exception $e) {
            Log::error('Error al facturar orden ' . $ordenId . ': ' . $e->getMessage());
            
            // Actualizar estado en 'ordenes'
            DB::table('ordenes')
                ->where('id', $ordenId)
                ->update([
                    'sunat_estado' => 'error',
                    'sunat_mensaje' => 'Error interno: ' . $e->getMessage(),
                    'updated_at' => now()
                ]);
                
            return response()->json([
                'success' => false,
                'message' => 'Error de procesamiento: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function testCert()
    {
        try {
            $empresa = DB::table('empresas')->where('id', '2246b9a3-41fd-4e92-8124-11bea071dc77')->first();
            if (!$empresa) return response()->json(['error' => 'Empresa not found']);
            
            $pem = $empresa->sunat_certificado_pem;
            if (!$pem) return response()->json(['error' => 'Certificate pem is null']);
            
            $pem = str_replace(['\r\n', '\r', '\n', '\\n', '\\r'], "\n", $pem);
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
                               
            $passphrase = $empresa->sunat_certificado_password ?? '';
            
            $pkeyRawPass = openssl_pkey_get_private($pem, $passphrase);
            $pkeyRawNoPass = openssl_pkey_get_private($pem);
            
            $pkeyCleanPass = openssl_pkey_get_private($certificadoLimpio, $passphrase);
            $pkeyCleanNoPass = openssl_pkey_get_private($certificadoLimpio);
            
            $preparedPem = '';
            $prepareError = null;
            try {
                // Reconstruct utilizing the exact preparation function
                $reconstructed = trim($pem);
                $reconstructed = str_replace(["\r\n", "\r"], "\n", $reconstructed);
                $lines = explode("\n", $reconstructed);
                $lines = array_map('trim', $lines);
                $reconstructed = implode("\n", $lines);
                
                // Clean attributes
                $lines = explode("\n", $reconstructed);
                $cleanedLines = [];
                $inPemBlock = false;
                foreach ($lines as $line) {
                    if (strpos(trim($line), '-----BEGIN') === 0) $inPemBlock = true;
                    if ($inPemBlock) $cleanedLines[] = $line;
                    if (strpos(trim($line), '-----END') === 0) $inPemBlock = false;
                }
                $cleanedPem = implode("\n", $cleanedLines);
                
                $output = [];
                if (preg_match('/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s', $cleanedPem, $matches)) {
                    $privateKey = preg_replace('/\s+/', '', $matches[1]);
                    $output[] = "-----BEGIN PRIVATE KEY-----";
                    $output[] = rtrim(chunk_split($privateKey, 64, "\n"), "\n");
                    $output[] = "-----END PRIVATE KEY-----";
                }
                if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $cleanedPem, $matches)) {
                    $certificate = preg_replace('/\s+/', '', $matches[1]);
                    $output[] = "-----BEGIN CERTIFICATE-----";
                    $output[] = rtrim(chunk_split($certificate, 64, "\n"), "\n");
                    $output[] = "-----END CERTIFICATE-----";
                }
                $reconstructedPem = implode("\n", $output);
                
                $pkeyReconstructedPass = openssl_pkey_get_private($reconstructedPem, $passphrase);
                $pkeyReconstructedNoPass = openssl_pkey_get_private($reconstructedPem);
                
                $outputPriv = [];
                if (preg_match('/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s', $cleanedPem, $matches)) {
                    $privateKey = preg_replace('/\s+/', '', $matches[1]);
                    $outputPriv[] = "-----BEGIN PRIVATE KEY-----";
                    $outputPriv[] = rtrim(chunk_split($privateKey, 64, "\n"), "\n");
                    $outputPriv[] = "-----END PRIVATE KEY-----";
                }
                $onlyPrivateKeyPem = implode("\n", $outputPriv);
                
                $pkeyOnlyPrivPass = openssl_pkey_get_private($onlyPrivateKeyPem, $passphrase);
                $pkeyOnlyPrivNoPass = openssl_pkey_get_private($onlyPrivateKeyPem);
                
                $preparedPem = [
                    'reconstructed_with_pass' => is_resource($pkeyReconstructedPass) || $pkeyReconstructedPass instanceof \OpenSSLAsymmetricKey,
                    'reconstructed_no_pass' => is_resource($pkeyReconstructedNoPass) || $pkeyReconstructedNoPass instanceof \OpenSSLAsymmetricKey,
                    'only_priv_with_pass' => is_resource($pkeyOnlyPrivPass) || $pkeyOnlyPrivPass instanceof \OpenSSLAsymmetricKey,
                    'only_priv_no_pass' => is_resource($pkeyOnlyPrivNoPass) || $pkeyOnlyPrivNoPass instanceof \OpenSSLAsymmetricKey,
                    'reconstructed_len' => strlen($reconstructedPem),
                ];
            } catch (\Exception $e) {
                $prepareError = $e->getMessage();
            }
            
            $opensslErrors = [];
            while ($err = openssl_error_string()) {
                $opensslErrors[] = $err;
            }
                               
            return response()->json([
                'has_private_key' => $hasPrivateKey,
                'has_certificate' => $hasCertificate,
                'has_rsa_private_key' => $hasRsaPrivateKey,
                'passphrase_used' => $passphrase,
                'pkey_raw_with_pass' => is_resource($pkeyRawPass) || $pkeyRawPass instanceof \OpenSSLAsymmetricKey,
                'pkey_raw_no_pass' => is_resource($pkeyRawNoPass) || $pkeyRawNoPass instanceof \OpenSSLAsymmetricKey,
                'pkey_clean_with_pass' => is_resource($pkeyCleanPass) || $pkeyCleanPass instanceof \OpenSSLAsymmetricKey,
                'pkey_clean_no_pass' => is_resource($pkeyCleanNoPass) || $pkeyCleanNoPass instanceof \OpenSSLAsymmetricKey,
                'prepared_pem_status' => $preparedPem,
                'only_priv_pem' => $onlyPrivateKeyPem,
                'prepare_error' => $prepareError,
                'openssl_errors' => $opensslErrors,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
    
    protected function extractHashFromXml(string $xml): ?string
    {
        preg_match('/<ds:DigestValue[^>]*>([^<]+)<\/ds:DigestValue>/', $xml, $matches);
        return $matches[1] ?? null;
    }
    
    protected function convertNumberToWords(float $numero, string $moneda): string
    {
        $monedaName = $moneda === 'PEN' ? 'SOLES' : 'DÓLARES AMERICANOS';
        $entero = intval($numero);
        $decimales = intval(round(($numero - $entero) * 100));
        
        $letras = $this->numeroALetras($entero);
        
        return strtoupper($letras . ' CON ' . sprintf('%02d', $decimales) . '/100 ' . $monedaName);
    }

    private function numeroALetras($num): string
    {
        $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $decenas = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $dieces = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
        
        if ($num == 0) return 'CERO';
        if ($num == 100) return 'CIEN';
        
        if ($num < 10) return $unidades[$num];
        if ($num < 20) return $dieces[$num - 10];
        if ($num < 30) {
            $u = $num % 10;
            return $u == 0 ? 'VEINTE' : 'VEINTI' . $unidades[$u];
        }
        if ($num < 100) {
            $d = intval($num / 10);
            $u = $num % 10;
            return $u == 0 ? $decenas[$d] : $decenas[$d] . ' Y ' . $unidades[$u];
        }
        if ($num < 1000) {
            $c = intval($num / 100);
            $resto = $num % 100;
            return $resto == 0 ? $centenas[$c] : $centenas[$c] . ' ' . $this->numeroALetras($resto);
        }
        if ($num < 1000000) {
            $mil = intval($num / 1000);
            $resto = $num % 1000;
            $milLetras = ($mil == 1) ? 'MIL' : $this->numeroALetras($mil) . ' MIL';
            return $resto == 0 ? $milLetras : $milLetras . ' ' . $this->numeroALetras($resto);
        }
        
        return 'NÚMERO EN LETRAS';
    }
}
