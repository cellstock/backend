<?php

namespace App\Exceptions;

use RuntimeException;

class RefurbedApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $grpcCode,
        private readonly int $httpStatus,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function grpcCode(): int
    {
        return $this->grpcCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function details(): array
    {
        return $this->details;
    }
}
