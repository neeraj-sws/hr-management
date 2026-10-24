<?php

namespace App\Services;

use App\Models\User;
use App\Support\Access;

/**
 * Settings > Security > Website access: who may use the website. With "selected roles" everyone else
 * (e.g. employees) can still use the desktop and mobile apps, which sign in through the API and are
 * not affected. The Master Admin (anyone who manages roles) can always open the website, so the
 * company can never lock itself out.
 */
class WebAccessService
{
    public const POLICY = 'security.web_access';
    public const MODES = ['all' => 'Everyone', 'roles' => 'Only selected roles and people'];
    public const MESSAGE = 'The website is not open for your account. Please use the SWS HR desktop app or mobile app.';

    public function __construct(private readonly PolicyConfigurationService $policies) {}

    /** @return array{mode: string, roles: string[], user_ids: int[]} */
    public function settings(): array
    {
        $p = $this->policies->forEmployee(null, self::POLICY, today()->toDateString(), ['mode' => 'all', 'roles' => [], 'user_ids' => []]);
        $mode = (string) ($p['mode'] ?? 'all');

        return [
            'mode' => isset(self::MODES[$mode]) ? $mode : 'all',
            'roles' => array_values(array_filter(array_map('strval', (array) ($p['roles'] ?? [])))),
            'user_ids' => array_values(array_map('intval', (array) ($p['user_ids'] ?? []))),
        ];
    }

    public function allows(?User $user): bool
    {
        if (! $user) return false;
        if (Access::isAdmin($user)) return true;
        $s = $this->settings();
        if ($s['mode'] === 'all') return true;

        return in_array($user->id, $s['user_ids'], true) || ($s['roles'] !== [] && $user->hasAnyRole($s['roles']));
    }
}
