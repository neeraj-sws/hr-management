<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingTax;
use App\Models\Project;
use App\Models\TaskTimeEntry;
use App\Models\TimesheetEntry;
use App\Models\TimesheetMonth;
use App\Models\TimesheetObjection;
use App\Models\User;
use App\Support\XlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project timesheets the client can review: entries come from the employees' task timers (and manual
 * lines or an imported sheet), the client objects to lines or approves the month, and an approved month
 * becomes an invoice at the project's hourly rate.
 */
class TimesheetService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function month(Project $project, string $month): TimesheetMonth
    {
        return TimesheetMonth::firstOrCreate(['project_id' => $project->id, 'month' => $month]);
    }

    public function isLocked(Project $project, string $month): bool
    {
        return (bool) TimesheetMonth::where('project_id', $project->id)->where('month', $month)->value('approved_at');
    }

    /** Turns finished task-timer sessions on this project's tasks in $month into timesheet lines (once each; edits are kept). */
    public function syncFromTimers(Project $project, string $month): int
    {
        if ($this->isLocked($project, $month)) return 0;
        $from = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $added = 0;
        TaskTimeEntry::with('task')->whereHas('task', fn ($q) => $q->where('project_id', $project->id))
            ->where('status', '!=', 'running')->whereNotNull('ended_at')
            ->whereBetween('started_at', [$from, $from->copy()->endOfMonth()])
            ->whereNotIn('id', TimesheetEntry::whereNotNull('task_time_entry_id')->select('task_time_entry_id'))
            ->orderBy('started_at')->get()
            ->each(function (TaskTimeEntry $t) use ($project, &$added) {
                $minutes = (int) round(max(0, (int) $t->duration_seconds) / 60);
                if ($minutes <= 0) return;
                TimesheetEntry::create(['project_id' => $project->id, 'task_id' => $t->task_id, 'employee_id' => $t->employee_id, 'work_date' => $t->started_at->toDateString(),
                    'start_time' => $t->started_at->format('H:i'), 'end_time' => $t->ended_at?->format('H:i'), 'minutes' => $minutes, 'billable_minutes' => $minutes,
                    'description' => (string) $t->task?->title, 'status' => $t->task?->status === 'completed' ? 'Done' : 'In progress', 'source' => 'timer', 'task_time_entry_id' => $t->id]);
                $added++;
            });

        return $added;
    }

    /** Totals of a month: minutes carried in, worked, billable (incl. carried in), open objections, amount at the hourly rate. */
    public function summary(Project $project, string $month, bool $clientView = false): array
    {
        $entries = TimesheetEntry::where('project_id', $project->id)->where('work_date', '>=', $month.'-01')->where('work_date', '<=', Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString())
            ->when($clientView, fn ($q) => $q->where('visible_to_client', true))->get(['id', 'minutes', 'billable_minutes']);
        $record = TimesheetMonth::where('project_id', $project->id)->where('month', $month)->first();
        $opening = (int) ($record?->opening_remaining_minutes ?? 0);
        $billable = (int) $entries->sum('billable_minutes') + $opening;

        return [
            'entries' => $entries->count(), 'opening_minutes' => $opening, 'worked_minutes' => (int) $entries->sum('minutes'),
            'billable_minutes' => $billable, 'rate' => (float) $project->hourly_rate, 'amount' => round($billable / 60 * (float) $project->hourly_rate, 2),
            'open_objections' => TimesheetObjection::where('status', 'open')->whereIn('timesheet_entry_id', $entries->pluck('id'))->count(),
            'approved_at' => $record?->approved_at, 'approved_by' => $record?->approver?->name, 'invoice' => $record?->invoice,
        ];
    }

    /** The client objects to a line (optionally saying how many minutes it should be). */
    public function object(TimesheetEntry $entry, User $client, string $reason, ?int $proposedMinutes): TimesheetObjection
    {
        $this->assertClientCanSee($client, $entry);
        if ($this->isLocked($entry->project, $entry->work_date->format('Y-m'))) throw ValidationException::withMessages(['reason' => 'This month is already approved.']);
        if ($entry->objections()->where('status', 'open')->exists()) throw ValidationException::withMessages(['reason' => 'There is already an open objection on this line.']);
        $objection = TimesheetObjection::create(['timesheet_entry_id' => $entry->id, 'raised_by' => $client->id, 'reason' => trim($reason), 'proposed_minutes' => $proposedMinutes]);
        $this->audit->record('timesheet.objection_raised', $entry, ['objection_id' => $objection->id, 'proposed_minutes' => $proposedMinutes]);
        $project = $entry->project()->with('manager.user')->first();
        Notifier::send(Notifier::withPermission('projects.manage')->push($project?->manager?->user), 'TimesheetObjection', $objection->id,
            "{$client->name} ({$client->client?->name}) objected to {$entry->developer()}'s {$entry->work_date->format('d M')} entry on {$project?->name}: \"".\Illuminate\Support\Str::limit($objection->reason, 90).'"',
            route('work.timesheets', ['project' => $project?->id, 'month' => $entry->work_date->format('Y-m')], false), $client->id);

        return $objection;
    }

    /** The manager answers: accept (optionally setting the billable minutes) or reject; the client is told. */
    public function respond(TimesheetObjection $objection, User $manager, bool $accept, string $response, ?int $billableMinutes = null): void
    {
        if ($objection->status !== 'open') throw ValidationException::withMessages(['response' => 'This objection is already answered.']);
        DB::transaction(function () use ($objection, $manager, $accept, $response, $billableMinutes) {
            $entry = $objection->entry;
            if ($accept) {
                $minutes = $billableMinutes ?? $objection->proposed_minutes;
                if ($minutes !== null) $entry->update(['billable_minutes' => max(0, $minutes)]);
            }
            $objection->update(['status' => $accept ? 'accepted' : 'rejected', 'response' => trim($response), 'responded_by' => $manager->id, 'responded_at' => now()]);
            $this->audit->record('timesheet.objection_'.($accept ? 'accepted' : 'rejected'), $entry, ['objection_id' => $objection->id, 'billable_minutes' => $entry->fresh()->billable_minutes]);
        });
        $entry = $objection->entry;
        Notifier::send($objection->raiser, 'TimesheetObjection', $objection->id,
            "{$manager->name} ".($accept ? 'accepted' : 'did not accept')." your objection on the {$entry->work_date->format('d M')} entry".($accept ? ' (now '.\App\Support\Duration::fromMinutes((int) $entry->fresh()->billable_minutes).' billable)' : '').": \"".\Illuminate\Support\Str::limit(trim($response), 90).'"',
            route('client.timesheet', ['project' => $entry->project_id, 'month' => $entry->work_date->format('Y-m')], false), $manager->id);
    }

    /** The client approves the month (no open objections left); its lines are then locked. */
    public function approveMonth(Project $project, string $month, User $client): TimesheetMonth
    {
        abort_unless($client->client_id && $project->client_id === $client->client_id, 403);
        $summary = $this->summary($project, $month, true);
        if ($summary['open_objections'] > 0) throw ValidationException::withMessages(['month' => 'Some objections are still open. They need an answer before the month can be approved.']);
        if ($summary['entries'] === 0) throw ValidationException::withMessages(['month' => 'There is nothing to approve in this month.']);
        $record = $this->month($project, $month);
        if ($record->approved_at) return $record;
        $record->update(['approved_at' => now(), 'approved_by' => $client->id]);
        $this->audit->record('timesheet.month_approved', $project, ['month' => $month, 'billable_minutes' => $summary['billable_minutes']]);
        Notifier::send(Notifier::withPermission('projects.manage')->push($project->manager?->user), 'TimesheetApproved', $project->id,
            "{$client->name} approved the {$project->name} timesheet for ".Carbon::createFromFormat('Y-m', $month)->format('F Y').' ('.\App\Support\Duration::fromMinutes($summary['billable_minutes']).' billable).',
            route('work.timesheets', ['project' => $project->id, 'month' => $month], false), $client->id);

        return $record;
    }

    /** An invoice for the month's billable hours at the project's rate, for the client's billing customer. */
    public function createInvoice(Project $project, string $month, User $actor): BillingInvoice
    {
        $project->loadMissing('client');
        $customerId = $project->client?->billing_customer_id;
        if (! $customerId) throw ValidationException::withMessages(['invoice' => 'Link the client to a billing customer first (Work → Clients).']);
        if ((float) $project->hourly_rate <= 0) throw ValidationException::withMessages(['invoice' => 'Set the project\'s hourly rate first.']);
        $record = $this->month($project, $month);
        if ($record->invoice_id) throw ValidationException::withMessages(['invoice' => 'This month already has an invoice.']);
        $summary = $this->summary($project, $month);
        if ($summary['billable_minutes'] <= 0) throw ValidationException::withMessages(['invoice' => 'There are no billable minutes in this month.']);

        return DB::transaction(function () use ($project, $month, $actor, $customerId, $record, $summary) {
            $billing = app(BillingService::class);
            $n = $billing->nextInvoiceNumber(today());
            $invoice = BillingInvoice::create($n + ['customer_id' => $customerId, 'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays((int) ($billing->company()['due_days'] ?: 7))->toDateString(), 'status' => 'Draft', 'project_id' => $project->id,
                'place_of_supply' => \App\Models\BillingCustomer::find($customerId)?->billing_state, 'notes' => 'Timesheet '.Carbon::createFromFormat('Y-m', $month)->format('F Y').($record->approved_at ? ', approved by the client on '.$record->approved_at->format('d M Y') : ''), 'created_by' => $actor->id]);
            $hours = round($summary['billable_minutes'] / 60, 2);
            BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Development services — '.$project->name.' — '.Carbon::createFromFormat('Y-m', $month)->format('F Y'),
                'description' => \App\Support\Duration::fromMinutes($summary['billable_minutes']).' billable hours'.($summary['opening_minutes'] ? ' (incl. '.\App\Support\Duration::fromMinutes($summary['opening_minutes']).' carried in)' : ''),
                'quantity' => $hours, 'price' => (float) $project->hourly_rate, 'discount' => 0, 'hsn_sac' => '998314', 'tax_id' => BillingTax::where('rate', 18)->value('id')]);
            $record->update(['invoice_id' => $invoice->id]);
            $this->audit->record('timesheet.invoiced', $project, ['month' => $month, 'invoice_id' => $invoice->id, 'hours' => $hours]);

            return $invoice;
        });
    }

    /**
     * Imports an old timesheet workbook (one sheet per month, e.g. "Oct 2026", "August- 2026"): Date, Task
     * Details, Start/End Time, Hours, In Minutes, an unlabelled billable-minutes column (or "Adjustment" in
     * hours), Developer, Status, Remarks. An "Old remaining" line sets the minutes carried into the month.
     * Re-importing the same file adds nothing twice.
     */
    public function import(Project $project, UploadedFile $file, int $actorId): array
    {
        $created = $kept = 0;
        $errors = $notes = [];
        $months = [];
        $employees = AttendanceEmployee::get(['id', 'name']);
        foreach (XlsxReader::sheets($file->getRealPath()) as $sheet) {
            $month = LegacySalaryImport::monthFromSheetName($sheet['name']);
            [$cols, $headerRow] = $this->header($sheet['rows']);
            if (! $month || ! $cols) { if ($sheet['rows']) $notes[] = "Sheet \"{$sheet['name']}\" skipped (not a month sheet with Date / Task Details columns)."; continue; }
            foreach ($sheet['rows'] as $r => $cells) {
                if ($r <= $headerRow) continue;
                $get = fn (string $k) => isset($cols[$k]) ? trim((string) ($cells[$cols[$k]] ?? '')) : '';
                $task = $get('task');
                if (preg_match('/^old\s*remaining/i', $task)) {
                    $carried = (int) round((float) ($get('billable') !== '' ? $get('billable') : $get('minutes')));
                    if ($this->isLocked($project, $month)) { $notes[] = "{$sheet['name']}: month already approved — carried-in minutes not changed."; continue; }
                    $this->month($project, $month)->update(['opening_remaining_minutes' => $carried]);
                    $notes[] = "{$sheet['name']}: {$carried} minutes carried in (\"Old remaining\").";
                    continue;
                }
                if ($task === '' && $get('date') === '') continue;
                $date = $this->excelDate($get('date'));
                if (! $date) { $errors[] = "{$sheet['name']} row {$r}: date \"{$get('date')}\" is not a date."; continue; }
                $start = $this->excelTime($get('start'));
                $end = $this->excelTime($get('end'));
                if ($start && $end && $end < $start && (int) substr($end, 0, 2) < 12) $end = sprintf('%02d', (int) substr($end, 0, 2) + 12).substr($end, 2); // "2:00" after a morning start is 14:00
                $minutes = $get('minutes') !== '' && is_numeric($get('minutes')) ? (int) round((float) $get('minutes')) : $this->durationMinutes($get('hours'), $start, $end);
                if ($minutes === null) { $errors[] = "{$sheet['name']} row {$r}: no minutes / hours."; continue; }
                $billable = $get('billable') !== '' && is_numeric($get('billable')) ? (int) round((float) $get('billable'))
                    : ($get('adjustment') !== '' && is_numeric($get('adjustment')) ? (int) round((float) $get('adjustment') * 60) : $minutes);
                $developer = $get('developer');
                $employee = $developer !== '' ? $employees->filter(fn ($e) => stripos($e->name, $developer) === 0 || strcasecmp(strtok($e->name, ' '), $developer) === 0)->first() : null;
                $key = hash('sha256', implode('|', [$project->id, $date, $task, $developer, $minutes, $start]));
                if (TimesheetEntry::where('import_key', $key)->exists()) { $kept++; continue; }
                if ($this->isLocked($project, $date->format('Y-m'))) { $errors[] = "{$sheet['name']} row {$r}: month already approved."; continue; }
                TimesheetEntry::create(['project_id' => $project->id, 'employee_id' => $employee?->id, 'developer_name' => $employee ? null : ($developer ?: null),
                    'work_date' => $date->toDateString(), 'start_time' => $start, 'end_time' => $end, 'minutes' => $minutes, 'billable_minutes' => $billable,
                    'description' => mb_substr($task ?: 'Work', 0, 5000), 'status' => $get('status') ?: null, 'remarks' => $get('remarks') ?: null, 'source' => 'import', 'import_key' => $key, 'created_by' => $actorId]);
                $created++;
                $months[$date->format('Y-m')] = true;
            }
        }
        $this->audit->record('timesheet.imported', $project, ['file' => $file->getClientOriginalName(), 'created' => $created, 'kept' => $kept, 'skipped' => count($errors)]);
        ksort($months);

        return ['created' => $created, 'kept' => $kept, 'errors' => array_slice($errors, 0, 50), 'notes' => $notes, 'months' => array_keys($months)];
    }

    /** @return array{0: array<string,int>, 1: int} column index by field, header row */
    private function header(array $rows): array
    {
        $map = ['date' => 'date', 'task_details' => 'task', 'task' => 'task', 'start_time' => 'start', 'end_time' => 'end', 'hours' => 'hours', 'in_minutes' => 'minutes', 'minutes' => 'minutes',
            'adjustment' => 'adjustment', 'developer' => 'developer', 'status' => 'status', 'remarks' => 'remarks'];
        foreach (array_slice($rows, 0, 5, true) as $r => $cells) {
            $cols = [];
            foreach ($cells as $i => $text) if (isset($map[$slug = LegacySalaryImport::slug((string) $text)])) $cols[$map[$slug]] ??= $i;
            if (isset($cols['date'], $cols['task'])) {
                // The unlabelled column right after "In Minutes" holds the minutes charged to the client.
                if (isset($cols['minutes']) && ! isset($cells[$cols['minutes'] + 1])) $cols['billable'] = $cols['minutes'] + 1;

                return [$cols, $r];
            }
        }

        return [[], 0];
    }

    private function excelDate(string $v): ?Carbon
    {
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) return Carbon::create(1899, 12, 30)->addDays((int) $v)->startOfDay();

        return app(ImportService::class)->parseDate($v);
    }

    /** Excel time fraction (0.4375) or "10:30" / "10:30 AM" → "HH:MM"; null when blank. */
    private function excelTime(string $v): ?string
    {
        if ($v === '') return null;
        if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) return gmdate('H:i', (int) round((float) $v * 86400));
        $t = app(ImportService::class)->parseTime(today(), $v);

        return $t?->format('H:i');
    }

    /** "35min", "2 hrs", "1 hr 40 min", "2h 0m", "45min", an Excel duration (0.0159) — or end − start. */
    private function durationMinutes(string $hours, ?string $start, ?string $end): ?int
    {
        $h = strtolower(trim($hours));
        if ($h !== '') {
            if (is_numeric($h) && (float) $h < 1) return (int) round((float) $h * 1440);
            if (is_numeric($h)) return (int) round((float) $h * 60);
            preg_match('/(\d+(?:\.\d+)?)\s*h/', $h, $hh);
            preg_match('/(\d+)\s*m/', $h, $mm);
            if ($hh || $mm) return (int) round((float) ($hh[1] ?? 0) * 60 + (int) ($mm[1] ?? 0));
        }
        if ($start && $end) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            if ($e->lt($s)) $e->addHours(12); // sheets write afternoon times without AM/PM (2:00 = 14:00)

            return (int) $s->diffInMinutes($e);
        }

        return null;
    }

    /** A client may only see visible lines of their own projects. */
    public function assertClientCanSee(User $client, TimesheetEntry $entry): void
    {
        abort_unless($client->client_id && $entry->visible_to_client && $entry->project?->client_id === $client->client_id, 403);
    }

    /** Lines of a month for the admin page or (visible only) the client portal. */
    public function entries(Project $project, string $month, bool $clientView = false): Collection
    {
        return TimesheetEntry::with(['employee', 'objections.raiser', 'objections.responder', 'task'])->where('project_id', $project->id)
            ->where('work_date', '>=', $month.'-01')->where('work_date', '<=', Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString())
            ->when($clientView, fn ($q) => $q->where('visible_to_client', true))->orderBy('work_date')->orderBy('start_time')->orderBy('id')->get();
    }
}
