<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Wraps a channel's own formatter and cleans the finished text. This is the
 * second net: it also catches the message and trace of an exception, which
 * Monolog prints after the processors have run.
 */
final class RedactingFormatter implements FormatterInterface
{
    public function __construct(private readonly FormatterInterface $inner) {}

    public function format(LogRecord $record): mixed
    {
        $formatted = $this->inner->format($record);

        return is_string($formatted) ? Redactor::text($formatted) : $formatted;
    }

    /** @param  array<LogRecord>  $records */
    public function formatBatch(array $records): mixed
    {
        $formatted = $this->inner->formatBatch($records);

        return is_string($formatted) ? Redactor::text($formatted) : $formatted;
    }
}
