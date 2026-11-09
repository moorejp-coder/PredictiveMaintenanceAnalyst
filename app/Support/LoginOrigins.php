<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Remembers where each account signs in from, and raises an alert when a
 * sign-in comes from somewhere new (detectors login_new_country and
 * login_new_network in Alerts).
 *
 * What is kept for each account, in the login_origins table:
 * - a keyed one-way code of the network: the first 24 bits of an IPv4 address
 *   or the first 48 bits of an IPv6 address, run through HMAC-SHA-256 with the
 *   app key. The address itself is not stored and the code cannot be turned
 *   back into one.
 * - the two-letter country, only when ALERT_COUNTRY_HEADER names a header that
 *   a CDN in front of PRISM sets. PRISM does not look up where an IP address
 *   is, and sends no address to any service.
 * - the first and last time the account signed in from that network.
 * At most 20 networks are kept for an account, none older than 180 days.
 * Deleting the account deletes its rows (foreign key, cascade).
 *
 * The first sign-in of an account sets the baseline and alerts nobody. A later
 * one alerts when its country is new (and the account has a known country) or,
 * if ALERT_NEW_NETWORK is on, when its network is new.
 */
final class LoginOrigins
{
    /** Cloudflare marks "unknown" as XX and Tor as T1. Neither is a country. */
    private const NOT_A_COUNTRY = ['XX', 'T1', 'ZZ'];

    public static function observe(User $user, Request $request): void
    {
        try {
            self::record($user, $request);
        } catch (Throwable $e) {
            // The sign-in has already succeeded. A problem here must not undo it.
            Log::error('login_origin_failed', ['class' => $e::class]);
        }
    }

    private static function record(User $user, Request $request): void
    {
        $network = self::network((string) $request->ip());
        if ($network === null) {
            return;
        }

        $country = self::country($request);
        $now = now();
        $known = DB::table('login_origins')->where('user_id', $user->getKey())->get(['network_hash', 'country']);
        $knownCountries = $known->pluck('country')->filter()->unique()->values()->all();
        $seenNetwork = $known->contains('network_hash', $network);

        if ($seenNetwork) {
            DB::table('login_origins')->where('user_id', $user->getKey())->where('network_hash', $network)->update(array_filter([
                'last_seen_at' => $now,
                // A header switched on later fills in the country of a network seen before.
                'country' => $country,
            ], fn ($value): bool => $value !== null));
        } else {
            DB::table('login_origins')->insert([
                'user_id' => $user->getKey(),
                'network_hash' => $network,
                'country' => $country,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
            self::prune((int) $user->getKey());
        }

        // The first sign-in only sets the baseline.
        if ($known->isEmpty()) {
            return;
        }

        $detail = [
            'target_user_id' => $user->getKey(),
            'email_code' => LogId::email((string) $user->email),
            'country' => $country ?? 'unknown',
            'known_countries' => $knownCountries,
        ];

        if ($country !== null && $knownCountries !== [] && ! in_array($country, $knownCountries, true)) {
            Alerts::fire('login_new_country', $user->getKey().'|'.$country, $detail + ['new_network' => ! $seenNetwork], $request);
        } elseif (! $seenNetwork && config('prism.alerts.new_network')) {
            Alerts::fire('login_new_network', $user->getKey().'|'.$network, $detail + ['new_network' => true], $request);
        }
    }

    /** Keyed code of the network an address belongs to, or null for a value that is no address. */
    private static function network(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // An IPv4 address that arrives in IPv6 form (::ffff:a.b.c.d) is the IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $prefix = strlen($packed) === 4 ? 'v4:'.bin2hex(substr($packed, 0, 3)) : 'v6:'.bin2hex(substr($packed, 0, 6));

        return substr(hash_hmac('sha256', 'network:'.$prefix, (string) config('app.key')), 0, 32);
    }

    /**
     * The visitor's country from the header a CDN sets, if ALERT_COUNTRY_HEADER
     * names one. A client can send any header, so use this only when every
     * request comes through a CDN that overwrites it.
     */
    private static function country(Request $request): ?string
    {
        $header = config('prism.alerts.country_header');
        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $value = strtoupper(trim((string) $request->headers->get(trim($header))));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 && ! in_array($value, self::NOT_A_COUNTRY, true) ? $value : null;
    }

    /** Drops networks not seen for the retention period, then all but the newest ones. */
    private static function prune(int $userId): void
    {
        $days = max(1, (int) config('prism.alerts.origin_retention_days'));
        $keep = max(1, (int) config('prism.alerts.origins_kept'));

        DB::table('login_origins')->where('user_id', $userId)->where('last_seen_at', '<', now()->subDays($days))->delete();

        $stale = DB::table('login_origins')->where('user_id', $userId)->orderByDesc('last_seen_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');
        if ($stale->isNotEmpty()) {
            DB::table('login_origins')->whereIn('id', $stale->all())->delete();
        }
    }
}
