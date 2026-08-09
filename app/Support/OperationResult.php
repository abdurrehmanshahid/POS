<?php

namespace App\Support;

/**
 * What `Operations::once()` hands back.
 *
 * `value` is whatever the work returned the one time it ran, and null on a
 * replay — a replay has nothing new to report by definition. Callers that need
 * to render something still hold their own form state, which is where the
 * message should come from anyway.
 */
final class OperationResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly bool $replayed,
    ) {}
}
