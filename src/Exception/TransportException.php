<?php

declare(strict_types=1);

namespace Metty\Client\Exception;

/**
 * Sieťová chyba alebo odpoveď, ktorá sa nedá spracovať.
 */
final class TransportException extends \RuntimeException implements MettyException {}
