<?php

declare(strict_types=1);

namespace Metty\Client\Exception;

use Metty\Client\Catalog\WriteResult;

/**
 * A full sync did not complete, so it must not be committed: the commit would delete exactly the
 * products that just failed to upload.
 *
 * The sync stays open, so the failed products can be resent under the same `syncId` and committed
 * later.
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
