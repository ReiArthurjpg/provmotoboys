<?php

declare(strict_types=1);

namespace Psr\Container {
    if (!interface_exists(ContainerInterface::class)) {
        interface ContainerInterface
        {
            public function get(string $id): mixed;
            public function has(string $id): bool;
        }
    }
}

namespace Psr\Http\Message {
    if (!interface_exists(UriInterface::class)) {
        interface UriInterface
        {
            public function getPath(): string;
            public function __toString(): string;
        }
    }

    if (!interface_exists(StreamInterface::class)) {
        interface StreamInterface
        {
            public function write(string $string): int;
            public function __toString(): string;
        }
    }

    if (!interface_exists(MessageInterface::class)) {
        interface MessageInterface
        {
            public function getHeaderLine(string $name): string;
            /** @return array<string, array<int, string>> */
            public function getHeaders(): array;
            public function withHeader(string $name, $value): static;
            public function getBody(): StreamInterface;
        }
    }

    if (!interface_exists(RequestInterface::class)) {
        interface RequestInterface extends MessageInterface
        {
            public function getMethod(): string;
            public function getUri(): UriInterface;
        }
    }

    if (!interface_exists(ServerRequestInterface::class)) {
        interface ServerRequestInterface extends RequestInterface
        {
            /** @return array<string, mixed> */
            public function getServerParams(): array;
            /** @return array<string, mixed> */
            public function getQueryParams(): array;
            public function getParsedBody(): mixed;
        }
    }

    if (!interface_exists(ResponseInterface::class)) {
        interface ResponseInterface extends MessageInterface
        {
            public function getStatusCode(): int;
            public function withStatus(int $code, string $reasonPhrase = ''): static;
        }
    }
}

namespace Psr\Http\Server {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;

    if (!interface_exists(RequestHandlerInterface::class)) {
        interface RequestHandlerInterface
        {
            public function handle(ServerRequestInterface $request): ResponseInterface;
        }
    }

    if (!interface_exists(MiddlewareInterface::class)) {
        interface MiddlewareInterface
        {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
        }
    }
}

namespace Slim\Exception {
    use RuntimeException;

    if (!class_exists(HttpException::class)) {
        class HttpException extends RuntimeException
        {
        }
    }
}

namespace Slim\Psr7 {
    use Psr\Http\Message\MessageInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UriInterface;

