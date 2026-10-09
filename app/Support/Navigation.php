<?php

namespace App\Support;

use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class Navigation
{
    /** @return array<int, array{heading:string, items:array}> */
    public static function for(User $user): array
    {
        $groups = [];
        foreach (config('navigation') as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $params = $item['params'] ?? [];
                if (! self::canAccess($user, $item['route'], $params)) continue;
                $items[] = $item + ['url' => route($item['route'], $params), 'active' => self::isActive($item['route'], $params)];
            }
            if ($items) $groups[] = ['heading' => $group['heading'], 'items' => $items];
        }

        return $groups;
    }

    public static function canAccess(User $user, string $routeName, array $params = []): bool
    {
        $route = Route::getRoutes()->getByName($routeName);
        if (! $route) return false;

        $rules = collect($route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'access:'))
            ->flatMap(fn ($middleware) => explode(',', Str::after($middleware, 'access:')))
            ->all();
        if (! Access::allows($user, $rules)) return false;

        if ($routeName === 'reports.show') {
            return app(ReportService::class)->canView($user, (string) ($params['type'] ?? ''));
        }

        return true;
    }

    /** Returns [group heading, item label] for the current page. */
    public static function breadcrumb(): array
    {
        foreach (config('navigation') as $group) {
            foreach ($group['items'] as $item) {
                if (self::isActive($item['route'], $item['params'] ?? [])) return [$group['heading'], $item['label']];
            }
        }

        return [];
    }

    private static function isActive(string $routeName, array $params): bool
    {
        $current = request()->route();
        if (! $current || $current->getName() !== $routeName) return false;
        foreach ($params as $key => $value) {
            if ((string) $current->parameter($key) !== (string) $value) return false;
        }

        return true;
    }
}
