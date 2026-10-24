import type { AttendanceSession } from './types';

/**
 * The server's figures counted on to this second by the same rule as the website and the desktop app:
 * while working, the state last reported keeps counting for a while (in use / meeting -> worked and that
 * bucket; idle -> idle, worked stands still, except for field / mobile work); on a break, break counts.
 * `seconds` = seconds since the session was fetched.
 */
export function liveFigures(session: AttendanceSession | null | undefined, seconds: number) {
  const running = session?.live?.running ?? (!!session && !session.check_out && ['working', 'on_break'].includes(session.status));
  const s = running ? Math.max(0, Math.floor(seconds)) : 0;
  const v = {
    worked: session?.worked_seconds ?? session?.active_seconds ?? 0,
    idle: session?.idle_counted_seconds ?? session?.idle_seconds ?? 0,
    brk: session?.live?.break_seconds ?? session?.break_seconds ?? 0,
    active: session?.screen_active_seconds ?? 0,
    meeting: session?.screen_meeting_seconds ?? 0,
    sIdle: session?.screen_idle_seconds ?? 0,
  };
  if (s && session?.status === 'on_break') v.brk += s;
  else if (s && session?.status === 'working') {
    const k = Math.min(s, session.live?.tick_for ?? 0);
    const state = session.live?.state ?? null;
    if (state === 'idle' && session.live?.idle_deducted !== false) { v.idle += k; v.sIdle += k; v.worked += s - k; }
    else if (state === 'idle') { v.sIdle += k; v.worked += s; } // field / mobile work: idle is not taken off
    else { v.worked += s; if (state === 'active') v.active += k; else if (state === 'meeting') v.meeting += k; }
  }
  v.worked = Math.max(0, v.worked);

  return v;
}

/** HH:MM:SS */
export const clock = (seconds: number) => {
  const t = Math.max(0, Math.floor(seconds));
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(Math.floor(t / 3600))}:${p(Math.floor((t % 3600) / 60))}:${p(t % 60)}`;
};
