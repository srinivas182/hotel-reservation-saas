<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Media;

/**
 * Which file types guests may send that we store and show to staff.
 * Anything else (programs, scripts, web pages, SVG) is never downloaded.
 */
final class MediaPolicy
{
    private const ALLOWED = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr', 'audio/wav',
        'video/mp4', 'video/3gpp',
        'application/pdf', 'text/plain', 'text/csv',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** Shown in the page; everything else is downloaded as a file. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'audio/', 'video/', 'application/pdf'];

    public static function base(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }

    public static function allowed(string $mime): bool
    {
        return in_array(self::base($mime), self::ALLOWED, true);
    }

    public static function inline(string $mime): bool
    {
        $base = self::base($mime);

        foreach (self::INLINE as $prefix) {
            if ($base === $prefix || (str_ends_with($prefix, '/') && str_starts_with($base, $prefix))) {
                return true;
            }
        }

        return false;
    }
}
