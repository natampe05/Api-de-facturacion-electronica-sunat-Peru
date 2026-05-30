<?php

function pkcs8_to_pkcs1($pkcs8Base64) {
    $der = base64_decode($pkcs8Base64);
    if (!$der) return false;
    
    // Find rsaEncryption OID: \x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01
    $oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
    $pos = strpos($der, $oid);
    if ($pos === false) return false;
    
    $seqPos = strpos($der, "\x30", $pos + strlen($oid));
    if ($seqPos === false) return false;
    
    $pkcs1Der = substr($der, $seqPos);
    
    $pem = "-----BEGIN RSA PRIVATE KEY-----\n";
    $pem .= rtrim(chunk_split(base64_encode($pkcs1Der), 64, "\n"), "\n") . "\n";
    $pem .= "-----END RSA PRIVATE KEY-----";
    
    return $pem;
}

$pemFile = 'c:\\Users\\Anibal\\Documents\\CyC-Loyal\\api-src-temp\\ejemplos-postman\\certificado_prueba\\certificado.pem';
$pem = file_get_contents($pemFile);

preg_match('/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s', $pem, $matches);
$privateKeyBase64 = preg_replace('/\s+/', '', $matches[1]);

$convertedPem = pkcs8_to_pkcs1($privateKeyBase64);

$originalRsa = file_get_contents('c:\\Users\\Anibal\\Documents\\CyC-Loyal\\api-src-temp\\ejemplos-postman\\certificado_prueba\\certificado_rsa.pem');

$derOrig = base64_decode(preg_replace('/\s+/', '', str_replace(['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----'], '', $originalRsa)));
$derConv = base64_decode(preg_replace('/\s+/', '', str_replace(['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----'], '', $convertedPem)));

echo "Orig length: " . strlen($derOrig) . "\n";
echo "Conv length: " . strlen($derConv) . "\n";
echo "Orig first 30 bytes: " . bin2hex(substr($derOrig, 0, 30)) . "\n";
echo "Conv first 30 bytes: " . bin2hex(substr($derConv, 0, 30)) . "\n";
