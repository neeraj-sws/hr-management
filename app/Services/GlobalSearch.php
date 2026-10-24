<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\DailyWorkStatus;
use App\Models\EmployeeNote;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Support\Collection;

/**
 * One search for the header box, the full results page and the app: people, tasks, projects, daily status,
 * notes and pages — only what the person may already see.
 *   Employee: themself, their team-mates' basic profile, their own tasks / projects / notes / status.
 *   Manager: their team's people, tasks, projects and daily status.
 *   HR / Admin: everyone.
 * Never returns salary, documents or monitoring data.
 */
class GlobalSearch
{
    public const GROUPS = ['people' => 'People', 'tasks' => 'Tasks', 'projects' => 'Projects', 'status' => 'Daily status', 'notes' => 'Notes', 'pages' => 'Pages'];

    public function __construct(private readonly LiveStatusService $live) {}

    /**
     * @return array{query: string, groups: array<string, array{label: string, total: int, items: list<array>}>, did_you_mean: ?string}
     */
    public function search(User $user, string $query, string $type = 'all', int $limit = 5, int $offset = 0): array
    {
        $query = trim(mb_substr($query, 0, 80));
        $groups = [];
        if (mb_strlen($query) < 2) return ['query' => $query, 'groups' => [], 'did_you_mean' => null];
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query).'%';
        $limit = max(1, min($limit, 50));
        $scope = $this->scope($user);

        foreach (array_keys(self::GROUPS) as $group) {
            if ($type !== 'all' && $type !== $group) continue;
            [$total, $items] = match ($group) {
                'people' => $this->people($user, $scope, $like, $limit, $offset),
                'tasks' => $this->tasks($user, $scope, $like, $limit, $offset),
                'projects' => $this->projects($user, $scope, $like, $limit, $offset),
                'status' => $this->statuses($scope, $like, $limit, $offset),
                'notes' => $this->notes($user, $scope, $like, $limit, $offset),
                'pages' => $this->pages($user, $query, $limit, $offset),
            };
            if ($total > 0) $groups[$group] = ['label' => self::GROUPS[$group], 'total' => $total, 'items' => $items];
        }

