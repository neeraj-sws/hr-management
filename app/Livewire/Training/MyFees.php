<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\TrainingEnrollment;
use Livewire\Component;

/**
 * A trainee's own course and fees: the course, the fee, discount, what is paid and still due, the next installment,
 * every installment with its due date and status, and every payment with its receipt.
 */
class MyFees extends Component
{
    use InteractsWithHr;

    public function render()
    {
        $employee = $this->ownEmployee();

        return $this->page('livewire.training.my-fees', [
            'enrollments' => $employee ? TrainingEnrollment::with(['payments', 'course', 'topics.mentor:id,name'])->where('employee_id', $employee->id)->latest('id')->get() : collect(),
        ], 'My course & fees');
    }
}
