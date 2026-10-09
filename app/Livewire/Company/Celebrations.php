<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\CompanyService;
use Livewire\Component;

class Celebrations extends Component
{
    use InteractsWithHr;

    public function render(CompanyService $company)
    {
        return $this->page('livewire.company.celebrations', [
            'events' => $company->celebrations(30),
            'own' => $this->ownEmployee(),
        ], 'Celebrations');
    }
}
