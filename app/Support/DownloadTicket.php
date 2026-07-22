<?php

namespace App\Support;

/**
 * Single-use, short-lived permission to stream one sensitive file.
 *
 * Backup downloads have to stay plain GET routes, a browser can only save a
 * file from a real navigation, so the step-up challenge cannot live on the
 * request that streams the bytes. Instead the Livewire screen runs the usual
 * type-to-confirm plus fresh-TOTP check and, only on success, mints a ticket
 * naming exactly one artefact. The download route refuses to stream without it.
 *
 * The ticket is deliberately weak on purpose: it is pulled (read AND deleted)
 * on first use and expires within the minute, so a URL copied out of history,
 * a bookmark, or a back-button replay all fail. It authorises one download by
 * the person who just proved they were at the keyboard, and nothing else.
 */
class DownloadTicket
{
    private const KEY = 'download.ticket';

    /** Long enough to survive the redirect, short enough to be useless later. */
    private const TTL_SECONDS = 60;

    /** @param  string  $ref  the exact artefact, e.g. 'sql' or 'csv:students' */
    public static function issue(string $ref): void
    {
        session()->put(self::KEY, [
            'ref' => $ref,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]);
    }

    /** True once, for the matching artefact, within the TTL. */
    public static function consume(string $ref): bool
    {
        $ticket = session()->pull(self::KEY);

        return is_array($ticket)
            && ($ticket['ref'] ?? null) === $ref
            && ($ticket['expires_at'] ?? 0) >= now()->getTimestamp();
    }
}
