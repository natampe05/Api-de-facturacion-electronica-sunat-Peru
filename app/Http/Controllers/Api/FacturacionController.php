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
                
                $startNumero = $orden->tipo_comprobante === 'factura' 
                    ? ($empresa->sunat_iniciar_factura ?? 1) 
                    : ($empresa->sunat_iniciar_boleta ?? 1);
                
                $numero = ($maxNumero ? max($maxNumero, $startNumero - 1) : ($startNumero - 1)) + 1;
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
            
            $descuentoMonto = (float)($orden->descuento_monto ?? 0);
            $descuentos = [];
            
            if ($descuentoMonto > 0) {
                if ($porcentajeIgv > 0) {
                    $valorVentaDescontado = round($orden->total / (1 + ($porcentajeIgv / 100)), 2);
                    $mtoIgvDescontado = round($orden->total - $valorVentaDescontado, 2);
                } else {
                    $valorVentaDescontado = (float)$orden->total;
                    $mtoIgvDescontado = 0.00;
                }
                
                $descuentoNeto = round($valorVenta - $valorVentaDescontado, 2);
                $factor = $valorVenta > 0 ? round($descuentoNeto / $valorVenta, 5) : 0;
                
                if ($descuentoNeto > 0) {
                    $descuentos[] = [
                        'cod_tipo' => '02', // Descuento global que afecta la base imponible
                        'monto' => $descuentoNeto,
                        'monto_base' => $valorVenta,
                        'factor' => $factor
                    ];
                    
                    $mtoOperGravadas = $porcentajeIgv > 0 ? $valorVentaDescontado : 0;
                    $mtoOperExoneradas = $porcentajeIgv > 0 ? 0 : $valorVentaDescontado;
                    $valorVenta = $valorVentaDescontado;
                    $mtoIgv = $mtoIgvDescontado;
                    $totalImpuestos = $mtoIgvDescontado;
                    $mtoImpVenta = (float)$orden->total;
                } else {
                    $mtoOperGravadas = $porcentajeIgv > 0 ? $valorVenta : 0;
                    $mtoOperExoneradas = $porcentajeIgv > 0 ? 0 : $valorVenta;
                    $mtoImpVenta = round($valorVenta + $mtoIgv, 2);
                }
            } else {
                $mtoOperGravadas = $porcentajeIgv > 0 ? $valorVenta : 0;
                $mtoOperExoneradas = $porcentajeIgv > 0 ? 0 : $valorVenta;
                $mtoImpVenta = round($valorVenta + $mtoIgv, 2);
            }
            
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
                'mto_oper_gravadas' => $mtoOperGravadas,
                'mto_oper_exoneradas' => $mtoOperExoneradas,
                'mto_oper_inafectas' => 0,
                'mto_oper_gratuitas' => 0,
                'mto_igv_gratuitas' => 0,
                'mto_igv' => $mtoIgv,
                'total_impuestos' => $totalImpuestos,
                'valor_venta' => $valorVenta,
                'sub_total' => $valorVenta,
                'mto_imp_venta' => $mtoImpVenta,
                'detalles' => $detalles,
                'descuentos' => $descuentos,
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
            
            // Si solo se pidió firmar, retornar de inmediato (<50ms)
            if ($request->input('solo_firmar') || $request->query('solo_firmar')) {
                return response()->json([
                    'success' => true,
                    'comprobante' => $numeroCompleto,
                    'message' => 'Comprobante firmado y registrado localmente.'
                ]);
            }
            
            // Enviar respuesta exitosa de inmediato para liberar al cajero (si no es solo_enviar directo)
            // (Quitamos el retorno anticipado y fastcgi_finish_request para que sea síncrono en serverless)
            
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
                
                if ($request->input('solo_enviar') || $request->query('solo_enviar')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Error de transmisión: ' . $bgEx->getMessage()
                    ], 500);
                }
            }
            
            return response()->json([
                'success' => isset($sunatEstado) && $sunatEstado === 'enviado',
                'comprobante' => $numeroCompleto,
                'estado' => $sunatEstado ?? 'error',
                'mensaje' => $sunatMensaje ?? '',
                'message' => (isset($sunatEstado) && $sunatEstado === 'enviado')
                    ? 'Comprobante enviado a SUNAT con éxito.'
                    : 'Error SUNAT: ' . ($sunatMensaje ?? 'No se pudo enviar.')
            ]);
            
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

    public function anularComprobante(Request $request)
    {
        $request->validate([
            'orden_id' => 'required|string',
            'motivo' => 'nullable|string'
        ]);

        $ordenId = $request->input('orden_id');
        $motivo = $request->input('motivo') ?: 'Anulación de la operación';

        try {
            // 1. Obtener la orden de la base de datos
            $orden = DB::table('ordenes')->where('id', $ordenId)->first();
            if (!$orden) {
                return response()->json([
                    'success' => false,
                    'message' => 'Orden no encontrada.'
                ], 404);
            }

            // Si ya está anulada la orden, no hacer nada más
            if ($orden->estado === 'anulado' || $orden->estado === 'anulada') {
                return response()->json([
                    'success' => true,
                    'message' => 'El comprobante ya fue anulado.'
                ]);
            }

            // Si es un ticket normal o no fue enviado a SUNAT (sunat_estado !== enviado), 
            // solo anular localmente
            if ($orden->tipo_comprobante === 'ticket' || $orden->sunat_estado !== 'enviado' || !$orden->comprobante_serie) {
                DB::table('ordenes')
                    ->where('id', $ordenId)
                    ->update([
                        'estado' => 'anulado',
                        'updated_at' => now()
                    ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Comprobante anulado localmente (no requiere nota de crédito).'
                ]);
            }

            // 2. Obtener la empresa
            $empresa = DB::table('empresas')->where('id', $orden->empresa_id)->first();
            if (!$empresa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empresa de la orden no encontrada.'
                ], 404);
            }

            // 3. Determinar serie y número de la Nota de Crédito
            $serieOriginal = $orden->comprobante_serie;
            $serieNota = str_starts_with($serieOriginal, 'F') 
                ? 'FC' . substr($serieOriginal, 2) 
                : 'BC' . substr($serieOriginal, 2);

            $maxNumero = DB::table('ordenes')
                ->where('empresa_id', $empresa->id)
                ->where('tipo_comprobante', 'nota_credito')
                ->where('comprobante_serie', $serieNota)
                ->max('comprobante_numero');

            $startNumero = $empresa->sunat_iniciar_nota_credito ?? 1;
            $numeroNota = ($maxNumero ? max($maxNumero, $startNumero - 1) : ($startNumero - 1)) + 1;
            $numeroCompletoNota = $serieNota . '-' . str_pad($numeroNota, 8, '0', STR_PAD_LEFT);

            // 4. Crear cliente para Greenter
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

            // 5. Configurar empresa virtual para Greenter
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
                    $tipAfeIgv = '10'; // Gravado
                } else {
                    $mtoValorUnitario = round($precioConIgv, 4);
                    $mtoValorVenta = round($cantidad * $mtoValorUnitario, 2);
                    $igv = 0.00;
                    $totalImpuestos = 0.00;
                    $mtoPrecioUnitario = round($precioConIgv, 2);
                    $tipAfeIgv = '20'; // Exonerado
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

            $descuentoMonto = (float)($orden->descuento_monto ?? 0);
            $descuentos = [];
            
            if ($descuentoMonto > 0) {
                if ($porcentajeIgv > 0) {
                    $valorVentaDescontado = round($orden->total / (1 + ($porcentajeIgv / 100)), 2);
                    $mtoIgvDescontado = round($orden->total - $valorVentaDescontado, 2);
                } else {
                    $valorVentaDescontado = (float)$orden->total;
                    $mtoIgvDescontado = 0.00;
                }
                
                $descuentoNeto = round($valorVenta - $valorVentaDescontado, 2);
                $factor = $valorVenta > 0 ? round($descuentoNeto / $valorVenta, 5) : 0;
                
                if ($descuentoNeto > 0) {
                    $descuentos[] = [
                        'cod_tipo' => '02',
                        'monto' => $descuentoNeto,
                        'monto_base' => $valorVenta,
                        'factor' => $factor
                    ];
                    
                    $mtoOperGravadas = $porcentajeIgv > 0 ? $valorVentaDescontado : 0;
                    $mtoOperExoneradas = $porcentajeIgv > 0 ? 0 : $valorVentaDescontado;
                    $valorVenta = $valorVentaDescontado;
                    $mtoIgv = $mtoIgvDescontado;
                    $totalImpuestos = $mtoIgvDescontado;
                    $mtoImpVenta = (float)$orden->total;
                } else {
                    $mtoOperGravadas = $porcentajeIgv > 0 ? $valorVenta : 0;
                    $mtoOperExoneradas = $porcentajeIgv > 0 ? 0 : $valorVenta;
                    $mtoImpVenta = round($valorVenta + $mtoIgv, 2);
                }
            } else {
                $mtoOperGravadas = $porcentajeIgv > 0 ? $valorVenta : 0;
                $mtoOperExoneradas = $porcentajeIgv > 0 ? 0 : $valorVenta;
                $mtoImpVenta = round($valorVenta + $mtoIgv, 2);
            }
            
            $leyendaTexto = $this->convertNumberToWords($mtoImpVenta, 'PEN');
 
            // 7. Preparar documento de datos para Greenter (Nota de Crédito)
            $documentData = [
                'tipo_documento' => '07', // Nota de Crédito
                'serie' => $serieNota,
                'correlativo' => str_pad($numeroNota, 8, '0', STR_PAD_LEFT),
                'fecha_emision' => date('Y-m-d\TH:i:sP'),
                'tipo_doc_afectado' => $orden->tipo_comprobante === 'factura' ? '01' : '03',
                'num_doc_afectado' => $orden->comprobante_serie . '-' . str_pad($orden->comprobante_numero, 8, '0', STR_PAD_LEFT),
                'cod_motivo' => '01', // Anulación de la operación
                'des_motivo' => $motivo,
                'moneda' => 'PEN',
                'client' => [
                    'tipo_documento' => $clientDocType,
                    'numero_documento' => $clientDoc,
                    'razon_social' => $clientName,
                    'direccion' => $clientAddress
                ],
                'mto_oper_gravadas' => $mtoOperGravadas,
                'mto_oper_exoneradas' => $mtoOperExoneradas,
                'mto_oper_inafectas' => 0,
                'mto_igv' => $mtoIgv,
                'total_impuestos' => $totalImpuestos,
                'mto_imp_venta' => $mtoImpVenta,
                'detalles' => $detalles,
                'descuentos' => $descuentos,
                'leyendas' => [
                    [
                        'code' => '1000',
                        'value' => $leyendaTexto
                    ]
                ]
            ];

            // 8. Instanciar GreenterService y firmar
            $greenterService = new GreenterService($company);
            $greenterDocument = $greenterService->createNote($documentData);
            
            if (!$greenterDocument) {
                throw new Exception('No se pudo crear la Nota de Crédito para Greenter.');
            }
            
            $xmlSigned = $greenterService->getXmlSigned($greenterDocument);
            $sunatHash = null;
            if ($xmlSigned) {
                $sunatHash = $this->extractHashFromXml($xmlSigned);
            }
            $xmlBase64 = 'data:application/xml;base64,' . base64_encode($xmlSigned ?: '');
            
            $docObj = new SunatDocument();
            $docObj->fecha_emision = date('Y-m-d H:i:s');
            $docObj->numero_completo = $numeroCompletoNota;
            $docObj->tipo_documento = '07';
            
            if ($xmlSigned) {
                try {
                    $this->fileService->saveXml($docObj, $xmlSigned);
                } catch (Exception $e) {
                    Log::warning('No se pudo guardar XML de Nota de Crédito en disco local: ' . $e->getMessage());
                }
            }

            // 9. Crear el registro de la Nota de Crédito en 'ordenes' en Supabase
            $notaId = (string) \Illuminate\Support\Str::uuid();
            DB::table('ordenes')->insert([
                'id' => $notaId,
                'empresa_id' => $orden->empresa_id,
                'cliente_id' => $orden->cliente_id,
                'mesa_id' => $orden->mesa_id,
                'cajero_id' => $orden->cajero_id,
                'tipo_orden' => $orden->tipo_orden,
                'estado' => 'pagada', // nota_credito activa/emitida
                'tipo_comprobante' => 'nota_credito',
                'comprobante_serie' => $serieNota,
                'comprobante_numero' => $numeroNota,
                'subtotal' => -$orden->subtotal,
                'total' => -$orden->total,
                'igv_monto' => -$orden->igv_monto,
                'descuento_monto' => -$orden->descuento_monto,
                'items' => is_string($orden->items) ? $orden->items : json_encode($orden->items),
                'cliente_nombre' => $orden->cliente_nombre,
                'metodo_pago' => $orden->metodo_pago,
                'notas' => $motivo,
                'sunat_estado' => 'pendiente',
                'sunat_hash' => $sunatHash,
                'sunat_xml_url' => $xmlBase64,
                'documento_afectado_serie' => $orden->comprobante_serie,
                'documento_afectado_numero' => $orden->comprobante_numero,
                'documento_afectado_tipo' => $orden->tipo_comprobante,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 10. Actualizar la orden original a 'anulado'
            DB::table('ordenes')
                ->where('id', $ordenId)
                ->update([
                    'estado' => 'anulado',
                    'updated_at' => now()
                ]);

            // 11. (Procesando anulación de forma síncrona en serverless)

            // --- CÓDIGO EN SEGUNDO PLANO (TRANSMISIÓN A SUNAT) ---
            try {
                $result = $greenterService->sendDocument($greenterDocument);
                
                if (!$xmlSigned && $result['xml']) {
                    $xmlSigned = $result['xml'];
                    $xmlBase64 = 'data:application/xml;base64,' . base64_encode($xmlSigned);
                    $sunatHash = $this->extractHashFromXml($xmlSigned);
                }
                
                $cdrBase64 = null;
                if ($result['success'] && $result['cdr_zip']) {
                    try {
                        $this->fileService->saveCdr($docObj, $result['cdr_zip']);
                    } catch (Exception $e) {
                        Log::warning('No se pudo guardar CDR de Nota de Crédito en disco local: ' . $e->getMessage());
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
                
                DB::table('ordenes')
                    ->where('id', $notaId)
                    ->update([
                        'sunat_estado' => $sunatEstado,
                        'sunat_hash' => $sunatHash,
                        'sunat_mensaje' => $sunatMensaje,
                        'sunat_xml_url' => $xmlBase64,
                        'sunat_cdr_url' => $cdrBase64,
                        'updated_at' => now()
                    ]);
                    
            } catch (Exception $bgEx) {
                Log::error('Error en segundo plano al enviar Nota de Crédito ' . $notaId . ': ' . $bgEx->getMessage());
                DB::table('ordenes')
                    ->where('id', $notaId)
                    ->update([
                        'sunat_estado' => 'error',
                        'sunat_mensaje' => 'Error de transmisión: ' . $bgEx->getMessage(),
                        'updated_at' => now()
                    ]);
            }

            $notaCreditoRow = DB::table('ordenes')->where('id', $notaId)->first();

            return response()->json([
                'success' => isset($sunatEstado) && $sunatEstado === 'enviado',
                'comprobante' => $numeroCompletoNota,
                'estado' => $sunatEstado ?? 'error',
                'mensaje' => $sunatMensaje ?? '',
                'nota_credito' => $notaCreditoRow,
                'message' => (isset($sunatEstado) && $sunatEstado === 'enviado')
                    ? 'Nota de Crédito ' . $numeroCompletoNota . ' registrada y enviada a SUNAT con éxito.'
                    : 'Nota de Crédito ' . $numeroCompletoNota . ' registrada localmente pero con error SUNAT: ' . ($sunatMensaje ?? 'No se pudo enviar.')
            ]);

        } catch (Exception $e) {
            Log::error('Error al generar Nota de Crédito para orden ' . $ordenId . ': ' . $e->getMessage());
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

    public function enviarResumenDiario(Request $request)
    {
        $request->validate([
            'empresa_id' => 'required|string',
        ]);
        
        $empresaId = $request->input('empresa_id');
        
        try {
            $empresa = DB::table('empresas')->where('id', $empresaId)->first();
            if (!$empresa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empresa no encontrada.'
                ], 404);
            }
            
            // Si la empresa es de facturación interna (solo nota de venta), omitir
            if (isset($empresa->solo_nota_venta) && $empresa->solo_nota_venta) {
                return response()->json([
                    'success' => true,
                    'message' => 'La empresa está configurada para Facturación Interna (solo nota de venta). No se requiere envío a SUNAT.'
                ]);
            }
            
            // Obtener boletas en estado pendiente o error
            $boletas = DB::table('ordenes')
                ->where('empresa_id', $empresaId)
                ->where('tipo_comprobante', 'boleta')
                ->whereIn('sunat_estado', ['pendiente', 'error'])
                ->get();
                
            if ($boletas->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay boletas pendientes para enviar a SUNAT.'
                ]);
            }
            
            // SUNAT exige agrupar los resúmenes diarios por la fecha de emisión del comprobante
            $boletasByDate = [];
            foreach ($boletas as $boleta) {
                $dateKey = date('Y-m-d', strtotime($boleta->created_at));
                $boletasByDate[$dateKey][] = $boleta;
            }
            
            // Configuración general de la empresa
            $config = json_decode($empresa->configuracion, true) ?: [];
            $porcentajeIgv = isset($config['igv']) ? (float)$config['igv'] : 18.00;
            
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
            
            if ($company->certificado_pem) {
                $certPath = storage_path('app/public/certificado/certificado.pem');
                if (!file_exists(dirname($certPath))) {
                    mkdir(dirname($certPath), 0755, true);
                }
                file_put_contents($certPath, $company->certificado_pem);
            }
            
            $greenterService = new GreenterService($company);
            $summariesSent = [];
            
            foreach ($boletasByDate as $emissionDate => $dateBoletas) {
                $todayStr = date('Ymd');
                
                // Encontrar el correlativo para el día
                $lastMessage = DB::table('ordenes')
                    ->where('empresa_id', $empresaId)
                    ->where('tipo_comprobante', 'boleta')
                    ->where('sunat_mensaje', 'like', "%RC-{$todayStr}-%")
                    ->orderBy('created_at', 'desc')
                    ->first();
                    
                $correlativoInt = 1;
                if ($lastMessage) {
                    preg_match("/RC-{$todayStr}-(\d+)/", $lastMessage->sunat_mensaje, $matches);
                    if (isset($matches[1])) {
                        $correlativoInt = intval($matches[1]) + 1;
                    }
                }
                
                $correlativoStr = str_pad($correlativoInt, 3, '0', STR_PAD_LEFT);
                $summaryIdentifier = "RC-{$todayStr}-{$correlativoStr}";
                
                $detalles = [];
                foreach ($dateBoletas as $boleta) {
                    $clientDoc = '00000000';
                    if ($boleta->cliente_id) {
                        $cliente = DB::table('clientes')->where('id', $boleta->cliente_id)->first();
                        if ($cliente) {
                            $clientDoc = $cliente->dni ?: '00000000';
                        }
                    }
                    
                    $clientDocType = '0';
                    if (strlen($clientDoc) === 8) {
                        $clientDocType = '1';
                    } elseif (strlen($clientDoc) === 11) {
                        $clientDocType = '6';
                    }
                    
                    $total = (float)$boleta->total;
                    $igv = (float)($boleta->igv_monto ?? 0);
                    
                    if ($porcentajeIgv > 0) {
                        if ($igv > 0) {
                            $gravado = round($total - $igv, 2);
                        } else {
                            $gravado = round($total / (1 + ($porcentajeIgv / 100)), 2);
                            $igv = round($total - $gravado, 2);
                        }
                        $exonerado = 0.00;
                    } else {
                        $gravado = 0.00;
                        $exonerado = $total;
                        $igv = 0.00;
                    }
                    
                    $detalles[] = [
                        'tipo_documento' => '03',
                        'serie_numero' => $boleta->comprobante_serie . '-' . str_pad($boleta->comprobante_numero, 8, '0', STR_PAD_LEFT),
                        'estado' => '1', // 1 = ADICION
                        'cliente_tipo' => $clientDocType,
                        'cliente_numero' => $clientDoc,
                        'total' => $total,
                        'mto_oper_gravadas' => $gravado,
                        'mto_oper_exoneradas' => $exonerado,
                        'mto_oper_inafectas' => 0.00,
                        'mto_igv' => $igv
                    ];
                }
                
                $summaryData = [
                    'fecha_resumen' => date('Y-m-d'), // Fecha de generación
                    'fecha_generacion' => $emissionDate, // Fecha de referencia
                    'correlativo' => $correlativoStr,
                    'detalles' => $detalles
                ];
                
                $greenterSummary = $greenterService->createSummary($summaryData);
                if (!$greenterSummary) {
                    continue;
                }
                
                $result = $greenterService->sendSummaryDocument($greenterSummary);
                
                if ($result['success'] && $result['ticket']) {
                    $ticket = $result['ticket'];
                    $xmlSigned = $result['xml'];
                    $xmlBase64 = 'data:application/xml;base64,' . base64_encode($xmlSigned ?: '');
                    
                    $sunatEstado = 'pendiente';
                    $sunatMensaje = "Resumen {$summaryIdentifier} registrado. Ticket: {$ticket}";
                    $cdrBase64 = null;
                    
                    // Hacer un pequeño sondeo (polling) para recuperar el CDR final
                    for ($p = 0; $p < 4; $p++) {
                        sleep(2);
                        $statusResult = $greenterService->checkSummaryStatus($ticket);
                        if ($statusResult['success']) {
                            $sunatEstado = 'enviado';
                            $sunatMensaje = "Resumen {$summaryIdentifier} aceptado.";
                            if ($statusResult['cdr_response']) {
                                $sunatMensaje = $statusResult['cdr_response']->getDescription();
                            }
                            if ($statusResult['cdr_zip']) {
                                $cdrBase64 = 'data:application/zip;base64,' . base64_encode($statusResult['cdr_zip']);
                            }
                            break;
                        }
                    }
                    
                    $boletaIds = [];
                    foreach ($dateBoletas as $b) {
                        $boletaIds[] = $b->id;
                    }
                    
                    DB::table('ordenes')
                        ->whereIn('id', $boletaIds)
                        ->update([
                            'sunat_estado' => $sunatEstado,
                            'sunat_mensaje' => $sunatMensaje,
                            'sunat_xml_url' => $xmlBase64,
                            'sunat_cdr_url' => $cdrBase64 ?: DB::raw('sunat_cdr_url'),
                            'updated_at' => now()
                        ]);
                        
                    $summariesSent[] = [
                        'identifier' => $summaryIdentifier,
                        'fecha_referencia' => $emissionDate,
                        'ticket' => $ticket,
                        'cantidad_boletas' => count($dateBoletas),
                        'estado' => $sunatEstado,
                        'mensaje' => $sunatMensaje
                    ];
                } else {
                    $errorMsg = 'Error al enviar resumen: ';
                    if (isset($result['error'])) {
                        $errorMsg .= $result['error']->message ?? 'Error desconocido';
                    }
                    
                    $boletaIds = [];
                    foreach ($dateBoletas as $b) {
                        $boletaIds[] = $b->id;
                    }
                    
                    DB::table('ordenes')
                        ->whereIn('id', $boletaIds)
                        ->update([
                            'sunat_estado' => 'error',
                            'sunat_mensaje' => $errorMsg,
                            'updated_at' => now()
                        ]);
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Procesamiento de resúmenes diarios completado.',
                'summaries' => $summariesSent
            ]);
            
        } catch (Exception $e) {
            Log::error('Error al generar resumen diario de boletas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error de procesamiento: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdfPublic($id)
    {
        $orden = DB::table('ordenes')->where('id', $id)->first();
        if (!$orden) {
            abort(404, 'Comprobante no encontrado.');
        }

        $empresa = DB::table('empresas')->where('id', $orden->empresa_id)->first();
        if (!$empresa) {
            abort(404, 'Empresa no encontrada.');
        }

        // 1. Obtener datos del cliente
        $clienteDoc = '00000000';
        $clienteNombre = 'PÚBLICO EN GENERAL';
        $clienteDireccion = '-';
        $clienteTelefono = '';

        if ($orden->cliente_id) {
            $cliente = DB::table('clientes')->where('id', $orden->cliente_id)->first();
            if ($cliente) {
                $clienteDoc = $cliente->dni ?: '00000000';
                $clienteNombre = $cliente->nombre ?: 'PÚBLICO EN GENERAL';
                $clienteDireccion = $cliente->direccion ?: '-';
                $clienteTelefono = $cliente->telefono ?: '';
            }
        } else {
            $clienteNombre = $orden->cliente_nombre ?: 'PÚBLICO EN GENERAL';
        }

        // 2. Configurar variables del comprobante
        $tc = $orden->tipo_comprobante ?: 'ticket';
        $isElectronico = in_array($tc, ['boleta', 'factura', 'nota_credito']);
        
        $tituloComprobante = 'NOTA DE VENTA';
        if ($tc === 'boleta') $tituloComprobante = 'BOLETA DE VENTA ELECTRÓNICA';
        if ($tc === 'factura') $tituloComprobante = 'FACTURA DE VENTA ELECTRÓNICA';
        if ($tc === 'nota_credito') $tituloComprobante = 'NOTA DE CRÉDITO ELECTRÓNICA';

        $fallbackSerie = $tc === 'factura' 
            ? ($empresa->sunat_serie_factura ?: 'F001') 
            : ($tc === 'nota_credito' ? 'BC01' : ($empresa->sunat_serie_boleta ?: 'B001'));

        $numDoc = $isElectronico
            ? ($orden->comprobante_serie ?: $fallbackSerie) . '-' . str_pad($orden->comprobante_numero ?: 1, 8, '0', STR_PAD_LEFT)
            : 'NP01-' . str_pad($orden->numero ?: 1, 8, '0', STR_PAD_LEFT);

        $labelDoc = strlen($clienteDoc) === 11 ? 'RUC' : 'DNI';

        // 3. Totales e Items
        $items = json_decode($orden->items, true) ?: [];
        $moneda = 'S/';
        
        $delivery = (float)($orden->monto_delivery ?? 0);
        $propina = (float)($orden->propina ?? 0);
        $descuento = (float)($orden->descuento_monto ?? 0);
        
        $totalItems = 0;
        foreach ($items as $it) {
            $totalItems += (float)($it['subtotal'] ?? 0);
        }
        
        $totalTaxable = $totalItems + $delivery - $descuento;
        $config = json_decode($empresa->configuracion, true) ?: [];
        $igvPercent = isset($config['igv']) ? (float)$config['igv'] : 18.00;
        
        $opGravadas = $totalTaxable / (1 + ($igvPercent / 100));
        $igvCalculado = $totalTaxable - $opGravadas;
        $totalPagar = (float)($orden->total ?? ($totalTaxable + $propina));

        $now = new \DateTime($orden->created_at);
        $fechaEmision = $now->format('d/m/Y H:i');

        // 4. Construir el HTML
        $html = '
        <html>
        <head>
          <meta charset="UTF-8">
          <style>
            @page { margin: 0px; }
            body { 
              font-family: monospace; 
              font-size: 9px; 
              font-weight: bold; 
              width: 100%; 
              margin: 0; 
              padding: 5px 8px; 
              color: #000; 
            }
            .center { text-align: center; }
            .title { font-size: 11px; font-weight: bold; margin-bottom: 2px; text-transform: uppercase; }
            .header-box { border: 1px solid #000; padding: 4px; margin: 10px 0; text-align: center; font-size: 10px; }
            .info-row { display: table; width: 100%; margin-bottom: 1px; }
            .info-label { display: table-cell; width: 65px; font-weight: bold; }
            .info-value { display: table-cell; text-align: left; }
            table.items-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            table.items-table th { border-bottom: 1px dashed #000; padding: 3px 0; text-align: left; font-size: 8px; }
            table.items-table td { padding: 3px 0; vertical-align: top; font-size: 8px; }
            .right { text-align: right; }
            table.totals-table { width: 100%; margin-top: 10px; border-collapse: collapse; }
            table.totals-table td { padding: 1px 0; font-size: 8px; }
            .qr-container { text-align: center; margin: 15px 0; }
            .qr-code { width: 100px; height: 100px; }
            .line-dashed { border-top: 1px dashed #000; margin: 5px 0; }
          </style>
        </head>
        <body>
          <div class="center">';
        
        if ($empresa->logo_url) {
            $html .= '<img src="' . $empresa->logo_url . '" style="max-height: 40px; max-width: 120px; margin-bottom: 5px;" /><br>';
        }
        
        $html .= '
            <div class="title">' . htmlspecialchars(strtoupper($empresa->nombre)) . '</div>';
        
        if ($empresa->razon_social && strtolower($empresa->razon_social) !== strtolower($empresa->nombre)) {
            $html .= '<div style="font-size: 9px; font-weight: bold; margin-bottom: 2px;">Razón Social: ' . htmlspecialchars(strtoupper($empresa->razon_social)) . '</div>';
        }
        
        $html .= '
            <div style="font-weight: bold;">RUC: ' . htmlspecialchars($empresa->ruc) . '</div>
            <div>' . htmlspecialchars(strtoupper($empresa->direccion_fiscal ?: 'AV. PRINCIPAL S/N')) . '</div>
          </div>

          <div class="header-box">
            <div style="font-weight: bold;">' . htmlspecialchars($tituloComprobante) . '</div>
            <div style="font-weight: bold;">' . htmlspecialchars($numDoc) . '</div>
          </div>

          <div class="info-row"><span class="info-label">F. Emisión:</span><span class="info-value">' . htmlspecialchars($fechaEmision) . '</span></div>
          <div class="info-row"><span class="info-label">Cliente:</span><span class="info-value">' . htmlspecialchars(strtoupper($clienteNombre)) . '</span></div>
          <div class="info-row"><span class="info-label">' . htmlspecialchars($labelDoc) . ':</span><span class="info-value">' . htmlspecialchars($clienteDoc) . '</span></div>';
        
        if ($clienteTelefono) {
            $html .= '<div class="info-row"><span class="info-label">Teléfono:</span><span class="info-value">' . htmlspecialchars($clienteTelefono) . '</span></div>';
        }
        
        $html .= '
          <div class="info-row"><span class="info-label">Dirección:</span><span class="info-value">' . htmlspecialchars(strtoupper($clienteDireccion)) . '</span></div>';
          
        if ($tc === 'nota_credito') {
            $docAfectadoTipo = $orden->documento_afectado_tipo ?: 'boleta';
            $docAfectadoNum = ($orden->documento_afectado_serie ?: '') . '-' . str_pad($orden->documento_afectado_numero ?: '', 8, '0', STR_PAD_LEFT);
            $html .= '
            <div class="info-row"><span class="info-label">Doc. Afectado:</span><span class="info-value">' . htmlspecialchars(strtoupper($docAfectadoTipo)) . ' ' . htmlspecialchars($docAfectadoNum) . '</span></div>
            <div class="info-row"><span class="info-label">Motivo:</span><span class="info-value">' . htmlspecialchars(strtoupper($orden->notas ?: 'ANULACION DE LA OPERACION')) . '</span></div>';
        }

        $html .= '
          <table class="items-table">
            <thead>
              <tr>
                <th style="width: 12%;">CANT</th>
                <th style="width: 48%;">DESCRIPCIÓN</th>
                <th style="width: 18%; text-align: right;">P.U.</th>
                <th style="width: 22%; text-align: right;">TOTAL</th>
              </tr>
            </thead>
            <tbody>';
        
        foreach ($items as $it) {
            $cant = (float)($it['cantidad'] ?: 1);
            $precio = (float)($it['precio'] ?? (($it['subtotal'] ?? 0) / $cant));
            $subtot = (float)($it['subtotal'] ?? 0);
            
            $html .= '
              <tr>
                <td>' . $cant . '</td>
                <td>
                  ' . htmlspecialchars(strtoupper($it['nombre'])) . '';
            
            if (isset($it['subProductos']) && is_array($it['subProductos']) && count($it['subProductos']) > 0) {
                foreach ($it['subProductos'] as $sub) {
                    $html .= '<br><small style="font-size: 7px;">• ' . htmlspecialchars(strtoupper($sub['nombre'])) . (isset($sub['notas']) && $sub['notas'] ? ' (' . htmlspecialchars(strtoupper($sub['notas'])) . ')' : '') . '</small>';
                }
            } elseif (isset($it['opcionesSeleccionadas']) && $it['opcionesSeleccionadas']) {
                $html .= '<br><small style="font-size: 7px;">Opc: ' . htmlspecialchars(strtoupper($it['opcionesSeleccionadas'])) . '</small>';
            }
            
            if (isset($it['notas']) && $it['notas']) {
                $html .= '<br><small style="font-size: 7px;">Nota: ' . htmlspecialchars(strtoupper($it['notas'])) . '</small>';
            }
            
            $html .= '
                </td>
                <td class="right">' . number_format($precio, 2) . '</td>
                <td class="right">' . number_format($subtot, 2) . '</td>
              </tr>';
        }
        
        $html .= '
            </tbody>
          </table>

          <div class="line-dashed"></div>

          <table class="totals-table">';
        
        if ($delivery > 0) {
            $html .= '<tr><td colspan="3" class="right">DELIVERY:</td><td class="right">' . $moneda . ' ' . number_format($delivery, 2) . '</td></tr>';
        }
        if ($propina > 0) {
            $html .= '<tr><td colspan="3" class="right">PROPINA:</td><td class="right">' . $moneda . ' ' . number_format($propina, 2) . '</td></tr>';
        }
        if ($descuento > 0) {
            $html .= '<tr><td colspan="3" class="right">DESCUENTO:</td><td class="right">-' . $moneda . ' ' . number_format($descuento, 2) . '</td></tr>';
        }
        
        $html .= '
            <tr><td colspan="3" class="right">OP. GRAVADAS:</td><td class="right">' . $moneda . ' ' . number_format($opGravadas, 2) . '</td></tr>
            <tr><td colspan="3" class="right">IGV (' . $igvPercent . '%):</td><td class="right">' . $moneda . ' ' . number_format($igvCalculado, 2) . '</td></tr>
            <tr><td colspan="3" class="right" style="font-weight: bold; border-top: 1px dashed #000; padding-top: 3px;">' . ($tc === 'nota_credito' ? 'TOTAL DEVUELTO:' : 'TOTAL A PAGAR:') . '</td><td class="right" style="font-weight: bold; border-top: 1px dashed #000; padding-top: 3px;">' . $moneda . ' ' . number_format($totalPagar, 2) . '</td></tr>
          </table>

          <div style="margin-top: 8px; font-size: 9px;">
            <strong>CONDICIÓN DE PAGO:</strong> CONTADO<br>
            <strong>MÉTODO DE PAGO:</strong> ' . htmlspecialchars(strtoupper($orden->metodo_pago ?: 'CONTADO')) . '
          </div>';

        $estrategia = ($empresa->plan ?? '') === 'Básico' ? 'sellos' : ($config['estrategia'] ?? 'puntos');
        $estrategiaLabel = strtolower($estrategia) === 'sellos' ? 'SELLOS' : 'PUNTOS';

        if ($tc !== 'factura' && $tc !== 'nota_credito' && isset($orden->puntos_generados) && (int)$orden->puntos_generados > 0) {
            $html .= '
            <div class="center" style="border: 2px dashed #000; padding: 6px; margin: 8px 0; font-size: 10px; line-height: 1.3;">
              ⭐ ¡FELICIDADES! ⭐<br>
              Por tu compra has ganado<br>
              <span style="font-size: 13px; font-weight: bold; display: block; margin-top: 3px;">+' . (int)$orden->puntos_generados . ' ' . $estrategiaLabel . '</span>
            </div>';
        }

        if ($isElectronico) {
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode(
                "{$empresa->ruc}|" . ($tc === 'factura' ? '01' : ($tc === 'nota_credito' ? '07' : '03')) . "|" . ($orden->comprobante_serie) . "|" . str_pad($orden->comprobante_numero, 8, '0', STR_PAD_LEFT) . "|" . number_format($igvCalculado, 2, '.', '') . "|" . number_format($totalPagar, 2, '.', '') . "|" . $now->format('Y-m-d') . "|" . (strlen($clienteDoc) === 11 ? '6' : '1') . "|{$clienteDoc}|" . ($orden->sunat_hash ?: '') . "|"
            );
            
            $html .= '
            <div class="qr-container">
              <img src="' . $qrUrl . '" class="qr-code" />
            </div>
            
            <div class="center" style="font-size: 8px; margin-top: 3px; word-break: break-all;">
              <strong>Representación Digital:</strong><br>' . htmlspecialchars($orden->sunat_hash ?: '-') . '
            </div>';
        }

        $html .= '
          <div class="center" style="font-size: 8.5px; margin-top: 8px; line-height: 1.2;">
            <div>Representación impresa de la</div>
            <div style="font-weight: bold;">' . ($isElectronico ? ($tc === 'boleta' ? 'Boleta de Venta Electrónica' : ($tc === 'nota_credito' ? 'Nota de Crédito Electrónica' : 'Factura de Venta Electrónica')) : 'Nota de Venta') . '</div>';
            
        if ($isElectronico) {
            $html .= '<div style="font-size: 7.5px; margin-top: 1px;">Autorizado mediante resolución SUNAT</div>';
            if ($empresa->web_consulta) {
                $html .= '<div style="font-size: 7px; margin-top: 2px; word-break: break-all;">Consulte su comprobante en:<br>' . htmlspecialchars($empresa->web_consulta) . '</div>';
            }
        }
        
        $html .= '
            <br>
            <div style="font-weight: bold;">¡Gracias por su preferencia!</div>
          </div>
        </body>
        </html>';

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Courier');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        
        $width = 80 * 2.834645669;
        $height = 200 * 2.834645669;
        $dompdf->setPaper([0, 0, $width, $height], 'portrait');
        
        $dompdf->render();
        
        $filename = ($orden->comprobante_serie ?: 'DOC') . '-' . str_pad($orden->comprobante_numero ?: 1, 8, '0', STR_PAD_LEFT) . '.pdf';
        
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
