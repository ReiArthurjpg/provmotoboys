<?php

declare(strict_types=1);

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix)) {
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = __DIR__ . '/../src/' . $relative . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
require_once __DIR__ . '/../src/Support/StandaloneRuntime.php';

use App\Controller\CatalogController;
use App\Controller\HealthController;
use App\Controller\MotoboyController;
use App\Middleware\AdminApiKeyMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\IdempotencyMiddleware;
use App\Middleware\JsonErrorMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Repository\MotoboyRepository;
use App\Support\JsonResponse;
use App\Support\SimpleContainer;
use App\Support\StandaloneApp;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Carrega variáveis de ambiente do .env (ou .env.prod como fallback)
(static function (string $rootDir): void {
    $envFiles = [$rootDir . '/.env', $rootDir . '/.env.prod'];
    foreach ($envFiles as $envFile) {
        if (!is_file($envFile) || !is_readable($envFile)) {
            continue;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($name !== '' && !isset($_ENV[$name]) && getenv($name) === false) {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
        break;
    }
})(dirname(__DIR__));

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

$container = new SimpleContainer([
    PDO::class => static function (): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $db = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'logmovy_cadastro');
        $user = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'logmovy_user');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: 'logmovy_pass_secret');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 2,
        ];

        // 1. Prioridade máxima: conectar diretamente na mesma instância MySQL do C:\git\CadastroMotoboys (porta 3307 no host Docker)
        $sharedHost = (string) ($_ENV['SHARED_DB_HOST'] ?? getenv('SHARED_DB_HOST') ?: '');
        $sharedPort = (string) ($_ENV['SHARED_DB_PORT'] ?? getenv('SHARED_DB_PORT') ?: '3307');
        if ($sharedHost !== '') {
            try {
                $sharedIp = gethostbyname($sharedHost);
                $sharedDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $sharedIp, $sharedPort, $db);
                $pdo = new PDO($sharedDsn, $user, $pass, $options);
                return $pdo;
            } catch (Throwable) {
                // Se o container logmovy_mysql (3307) não estiver rodando, faz fallback transparente para DB_HOST
            }
        }

        // 2. Conexão padrão (container mysql local ou banco de produção Hostinger u865404728_LogCadastroMot)
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306');
        $ip = gethostbyname($host);
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $ip, $port, $db);

        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    },

    Redis::class => static function (): ?Redis {
        static $redis = null;
        static $initialized = false;
        if ($initialized) {
            return $redis;
        }
        $initialized = true;
        if (!class_exists(Redis::class)) {
            return null;
        }
        try {
            $client = new Redis();
            $host = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
            $ip = gethostbyname((string) $host);
            $connected = @$client->pconnect($ip, $port, 0.5, 'logmovy_motoboys_pool');
            if (!$connected) {
                return null;
            }
            $redis = $client;
            return $redis;
        } catch (Throwable) {
            return null;
        }
    },

    MotoboyRepository::class => static fn (ContainerInterface $c): MotoboyRepository => new MotoboyRepository(
        $c->get(PDO::class)
    ),

    MotoboyController::class => static fn (ContainerInterface $c): MotoboyController => new MotoboyController(
        $c->get(MotoboyRepository::class),
        (string) ($_ENV['TERMS_CURRENT_VERSION'] ?? getenv('TERMS_CURRENT_VERSION') ?: '1.1')
    ),

    CatalogController::class => static fn (ContainerInterface $c): CatalogController => new CatalogController(
        $c->get(MotoboyRepository::class),
        $c->get(Redis::class),
        (string) ($_ENV['TERMS_CURRENT_VERSION'] ?? getenv('TERMS_CURRENT_VERSION') ?: '1.1')
    ),

    HealthController::class => static fn (ContainerInterface $c): HealthController => new HealthController(
        $c->get(PDO::class),
        $c->get(Redis::class)
    ),
]);

$app = new StandaloneApp($container);

// Detecta automaticamente o BasePath para subdiretórios (ex.: /Motoboys na Hostinger)
$requestUriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

$detectedBasePath = '';
if ($scriptDir !== '' && $scriptDir !== '/') {
    if (str_starts_with($requestUriPath, $scriptDir)) {
        $detectedBasePath = $scriptDir;
    } elseif (str_ends_with($scriptDir, '/public')) {
        $parentDir = substr($scriptDir, 0, -7);
        if ($parentDir !== '' && str_starts_with($requestUriPath, $parentDir)) {
            $detectedBasePath = $parentDir;
        }
    }
}

if ($detectedBasePath === '') {
    $configuredBasePath = '/' . trim((string) ($_ENV['APP_BASE_PATH'] ?? getenv('APP_BASE_PATH') ?: ''), '/');
    if ($configuredBasePath !== '/' && str_starts_with($requestUriPath, $configuredBasePath)) {
        $detectedBasePath = $configuredBasePath;
    }
}

