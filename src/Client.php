<?php

declare(strict_types=1);

namespace Mnemo;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Client
{
    private const DEFAULT_BASE_URL = 'https://api.getmnemo.xyz';
    private const VERSION = '0.1.0';
    private const DEFAULT_MAX_RETRIES = 3;
    private const RETRY_BASE_DELAY_MS = 200;
    private const RETRY_MAX_DELAY_MS = 5_000;

    private readonly ClientInterface $http;
    private readonly array $defaultHeaders;

    public function __construct(
        string $apiKey,
        string $workspaceId,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        if ($workspaceId === '') {
            throw new \InvalidArgumentException('workspaceId is required');
        }

        $url = $baseUrl
            ?? (getenv('GETMNEMO_API_URL') ?: null)
            ?? self::DEFAULT_BASE_URL;

        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $apiKey,
            'x-workspace-id' => $workspaceId,
            'User-Agent' => 'getmnemo-php/' . self::VERSION,
            'Accept' => 'application/json',
        ];

        if ($httpClient !== null) {
            $this->http = $httpClient;
        } else {
            $stack = HandlerStack::create();
            $stack->push(Middleware::retry(
                self::retryDecider(max(0, $maxRetries)),
                self::retryDelay(...),
            ));
            $this->http = new GuzzleClient([
                'base_uri' => rtrim($url, '/') . '/',
                'timeout' => 30.0,
                'handler' => $stack,
            ]);
        }
    }

    /**
     * @return callable(int, RequestInterface, ?ResponseInterface, ?\Throwable): bool
     */
    private static function retryDecider(int $maxRetries): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null,
        ) use ($maxRetries): bool {
            if ($retries >= $maxRetries) {
                return false;
            }
            if ($exception instanceof ConnectException) {
                return true;
            }
            if ($response !== null) {
                $status = $response->getStatusCode();
                // 501 Not Implemented is a permanent failure.
                if ($status === 501) {
                    return false;
                }
                return $status === 429 || ($status >= 500 && $status < 600);
            }
            return false;
        };
    }

    /**
     * Exponential backoff with full jitter, capped at RETRY_MAX_DELAY_MS.
     * Honours a server-provided Retry-After header when present.
     */
    private static function retryDelay(int $retries, ?ResponseInterface $response = null): int
    {
        if ($response !== null) {
            $hint = self::parseRetryAfter($response);
            if ($hint !== null) {
                return min($hint, self::RETRY_MAX_DELAY_MS);
            }
        }
        $shifted = self::RETRY_BASE_DELAY_MS * (1 << min($retries, 20));
        $capped = min($shifted, self::RETRY_MAX_DELAY_MS);
        return random_int(0, $capped);
    }

    /** Returns Retry-After in milliseconds, or null when absent/invalid. */
    private static function parseRetryAfter(ResponseInterface $response): ?int
    {
        $values = $response->getHeader('Retry-After');
        if ($values === []) {
            return null;
        }
        $raw = trim($values[0]);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return ((int) $raw) * 1000;
        }
        $epoch = strtotime($raw);
        if ($epoch === false) {
            return null;
        }
        $deltaMs = ($epoch - time()) * 1000;
        return max(0, $deltaMs);
    }

    public function search(string $query, ?int $limit = null, ?string $actorId = null): array
    {
        $body = ['query' => $query];
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        if ($actorId !== null) {
            $body['actorId'] = $actorId;
        }
        return $this->request('POST', 'v1/search', body: $body);
    }

    public function create(string $content, ?array $metadata = null, ?string $actorId = null): array
    {
        $body = ['content' => $content];
        if ($metadata !== null) {
            $body['metadata'] = $metadata;
        }
        if ($actorId !== null) {
            $body['actorId'] = $actorId;
        }
        return $this->request('POST', 'v1/memories', body: $body);
    }

    public function update(string $id, ?string $content = null, ?array $metadata = null): array
    {
        $body = [];
        if ($content !== null) {
            $body['content'] = $content;
        }
        if ($metadata !== null) {
            $body['metadata'] = $metadata;
        }
        return $this->request('PATCH', 'v1/memories/' . rawurlencode($id), body: $body);
    }

    public function delete(string $id): void
    {
        $this->request('DELETE', 'v1/memories/' . rawurlencode($id), expectJson: false);
    }

    public function list(?int $limit = null, ?string $cursor = null, ?string $actorId = null): array
    {
        $query = array_filter([
            'limit' => $limit,
            'cursor' => $cursor,
            'actorId' => $actorId,
        ], static fn ($v) => $v !== null);
        return $this->request('GET', 'v1/memories', query: $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed>|null $query
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?array $query = null, bool $expectJson = true): array
    {
        $options = [RequestOptions::HEADERS => $this->defaultHeaders];
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }
        if ($query !== null && $query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (GuzzleException $e) {
            throw new MnemoException(0, 'Transport error: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();

        if ($status >= 400) {
            throw new MnemoException($status, "Mnemo API error {$status}: {$payload}");
        }

        if (!$expectJson || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new MnemoException($status, 'Invalid JSON response: ' . $payload);
        }
        return $decoded;
    }
}