    if (!class_exists(SimpleStream::class)) {
        final class SimpleStream implements StreamInterface
        {
            public function __construct(private string $buffer = '')
            {
            }

            public function write(string $string): int
            {
                $this->buffer .= $string;
                return strlen($string);
            }

            public function __toString(): string
            {
                return $this->buffer;
            }

            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return strlen($this->buffer); }
            public function tell(): int { return strlen($this->buffer); }
            public function eof(): bool { return true; }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return true; }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { return $this->buffer; }
            public function getContents(): string { return $this->buffer; }
            public function getMetadata(?string $key = null): mixed { return $key === null ? [] : null; }
        }
    }

    if (!class_exists(SimpleUri::class)) {
        final class SimpleUri implements UriInterface
        {
            public function __construct(private readonly string $path)
            {
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function __toString(): string
            {
                return $this->path;
            }

            public function getScheme(): string { return 'http'; }
            public function getAuthority(): string { return ''; }
            public function getUserInfo(): string { return ''; }
            public function getHost(): string { return 'localhost'; }
            public function getPort(): ?int { return null; }
            public function getQuery(): string { return ''; }
            public function getFragment(): string { return ''; }
            public function withScheme(string $scheme): UriInterface { return $this; }
            public function withUserInfo(string $user, ?string $password = null): UriInterface { return $this; }
            public function withHost(string $host): UriInterface { return $this; }
            public function withPort(?int $port): UriInterface { return $this; }
            public function withPath(string $path): UriInterface { return new self($path); }
            public function withQuery(string $query): UriInterface { return $this; }
            public function withFragment(string $fragment): UriInterface { return $this; }
        }
    }

    if (!class_exists(Response::class)) {
        class Response implements ResponseInterface
        {
            /** @var array<string, array<int, string>> */
            private array $headers = [];
            private SimpleStream $body;

            public function __construct(private int $status = 200)
            {
                $this->body = new SimpleStream();
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function withStatus(int $code, string $reasonPhrase = ''): static
            {
                $clone = clone $this;
                $clone->status = $code;
                return $clone;
            }

            public function getHeaderLine(string $name): string
            {
                foreach ($this->headers as $k => $values) {
                    if (strcasecmp($k, $name) === 0) {
                        return implode(', ', $values);
                    }
                }
                return '';
            }

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function withHeader(string $name, $value): static
            {
                $clone = clone $this;
                foreach (array_keys($clone->headers) as $existing) {
                    if (strcasecmp($existing, $name) === 0) {
                        unset($clone->headers[$existing]);
                    }
                }
                $clone->headers[$name] = is_array($value) ? array_map('strval', $value) : [(string) $value];
                return $clone;
            }

            public function getBody(): StreamInterface
            {
                return $this->body;
            }

            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion(string $version): MessageInterface { return $this; }
            public function hasHeader(string $name): bool { return $this->getHeaderLine($name) !== ''; }
            public function getHeader(string $name): array {
                foreach ($this->headers as $k => $values) {
                    if (strcasecmp($k, $name) === 0) return $values;
                }
                return [];
            }
            public function withAddedHeader(string $name, $value): MessageInterface { return $this->withHeader($name, $value); }
            public function withoutHeader(string $name): MessageInterface { $c = clone $this; unset($c->headers[$name]); return $c; }
            public function withBody(StreamInterface $body): MessageInterface { return $this; }
            public function getReasonPhrase(): string { return ''; }
        }
    }

    if (!class_exists(StandaloneServerRequest::class)) {
        final class StandaloneServerRequest implements ServerRequestInterface
        {
            private SimpleStream $body;
            private SimpleUri $uri;
            /** @var array<string, array<int, string>> */
            private array $headers = [];
            private mixed $parsedBody = null;
            /** @var array<string, mixed> */
            private array $attributes = [];
            /** @var array<string, mixed> */
            private array $cookieParams = [];
            /** @var array<string, mixed> */
            private array $uploadedFiles = [];
            private string $protocolVersion = '1.1';
            private string $requestTarget = '/';

            /**
             * @param array<string, mixed> $serverParams
             * @param array<string, mixed> $queryParams
             */
            public function __construct(
                private string $method,
                string $path,
                private readonly array $serverParams,
                private array $queryParams,
                string $rawBody
            ) {
                $this->uri = new SimpleUri($path);
                $this->body = new SimpleStream($rawBody);
                $this->requestTarget = $path;

                foreach ($serverParams as $k => $v) {
                    if (str_starts_with($k, 'HTTP_')) {
                        $headerName = str_replace('_', '-', substr($k, 5));
                        $this->headers[$headerName] = [(string) $v];
                    } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                        $headerName = str_replace('_', '-', $k);
                        $this->headers[$headerName] = [(string) $v];
                    }
                }

                if ($rawBody !== '' && str_contains(strtolower($this->getHeaderLine('Content-Type')), 'application/json')) {
                    $decoded = json_decode($rawBody, true);
                    if (is_array($decoded)) {
                        $this->parsedBody = $decoded;
                    }
                }
            }

            public function getProtocolVersion(): string { return $this->protocolVersion; }
            public function withProtocolVersion(string $version): MessageInterface { $c = clone $this; $c->protocolVersion = $version; return $c; }
            public function hasHeader(string $name): bool { return $this->getHeaderLine($name) !== ''; }
            public function getHeader(string $name): array {
                foreach ($this->headers as $k => $values) {
                    if (strcasecmp($k, $name) === 0) return $values;
                }
                return [];
            }
            public function withAddedHeader(string $name, $value): MessageInterface { return $this->withHeader($name, $value); }
            public function withoutHeader(string $name): MessageInterface { $c = clone $this; unset($c->headers[$name]); return $c; }
            public function withBody(StreamInterface $body): MessageInterface { return $this; }
            public function getRequestTarget(): string { return $this->requestTarget; }
            public function withRequestTarget(string $requestTarget): RequestInterface { $c = clone $this; $c->requestTarget = $requestTarget; return $c; }
            public function withMethod(string $method): RequestInterface { $c = clone $this; $c->method = $method; return $c; }
            public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface { return $this; }
            public function getCookieParams(): array { return $this->cookieParams; }
            public function withCookieParams(array $cookies): ServerRequestInterface { $c = clone $this; $c->cookieParams = $cookies; return $c; }
            public function withQueryParams(array $query): ServerRequestInterface { $c = clone $this; $c->queryParams = $query; return $c; }
            public function getUploadedFiles(): array { return $this->uploadedFiles; }
            public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface { $c = clone $this; $c->uploadedFiles = $uploadedFiles; return $c; }
            public function withParsedBody($data): ServerRequestInterface { $c = clone $this; $c->parsedBody = $data; return $c; }
            public function getAttributes(): array { return $this->attributes; }
            public function getAttribute(string $name, $default = null): mixed { return $this->attributes[$name] ?? $default; }
            public function withAttribute(string $name, $value): ServerRequestInterface { $c = clone $this; $c->attributes[$name] = $value; return $c; }
            public function withoutAttribute(string $name): ServerRequestInterface { $c = clone $this; unset($c->attributes[$name]); return $c; }

            public function getMethod(): string { return $this->method; }
            public function getUri(): UriInterface { return $this->uri; }

            public function getHeaderLine(string $name): string
            {
                foreach ($this->headers as $k => $values) {
                    if (strcasecmp($k, $name) === 0) {
                        return implode(', ', $values);
                    }
                }
                return '';
            }

            public function getHeaders(): array { return $this->headers; }

            public function withHeader(string $name, $value): static
            {
                $clone = clone $this;
                $clone->headers[$name] = is_array($value) ? array_map('strval', $value) : [(string) $value];
                return $clone;
            }

            public function getBody(): StreamInterface { return $this->body; }
            public function getServerParams(): array { return $this->serverParams; }
            public function getQueryParams(): array { return $this->queryParams; }
            public function getParsedBody(): mixed { return $this->parsedBody; }
        }
    }
}

