<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Cleans the message, the context and the extra fields of every record. */
final class RedactSensitiveData implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: Redactor::text($record->message),
            context: Redactor::context($record->context),
            extra: Redactor::context($record->extra),
        );
    }
}
