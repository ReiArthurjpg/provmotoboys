<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MotoboyRepository;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Redis;
use Throwable;

final class CatalogController
{
    public function __construct(
        private readonly MotoboyRepository $repository,
        private readonly ?Redis $redis,
        private readonly string $currentTermsVersion = '1.1'
    ) {
    }

    public function listBanks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cacheKey = 'catalog:banks:v1';

        if ($this->redis !== null) {
            try {
                $cached = $this->redis->get($cacheKey);
                if (is_string($cached) && $cached !== '') {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        return JsonResponse::send($response, ['items' => $decoded, 'cached' => true]);
                    }
                }
            } catch (Throwable) {
            }
        }

        $banks = $this->repository->listAllBanks();

        if ($this->redis !== null && $banks !== []) {
            try {
                $this->redis->setex($cacheKey, 3600, (string) json_encode($banks, JSON_UNESCAPED_UNICODE));
            } catch (Throwable) {
            }
        }

        return JsonResponse::send($response, ['items' => $banks, 'cached' => false]);
    }
}