namespace App\Support {
    use Psr\Container\ContainerInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Psr\Http\Server\RequestHandlerInterface;
    use Slim\Exception\HttpException;
    use Slim\Psr7\Response;
    use Slim\Psr7\StandaloneServerRequest;

    final class SimpleContainer implements ContainerInterface
    {
        /** @var array<string, mixed> */
        private array $resolved = [];

        /** @param array<string, callable> $definitions */
        public function __construct(private readonly array $definitions)
        {
        }

        public function get(string $id): mixed
        {
            if (array_key_exists($id, $this->resolved)) {
                return $this->resolved[$id];
            }
            if (!isset($this->definitions[$id])) {
                throw new \RuntimeException("Service not found: {$id}");
            }
            $this->resolved[$id] = ($this->definitions[$id])($this);
            return $this->resolved[$id];
        }

        public function has(string $id): bool
        {
            return isset($this->definitions[$id]) || array_key_exists($id, $this->resolved);
        }
    }

    final class StandaloneRouteGroup
    {
        /** @var array<int, MiddlewareInterface> */
        private array $middlewares = [];

        public function __construct(
            private readonly StandaloneApp $app,
            private readonly string $prefix
        ) {
        }

        public function get(string $pattern, callable|array $handler): void
        {
            $this->app->registerRoute('GET', $this->prefix . $pattern, $handler, $this->middlewares);
        }

        public function post(string $pattern, callable|array $handler): void
        {
            $this->app->registerRoute('POST', $this->prefix . $pattern, $handler, $this->middlewares);
        }

        public function put(string $pattern, callable|array $handler): void
        {
            $this->app->registerRoute('PUT', $this->prefix . $pattern, $handler, $this->middlewares);
        }

        public function patch(string $pattern, callable|array $handler): void
        {
            $this->app->registerRoute('PATCH', $this->prefix . $pattern, $handler, $this->middlewares);
        }

        public function delete(string $pattern, callable|array $handler): void
        {
            $this->app->registerRoute('DELETE', $this->prefix . $pattern, $handler, $this->middlewares);
        }

        public function group(string $prefix, callable $callback): self
        {
            $subGroup = new self($this->app, $this->prefix . $prefix);
            $callback($subGroup);
            return $subGroup;
        }

        public function add(MiddlewareInterface $middleware): self
        {
            $this->app->attachMiddlewareToPrefix($this->prefix, $middleware);
            return $this;
        }
    }

    final class StandaloneApp
    {
        private string $basePath = '';
        /** @var array<int, array{method: string, pattern: string, handler: callable|array, middlewares: array<int, MiddlewareInterface>}> */
        private array $routes = [];
        /** @var array<int, MiddlewareInterface> */
        private array $middlewares = [];

        public function __construct(private readonly ContainerInterface $container)
        {
        }

        public function setBasePath(string $basePath): void
        {
            $this->basePath = rtrim($basePath, '/');
        }

        public function getBasePath(): string
        {
            return $this->basePath;
        }

        public function add(MiddlewareInterface $middleware): void
        {
            $this->middlewares[] = $middleware;
        }

        public function get(string $pattern, callable|array $handler): void
        {
            $this->registerRoute('GET', $pattern, $handler, []);
        }

        public function post(string $pattern, callable|array $handler): void
        {
            $this->registerRoute('POST', $pattern, $handler, []);
        }

