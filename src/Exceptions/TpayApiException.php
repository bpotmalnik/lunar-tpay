<?php

namespace Bpotmalnik\LunarTpay\Exceptions;

use Bpotmalnik\LunarTpay\Enums\ApiErrorType;
use RuntimeException;

class TpayApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        public readonly ?ApiErrorType $errorType = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(int $status, array $body): self
    {
        $rawType = $body['errors'][0]['errorCode']
            ?? $body['errorCode']
            ?? null;

        $message = $body['errors'][0]['errorMessage']
            ?? $body['errors'][0]['devMessage']
            ?? $body['errorMessage']
            ?? $body['message']
            ?? "Tpay API error (HTTP {$status})";

        return new self(
            message: $message,
            statusCode: $status,
            errors: $body,
            errorType: $rawType !== null ? ApiErrorType::tryFrom($rawType) : null,
        );
    }
}
