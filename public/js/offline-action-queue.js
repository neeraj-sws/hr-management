(() => {
    const key = 'sws.hr.offline.actions.v1';
    const read = () => { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch { return []; } };
    const write = (actions) => localStorage.setItem(key, JSON.stringify(actions));
    const emit = (detail) => window.dispatchEvent(new CustomEvent('sws:sync-status', { detail }));
    let active = false;
    const api = {
        endpoint: '/api/sync/actions',
        token: '',
        configure(options = {}) { api.endpoint = options.endpoint || '/api/sync/actions'; api.token = options.token || ''; },
        enqueue(action) {
            if (!action || !action.type || !action.action_id) throw new Error('action_id and type are required');
            const actions = read();
            const existing = actions.find((item) => item.action_id === action.action_id);
            if (existing) return existing;
            const item = { ...action, status: 'queued', attempts: 0, queued_at: new Date().toISOString() };
            actions.push(item); write(actions); emit({ action_id: item.action_id, status: item.status });
            if (navigator.onLine) api.sync();
            return item;
        },
        list() { return read(); },
        async status(actionIds) {
            const params = new URLSearchParams();
            actionIds.forEach((id) => params.append('action_ids[]', id));
            const headers = { 'Accept': 'application/json' };
            if (api.token) headers.Authorization = `Bearer ${api.token}`;
            const response = await fetch(`/api/sync/status?${params}`, { credentials: 'same-origin', headers });
            if (!response.ok) throw new Error(`Sync status returned ${response.status}`);
            return response.json();
        },
        async sync() {
            if (active || !navigator.onLine) return { skipped: true };
            active = true;
            try {
                const actions = read().filter((item) => item.status !== 'conflict' && item.status !== 'failed');
                if (!actions.length) return { synced: 0 };
                const batch = actions.slice(0, 50);
                batch.forEach((item) => { item.status = 'syncing'; }); write(actions);
                const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' };
                if (api.token) headers.Authorization = `Bearer ${api.token}`;
                const response = await fetch(api.endpoint, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify({ actions: batch.map(({ status, attempts, queued_at, last_error, ...action }) => action) }) });
                const body = await response.json();
                const results = new Map((body.results || []).map((item) => [item.action_id, item]));
                const remaining = read().filter((item) => {
                    const result = results.get(item.action_id);
                    if (!result) return true;
                    if (result.status < 300 && result.success) { emit({ action_id: item.action_id, status: result.replayed ? 'replayed' : 'synced', result }); return false; }
                    item.status = result.status === 409 || result.conflict ? 'conflict' : ([401, 403, 422].includes(result.status) ? 'failed' : 'retry');
                    item.last_error = result.message || 'Sync did not complete';
                    item.attempts = (item.attempts || 0) + 1;
                    emit({ action_id: item.action_id, status: item.status, error: item.last_error });
                    return true;
                });
                write(remaining);
                if (!response.ok && response.status !== 207) throw Object.assign(new Error(body.message || `Sync returned ${response.status}`), { status: response.status, terminal: response.status === 401 || response.status === 403 || response.status === 422 });
                return body;
            } catch (error) {
                const actions = read();
                actions.forEach((item) => { if (item.status === 'syncing') { item.status = error.terminal ? 'failed' : 'retry'; item.attempts = (item.attempts || 0) + 1; item.last_error = error.message; } });
                write(actions); emit({ status: 'retry', error: error.message });
                const attempts = Math.min(6, Math.max(1, ...actions.map((item) => item.attempts || 1)));
                if (!error.terminal) window.setTimeout(() => api.sync(), Math.min(60000, 1000 * (2 ** attempts)));
                return { error: error.message };
            } finally { active = false; }
        },
        resolveConflict(actionId, replacement) {
            const actions = read().filter((item) => item.action_id !== actionId);
            write(actions);
            if (replacement) api.enqueue(replacement);
        },
    };
    window.SwsOfflineQueue = api;
    window.addEventListener('sws:sync-status', (event) => {
        const display = document.querySelector('[data-offline-sync-status]');
        if (display) display.textContent = `Offline sync: ${event.detail.status || 'queued'}${event.detail.error ? ` (${event.detail.error})` : ''}`;
    });
    window.addEventListener('online', () => api.sync());
})();
