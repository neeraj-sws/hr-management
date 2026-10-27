<div>
    <div class="page-head">
        <div class="profile-chip"><x-avatar :employee="$employee" :name="$user->name" size="lg" />
            <div><h1>{{ $user->name }}</h1><p>{{ $employee?->designation ?? 'SWS HR user' }}</p><x-person-badges :user="$user" />
                @if($employee)
                    <div class="photo-edit">
                        <label class="btn btn-sm" style="cursor:pointer"><x-icon name="user" class="icon-sm" />{{ $employee->photo_path ? 'Change photo' : 'Add photo' }}<input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" hidden></label>
                        @if($employee->photo_path)<button type="button" class="btn btn-sm btn-ghost" wire:click="removePhoto" wire:confirm="Remove your photo?">Remove</button>@endif
                        <span wire:loading wire:target="photo" class="spinner"></span>
                        @error('photo')<span class="error">{{ $message }}</span>@enderror
                    </div>
                @endif
            </div></div>
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
                        <x-field label="Current address" name="address" hint="only HR sees it"><textarea wire:model="address" rows="2" autocomplete="street-address"></textarea></x-field>
                        <x-field label="Emergency contact name" name="emergencyName"><input wire:model="emergencyName" autocomplete="off"></x-field>
                        <x-field label="Relation" name="emergencyRelation" hint="e.g. Father, Wife, Brother"><input wire:model="emergencyRelation"></x-field>
                        <x-field label="Emergency contact phone" name="emergencyPhone"><input wire:model="emergencyPhone" type="tel"></x-field>
                        <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div>
                    </form>
                </section>
            @endif
        </div>
        <section class="card" id="notifications">
            <div class="card-head"><div><h2>Notifications</h2><p>Choose what reaches you in the app, on your phone (SWS HR mobile app) and by e-mail. Important updates always appear in the app.</p></div></div>
            <form class="card-body" wire:submit="savePreferences">
                <div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>In the app</th><th>Phone push</th><th>E-mail</th></tr></thead><tbody>
                    @foreach(\App\Notifications\ApprovalRequested::GROUPS as $group => [$label, $mutable])
                        <tr><td>{{ $label }}</td>
                            <td>@if($mutable)<input type="checkbox" wire:model="prefs.{{ $group }}.in_app" aria-label="{{ $label }} in the app">@else<span class="small muted">always</span>@endif</td>
                            <td><input type="checkbox" wire:model="prefs.{{ $group }}.push" aria-label="{{ $label }} as a phone notification"></td>
                            <td><input type="checkbox" wire:model="prefs.{{ $group }}.email" aria-label="{{ $label }} by e-mail"></td></tr>
                    @endforeach
                </tbody></table></div>
                <label class="check nf-sound-pref"><input type="checkbox" wire:model="webSound"> <span><strong>Sound on the website</strong> · play a short sound when a new notification arrives (the bell updates by itself every 30 seconds)</span></label>
                <div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Save preferences</button></div>
            </form>
        </section>
        <section class="card" id="two-factor">
            <div class="card-head"><div><h2>2-step sign-in</h2><p>After your password, a 6-digit code is e-mailed to {{ $maskedEmail }}. Someone who learns your password still cannot sign in.</p></div>
                @if($twoFactorRequired)<x-badge tone="green">On (company rule)</x-badge>
                @else<button class="btn btn-sm {{ $user->two_factor_enabled ? '' : 'btn-primary' }}" wire:click="toggleTwoFactor" wire:confirm="{{ $user->two_factor_enabled ? 'Turn off 2-step sign-in for your account?' : 'Turn on 2-step sign-in? From your next sign-in you will need the code e-mailed to you.' }}">{{ $user->two_factor_enabled ? 'Turn off' : 'Turn on' }}</button>@endif
            </div>
        </section>
        <section class="card" id="password">
            <div class="card-head"><div><h2>Password</h2><p>For security, only an administrator can change your password. Contact your HR administrator if you need it reset.</p></div></div>
        </section>
    </div>
</div>
