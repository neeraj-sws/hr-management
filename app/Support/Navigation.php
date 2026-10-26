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

    /** Sidebar look per module: [section, icon, tile colour]. Modules not listed go under "More". */
    public const META = [
        'Dashboard' => [null, 'home', '#2B5BD7'],
        'Company' => ['Organization', 'building', '#2563EB'],
        'People' => ['Organization', 'users', '#5B6B86'],
        'Attendance' => ['Attendance & Work', 'calendar', '#2563EB'],
        'Work' => ['Attendance & Work', 'briefcase', '#7C3AED'],
        'Leave & Time' => ['Attendance & Work', 'clock', '#16A34A'],
        'Expenses' => ['Finance', 'credit-card', '#2563EB'],
        'Payroll' => ['Finance', 'wallet', '#D97706'],
        'Performance' => ['Performance & Growth', 'chart', '#0D9488'],
        'Training' => ['Performance & Growth', 'layers', '#0284C7'],
        'Recruitment' => ['Performance & Growth', 'user-plus', '#DB2777'],
        'Compliance' => ['Administration', 'shield', '#4F46E5'],
        'Approvals' => ['Administration', 'check-circle', '#DC2626'],
        'Notes' => ['Administration', 'note', '#2563EB'],
        'Employee Lifecycle' => ['Administration', 'user-check', '#0EA5E9'],
        'Reports' => ['Reports & System', 'pie', '#1D4ED8'],
        'Monitoring' => ['Reports & System', 'monitor', '#0891B2'],
        'Security' => ['Reports & System', 'lock', '#5B6B86'],
        'System' => ['Reports & System', 'settings', '#5B6B86'],
    ];

    /**
     * The menu grouped into sections for the sidebar.
     *
     * @return list<array{title: ?string, groups: list<array>}>
     */
    public static function sections(User $user): array
    {
        $sections = [];
        foreach (self::for($user) as $group) {
            [$section, $icon, $tone] = self::META[$group['heading']] ?? ['More', $group['items'][0]['icon'] ?? 'layers', '#5B6B86'];
            $key = $section ?? '';
            $sections[$key] ??= ['title' => $section, 'groups' => []];
            $sections[$key]['groups'][] = [...$group, 'icon' => $icon, 'tone' => $tone, 'active' => collect($group['items'])->contains('active', true)];
        }

        return array_values($sections);
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
        // Overtime switched off in Settings > Payroll: its page and report leave the menu.
        if (($routeName === 'leave.overtime' || ($routeName === 'reports.show' && ($params['type'] ?? '') === 'overtime')) && ! app(\App\Services\PeopleOperationsService::class)->overtimeEnabled()) return false;

        if ($routeName === 'performance.goals' && ! config('features.goals')) return false;
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
