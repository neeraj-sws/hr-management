<div>
    <div class="page-head"><div><h1>Telegram</h1><p>SWS HRMS Notifications bot (official Telegram Bot API, free): HR events to the Super Admin's private chat, and each employee's own notifications to the chat they connected in My profile.</p></div></div>
    <x-errors />
    @error('telegram')<div class="alert alert-error">{{ $message }}</div>@enderror

    <div class="grid grid-2 mb">
        <section class="card">
            <div class="card-head"><div><h2>Setup</h2><p>The bot token and the Super Admin chat ID are kept in .env on the server only.</p></div></div>
            <div class="list">
                @foreach($checks as [$label, $ok])
                    <div class="list-row"><div><strong>{{ $label }}</strong></div><x-badge :tone="$ok ? 'green' : 'red'">{{ $ok ? 'OK' : 'Missing' }}</x-badge></div>
                @endforeach
                <div class="list-row"><div><strong>Webhook</strong><small>{{ $webhookUrl }}</small></div><button type="button" class="btn btn-sm" wire:click="connectWebhook" wire:loading.attr="disabled">Connect the bot to this website</button></div>
                <div class="list-row"><div><strong>Queue</strong><small>{{ $queueConnection }} · queue "telegram" · sent by the scheduler every minute</small></div><button type="button" class="btn btn-sm btn-primary" wire:click="sendTest" wire:loading.attr="disabled">Send test to Super Admin</button></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Who gets what</h2><p>Employees get only their own records. Each employee also chooses per kind in My profile.</p></div></div>
            <form class="card-body stack" wire:submit="save">
                <label class="check"><input type="checkbox" wire:model="form.on"> <span><strong>Telegram notifications on</strong> · master switch</span></label>
                <label class="check"><input type="checkbox" wire:model="form.employees"> <span><strong>Employees' own notifications on Telegram</strong> · leave, salary slip, payments, tasks, corrections, fines (only to people who connected)</span></label>
                <x-field label="Message format" name="form.format"><select wire:model="form.format"><option value="html">Formatted (bold titles, link)</option><option value="text">Plain text</option></select></x-field>
                {{-- Only an administrator adds people here; each must have connected their own Telegram first. --}}
                <x-field label="Also send the Super Admin feed to" name="form.extra_users" hint="People who connected Telegram in My profile. They get the same administrative messages in their own chat.">
                    <div style="max-height:150px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:6px 10px">
                        @forelse($links as $link)<label class="check" style="display:flex"><input type="checkbox" value="{{ $link->user_id }}" wire:model="form.extra_users"> {{ $link->user?->name }}</label>@empty<span class="small muted">Nobody has connected Telegram yet.</span>@endforelse
                    </div>
                </x-field>
                <div class="table-wrap"><table class="table" data-no-sort><thead><tr><th>Event</th><th>Super Admin chat</th><th>Employee's own chat</th></tr></thead><tbody>
                    @foreach($events as $event => [$label, , $employeeDefault])
                        <tr><td>{{ $label }}</td><td><input type="checkbox" wire:model="form.admin.{{ $event }}" aria-label="{{ $label }} to the Super Admin"></td>
                            <td>@if($employeeDefault !== null)<input type="checkbox" wire:model="form.employee.{{ $event }}" aria-label="{{ $label }} to the employee">@else<span class="small muted">via their notifications</span>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
                <div><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div>
            </form>
        </section>
    </div>

    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><div><h2>Connected people</h2><p>{{ $links->count() }} connected. People connect themselves in My profile > Connect Telegram.</p></div></div>
            <div class="list">
                @forelse($links as $link)
                    <div class="list-row"><div><strong>{{ $link->user?->name }}</strong><small>{{ $link->telegram_username ? '@'.$link->telegram_username.' · ' : '' }}since {{ $link->linked_at?->format('d M Y, h:i A') }}</small></div><button type="button" class="btn btn-sm btn-danger" wire:click="disconnect({{ $link->user_id }})" wire:confirm="Disconnect Telegram for {{ $link->user?->name }}?">Disconnect</button></div>
                @empty
                    <x-empty title="Nobody has connected Telegram yet" icon="bell" />
                @endforelse
            </div>
        </section>
        <section class="card">
            <div class="card-head"><div><h2>Latest messages</h2><p>Each message is sent once; failures are retried, then shown here.</p></div></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>To</th><th>Event</th><th>Status</th></tr></thead><tbody>
                @forelse($messages as $m)
                    <tr><td class="nowrap small">{{ $m->created_at?->format('d M, h:i A') }}</td><td>{{ $m->target === 'admin' ? 'Super Admin' : 'Employee' }}</td><td>{{ $m->event ?? 'notification' }}</td>
                        <td><x-badge :tone="['sent' => 'green', 'failed' => 'red', 'retrying' => 'amber'][$m->status] ?? 'gray'">{{ $m->status }}</x-badge>@if($m->error)<small class="muted" style="display:block">{{ $m->error }}</small>@endif</td></tr>
                @empty
                    <tr><td colspan="4"><x-empty title="No messages yet" icon="bell" /></td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    </div>
</div>
