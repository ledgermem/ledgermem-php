<?php

declare(strict_types=1);

namespace LedgerMem;

use RuntimeException;

final class LedgerMemException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message, $statusCode);
    }
}
