<?php

declare(strict_types=1);

namespace App;

use Derafu\Certificate\Contract\CertificateInterface;

/**
 * Firma la semilla del SII (getToken) en formato XML-DSig compacto.
 *
 * La firma generada por derafu/signature es rechazada por el SII con
 * ESTADO 11 ("elemento Certificate no existe"), tanto en REST como SOAP.
 */
final class SiiSemilla
{
    public static function firmar(string $semilla, CertificateInterface $certificate): string
    {
        $doc = "<getToken><item><Semilla>$semilla</Semilla></item></getToken>";
        $digest = base64_encode(sha1($doc, true)); // $doc ya está en forma canónica.

        $signedInfo = '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"></CanonicalizationMethod>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"></SignatureMethod>'
            . '<Reference URI=""><Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"></Transform></Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"></DigestMethod>'
            . "<DigestValue>$digest</DigestValue></Reference>";

        $privateKey = $certificate->getPrivateKey();
        openssl_sign(
            '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">' . $signedInfo . '</SignedInfo>',
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA1
        );
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['rsa'];

        $signatureXml = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . "<SignedInfo>$signedInfo</SignedInfo>"
            . '<SignatureValue>' . base64_encode($signature) . '</SignatureValue>'
            . '<KeyInfo><KeyValue><RSAKeyValue>'
            . '<Modulus>' . base64_encode($rsa['n']) . '</Modulus>'
            . '<Exponent>' . base64_encode($rsa['e']) . '</Exponent>'
            . '</RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $certificate->getCertificate(true) . '</X509Certificate></X509Data>'
            . '</KeyInfo></Signature>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . str_replace('</getToken>', "$signatureXml</getToken>", $doc);
    }
}
