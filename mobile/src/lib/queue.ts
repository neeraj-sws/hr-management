import NetInfo from '@react-native-community/netinfo';
import { ApiError, post, upload } from './api';
import { store } from './storage';

/**
 * Offline action queue. Actions captured while offline are replayed through
 * POST /api/sync/actions in order. The server is authoritative:
 *  - every action carries a unique action_id, so a replay never duplicates;
 *  - task actions carry the last seen server_version and come back as a
 *    conflict (409) if the task changed meanwhile;
 *  - attendance actions carry occurred_at (server accepts up to 48 h old).
 * Leave requests, expense claims and daily status are sent to their normal
 * endpoints with an X-Action-Id header, which the server also de-duplicates.
 */
export type QueuedAction = {
  action_id: string;
  type: 'attendance.check_in' | 'attendance.break_start' | 'attendance.break_end' | 'attendance.check_out' | 'task.start' | 'task.pause' | 'task.resume' | 'task.complete' | DirectType;
  entity_id?: number;
  base_version?: number;
  payload: Record<string, unknown>;
  label: string;
  status: 'queued' | 'conflict' | 'failed';
  attempts: number;
  last_error?: string;
  queued_at: string;
};

type DirectType = 'leave.request' | 'expense.submit' | 'daily_status.submit';
const DIRECT: Record<DirectType, string> = { 'leave.request': '/leave/requests', 'expense.submit': '/expenses', 'daily_status.submit': '/daily-status' };
const isDirect = (type: string): type is DirectType => type in DIRECT;

const KEY = 'sws.offline.queue.v1';
type Listener = (items: QueuedAction[]) => void;
const listeners = new Set<Listener>();
let syncing = false;

export function uuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export async function list(): Promise<QueuedAction[]> {
  return store.getJson<QueuedAction[]>(KEY, []);
}

async function save(items: QueuedAction[]) {
  await store.setJson(KEY, items);
  listeners.forEach((listener) => listener(items));
}

export function subscribe(listener: Listener): () => void {
  listeners.add(listener);
  list().then(listener);
  return () => listeners.delete(listener);
}

export async function enqueue(action: Omit<QueuedAction, 'action_id' | 'status' | 'attempts' | 'queued_at'> & { action_id?: string }): Promise<QueuedAction> {
  const items = await list();
  const item: QueuedAction = { ...action, action_id: action.action_id ?? uuid(), status: 'queued', attempts: 0, queued_at: new Date().toISOString() };
  if (items.some((existing) => existing.action_id === item.action_id)) return item;
  await save([...items, item]);
  return item;
}

/** Removes every queued action (used on sign-out so another user can never sync them). */
export async function clearQueue() {
  await save([]);
}

export async function discard(actionId: string) {
  await save((await list()).filter((item) => item.action_id !== actionId));
}

async function update(actionId: string, change: Partial<QueuedAction> | null) {
  const items = await list();
  await save(change === null ? items.filter((item) => item.action_id !== actionId) : items.map((item) => (item.action_id === actionId ? { ...item, ...change } : item)));
}

/** Posts one leave / expense / daily-status item to its own endpoint. */
async function sendDirect(item: QueuedAction & { type: DirectType }) {
  const headers = { 'X-Action-Id': item.action_id };
  if (item.type !== 'expense.submit') {
    await post(DIRECT[item.type], item.payload, headers);
    return;
  }
  const { receipt, ...fields } = item.payload as Record<string, unknown> & { receipt?: { uri: string; name: string; type: string } };
  // No bill photo: a plain request (the file-upload format failed on some phones and kept claims stuck here).
  if (!receipt) {
    await post(DIRECT[item.type], fields, headers);
    return;
  }
  const form = new FormData();
  Object.entries(fields).forEach(([key, value]) => { if (value !== null && value !== undefined) form.append(key, String(value)); });
  if (receipt) form.append('receipt', receipt as unknown as Blob);
  await upload(DIRECT[item.type], form, headers);
}

/** Sends queued actions; returns how many were accepted by the server. */
export async function flush(): Promise<number> {
  if (syncing) return 0;
  const queued = (await list()).filter((item) => item.status === 'queued');
  if (!queued.length) return 0;
  syncing = true;
  try {
    let sent = 0;
    for (const item of queued.filter((i) => isDirect(i.type)).slice(0, 20)) {
      try {
        await sendDirect(item as QueuedAction & { type: DirectType });
        await update(item.action_id, null);
        sent++;
      } catch (error) {
        if (error instanceof ApiError && error.isNetwork) return sent; // still offline
        const status = error instanceof ApiError ? error.status : 0;
        const message = error instanceof ApiError ? error.firstMessage : 'Could not send';
        await update(item.action_id, { attempts: item.attempts + 1, last_error: message, status: status === 409 ? 'conflict' : status >= 500 || status === 0 ? 'queued' : 'failed' });
      }
    }
    return sent + (await flushSyncActions(queued.filter((i) => !isDirect(i.type)).slice(0, 50)));
  } finally {
    syncing = false;
  }
}

/** Attendance and task actions go through POST /api/sync/actions in one batch. */
async function flushSyncActions(pending: QueuedAction[]): Promise<number> {
  if (!pending.length) return 0;
  const body = { actions: pending.map(({ action_id, type, entity_id, base_version, payload }) => ({ action_id, type, ...(entity_id !== undefined ? { entity_id } : {}), ...(base_version !== undefined ? { base_version } : {}), payload })) };
  let results: { action_id: string; status: number; success?: boolean; conflict?: boolean; message?: string; errors?: Record<string, string[]> }[] = [];
  try {
    results = (await post<{ results: typeof results }>('/sync/actions', body)).results ?? [];
  } catch (error) {
    if (error instanceof ApiError && error.isNetwork) return 0; // still offline, keep everything
    if (error instanceof ApiError && error.status === 422) {
      const message = error.firstMessage;
      await save((await list()).map((item) => (pending.some((p) => p.action_id === item.action_id) ? { ...item, status: 'failed', last_error: message, attempts: item.attempts + 1 } : item)));
      return 0;
    }
    throw error;
  }
  const byId = new Map(results.map((result) => [result.action_id, result]));
  let accepted = 0;
  const next: QueuedAction[] = [];
  for (const item of await list()) {
    const result = byId.get(item.action_id);
    if (!result) {
      next.push(item);
      continue;
    }
    if (result.status < 300 && result.success !== false) {
      accepted++;
      continue;
    }
    const message = result.message ?? Object.values(result.errors ?? {})[0]?.[0] ?? 'Rejected by server';
    next.push({ ...item, attempts: item.attempts + 1, last_error: message, status: result.status === 409 || result.conflict ? 'conflict' : [401, 403, 404, 422].includes(result.status) ? 'failed' : 'queued' });
  }
  await save(next);
  return accepted;
}

let started = false;
/** Flush whenever connectivity returns and every 30 s while the app runs. */
export function startAutoSync() {
  if (started) return;
  started = true;
  NetInfo.addEventListener((state) => {
    if (state.isConnected) flush().catch(() => undefined);
  });
  setInterval(() => flush().catch(() => undefined), 30000);
}

/**
 * Runs an online action; if the server cannot be reached, queues the
 * equivalent sync action instead. Returns 'online' or 'queued'.
 */
export async function runOrQueue(online: () => Promise<unknown>, offline: Parameters<typeof enqueue>[0]): Promise<'online' | 'queued'> {
  try {
    await online();
    return 'online';
  } catch (error) {
    if (error instanceof ApiError && error.isNetwork) {
      await enqueue(offline);
      return 'queued';
    }
    throw error;
  }
}
