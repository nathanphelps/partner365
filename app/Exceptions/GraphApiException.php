<?php

namespace App\Exceptions;

use RuntimeException;

class GraphApiException extends RuntimeException
{
    public readonly string $graphErrorCode;
    public readonly array $graphError;

    public function __construct(string $message, int $httpStatus, string $graphErrorCode = '', array $graphError = [])
    {
        parent::__construct($message, $httpStatus);
        $this->graphErrorCode = $graphErrorCode;
        $this->graphError = $graphError;
    }

    public static function fromResponse(int $status, array $body): self
    {
        $error = $body['error'] ?? [];
        $message = $error['message'] ?? 'Unknown Graph API error';
        $code = $error['code'] ?? '';

        return new self($message, $status, $code, $error);
    }
}
