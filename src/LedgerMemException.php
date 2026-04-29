<?php

declare(strict_types=1);

namespace Mnemo;

use RuntimeException;

final class MnemoException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message, $statusCode);
    }
}
