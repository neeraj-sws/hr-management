<div>
    <div class="page-head"><div><h1>Telegram</h1><p>SWS HRMS Notifications bot (official Telegram Bot API, free): HR events to the Super Admin's private chat, and each employee's own notifications to the chat they connected in My profile.</p></div></div>
    <x-errors />
    @error('telegram')<div class="alert alert-error">{{ $message }}</div>@enderror

    <section class="card mb">
        <div class="card-head"><div><h2>Bot connection</h2><p>Create the bot with @BotFather (/newbot), paste its token here and Save. The token is checked with Telegram, stored encrypted and never shown again. Saving a new token also connects the bot to this website.</p></div></div>
        <form class="card-body stack" wire:submit="saveBot">
            <label class="check"><input type="checkbox" wire:model="botEnabled"> <span><strong>Telegram bot on</strong> · turn off to stop every Telegram message at once</span></label>
            <div class="grid grid-2">
                <x-field label="Bot token" name="botToken" :hint="$tokenSource === 'HRMS' ? 'A token is saved'.($savedBot?->updater ? ' (by '.$savedBot->updater->name.', '.$savedBot->updated_at?->format('d M Y').')' : '').'. Leave empty to keep it; paste a new one to replace it.' : ($tokenSource === '.env' ? 'Using the token in .env. Paste one here to manage it from the HRMS instead.' : 'From @BotFather, looks like 123456789:ABC...')">
                    <input type="password" wire:model="botToken" autocomplete="new-password" placeholder="{{ $tokenSource ? '•••••••• (saved)' : '123456789:ABC...' }}">
                </x-field>
                <x-field label="Super Admin chat ID" name="adminChatId" hint="Where all administrative messages go. Easiest: connect your own Telegram in My profile, then press Use my Telegram.">
                    <div style="display:flex;gap:6px"><input type="text" wire:model="adminChatId" inputmode="numeric" placeholder="e.g. 1234567890" style="flex:1"><button type="button" class="btn btn-sm" wire:click="useMyChat" title="{{ $myChatLinked ? 'Fill in your own connected Telegram chat' : 'Connect your Telegram in My profile first' }}">Use my Telegram</button></div>
                </x-field>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn btn-primary" wire:loading.attr="disabled">Save bot</button>
                @if($tokenSource === 'HRMS')<button type="button" class="btn" wire:click="removeToken" wire:confirm="Remove the saved bot token? Telegram messages stop until a token is saved again (or .env has one).">Remove saved token</button>@endif
            </div>
        </form>
    </section>

    <div class="grid grid-2 mb">
        <section class="card">
            <div class="card-head"><div><h2>Setup</h2><p>Set the bot above under "Bot connection" - no .env editing needed.</p></div></div>
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
                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center" class="small">
                    <span class="muted">Super Admin chat:</span><button type="button" class="btn btn-sm" wire:click="setAll('admin', true)">Select all</button><button type="button" class="btn btn-sm" wire:click="setAll('admin', false)">Unselect all</button>
                    <span class="muted" style="margin-left:10px">Employee's own chat:</span><button type="button" class="btn btn-sm" wire:click="setAll('employee', true)">Select all</button><button type="button" class="btn btn-sm" wire:click="setAll('employee', false)">Unselect all</button>
                    <span class="muted">(then Save)</span>
                </div>
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
            <div class="card-head"><div><h2>Latest messages</h2><p>Today: {{ $messageCounts['sent'] ?? 0 }} sent @if($messageCounts['failed'] ?? 0)· <span style="color:var(--red)">{{ $messageCounts['failed'] }} failed</span>@endif @if(($messageCounts['pending'] ?? 0) + ($messageCounts['retrying'] ?? 0))· {{ ($messageCounts['pending'] ?? 0) + ($messageCounts['retrying'] ?? 0) }} waiting @endif · each message is sent once; failures are retried.</p></div></div>
            <div class="toolbar">
                <label class="field"><span>To</span><select wire:model.live="msgTarget"><option value="">Everyone</option><option value="admin">Super Admin</option><option value="user">Employees</option></select></label>
                <label class="field"><span>Status</span><select wire:model.live="msgStatus"><option value="">Any</option><option value="sent">Sent</option><option value="failed">Failed</option><option value="retrying">Retrying</option><option value="pending">Waiting</option><option value="skipped">Skipped</option></select></label>
                <label class="field"><span>Day</span><input type="date" wire:model.live="msgDay"></label>
            </div>
            @php($labels = collect($events)->map(fn ($e) => $e[0])->all())
            <div class="table-wrap"><table class="table" data-no-sort><thead><tr><th>Time</th><th>To</th><th>What</th><th>Status</th></tr></thead><tbody>
                @forelse($messages->getCollection()->groupBy(fn ($m) => $m->created_at?->toDateString()) as $day => $rows)
                    @php($d = \Illuminate\Support\Carbon::parse($day))
                    <tr><td colspan="4" style="background:var(--canvas);font-weight:700;font-size:12px">{{ $d->isToday() ? 'Today' : ($d->isYesterday() ? 'Yesterday' : $d->format('D, d M Y')) }} · {{ $rows->count() }}</td></tr>
                    @foreach($rows as $m)
                        <tr wire:key="tm-{{ $m->id }}"><td class="nowrap small">{{ $m->created_at?->format('h:i A') }}</td>
                            <td>{{ $m->target === 'admin' ? 'Super Admin' : ($m->user?->name ?? 'Employee') }}</td>
                            <td class="small">{{ $m->event ? ($labels[$m->event] ?? str($m->event)->replace('_', ' ')->ucfirst()) : 'Their notification' }}</td>
                            <td><x-badge :tone="['sent' => 'green', 'failed' => 'red', 'retrying' => 'amber', 'pending' => 'amber'][$m->status] ?? 'gray'">{{ ['pending' => 'waiting'][$m->status] ?? $m->status }}</x-badge>@if($m->error)<small class="muted" style="display:block">{{ $m->error }}</small>@endif</td></tr>
                    @endforeach
                @empty
                    <tr><td colspan="4"><x-empty title="No messages for these filters" icon="bell" /></td></tr>
                @endforelse
            </tbody></table></div>
            {{ $messages->links() }}
        </section>
    </div>
</div>
