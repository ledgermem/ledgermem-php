<?php

declare(strict_types=1);

namespace LedgerMem;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

final class Client
{
    private const DEFAULT_BASE_URL = 'https://api.proofly.dev';
    private const VERSION = '0.1.0';

    private readonly ClientInterface $http;
    private readonly array $defaultHeaders;

    public function __construct(
        string $apiKey,
        string $workspaceId,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        if ($workspaceId === '') {
            throw new \InvalidArgumentException('workspaceId is required');
        }

        $url = $baseUrl
            ?? (getenv('LEDGERMEM_API_URL') ?: null)
            ?? self::DEFAULT_BASE_URL;

        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $apiKey,
            'x-workspace-id' => $workspaceId,
            'User-Agent' => 'ledgermem-php/' . self::VERSION,
            'Accept' => 'application/json',
        ];

        $this->http = $httpClient ?? new GuzzleClient([
            'base_uri' => rtrim($url, '/') . '/',
            'timeout' => 30.0,
        ]);
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
            throw new LedgerMemException(0, 'Transport error: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $payload = (string) $response->getBody();

        if ($status >= 400) {
            throw new LedgerMemException($status, "LedgerMem API error {$status}: {$payload}");
        }

        if (!$expectJson || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new LedgerMemException($status, 'Invalid JSON response: ' . $payload);
        }
        return $decoded;
    }
}
