<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Support\Instructions as Rules;
use Livewire\Component;

/** Company > Instructions: the rules everyone must know (attendance, leave, salary, e-mails…), from the current settings. */
class Instructions extends Component
{
    use InteractsWithHr;

    public function render()
    {
        return $this->page('livewire.company.instructions', ['sections' => Rules::sections($this->ownEmployee())], 'Instructions');
    }
}