if ($detectedBasePath !== '') {
    $app->setBasePath($detectedBasePath);
}

$redisInstance = $container->get(Redis::class);
$app->add(new IdempotencyMiddleware($redisInstance));
$app->add(new RateLimitMiddleware(
    $redisInstance,
    (int) ($_ENV['RATE_LIMIT_GENERAL_PER_MIN'] ?? getenv('RATE_LIMIT_GENERAL_PER_MIN') ?: 300)
));

$debug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
$app->add(new JsonErrorMiddleware($debug));
$app->add(new CorsMiddleware(
    (string) ($_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS') ?: '*')
));

$app->get('/', static function (ServerRequestInterface $request, ResponseInterface $response) use ($app): ResponseInterface {
    $base = $app->getBasePath();
    return JsonResponse::send($response, [
        'name' => 'LogMovy - API de Gestão e Homologação de Motoboys',
        'version' => '1.0.0',
        'port' => 8020,
        'database' => 'Compartilhado com CadastroMotoboys (logmovy_cadastro / u865404728_LogCadastroMot)',
        'docs' => $base . '/docs',
        'openapi' => $base . '/openapi.json',
        'health' => $base . '/health',
        'endpoints' => [
            'stats' => 'GET ' . $base . '/api/v1/admin/motoboys/stats',
            'list' => 'GET ' . $base . '/api/v1/admin/motoboys',
            'detail' => 'GET ' . $base . '/api/v1/admin/motoboys/{id}',
            'create' => 'POST ' . $base . '/api/v1/admin/motoboys',
            'update_status' => 'PATCH ' . $base . '/api/v1/admin/motoboys/{id}/status',
            'delete' => 'DELETE ' . $base . '/api/v1/admin/motoboys/{id}',
            'banks' => 'GET ' . $base . '/api/v1/banks',
        ],
    ]);
});

$app->get('/health', [HealthController::class, 'check']);

$app->get('/openapi.json', static function (ServerRequestInterface $request, ResponseInterface $response) use ($app): ResponseInterface {
    $staticFile = __DIR__ . '/../docs/openapi.json';
    $basePath = $app->getBasePath();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https'
        : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8020';
    $currentServerUrl = rtrim($scheme . '://' . $host . $basePath, '/');

    if (is_file($staticFile)) {
        $raw = (string) file_get_contents($staticFile);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $decoded['servers'] = [
                ['url' => $currentServerUrl, 'description' => 'Servidor Atual (' . $host . ')'],
                ['url' => 'http://localhost:8020', 'description' => 'Servidor Docker Local (Porta 8020)'],
                ['url' => 'https://logmovy.kodyvera.com/Motoboys', 'description' => 'Produção Hostinger (LogMovy)'],
            ];
            $response->getBody()->write((string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        $response->getBody()->write($raw);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    return JsonResponse::send($response, ['error' => 'openapi.json not found'], 404);
});

$app->get('/docs', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $htmlFile = __DIR__ . '/../docs/index.html';
    $response->getBody()->write((string) file_get_contents($htmlFile));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->group('/api/v1', function ($group): void {
    $group->get('/banks', [CatalogController::class, 'listBanks']);

    // Rotas diretas (/api/v1/motoboys...) e rotas admin (/api/v1/admin/motoboys...) protegidas por X-API-Key
    $adminKey = (string) ($_ENV['ADMIN_API_KEY'] ?? getenv('ADMIN_API_KEY') ?: 'lmv_admin_secret_key_2026_prod');
    $authMiddleware = new AdminApiKeyMiddleware($adminKey);

    $group->group('/motoboys', function ($m): void {
        $m->get('/stats', [MotoboyController::class, 'stats']);
        $m->get('', [MotoboyController::class, 'list']);
        $m->post('', [MotoboyController::class, 'create']);
        $m->get('/{id}', [MotoboyController::class, 'detail']);
        $m->patch('/{id}/status', [MotoboyController::class, 'updateStatus']);
        $m->delete('/{id}', [MotoboyController::class, 'delete']);
    })->add($authMiddleware);

    $group->group('/admin', function ($admin): void {
        $admin->get('/motoboys/stats', [MotoboyController::class, 'stats']);
        $admin->get('/motoboys', [MotoboyController::class, 'list']);
        $admin->post('/motoboys', [MotoboyController::class, 'create']);
        $admin->get('/motoboys/{id}', [MotoboyController::class, 'detail']);
        $admin->patch('/motoboys/{id}/status', [MotoboyController::class, 'updateStatus']);
        $admin->delete('/motoboys/{id}', [MotoboyController::class, 'delete']);
    })->add($authMiddleware);
});

$app->run();

