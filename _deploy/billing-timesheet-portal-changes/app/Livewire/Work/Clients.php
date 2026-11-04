<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/** Clients: their projects, the billing customer their invoices go to, and the logins that open the client portal. */
class Clients extends Component
{
    use InteractsWithHr;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $contactName = '';
    public string $email = '';
    public string $phone = '';
    public ?int $billingCustomerId = null;
    public array $projectIds = [];
    public bool $isActive = true;

    public ?int $loginFor = null;
    public string $loginName = '';
    public string $loginEmail = '';
    public string $loginPassword = '';

    private function guard(): void
    {
        abort_unless($this->user()->canAny(['projects.manage', 'hr.manage']), 403);
    }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'name', 'contactName', 'email', 'phone', 'billingCustomerId', 'projectIds');
        $this->isActive = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $c = Client::findOrFail($id);
        $this->fill(['editingId' => $c->id, 'name' => $c->name, 'contactName' => (string) $c->contact_name, 'email' => (string) $c->email, 'phone' => (string) $c->phone,
            'billingCustomerId' => $c->billing_customer_id, 'isActive' => $c->is_active, 'projectIds' => $c->projects()->pluck('id')->map(fn ($i) => (string) $i)->all()]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:191'],
            'contactName' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'billingCustomerId' => ['nullable', 'exists:billing_customers,id'],
            'projectIds' => ['array'], 'projectIds.*' => ['integer', 'exists:projects,id'],
        ], [], ['billingCustomerId' => 'billing customer', 'projectIds' => 'projects']);
        DB::transaction(function () use ($data, $audit) {
            $client = Client::updateOrCreate(['id' => $this->editingId], ['name' => $data['name'], 'contact_name' => $data['contactName'] ?: null, 'email' => $data['email'] ?: null,
                'phone' => $data['phone'] ?: null, 'billing_customer_id' => $data['billingCustomerId'] ?: null, 'is_active' => $this->isActive]);
            $ids = array_map('intval', $this->projectIds);
            Project::where('client_id', $client->id)->whereNotIn('id', $ids ?: [0])->update(['client_id' => null]);
            if ($ids) Project::whereIn('id', $ids)->update(['client_id' => $client->id, 'client_name' => $client->name]);
            // Switching a client off also stops their portal logins.
            User::where('client_id', $client->id)->update(['is_active' => $client->is_active]);
            $audit->record($this->editingId ? 'client.updated' : 'client.created', $client, ['projects' => $ids]);
        });
        $this->showForm = false;
        $this->toast('Client saved.');
    }

    public function openLogin(int $clientId): void
    {
        $this->guard();
        $client = Client::findOrFail($clientId);
        $this->fill(['loginFor' => $client->id, 'loginName' => (string) ($client->contact_name ?: $client->name), 'loginEmail' => (string) $client->email, 'loginPassword' => '']);
        $this->resetValidation();
    }

    /** Creates a client-portal login (no HRMS role or permission: it can only see this client's projects). */
    public function saveLogin(AuditLogger $audit): void
    {
        $this->guard();
        $client = Client::findOrFail($this->loginFor);
        $this->validate([
            'loginName' => ['required', 'string', 'max:191'],
            'loginEmail' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'loginPassword' => ['required', 'string', Password::min(10)->letters()->numbers()],
        ], [], ['loginName' => 'name', 'loginEmail' => 'e-mail', 'loginPassword' => 'password']);
        $user = User::create(['name' => $this->loginName, 'email' => strtolower(trim($this->loginEmail)), 'password' => $this->loginPassword, 'is_active' => $client->is_active, 'client_id' => $client->id]);
        $audit->record('client.login_created', $user, ['client_id' => $client->id]);
        $this->loginFor = null;
        $this->toast("Portal login created for {$user->email}. Share the password with them securely; they sign in on the normal sign-in page.");
    }

    public function toggleLogin(int $userId, AuditLogger $audit): void
    {
        $this->guard();
        $user = User::whereNotNull('client_id')->findOrFail($userId);
        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) $user->tokens()->delete();
        $audit->record($user->is_active ? 'client.login_enabled' : 'client.login_disabled', $user);
        $this->toast($user->is_active ? 'Login enabled.' : 'Login disabled.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->loginFor = null;
    }

    public function render()
    {
        $this->guard();

        return $this->page('livewire.work.clients', [
            'clients' => Client::with(['projects:id,name,client_id,hourly_rate', 'users:id,name,email,client_id,is_active', 'billingCustomer:id,name'])->orderBy('name')->get(),
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name', 'client_id']),
            'loginClient' => $this->loginFor ? Client::find($this->loginFor) : null,
        ], 'Clients');
    }
}
