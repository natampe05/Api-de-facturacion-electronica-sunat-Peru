<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\GreenterService;
use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

            // Actualizar la orden de inmediato en la base de datos con la serie y número
            DB::table('ordenes')
                ->where('id', $ordenId)
                ->update([
                    'comprobante_serie' => $serie,
                    'comprobante_numero' => $numero,
                    'updated_at' => now()
                ]);
            
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
            $config = json_decode($empresa->configuracion, true) ?: [];
            $porcentajeIgv = isset($config['igv']) ? (float)$config['igv'] : 18.00;

            $itemsRaw = is_string($orden->items) ? json_decode($orden->items, true) : (array)$orden->items;
            $detalles = [];
            $valorVenta = 0;
            $mtoIgv = 0;
            
            foreach ($itemsRaw as $index => $item) {
                $precioConIgv = (float)($item['precio'] ?? ($item['subtotal'] / ($item['cantidad'] ?: 1)));
                $cantidad = (float)($item['cantidad'] ?: 1);
                
                if ($porcentajeIgv > 0) {
                    $mtoValorUnitario = round($precioConIgv / (1 + ($porcentajeIgv / 100)), 4);
                    $mtoValorVenta = round($cantidad * $mtoValorUnitario, 2);
                    $igv = round($mtoValorVenta * ($porcentajeIgv / 100), 2);
                    $totalImpuestos = $igv;
                    $mtoPrecioUnitario = round(($mtoValorVenta + $igv) / $cantidad, 2);
                    $tipAfeIgv = '10'; // Gravado - Operación Onerosa
                } else {
                    $mtoValorUnitario = round($precioConIgv, 4);
                    $mtoValorVenta = round($cantidad * $mtoValorUnitario, 2);
                    $igv = 0.00;
                    $totalImpuestos = 0.00;
                    $mtoPrecioUnitario = round($precioConIgv, 2);
                    $tipAfeIgv = '20'; // Exonerado - Operación Onerosa
                }
                
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
                    'tip_afe_igv' => $tipAfeIgv,
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
            
            // Firmar el XML localmente y extraer el hash (operación rápida local de microsegundos)
            $xmlSigned = $greenterService->getXmlSigned($greenterDocument);
            $sunatHash = null;
            if ($xmlSigned) {
                $sunatHash = $this->extractHashFromXml($xmlSigned);
            }
            
            // Generar Base64 data URL del XML preliminar firmado
            $xmlBase64 = 'data:application/xml;base64,' . base64_encode($xmlSigned ?: '');
            
            // Guardar en almacenamiento local (fallback)
            $docObj = new SunatDocument();
            $docObj->fecha_emision = $orden->created_at;
            $docObj->numero_completo = $numeroCompleto;
            $docObj->tipo_documento = $orden->tipo_comprobante === 'factura' ? '01' : '03';
            
            if ($xmlSigned) {
                try {
                    $this->fileService->saveXml($docObj, $xmlSigned);
                } catch (Exception $e) {
                    Log::warning('No se pudo guardar XML firmado en disco local: ' . $e->getMessage());
                }
            }
            
            // Actualizar la orden localmente de inmediato con los datos firmados
            DB::table('ordenes')
                ->where('id', $ordenId)
                ->update([
                    'comprobante_serie' => $serie,
                    'comprobante_numero' => $numero,
                    'sunat_estado' => 'pendiente', // Cambiará a 'enviado' o 'error' en segundo plano
                    'sunat_hash' => $sunatHash,
                    'sunat_xml_url' => $xmlBase64,
                    'updated_at' => now()
                ]);
            
            // Enviar respuesta exitosa de inmediato para liberar al cajero
            $response = response()->json([
                'success' => true,
                'comprobante' => $numeroCompleto,
                'message' => 'Comprobante firmado y registrado localmente. Enviando a SUNAT en segundo plano...'
            ]);
            $response->send();
            
            // Finalizar la conexión HTTP con el cliente para que continúe sin demora
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // --- CÓDIGO EN SEGUNDO PLANO (TRANSMISIÓN A SUNAT) ---
            try {
                // Enviar a SUNAT (esta llamada de red tarda varios segundos)
                $result = $greenterService->sendDocument($greenterDocument);
                
                // Si el envío retornó un XML firmado alternativo, lo actualizamos
                if (!$xmlSigned && $result['xml']) {
                    $xmlSigned = $result['xml'];
                    $xmlBase64 = 'data:application/xml;base64,' . base64_encode($xmlSigned);
                    $sunatHash = $this->extractHashFromXml($xmlSigned);
                }
                
                // Guardar el CDR si tuvo éxito
                $cdrBase64 = null;
                if ($result['success'] && $result['cdr_zip']) {
                    try {
                        $this->fileService->saveCdr($docObj, $result['cdr_zip']);
                    } catch (Exception $e) {
                        Log::warning('No se pudo guardar CDR en disco local: ' . $e->getMessage());
                    }
                    $cdrBase64 = 'data:application/zip;base64,' . base64_encode($result['cdr_zip']);
                }
                
                $sunatEstado = $result['success'] ? 'enviado' : 'error';
                $sunatMensaje = '';
                
                if ($result['success'] && $result['cdr_response']) {
                    $sunatMensaje = $result['cdr_response']->getDescription();
                } elseif ($result['error']) {
                    $sunatMensaje = $result['error']->message ?? 'Error SUNAT desconocido';
                }
                
                // Actualizar el estado final en Supabase
                DB::table('ordenes')
                    ->where('id', $ordenId)
                    ->update([
                        'sunat_estado' => $sunatEstado,
                        'sunat_hash' => $sunatHash,
                        'sunat_mensaje' => $sunatMensaje,
                        'sunat_xml_url' => $xmlBase64,
                        'sunat_cdr_url' => $cdrBase64,
                        'updated_at' => now()
                    ]);
                    
            } catch (Exception $bgEx) {
                Log::error('Error en segundo plano al enviar a SUNAT la orden ' . $ordenId . ': ' . $bgEx->getMessage());
                DB::table('ordenes')
                    ->where('id', $ordenId)
                    ->update([
                        'sunat_estado' => 'error',
                        'sunat_mensaje' => 'Error de transmisión: ' . $bgEx->getMessage(),
                        'updated_at' => now()
                    ]);
            }
            
            return;
            
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

    public function downloadXmlPublic($id)
    {
        $orden = DB::table('ordenes')->where('id', $id)->first();
        if (!$orden || !$orden->sunat_xml_url) {
            abort(404, 'XML no encontrado para esta orden.');
        }
        
        $filename = ($orden->comprobante_serie ?: 'DOC') . '-' . str_pad($orden->comprobante_numero ?: 1, 8, '0', STR_PAD_LEFT) . '.xml';
        
        if (str_starts_with($orden->sunat_xml_url, 'data:')) {
            $pos = strpos($orden->sunat_xml_url, 'base64,');
            if ($pos !== false) {
                $content = base64_decode(substr($orden->sunat_xml_url, $pos + 7));
                return response($content, 200, [
                    'Content-Type' => 'application/xml',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }
        }
        
        $urlParts = parse_url($orden->sunat_xml_url);
        $path = ltrim($urlParts['path'] ?? '', '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }
        
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'El archivo XML no existe en el almacenamiento.');
        }
        
        return Storage::disk('public')->download($path, $filename);
    }

    public function downloadCdrPublic($id)
    {
        $orden = DB::table('ordenes')->where('id', $id)->first();
        if (!$orden || !$orden->sunat_cdr_url) {
            abort(404, 'CDR no encontrado para esta orden.');
        }
        
        $filename = 'R-' . ($orden->comprobante_serie ?: 'DOC') . '-' . str_pad($orden->comprobante_numero ?: 1, 8, '0', STR_PAD_LEFT) . '.zip';
        
        if (str_starts_with($orden->sunat_cdr_url, 'data:')) {
            $pos = strpos($orden->sunat_cdr_url, 'base64,');
            if ($pos !== false) {
                $content = base64_decode(substr($orden->sunat_cdr_url, $pos + 7));
                return response($content, 200, [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }
        }
        
        $urlParts = parse_url($orden->sunat_cdr_url);
        $path = ltrim($urlParts['path'] ?? '', '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }
        
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'El archivo CDR no existe en el almacenamiento.');
        }
        
        return Storage::disk('public')->download($path, $filename);
    }
}
