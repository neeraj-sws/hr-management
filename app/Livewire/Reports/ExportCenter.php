<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AuditLog;
use App\Services\ReportService;
use Livewire\Component;

class ExportCenter extends Component
{
    use InteractsWithHr;

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
    }

    public function render(ReportService $reports)
    {
        if (! strtotime($this->from)) $this->from = now()->startOfMonth()->toDateString();
        if (! strtotime($this->to) || $this->to < $this->from) $this->to = max($this->from, today()->toDateString());

        return $this->page('livewire.reports.export-center', [
            'types' => collect($reports->availableTypes($this->user()))->mapWithKeys(fn ($t) => [$t => ['label' => $reports->label($t), 'columns' => $reports->columns($t)]]),
            'recent' => AuditLog::where('action', 'report.exported')->where('actor_id', auth()->id())->latest('id')->limit(10)->get(),
        ], 'Export Center');
    }
}
