<?php

declare(strict_types=1);

namespace Metty\Client\Exception;

/**
 * A network error, or a response that cannot be parsed.
 */
final class TransportException extends \RuntimeException implements MettyException {}