        return ['query' => $query, 'groups' => $groups, 'did_you_mean' => $groups ? null : $this->closest($scope, $query)];
    }

    /** Who the person may find: null = everyone (HR / Admin). */
    private function scope(User $user): array
    {
        $own = $user->employeeProfile;
        if ($user->canAny(['hr.manage', 'employees.manage'])) return ['all' => true, 'own' => $own, 'people' => null, 'team' => null];
        $reports = $own ? $own->directReports()->pluck('id') : collect();
        $managed = $own ? Project::where('manager_id', $own->id)->with('members:attendance_employees.id')->get()->flatMap(fn ($p) => $p->members->pluck('id')) : collect();
        $team = collect([$own?->id])->merge($reports)->merge($managed)->filter()->unique()->values();
        // Team-mates' basic profile: the same manager or the same team.
        $mates = $own ? AttendanceEmployee::where('is_active', true)->where(fn ($q) => $q->when($own->manager_id, fn ($q) => $q->where('manager_id', $own->manager_id)->orWhere('id', $own->manager_id))
            ->orWhereHas('teams', fn ($t) => $t->whereIn('teams.id', $own->teams()->pluck('teams.id')->all() ?: [0])))->pluck('id') : collect();

        return ['all' => false, 'own' => $own, 'people' => $team->merge($mates)->unique()->values(), 'team' => $team, 'isManager' => $team->count() > 1];
    }

    private function people(User $user, array $scope, string $like, int $limit, int $offset): array
    {
        $q = AttendanceEmployee::with('department')->where('is_active', true)
            ->when(! $scope['all'], fn ($q) => $q->whereIn('id', $scope['people']->all() ?: [0]))
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_code', 'like', $like)->orWhere('email', 'like', $like)->orWhere('designation', 'like', $like));
        $total = (clone $q)->count();
        $people = $q->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [ltrim($like, '%')])->orderBy('name')->skip($offset)->take($limit)->get();
        // Live status only for people in the person's own team (or everyone for HR).
        $live = $this->live->forEmployees($people->filter(fn ($e) => $scope['all'] || $scope['team']->contains($e->id))->pluck('id')->all());

        return [$total, $people->map(fn (AttendanceEmployee $e) => [
            'id' => $e->id, 'title' => $e->name, 'subtitle' => trim($e->employee_code.($e->department ? ' · '.$e->department->name : ''), ' ·'),
            'initials' => $e->initials(), 'photo' => $e->photoUrl(),
            'status' => isset($live[$e->id]) ? ['key' => $live[$e->id]['status'], 'label' => $live[$e->id]['label'], 'color' => $live[$e->id]['color']] : null,
            'url' => \App\Support\Navigation::canAccess($user, 'people.employees.show', ['employee' => $e->id]) ? route('people.employees.show', $e, false) : null,
            'shortcuts' => $scope['all'] || $scope['team']->contains($e->id) ? array_values(array_filter([
                Navigation::canAccess($user, 'people.employees.show', ['employee' => $e->id]) ? ['label' => 'Profile', 'icon' => 'user', 'url' => route('people.employees.show', $e, false)] : null,
                Navigation::canAccess($user, 'attendance.records') ? ['label' => 'Attendance', 'icon' => 'clock', 'url' => route('attendance.records', ['employee' => $e->id], false)] : null,
                Navigation::canAccess($user, 'work.tasks') ? ['label' => 'Tasks', 'icon' => 'check-square', 'url' => route('work.tasks', ['assignee' => $e->id], false)] : null,
            ])) : [],
        ])->all()];
    }

    private function tasks(User $user, array $scope, string $like, int $limit, int $offset): array
    {
        $q = Task::with(['assignee', 'project'])->where('title', 'like', $like)
            ->when(! $scope['all'], function ($q) use ($scope) {
                $projects = $scope['own'] ? Project::where('manager_id', $scope['own']->id)->orWhereHas('members', fn ($m) => $m->where('attendance_employees.id', $scope['own']->id))->pluck('id') : collect();
                $q->where(fn ($q) => $q->whereIn('assignee_id', $scope['team']->all() ?: [0])->orWhereIn('project_id', $projects->all() ?: [0]));
            });
        $total = (clone $q)->count();

        return [$total, $q->latest()->skip($offset)->take($limit)->get()->map(fn (Task $t) => [
            'id' => $t->id, 'title' => $t->title,
            'subtitle' => trim(($t->assignee ? 'Assigned to '.$t->assignee->name : 'Unassigned').($t->completed_at ? ' · Done' : ($t->due_on ? ' · Due '.$t->due_on->format('d M') : ''))),
            'status' => ['key' => $t->status, 'label' => ['todo' => 'To do', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'completed' => 'Done'][$t->status] ?? ucfirst((string) $t->status)],
            'url' => $t->assignee_id === $user->employeeProfile?->id ? route('work.my-tasks', ['task' => $t->id], false) : route('work.tasks', ['task' => $t->id], false),
        ])->all()];
    }

    private function projects(User $user, array $scope, string $like, int $limit, int $offset): array
    {
        $seeAll = $scope['all'] || $user->canAny(['projects.manage']);
        $q = Project::where(fn ($q) => $q->where('name', 'like', $like)->orWhere('client_name', 'like', $like))
            ->when(! $seeAll, fn ($q) => $q->where(fn ($q) => $q->where('manager_id', $scope['own']?->id ?? 0)->orWhereHas('members', fn ($m) => $m->whereIn('attendance_employees.id', $scope['team']->all() ?: [0]))));
        $total = (clone $q)->count();

        return [$total, $q->orderBy('name')->skip($offset)->take($limit)->get()->map(fn (Project $p) => [
            'id' => $p->id, 'title' => $p->name, 'subtitle' => $p->client_name ?: 'Internal project',
            'status' => ['key' => $p->status, 'label' => \App\Services\WorkService::PROJECT_STATUSES[$p->status] ?? ucfirst((string) $p->status)],
            'url' => Navigation::canAccess($user, 'work.projects') ? route('work.projects', ['project' => $p->id], false) : route('work.my-projects', [], false),
        ])->all()];
    }

    private function statuses(array $scope, string $like, int $limit, int $offset): array
    {
        $q = DailyWorkStatus::with('employee')
            ->when(! $scope['all'], fn ($q) => $q->whereIn('employee_id', ($scope['isManager'] ?? false ? $scope['team'] : collect([$scope['own']?->id]))->filter()->all() ?: [0]))
            ->where(fn ($q) => $q->where('summary', 'like', $like)->orWhere('plan', 'like', $like)->orWhere('blockers', 'like', $like)
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', $like)));
        $total = (clone $q)->count();

        return [$total, $q->latest('work_date')->skip($offset)->take($limit)->get()->map(fn (DailyWorkStatus $s) => [
            'id' => $s->id, 'title' => $s->work_date->format('d M').' · '.\Illuminate\Support\Str::limit(strtok((string) ($s->summary ?: $s->plan), "\n"), 80),
            'subtitle' => 'by '.($s->employee?->name ?? '—'),
            'url' => route('work.daily-status', ['employee' => $s->employee_id, 'date' => $s->work_date->toDateString()], false),
        ])->all()];
    }

    private function notes(User $user, array $scope, string $like, int $limit, int $offset): array
    {
        $own = $scope['own'];
        $teamIds = $own ? $own->teams()->pluck('teams.id') : collect();
        $reportIds = $own ? $own->directReports()->pluck('id') : collect();
        // The same rules as My Notes / Team Notes.
        $q = EmployeeNote::query()->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('body', 'like', $like))
            ->where(fn ($q) => $q->where('author_id', $user->id)
                ->orWhere(fn ($q) => $q->where('visibility', 'manager')->where('employee_id', $own?->id ?? 0))
                ->orWhere(fn ($q) => $q->where('visibility', 'team')->whereIn('team_id', $teamIds->all() ?: [0]))
                ->orWhere(fn ($q) => $q->where('visibility', 'manager')->whereIn('employee_id', $reportIds->all() ?: [0]))
                ->when($user->can('hr.manage'), fn ($q) => $q->orWhereIn('visibility', ['team', 'manager'])));
        $total = (clone $q)->count();

        return [$total, $q->latest()->skip($offset)->take($limit)->get()->map(fn (EmployeeNote $n) => [
            'id' => $n->id, 'title' => $n->title ?: 'Note', 'subtitle' => \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', strip_tags((string) $n->body)), 90),
            'url' => route($n->visibility === 'team' ? 'notes.team' : 'notes.my', [], false),
        ])->all()];
    }

    private function pages(User $user, string $query, int $limit, int $offset): array
    {
        $needle = mb_strtolower($query);
        $pages = collect(Navigation::for($user))->flatMap(fn ($group) => collect($group['items'])->map(fn ($item) => ['title' => $item['label'], 'section' => $group['heading'], 'url' => parse_url($item['url'], PHP_URL_PATH).(parse_url($item['url'], PHP_URL_QUERY) ? '?'.parse_url($item['url'], PHP_URL_QUERY) : '')]))
            ->filter(fn ($p) => str_contains(mb_strtolower($p['title']), $needle) || str_contains(mb_strtolower($p['section'].' '.$p['title']), $needle))
            ->unique('url')->values();

        return [$pages->count(), $pages->slice($offset, $limit)->map(fn ($p) => ['id' => $p['url'], 'title' => $p['title'], 'subtitle' => $p['section'], 'url' => $p['url']])->values()->all()];
    }

    /** "Did you mean": the closest employee name (a typo of one or two letters). */
    private function closest(array $scope, string $query): ?string
    {
        $needle = mb_strtolower($query);
        $names = AttendanceEmployee::where('is_active', true)->when(! $scope['all'], fn ($q) => $q->whereIn('id', $scope['people']->all() ?: [0]))->pluck('name');
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($names as $name) {
            foreach (array_merge([$name], explode(' ', $name)) as $part) {
                $distance = levenshtein($needle, mb_strtolower($part));
                if ($distance < $bestDistance) [$best, $bestDistance] = [$part, $distance];
            }
        }

        return $best !== null && $bestDistance <= max(1, min(2, intdiv(mb_strlen($needle), 3))) && mb_strtolower($best) !== $needle ? $best : null;
    }
}
