<?php

/**
 * Prueba de notas de crédito sin SII ni folios reales (ambiente 'test', CAF y
 * certificado falsos). Emite una boleta, la marca aceptada y lanza 4 procesos
 * que intentan anularla a la vez: solo 1 debe lograrlo y gastar 1 folio.
 * Verifica también la nota de crédito generada y los casos de error. Limpia al final.
 *
 * Uso: php scripts/prueba_nc_concurrencia.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Emisor;
use App\NotasCredito;
use Derafu\Certificate\Service\CertificateFaker;
use Derafu\Certificate\Service\CertificateLoader;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Emisor as EmisorEntidad;

$dir = __DIR__ . '/../var/pruebas/nc_test';
$empresa = require __DIR__ . '/../config/empresa.php';
$empresa['resolucion']['test'] = $empresa['resolucion']['cert'];
$billing = libredte()->getPackageRegistry()->getBillingPackage();
$certificado = (new CertificateFaker(new CertificateLoader()))->createFake(id: '11111111-1');
$args = [db(), $billing, $certificado, $empresa, 'test', $dir];

if (($argv[1] ?? '') === '--hijo') {
    try {
        $folio = (new NotasCredito(...$args))->anular((int) $argv[2])['folio'];
        echo "OK $folio", PHP_EOL;
    } catch (RuntimeException $e) {
        echo 'ERROR ', $e->getMessage(), PHP_EOL;
    }
    exit(0);
}

$db = db();
$limpiar = function () use ($db, $dir): void {
    $db->exec("DELETE FROM notas_credito WHERE ambiente = 'test'");
    $db->exec("DELETE FROM boletas WHERE ambiente = 'test'");
    $db->exec("DELETE FROM cafs WHERE ambiente = 'test'");
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
};
$limpiar();
@mkdir($dir, 0750, true);

// CAF falsos: boleta 39 (folio 1) y nota de crédito 61 (folios 1 a 10).
$entidad = new EmisorEntidad($empresa['rut'], $empresa['razon_social']);
foreach ([[39, 1, 1], [61, 1, 10]] as [$tipo, $desde, $hasta]) {
    $caf = $billing->getIdentifierComponent()->getCafFakerWorker()->create($entidad, $tipo, $desde, $hasta);
    file_put_contents("$dir/caf_$tipo.xml", $caf->getXml());
    $db->prepare("INSERT INTO cafs (ambiente, tipo_dte, folio_desde, folio_hasta, siguiente_folio, fecha_autorizacion, archivo)
                  VALUES ('test', ?, ?, ?, ?, CURDATE(), ?)")->execute([$tipo, $desde, $hasta, $desde, "caf_$tipo.xml"]);
}

// Boleta: el envío falla (ambiente 'test' no tiene SII), queda 'emitida'.
$boleta = (new Emisor(...$args))->emitir([
    ['nombre' => 'Cuaderno', 'cantidad' => 2, 'precio' => 1990],
    ['nombre' => 'Lápiz', 'cantidad' => 1, 'precio' => 350],
]);
$notas = new NotasCredito(...$args);

$errorNoAceptada = null;
try {
    $notas->anular((int) $boleta['id']);
} catch (RuntimeException $e) {
    $errorNoAceptada = $e->getMessage();
}
$db->prepare("UPDATE boletas SET estado = 'aceptada' WHERE id = ?")->execute([$boleta['id']]);

// 4 procesos anulando la misma boleta a la vez.
$procesos = [];
for ($p = 0; $p < 4; $p++) {
    $procesos[] = popen(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " --hijo {$boleta['id']} 2>/dev/null", 'r');
}
$resultados = [];
foreach ($procesos as $proc) {
    $resultados[] = trim((string) stream_get_contents($proc));
    pclose($proc);
}
$exitos = array_values(array_filter($resultados, fn ($r) => str_starts_with($r, 'OK ')));
$yaTiene = array_filter($resultados, fn ($r) => str_contains($r, 'ya tiene una nota de crédito'));

$filas = $db->query("SELECT id, folio, estado FROM notas_credito WHERE ambiente = 'test'")->fetchAll(PDO::FETCH_ASSOC);
$siguiente = (int) $db->query("SELECT siguiente_folio FROM cafs WHERE ambiente = 'test' AND tipo_dte = 61")->fetchColumn();

echo 'Anular boleta no aceptada: ', $errorNoAceptada ? "rechazado ($errorNoAceptada)" : 'FALLA: se permitió', PHP_EOL;
echo 'Procesos simultáneos: ', json_encode($resultados, JSON_UNESCAPED_UNICODE), PHP_EOL;
echo 'Exitosos: ', count($exitos), ' (esperado 1) | "ya tiene": ', count($yaTiene), ' (esperado 3)', PHP_EOL;
echo 'Notas de crédito en BD: ', count($filas), ' (esperado 1) | siguiente folio CAF 61: ', $siguiente, ' (esperado 2)', PHP_EOL;

if (count($filas) === 1) {
    $nc = $notas->buscar((int) $filas[0]['id']);
    $docs = $billing->getDocumentComponent();
    $d = $docs->loadXml($nc['xml'])[0]->getDocument()->getData();
    $b = $docs->loadXml($boleta['xml'])[0]->getDocument()->getData();
    $docs->getValidatorWorker()->validateSchema($nc['xml']);
    echo 'NC estado: ', $nc['estado'], ' (esperado emitida: sin SII en test)', PHP_EOL;
    echo 'NC esquema: OK | firma: ', $docs->getValidatorWorker()->validateSignature($nc['xml'])->isValid() ? 'válida' : 'INVÁLIDA', PHP_EOL;
    echo 'Totales iguales a la boleta: ', $d['Encabezado']['Totales']['MntTotal'] === $b['Encabezado']['Totales']['MntTotal']
        && $d['Encabezado']['Totales']['IVA'] === $b['Encabezado']['Totales']['IVA'] ? 'SI' : 'NO', ' (', $d['Encabezado']['Totales']['MntTotal'], ')', PHP_EOL;
    echo 'Receptor copiado: ', json_encode($d['Encabezado']['Receptor'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    echo 'Referencia: ', json_encode($d['Referencia'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    echo 'PDF: ', strlen($notas->pdf((int) $nc['id'])), ' bytes', PHP_EOL;
}

try {
    $notas->anular(999999999);
    echo "Boleta inexistente: FALLA: se permitió\n";
} catch (RuntimeException $e) {
    echo 'Boleta inexistente: rechazado (', $e->getMessage(), ')', PHP_EOL;
}

$limpiar();
