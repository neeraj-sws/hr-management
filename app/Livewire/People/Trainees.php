<?php

namespace App\Livewire\People;

/** People > Trainees: the employee list with trainees only (they pay a course fee and are not on payroll). */
class Trainees extends Employees
{
    protected bool $traineesOnly = true;
}
