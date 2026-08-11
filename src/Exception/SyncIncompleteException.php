<?php

declare(strict_types=1);

namespace Metty\Client\Exception;

use Metty\Client\Catalog\WriteResult;

/**
 * Full sync sa nedokončil celý, takže sa nesmie commitnúť — commit by zmazal produkty, ktoré sa
 * práve nepodarilo nahrať.
 *
 * Sync ostáva otvorený: chybné produkty sa dajú dopísať pod tým istým `syncId` a commitnúť neskôr.
 */
final class SyncIncompleteException extends \RuntimeException implements MettyException
{
    public function __construct(
        public readonly string $syncId,
        public readonly WriteResult $result,
    ) {
        parent::__construct(sprintf(
            'sync_incomplete: %d of %d products failed; sync "%s" was not committed.',
            count($result->failures()),
            count($result),
            $syncId,
        ));
    }
}
