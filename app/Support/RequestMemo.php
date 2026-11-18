<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Values read once per web request, such as the hotel's settings or its
 * plan, which several parts of one page need.
 *
 * Only web requests are memoized: jobs and commands have no route and
 * always read afresh, so a long-lived worker never serves stale values.
 * A new request (or a new test request) starts with an empty memo.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private static array $values = [];

    /** @var \WeakReference<\Illuminate\Http\Request>|null */
    private static ?\WeakReference $for = null;

    /**
     * @template T
     *
     * @param  \Closure(): T  $compute
     * @return T
     */
    public static function remember(string $key, \Closure $compute): mixed
    {
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof \Illuminate\Http\Request || $request->route() === null) {
            return $compute();
        }

        if (self::$for?->get() !== $request) {
            self::$values = [];
            self::$for = \WeakReference::create($request);
        }

        if (! array_key_exists($key, self::$values)) {
            self::$values[$key] = $compute();
        }

        /** @var T */
        return self::$values[$key];
    }

    /** Forget the values whose key starts with this prefix (all when empty). */
    public static function forget(string $prefix = ''): void
    {
        foreach (array_keys(self::$values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$values[$key]);
            }
        }
    }
}
