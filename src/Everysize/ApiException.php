<?php

declare(strict_types=1);

namespace Everysize\Checkout\Everysize;

/**
 * An answer of the everysize API that says no, with its reason: the External
 * API sends {"title": "...", "reason": "REFUND_AMOUNT_INVALID", "detail": "..."}.
 * A missing answer (network, TLS) stays a \RuntimeException of the Client.
 */
class ApiException extends \RuntimeException
{
    private int $status;
    private string $reason;
    private string $detail;

    public function __construct(string $message, int $status, string $reason = '', string $detail = '')
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->reason = $reason;
        $this->detail = $detail;
    }

    /**
     * @param array{status: int, body: string} $response
     */
    public static function fromResponse(string $title, array $response): self
    {
        $body = json_decode((string) $response['body'], true);
        $body = \is_array($body) ? $body : [];
        $text = (string) ($body['title'] ?? $body['error_description'] ?? '');

        return new self(
            sprintf('[%s] %s%s', $response['status'], $title, $text !== '' ? ': '.$text : ''),
            (int) $response['status'],
            \is_string($body['reason'] ?? null) ? $body['reason'] : '',
            \is_string($body['detail'] ?? null) ? $body['detail'] : ''
        );
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }
}
