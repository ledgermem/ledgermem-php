# LedgerMem PHP SDK

Official PHP client for the [LedgerMem](https://proofly.dev) memory API.

## Install

```bash
composer require ledgermem/ledgermem
```

Requires PHP 8.1+.

## Quickstart

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use LedgerMem\Client;

$client = new Client(
    apiKey: getenv('LEDGERMEM_API_KEY'),
    workspaceId: getenv('LEDGERMEM_WORKSPACE_ID'),
);

$memory = $client->create('Shah prefers dark mode in terminals.');
$results = $client->search('ui preferences', limit: 5);

foreach ($results['hits'] as $hit) {
    printf("%.2f %s\n", $hit['score'], $hit['content']);
}
```

## Configuration

| Env var | Purpose |
| --- | --- |
| `LEDGERMEM_API_KEY` | Bearer token (required) |
| `LEDGERMEM_WORKSPACE_ID` | Workspace identifier (required) |
| `LEDGERMEM_API_URL` | Override base URL (default `https://api.proofly.dev`) |

## API

| Method | HTTP | Description |
| --- | --- | --- |
| `search($query, $limit = null, $actorId = null)` | `POST /v1/search` | Semantic + keyword search |
| `create($content, $metadata = null, $actorId = null)` | `POST /v1/memories` | Store a new memory |
| `update($id, $content = null, $metadata = null)` | `PATCH /v1/memories/:id` | Patch an existing memory |
| `delete($id)` | `DELETE /v1/memories/:id` | Remove a memory |
| `list($limit = null, $cursor = null, $actorId = null)` | `GET /v1/memories` | Paginated listing |

## License

MIT
