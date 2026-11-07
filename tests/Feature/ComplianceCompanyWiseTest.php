<?php

namespace Tests\Feature;

use App\Livewire\Compliance\Policies;
use App\Models\AttendanceEmployee;
use App\Models\Company;
use App\Models\PolicyRule;
use App\Models\User;
use App\Services\PolicyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Compliance (fine) rules: each company keeps its own, the others keep theirs. */
class ComplianceCompanyWiseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $itweb;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->itweb = Company::create(['name' => 'ITWeb']);
        $this->owner = User::factory()->create(['company_id' => $this->itweb->id]);
        $this->owner->assignRole('Company Owner');
    }

    private function rule(?int $companyId, string $key): array
    {
        return app(PolicyConfigurationService::class)->forCompany($companyId, $key, today()->toDateString());
    }

    public function test_a_company_owner_saves_his_own_fines_including_task_and_daily_status(): void
    {
        $this->actingAs($this->owner);
        $component = Livewire::test(Policies::class);
        foreach (['attendance.late_penalty', 'daily_status.missing', 'task.missing_entry'] as $key) {
            $slot = Policies::slot($key);
            foreach (array_keys(Policies::RULES[$key][1]) as $field) $component->set("values.{$slot}.{$field}", $field === 'amount_per_day' ? '77' : '0');
            $component->call('saveRule', $key)->assertHasNoErrors()->assertOk();
        }
        $this->assertEqualsWithDelta(77, (float) $this->rule($this->itweb->id, 'daily_status.missing')['amount_per_day'], 0.001);
        $this->assertEqualsWithDelta(77, (float) $this->rule($this->itweb->id, 'task.missing_entry')['amount_per_day'], 0.001);
        // The rule for all companies is untouched.
        $this->assertSame(0, PolicyRule::withoutGlobalScopes()->whereNull('company_id')->where('rule_key', 'daily_status.missing')->where('configuration->amount_per_day', 77)->count());
    }

    public function test_the_super_admin_picks_a_company_and_saves_for_it_only(): void
    {
        $this->actingAs($this->admin);
        $slot = Policies::slot('task.missing_entry');
        Livewire::withQueryParams(['company' => (string) $this->itweb->id])->test(Policies::class)
            ->assertSee('ITWeb only')
            ->set("values.{$slot}.amount_per_day", '55')->call('saveRule', 'task.missing_entry')->assertHasNoErrors();
        $this->assertEqualsWithDelta(55, (float) $this->rule($this->itweb->id, 'task.missing_entry')['amount_per_day'], 0.001);
        $this->assertSame(0.0, (float) ($this->rule(null, 'task.missing_entry')['amount_per_day'] ?? 0));
    }
}
