<div>
    <div class="page-head">
        <div class="profile-chip"><span class="avatar avatar-lg">{{ collect(explode(' ', $user->name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('') }}</span>
            <div><h1>{{ $user->name }}</h1><p>{{ $employee?->designation ?? 'SWS HR user' }} · {{ $user->getRoleNames()->implode(', ') ?: 'No role' }}</p></div></div>
    </div>
    <div class="grid grid-sidebar">
        <div class="stack">
            <section class="card">
                <div class="card-head"><h2>Employment details</h2></div>
                <div class="card-body">
                    @if($employee)
                        <dl class="kv">
                            <dt>Employee code</dt><dd>{{ $employee->employee_code }}</dd>
                            <dt>Email</dt><dd>{{ $employee->email ?? $user->email }}</dd>
                            <dt>Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd>
                            <dt>Manager</dt><dd>{{ $employee->manager?->name ?? '—' }}</dd>
                            <dt>Teams</dt><dd>{{ $employee->teams->pluck('name')->join(', ') ?: '—' }}</dd>
                            <dt>Joined</dt><dd>{{ $employee->joined_at?->format('d M Y') ?? '—' }}</dd>
                            <dt>Work hours</dt><dd>{{ $employee->work_start ? substr($employee->work_start, 0, 5).' – '.substr((string) $employee->work_end, 0, 5) : 'Not scheduled' }} · {{ (float) $employee->daily_work_hours }} h/day</dd>
                        </dl>
                    @else
                        <x-empty title="No employee profile" icon="user">This account is not linked to an employee record.</x-empty>
                    @endif
                </div>
            </section>
            @if($employee)
                <section class="card">
                    <div class="card-head"><h2>Contact &amp; birthday</h2></div>
                    <form class="card-body" wire:submit="saveContact">
                        <x-field label="Phone" name="phone"><input wire:model="phone" type="tel" autocomplete="tel"></x-field>
                        <x-field label="Date of birth" name="dateOfBirth" hint="only the day and month are shown to colleagues"><input wire:model="dateOfBirth" type="date" max="{{ today()->subDay()->toDateString() }}"></x-field>
                        <label class="check"><input type="checkbox" wire:model="shareBirthday"> Let colleagues see my birthday and send wishes</label>
                        <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div>
                    </form>
                </section>
            @endif
        </div>
        <section class="card" id="notifications">
            <div class="card-head"><div><h2>Notifications</h2><p>Choose what reaches you in the app and by e-mail. Important updates always appear in the app.</p></div></div>
            <form class="card-body" wire:submit="savePreferences">
                <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>In the app</th><th>E-mail</th></tr></thead><tbody>
                    @foreach(\App\Notifications\ApprovalRequested::GROUPS as $group => [$label, $mutable])
                        <tr><td>{{ $label }}</td>
                            <td>@if($mutable)<input type="checkbox" wire:model="prefs.{{ $group }}.in_app" aria-label="{{ $label }} in the app">@else<span class="small muted">always</span>@endif</td>
                            <td><input type="checkbox" wire:model="prefs.{{ $group }}.email" aria-label="{{ $label }} by e-mail"></td></tr>
                    @endforeach
                </tbody></table></div>
                <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save preferences</button></div>
            </form>
        </section>
        <section class="card" id="password">
            <div class="card-head"><div><h2>Change password</h2><p>At least 12 characters with letters and numbers.</p></div></div>
            <form class="card-body stack" wire:submit="changePassword">
                <x-field label="Current password" name="currentPassword"><input type="password" wire:model="currentPassword" autocomplete="current-password"></x-field>
                <x-field label="New password" name="newPassword"><input type="password" wire:model="newPassword" autocomplete="new-password"></x-field>
                <x-field label="Confirm new password" name="newPassword_confirmation"><input type="password" wire:model="newPassword_confirmation" autocomplete="new-password"></x-field>
                <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="changePassword" class="spinner"></span>Update password</button></div>
            </form>
        </section>
    </div>
</div>
