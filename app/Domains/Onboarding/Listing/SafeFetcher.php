<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads one public web page on the owner's behalf, politely and safely.
 *
 * - Only http(s) on the standard ports, no user:password in the link.
 * - The host must resolve to public addresses only; the connection is
 *   pinned to the checked address so a second DNS answer cannot point it
 *   at the server's own network (DNS rebinding).
 * - Every redirect is checked the same way, at most three.
 * - At most 2 MB, 8 seconds, HTML or plain text only.
 * - The site's robots.txt is respected.
 */
final class SafeFetcher
{
    public const USER_AGENT = 'AssistantHotelBot/1.0 (+https://assistant-hotel.com/robot)';

    public const MAX_BYTES = 2_000_000;

    private const MAX_REDIRECTS = 3;

    /** @var Closure(string): list<string> host => IP addresses */
    private readonly Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  for tests
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dns(...);
    }

    /**
     * The page body and the address it was finally read from.
     *
     * @return array{url: string, body: string, type: string}
     *
     * @throws UnreadableSource
     */
    public function page(string $url): array
    {
        $this->assertRobotsAllow($url);
        [$final, $response] = $this->follow($url, true);

        $type = mb_strtolower((string) $response->header('Content-Type'));
        if ($type !== '' && ! str_contains($type, 'text/html') && ! str_contains($type, 'application/xhtml') && ! str_contains($type, 'text/plain')) {
            throw new UnreadableSource((string) __('This link is not a web page. Upload the file instead.'));
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new UnreadableSource((string) __('This page is too large to read. Paste the text instead.'));
        }

        return ['url' => $final, 'body' => self::utf8($body, $type), 'type' => $type];
    }

    /**
     * Where a short link leads, without reading the page it leads to.
     *
     * @throws UnreadableSource
     */
    public function expand(string $url): string
    {
        return $this->follow($url, false)[0];
    }

    /**
     * @return array{0: string, 1: Response}
     */
    private function follow(string $url, bool $readBody): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->request($url, $readBody);

            if (! $response->redirect()) {
                if (! $response->successful()) {
                    throw new UnreadableSource((string) __('The website answered with an error (:status). Check the link, or paste the text instead.', ['status' => $response->status()]));
                }

                return [$url, $response];
            }

            $location = (string) $response->header('Location');
            if ($location === '') {
                break;
            }

            $url = self::absolute($location, $url);

            // A short Maps link is done as soon as it reaches Google Maps.
            if (! $readBody && MapsLink::isMapsLink($url) && ! MapsLink::isShortLink($url)) {
                return [$url, $response];
            }
        }

        throw new UnreadableSource((string) __('This link redirects too many times. Paste the final address instead.'));
    }

    private function request(string $url, bool $readBody): Response
    {
        [$host, $port, $ip] = $this->check($url);

        try {
            return Http::withUserAgent(self::USER_AGENT)
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8', 'Accept-Language' => 'fr,en;q=0.8'])
                ->connectTimeout(4)
                ->timeout(8)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                    'progress' => function (int|float $total, int|float $downloaded): void {
                        if ($downloaded > self::MAX_BYTES) {
                            throw new UnreadableSource((string) __('This page is too large to read. Paste the text instead.'));
                        }
                    },
                ])
                ->send($readBody ? 'GET' : 'HEAD', $url);
        } catch (UnreadableSource $e) {
            throw $e;
        } catch (ConnectionException) {
            throw new UnreadableSource((string) __('The website did not answer. Check the link, or paste the text instead.'));
        } catch (Throwable $e) {
            if ($e->getPrevious() instanceof UnreadableSource) {
                throw $e->getPrevious();
            }

            throw new UnreadableSource((string) __('The website did not answer. Check the link, or paste the text instead.'));
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port and the checked address
     */
    private function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnreadableSource((string) __('This link cannot be read. Use the full address starting with https://.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw new UnreadableSource((string) __('This link cannot be read. Use the full address starting with https://.'));
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);

        if ($ips === []) {
            throw new UnreadableSource((string) __('This website could not be found. Check the address.'));
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new UnreadableSource((string) __('This link cannot be read. Use the full address starting with https://.'));
            }
        }

        return [$host, $port, $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            // Shared address space (carrier NAT) and IPv4-mapped IPv6 are not covered by the flags.
            && preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip) !== 1
            && ! str_starts_with(mb_strtolower($ip), '::ffff:');
    }

    private function assertRobotsAllow(string $url): void
    {
        $parts = parse_url($url);
        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        try {
            [, $response] = $this->follow($root.'/robots.txt', true);
            $rules = $response->body();
        } catch (UnreadableSource) {
            return; // no robots.txt: reading is allowed
        }

        if (self::disallowed($rules, $path)) {
            throw new UnreadableSource((string) __('This website asks robots not to read this page. Copy its text and paste it instead.'));
        }
    }

    /**
     * Whether robots.txt rules for "*" or for this robot forbid the path.
     * The longest matching rule wins, as search engines do.
     */
    public static function disallowed(string $robots, string $path): bool
    {
        $groups = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = mb_strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = mb_strtolower($value);

                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;
                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field, $value];
                }
            }
        }

        $rules = $groups['assistanthotelbot'] ?? $groups['*'] ?? [];
        $best = ['allow', ''];

        foreach ($rules as [$kind, $prefix]) {
            if ($prefix === '') {
                continue;
            }
            $pattern = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($prefix, '#')).'#';
            if (preg_match($pattern, $path) === 1 && strlen($prefix) >= strlen($best[1])) {
                $best = [$kind, $prefix];
            }
        }

        return $best[0] === 'disallow';
    }

    private static function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($location, '/') ? $root.$location : $root.'/'.$location;
    }

    private static function utf8(string $body, string $type): string
    {
        $charset = preg_match('/charset=([\w-]+)/i', $type, $m) === 1 ? mb_strtoupper($m[1]) : null;

        if (($charset === null || $charset === 'UTF-8') && mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        $converted = @mb_convert_encoding($body, 'UTF-8', $charset !== null && in_array($charset, mb_list_encodings(), true) ? $charset : 'Windows-1252');

        return is_string($converted) ? $converted : '';
    }

    /**
     * @return list<string>
     */
    private static function dns(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
