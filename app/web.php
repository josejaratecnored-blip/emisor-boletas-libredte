<?php

/**
 * Punto de entrada web: panel (sesión) y API (token Bearer).
 * public_html/index.php solo incluye este archivo.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

try {
    if (str_starts_with($path, '/api/')) {
        api($method, $path);
    } elseif (preg_match('#^/pdf/(nc/)?(\d+)/([a-f0-9]{32})$#', $path, $m)) {
        pdf($m[1] === 'nc/' ? 'nc' : 'boleta', (int) $m[2], $m[3]);
    } else {
        panel($method, $path);
    }
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo 'Error interno';
}

// ---------------------------------------------------------------- comunes

function ambiente(): string
{
    return env('AMBIENTE') ?? 'cert';
}

function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/**
 * @param string $tipo 'boleta' o 'nc' (nota de crédito).
 */
function urlPdf(int $id, string $tipo = 'boleta'): string
{
    $ruta = $tipo === 'nc' ? "/pdf/nc/$id/" : "/pdf/$id/";

    return 'https://' . $_SERVER['HTTP_HOST'] . $ruta . firmaPdf($id, $tipo);
}

function firmaPdf(int $id, string $tipo): string
{
    // Las boletas conservan el formato original para no invalidar enlaces ya entregados.
    $mensaje = ambiente() . ($tipo === 'nc' ? ":pdf-nc:$id" : ":pdf:$id");

    return substr(hash_hmac('sha256', $mensaje, (string) env('APP_KEY')), 0, 32);
}

function pdf(string $tipo, int $id, string $firma): never
{
    if (!hash_equals(firmaPdf($id, $tipo), $firma)) {
        http_response_code(404);
        exit('No encontrado');
    }
    try {
        $pdf = $tipo === 'nc' ? notasCredito(ambiente())->pdf($id) : emisor(ambiente())->pdf($id);
    } catch (RuntimeException) {
        http_response_code(404);
        exit('No encontrado');
    }
    $nombre = $tipo === 'nc' ? "nota-credito-$id.pdf" : "boleta-$id.pdf";
    header('Content-Type: application/pdf');
    header("Content-Disposition: inline; filename=\"$nombre\"");
    echo $pdf;
    exit;
}

// ---------------------------------------------------------------- API

function json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function boletaJson(array $b): array
{
    return [
        'id' => (int) $b['id'],
        'folio' => (int) $b['folio'],
        'estado' => $b['estado'],
        'fecha_emision' => $b['fecha_emision'],
        'monto_total' => $b['monto_total'] === null ? null : (int) $b['monto_total'],
        'track_id' => $b['track_id'] === null ? null : (int) $b['track_id'],
        'pdf_url' => $b['xml'] === null ? null : urlPdf((int) $b['id']),
    ];
}

function notaCreditoJson(array $nc): array
{
    return [
        'id' => (int) $nc['id'],
        'folio' => (int) $nc['folio'],
        'boleta_id' => (int) $nc['boleta_id'],
        'estado' => $nc['estado'],
        'fecha_emision' => $nc['fecha_emision'],
        'monto_total' => $nc['monto_total'] === null ? null : (int) $nc['monto_total'],
        'track_id' => $nc['track_id'] === null ? null : (int) $nc['track_id'],
        'pdf_url' => $nc['xml'] === null ? null : urlPdf((int) $nc['id'], 'nc'),
    ];
}

