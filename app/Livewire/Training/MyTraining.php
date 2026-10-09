<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\TrainingAssignment;
use App\Services\TrainingService;
use Livewire\Component;

class MyTraining extends Component
{
    use InteractsWithHr;

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
        $assignments = TrainingAssignment::with(['course', 'assigner'])->where('employee_id', $employee->id)
            ->orderByRaw("status = 'completed'")->orderByRaw('due_on IS NULL')->orderBy('due_on')->get();

        return $this->page('livewire.training.my-training', [
            'open' => $assignments->where('status', '!=', 'completed'),
            'done' => $assignments->where('status', 'completed'),
            'completing' => $this->completingId ? $assignments->firstWhere('id', $this->completingId) : null,
        ], 'My Training');
    }
}
