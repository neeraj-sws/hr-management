<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\TrainingAssignment;
use App\Services\TrainingService;
use Livewire\Component;

class MyTraining extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public ?int $completingId = null;
    public string $note = '';

    public function start(int $id, TrainingService $training): void
    {
        $training->start(TrainingAssignment::findOrFail($id), $this->requireEmployee());
        $this->toast('Training started. Good luck!');
    }

    public function openComplete(int $id): void
    {
        $this->fill(['completingId' => $id, 'note' => '']);
        $this->resetValidation();
    }

    public function complete(TrainingService $training): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $training->complete(TrainingAssignment::findOrFail($this->completingId), $this->requireEmployee(), $this->note);
        $this->completingId = null;
        $this->toast('Marked as completed.');
    }

    public function closeModal(): void
    {
        $this->completingId = null;
    }

    public function render()
    {
        $employee = $this->requireEmployee();
        $base = fn () => TrainingAssignment::with(['course', 'assigner'])->where('employee_id', $employee->id);

        return $this->page('livewire.training.my-training', [
            'open' => $base()->where('status', '!=', 'completed')->orderByRaw('due_on IS NULL')->orderBy('due_on')->paginate(15, ['*'], 'openPage'),
            'done' => $base()->where('status', 'completed')->latest('completed_at')->paginate(15, ['*'], 'donePage'),
            'completing' => $this->completingId ? $base()->find($this->completingId) : null,
            'overdueCount' => $base()->where('status', '!=', 'completed')->get()->filter->isOverdue()->count(),
        ], 'My Training');
    }
}
