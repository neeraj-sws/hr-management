<?php

namespace App\Livewire\Performance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\PerformanceGoal;
use App\Services\PerformanceService;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Goals (KRA / OKR): the manager sets them (or approves the employee's), the employee updates progress. */
class Goals extends Component
{
    use InteractsWithHr;

    #[Url] public string $tab = '';
    #[Url] public string $period = '';
    #[Url] public string $employeeFilter = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $goal = [];
    public ?int $progressId = null;
    public int $progress = 0;
    public string $progressNote = '';

    public function mount(): void
    {
        abort_unless(config('features.goals'), 404);
        $canManage = $this->manageable()->isNotEmpty();
        if (! in_array($this->tab, ['mine', 'team'], true)) $this->tab = $this->ownEmployee() ? 'mine' : 'team';
        if ($this->tab === 'team' && ! $canManage) $this->tab = 'mine';
        if (! preg_match('/^\d{4}-(Q[1-4]|H[12]|FY)$/', $this->period)) $this->period = self::periodKey(today());
    }

    /** Financial-year periods (April–March): quarters by default ("2026-Q3" = Oct–Dec 2026), half-years and the full year. */
    public static function periodKey($date): string
    {
        $fy = $date->month >= 4 ? $date->year : $date->year - 1;

        return $fy.'-Q'.(intdiv(($date->month + 8) % 12, 3) + 1);
    }

    /** @return array{0: string, 1: string} */
    public static function periodRange(string $key): array
    {
        [$fy, $part] = explode('-', $key);
        $start = \Illuminate\Support\Carbon::create((int) $fy, 4, 1);
        [$offset, $months] = match ($part) { 'Q1' => [0, 3], 'Q2' => [3, 3], 'Q3' => [6, 3], 'Q4' => [9, 3], 'H1' => [0, 6], 'H2' => [6, 6], default => [0, 12] };
        $from = $start->copy()->addMonthsNoOverflow($offset);

        return [$from->toDateString(), $from->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->toDateString()];
    }

    public function periods(): array
    {
        $current = (int) explode('-', self::periodKey(today()))[0];
        $list = [];
        foreach ([$current - 1, $current, $current + 1] as $fy) {
            $label = 'FY '.$fy.'-'.substr((string) ($fy + 1), 2);
            foreach (['Q1', 'Q2', 'Q3', 'Q4', 'H1', 'H2', 'FY'] as $part) {
                [$from, $to] = self::periodRange("$fy-$part");
                $list["$fy-$part"] = ($part === 'FY' ? $label : "$part $label").' ('.\Illuminate\Support\Carbon::parse($from)->format('M Y').' – '.\Illuminate\Support\Carbon::parse($to)->format('M Y').')';
            }
        }

        return $list;
    }

    private function manageable()
    {
        return app(PerformanceService::class)->manageableIds($this->user())->reject(fn ($id) => $id === $this->ownEmployee()?->id)->values();
    }

    public function create(?int $employeeId = null): void
    {
        [$from, $to] = self::periodRange($this->period);
        $employeeId ??= $this->tab === 'mine' ? $this->ownEmployee()?->id : ((int) $this->employeeFilter ?: null);
        $this->goal = ['employee_id' => $employeeId, 'kind' => 'kra', 'title' => '', 'description' => '', 'target' => '', 'weight' => '', 'starts_on' => $from, 'ends_on' => $to];
        $this->editingId = null;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $goal = PerformanceGoal::findOrFail($id);
        $this->goal = ['employee_id' => $goal->employee_id, 'kind' => $goal->kind, 'title' => $goal->title, 'description' => (string) $goal->description, 'target' => (string) $goal->target, 'weight' => (string) $goal->weight, 'starts_on' => $goal->starts_on->toDateString(), 'ends_on' => $goal->ends_on->toDateString()];
        $this->editingId = $id;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(PerformanceService $performance): void
    {
        $this->validate([
            'goal.employee_id' => ['required', 'integer', 'exists:attendance_employees,id'], 'goal.kind' => ['required', 'in:kra,okr'],
            'goal.title' => ['required', 'string', 'max:200'], 'goal.description' => ['nullable', 'string', 'max:3000'], 'goal.target' => ['nullable', 'string', 'max:200'],
            'goal.weight' => ['required', 'integer', 'min:0', 'max:100'], 'goal.starts_on' => ['required', 'date'], 'goal.ends_on' => ['required', 'date', 'after_or_equal:goal.starts_on'],
        ], [], ['goal.employee_id' => 'employee', 'goal.title' => 'goal', 'goal.weight' => 'weight', 'goal.ends_on' => 'end date', 'goal.starts_on' => 'start date', 'goal.target' => 'target']);
        $goal = $performance->saveGoal($this->user(), $this->goal, $this->editingId ? PerformanceGoal::findOrFail($this->editingId) : null);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Goal updated.' : ($goal->status === 'proposed' ? 'Goal sent to your manager for approval.' : 'Goal set.'));
    }

    public function approve(int $id, PerformanceService $performance): void
    {
        $performance->approveGoal($this->user(), PerformanceGoal::findOrFail($id));
        $this->toast('Goal approved.');
    }

    public function setStatus(int $id, string $status, PerformanceService $performance): void
    {
        $performance->setGoalStatus($this->user(), PerformanceGoal::findOrFail($id), $status);
        $this->toast('Goal '.($status === 'active' ? 'reopened' : $status).'.');
    }

    public function openProgress(int $id): void
    {
        $goal = PerformanceGoal::findOrFail($id);
        $this->progressId = $id;
        $this->progress = $goal->progress;
        $this->progressNote = '';
        $this->resetValidation();
    }

    public function saveProgress(PerformanceService $performance): void
    {
        $this->validate(['progress' => ['required', 'integer', 'min:0', 'max:100'], 'progressNote' => ['nullable', 'string', 'max:1000']], [], ['progressNote' => 'note']);
        $performance->updateProgress($this->user(), PerformanceGoal::findOrFail($this->progressId), $this->progress, $this->progressNote);
        $this->progressId = null;
        $this->toast('Progress updated.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->progressId = null;
    }

    public function render(PerformanceService $performance)
    {
        [$from, $to] = self::periodRange($this->period);
        $own = $this->ownEmployee();
        $manageable = $this->manageable();
        $ids = $this->tab === 'mine' ? [$own?->id ?? 0] : ((int) $this->employeeFilter && $manageable->contains((int) $this->employeeFilter) ? [(int) $this->employeeFilter] : $manageable->all());
        $goals = PerformanceGoal::with(['employee', 'updates.user'])->whereIn('employee_id', $ids ?: [0])->forPeriod($from, $to)->orderBy('employee_id')->orderByDesc('weight')->orderBy('id')->get();
        $byEmployee = $goals->groupBy('employee_id');

        return $this->page('livewire.performance.goals', [
            'own' => $own,
            'canManage' => $manageable->isNotEmpty(),
            'groups' => $byEmployee,
            'from' => $from,
            'to' => $to,
            'people' => $manageable->isNotEmpty() ? AttendanceEmployee::whereIn('id', $manageable->all())->orderBy('name')->get(['id', 'name']) : collect(),
            'pendingApproval' => $this->tab === 'team' ? PerformanceGoal::whereIn('employee_id', $manageable->all() ?: [0])->where('status', 'proposed')->count() : 0,
            'progressGoal' => $this->progressId ? PerformanceGoal::with('updates.user')->find($this->progressId) : null,
        ], 'Goals');
    }
}
