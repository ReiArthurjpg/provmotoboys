<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Redis;
use Throwable;

final class HealthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Redis $redis
    ) {
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $dbOk = false;
        $dbName = null;
        try {
            $stmt = $this->pdo->query('SELECT DATABASE()');
            if ($stmt !== false) {
                $dbOk = true;
                $dbName = (string) $stmt->fetchColumn();
            }
        } catch (Throwable) {
            $dbOk = false;
        }

        $redisOk = false;
        if ($this->redis !== null) {
            try {
                $ping = $this->redis->ping();
                $redisOk = $ping === true || $ping === '+PONG' || $ping === 'PONG';
            } catch (Throwable) {
                $redisOk = false;
            }
        }

        $healthy = $dbOk;
        $status = $healthy ? 200 : 503;

        return JsonResponse::send($response, [
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'logmovy-motoboys-management-api',
            'port' => 8020,
            'database' => $dbName,
            'checks' => [
                'mysql' => $dbOk ? 'up' : 'down',
                'redis' => $redisOk ? 'up' : 'down',
            ],
            'timestamp' => date(DATE_ATOM),
        ], $status);
    }
}

