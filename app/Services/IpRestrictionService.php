<?php

namespace App\Services;

use App\Models\User;
use App\Support\Access;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Settings > Security > IP restriction (like the old CMS "IP restrict"): when on, the website opens only from
 * the listed IP addresses / ranges (e.g. the office). Master Admin is never blocked, so the company cannot
 * lock itself out; the desktop and mobile apps (API) are not affected.
 */
class IpRestrictionService
{
    public const POLICY = 'security.ip_restriction';
    public const MESSAGE = 'The website opens only from the office network. Please use the SWS HR desktop app or mobile app.';

    public function __construct(private readonly PolicyConfigurationService $policies) {}

    /** @return array{enabled: bool, ips: string[]} */
    public function settings(): array
    {
        $p = $this->policies->forEmployee(null, self::POLICY, today()->toDateString(), ['enabled' => false, 'ips' => []]);

        return ['enabled' => (bool) ($p['enabled'] ?? false), 'ips' => array_values(array_filter(array_map('trim', (array) ($p['ips'] ?? []))))];
    }

    /** Valid single IPs (v4 / v6) or CIDR ranges like 103.21.4.0/24. */
    public static function valid(string $entry): bool
    {
        [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if ($mask === null) return true;
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask >= 0 && (int) $mask <= $max;
    }

    public function allows(?User $user, ?string $ip): bool
    {
        if (! $user || Access::isAdmin($user)) return true;
        $s = $this->settings();
        if (! $s['enabled'] || $s['ips'] === []) return true;

        return $ip !== null && IpUtils::checkIp($ip, $s['ips']);
    }
}
