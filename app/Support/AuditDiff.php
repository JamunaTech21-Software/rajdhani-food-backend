<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * Reduces a before/after row pair to only the fields that changed (RTPP-35).
 *
 * The whole reason this exists rather than storing both rows whole: a
 * metadata-only edit on a news post or a page block — flipping `status`, say
 * — would otherwise copy that resource's entire rich-text body into
 * `audit_logs` on every single save, twice, forever. Diffing first is what
 * keeps a table that exists to answer "what changed" from growing dominated
 * by the one field that didn't.
 */
final class AuditDiff
{
    /**
     * `null` on either side means "nothing to diff against" — a create has no
     * before, a delete has no after — so the non-null side is stored whole
     * rather than reduced to nothing.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     *
     * @return array{before:array<string,mixed>|null,after:array<string,mixed>|null}
     */
    public static function compact(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return ['before' => $before, 'after' => $after];
        }

        $changedBefore = [];
        $changedAfter = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== $new) {
                $changedBefore[$key] = $old;
                $changedAfter[$key] = $new;
            }
        }

        return ['before' => $changedBefore, 'after' => $changedAfter];
    }
}
