<?php

namespace App\Shared\Exceptions;

use RuntimeException;

/**
 * Raised when a request is valid in shape but breaks a business rule.
 * Rendered as `{ message, code, context }` with the given HTTP status.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_RULE_VIOLATION',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