        public function put(string $pattern, callable|array $handler): void
        {
            $this->registerRoute('PUT', $pattern, $handler, []);
        }

        public function patch(string $pattern, callable|array $handler): void
        {
            $this->registerRoute('PATCH', $pattern, $handler, []);
        }

        public function delete(string $pattern, callable|array $handler): void
        {
            $this->registerRoute('DELETE', $pattern, $handler, []);
        }

        public function group(string $prefix, callable $callback): StandaloneRouteGroup
        {
            $group = new StandaloneRouteGroup($this, $prefix);
            $callback($group);
            return $group;
        }

        /**
         * @param array<int, MiddlewareInterface> $middlewares
         */
        public function registerRoute(string $method, string $pattern, callable|array $handler, array $middlewares): void
        {
            $this->routes[] = [
                'method' => strtoupper($method),
                'pattern' => $pattern,
                'handler' => $handler,
                'middlewares' => $middlewares,
            ];
        }

        public function attachMiddlewareToPrefix(string $prefix, MiddlewareInterface $middleware): void
        {
            foreach ($this->routes as &$route) {
                if (str_starts_with($route['pattern'], $prefix)) {
                    $route['middlewares'][] = $middleware;
                }
            }
        }

        public function run(): void
        {
            $rawUri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $path = $rawUri;
            if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
                $path = substr($path, strlen($this->basePath));
            }
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
            if ($path !== '/' && str_ends_with($path, '/')) {
                $path = rtrim($path, '/');
            }

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $rawBody = (string) file_get_contents('php://input');
            $request = new StandaloneServerRequest($method, $path, $_SERVER, $_GET, $rawBody);

            $coreHandler = new class($this->routes, $this->container) implements RequestHandlerInterface {
                /**
                 * @param array<int, array{method: string, pattern: string, handler: callable|array, middlewares: array<int, MiddlewareInterface>}> $routes
                 */
                public function __construct(
                    private readonly array $routes,
                    private readonly ContainerInterface $container
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $method = $request->getMethod();
                    $path = $request->getUri()->getPath();

                    foreach ($this->routes as $route) {
                        if ($route['method'] !== $method) {
                            continue;
                        }
                        $regex = '#^' . preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $route['pattern']) . '$#';
                        if (preg_match($regex, $path, $matches)) {
                            $args = [];
                            foreach ($matches as $k => $v) {
                                if (is_string($k)) {
                                    $args[$k] = $v;
                                }
                            }

                            $routeHandler = new class($route['handler'], $args, $this->container) implements RequestHandlerInterface {
                                /** @param array<string, string> $args */
                                public function __construct(
                                    private readonly mixed $callable,
                                    private readonly array $args,
                                    private readonly ContainerInterface $container
                                ) {
                                }

                                public function handle(ServerRequestInterface $req): ResponseInterface
                                {
                                    $response = new Response(200);
                                    $target = $this->callable;
                                    if (is_array($target) && count($target) === 2 && is_string($target[0])) {
                                        $instance = $this->container->get($target[0]);
                                        return $instance->{$target[1]}($req, $response, $this->args);
                                    }
                                    return ($target)($req, $response, $this->args);
                                }
                            };

                            $pipeline = $routeHandler;
                            foreach (array_reverse($route['middlewares']) as $mw) {
                                $next = $pipeline;
                                $pipeline = new class($mw, $next) implements RequestHandlerInterface {
                                    public function __construct(
                                        private readonly MiddlewareInterface $mw,
                                        private readonly RequestHandlerInterface $next
                                    ) {
                                    }

                                    public function handle(ServerRequestInterface $r): ResponseInterface
                                    {
                                        return $this->mw->process($r, $this->next);
                                    }
                                };
                            }

                            return $pipeline->handle($request);
                        }
                    }

                    throw new HttpException('Rota não encontrada: ' . $method . ' ' . $path, 404);
                }
            };

            $pipeline = $coreHandler;
            foreach ($this->middlewares as $mw) {
                $next = $pipeline;
                $pipeline = new class($mw, $next) implements RequestHandlerInterface {
                    public function __construct(
                        private readonly MiddlewareInterface $mw,
                        private readonly RequestHandlerInterface $next
                    ) {
                    }

                    public function handle(ServerRequestInterface $r): ResponseInterface
                    {
                        return $this->mw->process($r, $this->next);
                    }
                };
            }

            $response = $pipeline->handle($request);
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header("{$name}: {$value}", false);
                }
            }
            echo (string) $response->getBody();
        }
    }
}

