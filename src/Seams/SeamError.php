<?php

namespace Sifrious\Molly\Seams;

use RuntimeException;

final class SeamError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $affectedFile,
        public readonly mixed $expected = null,
        public readonly mixed $observed = null,
        public readonly string $repair = 'Correct the named file and create a new plan revision.',
        public readonly bool $retryable = false,
    ) {
        parent::__construct($errorCode.': '.$message);
    }

    public static function from(\Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }
        $code = preg_match('/\A([A-Z][A-Z0-9_]+): (.*)/s', $exception->getMessage(), $match) ? $match[1] : 'ENVIRONMENT_UNAVAILABLE';

        return new self($code, $match[2] ?? $exception->getMessage(), 'request', repair: 'Correct the reported environment or input, then retry the same request key.');
    }

    public function toArray(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage(), 'file' => $this->affectedFile,
            'expected' => $this->expected, 'observed' => $this->observed, 'retryable' => $this->retryable,
            'evidence' => [], 'next_action' => $this->repair];
    }
}
