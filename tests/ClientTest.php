<?php

declare(strict_types=1);

namespace Mnemo\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mnemo\Client;
use Mnemo\MnemoException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var array<int,array{request:\Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function makeClient(Response ...$responses): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $guzzle = new GuzzleClient([
            'handler' => $stack,
            'base_uri' => 'https://api.test/',
        ]);

        return new Client(
            apiKey: 'test-key',
            workspaceId: 'ws_123',
            baseUrl: 'https://api.test',
            httpClient: $guzzle,
        );
    }

    public function testSearchSendsAuthAndWorkspaceHeaders(): void
    {
        $client = $this->makeClient(new Response(200, [], json_encode([
            'hits' => [['id' => 'm1', 'content' => 'hi', 'score' => 0.9]],
        ])));

        $result = $client->search('hello', limit: 3);

        self::assertCount(1, $result['hits']);
        self::assertSame('m1', $result['hits'][0]['id']);

        $req = $this->history[0]['request'];
        self::assertSame('Bearer test-key', $req->getHeaderLine('Authorization'));
        self::assertSame('ws_123', $req->getHeaderLine('x-workspace-id'));
        self::assertStringContainsString('"query":"hello"', (string) $req->getBody());
        self::assertStringContainsString('"limit":3', (string) $req->getBody());
    }

    public function testCreatePostsMemory(): void
    {
        $client = $this->makeClient(new Response(200, [], json_encode([
            'id' => 'm_42', 'content' => 'remember', 'createdAt' => '2026-01-01T00:00:00Z',
        ])));

        $memory = $client->create('remember');

        self::assertSame('m_42', $memory['id']);
        self::assertSame('POST', $this->history[0]['request']->getMethod());
        self::assertSame('/v1/memories', $this->history[0]['request']->getUri()->getPath());
    }

    public function testDeleteThrowsOnError(): void
    {
        $client = $this->makeClient(new Response(404, [], '{"error":"not found"}'));

        $this->expectException(MnemoException::class);
        $client->delete('missing');
    }
}