function api(string $method, string $path): never
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = (string) env('API_TOKEN');
    if ($token === '' || !hash_equals("Bearer $token", $auth)) {
        json(401, ['error' => 'No autorizado']);
    }

    try {
        if ($method === 'POST' && $path === '/api/boletas') {
            $entrada = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($entrada) || !is_array($entrada['items'] ?? null)) {
                json(422, ['error' => 'Se espera JSON con "items": [{"nombre", "cantidad", "precio"}].']);
            }
            json(201, boletaJson(emisor(ambiente())->emitir($entrada['items'])));
        }

        if ($method === 'GET' && preg_match('#^/api/boletas/(\d+)$#', $path, $m)) {
            $emisor = emisor(ambiente());
            $boleta = $emisor->buscar((int) $m[1]);
            if ($boleta['estado'] === 'enviada') {
                $boleta = $emisor->actualizarEstado((int) $m[1]);
            }
            json(200, boletaJson($boleta));
        }

        if ($method === 'POST' && preg_match('#^/api/boletas/(\d+)/anular$#', $path, $m)) {
            json(201, notaCreditoJson(notasCredito(ambiente())->anular((int) $m[1])));
        }

        if ($method === 'GET' && preg_match('#^/api/notas-credito/(\d+)$#', $path, $m)) {
            $notas = notasCredito(ambiente());
            $nc = $notas->buscar((int) $m[1]);
            if ($nc['estado'] === 'enviada') {
                $nc = $notas->actualizarEstado((int) $m[1]);
            }
            json(200, notaCreditoJson($nc));
        }

        json(404, ['error' => 'Ruta no encontrada']);
    } catch (InvalidArgumentException $e) {
        json(422, ['error' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        $status = match (true) {
            str_starts_with($e->getMessage(), 'No existe') => 404,
            str_contains($e->getMessage(), 'ya tiene una nota de crédito'),
            str_starts_with($e->getMessage(), 'Solo se anulan') => 409,
            default => 400,
        };
        json($status, ['error' => $e->getMessage()]);
    }
}

// ---------------------------------------------------------------- panel

function panel(string $method, string $path): never
{
    session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
    session_name('boletas');
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));

    if ($method === 'POST' && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud inválida, recarga la página.');
    }

    if ($path === '/login') {
        $error = null;
        if ($method === 'POST') {
            $stmt = db()->prepare('SELECT id, nombre, password_hash FROM usuarios WHERE email = ?');
            $stmt->execute([trim((string) ($_POST['email'] ?? ''))]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($usuario && password_verify((string) ($_POST['password'] ?? ''), $usuario['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['usuario'] = ['id' => (int) $usuario['id'], 'nombre' => $usuario['nombre']];
                redirigir('/');
            }
            usleep(700_000);
            $error = 'Correo o contraseña incorrectos.';
        }
        vista('login', ['error' => $error]);
    }

    if (!isset($_SESSION['usuario'])) {
        redirigir('/login');
    }

    if ($method === 'POST' && $path === '/logout') {
        session_destroy();
        redirigir('/login');
    }

    if ($method === 'POST' && $path === '/emitir') {
        $items = [];
        foreach ((array) ($_POST['nombre'] ?? []) as $i => $nombre) {
            if (trim((string) $nombre) === '') {
                continue;
            }
            $items[] = ['nombre' => $nombre, 'cantidad' => $_POST['cantidad'][$i] ?? null, 'precio' => $_POST['precio'][$i] ?? null];
        }
        try {
            $boleta = emisor(ambiente())->emitir($items);
            flash("Boleta folio {$boleta['folio']} emitida por $" . number_format((int) $boleta['monto_total'], 0, ',', '.') . " (estado: {$boleta['estado']}).");
        } catch (InvalidArgumentException | RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirigir('/');
    }

    if ($method === 'POST' && preg_match('#^/boletas/(\d+)/(estado|reenviar)$#', $path, $m)) {
        try {
            $emisor = emisor(ambiente());
            $boleta = $m[2] === 'estado' ? $emisor->actualizarEstado((int) $m[1]) : $emisor->reenviar((int) $m[1]);
            flash("Folio {$boleta['folio']}: {$boleta['estado']}.");
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirigir('/');
    }

    if ($method === 'POST' && preg_match('#^/boletas/(\d+)/anular$#', $path, $m)) {
        try {
            $nc = notasCredito(ambiente())->anular((int) $m[1]);
            flash("Nota de crédito folio {$nc['folio']} emitida (estado: {$nc['estado']}).");
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirigir('/notas-credito');
    }

    if ($method === 'POST' && preg_match('#^/notas-credito/(\d+)/(estado|reenviar)$#', $path, $m)) {
        try {
            $notas = notasCredito(ambiente());
            $nc = $m[2] === 'estado' ? $notas->actualizarEstado((int) $m[1]) : $notas->reenviar((int) $m[1]);
            flash("Nota de crédito folio {$nc['folio']}: {$nc['estado']}.");
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirigir('/notas-credito');
    }

    if ($method === 'GET' && $path === '/') {
        vista('panel', ['boletas' => emisor(ambiente())->listar()]);
    }

    if ($method === 'GET' && $path === '/notas-credito') {
        $folio = filter_var($_GET['folio'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        vista('notas_credito', [
            'notas' => notasCredito(ambiente())->listar(),
            'folioBuscado' => $folio,
            'boleta' => $folio === null ? null : emisor(ambiente())->buscarPorFolio($folio),
        ]);
    }

    http_response_code(404);
    exit('No encontrado');
}

function flash(string $mensaje, string $tipo = 'ok'): void
{
    $_SESSION['flash'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
}

function redirigir(string $url): never
{
    header("Location: $url", true, 303);
    exit;
}

function vista(string $nombre, array $datos): never
{
    extract($datos);
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $csrf = $_SESSION['csrf'];
    $usuario = $_SESSION['usuario'] ?? null;
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    require __DIR__ . "/views/$nombre.php";
    exit;
}
