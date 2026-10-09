import { useCallback, useEffect, useState } from 'react';
import { ApiError, get, post } from './api';
import { useAuth } from './auth';
import { currentPosition, type Position } from './location';
import { runOrQueue } from './queue';
import { store } from './storage';
import type { AttendanceSession } from './types';

type Action = 'check-in' | 'break-start' | 'break-end' | 'check-out';
const offlineType = { 'check-in': 'attendance.check_in', 'break-start': 'attendance.break_start', 'break-end': 'attendance.break_end', 'check-out': 'attendance.check_out' } as const;
const endpoint = { 'check-in': '/attendance/check-in', 'break-start': '/attendance/break/start', 'break-end': '/attendance/break/end', 'check-out': '/attendance/check-out' } as const;
const nextStatus = { 'check-in': 'working', 'break-start': 'on_break', 'break-end': 'working', 'check-out': 'completed' } as const;
const label = { 'check-in': 'Check in', 'break-start': 'Start break', 'break-end': 'Resume work', 'check-out': 'Check out' } as const;

/** Today's attendance with live actions; falls back to the offline queue when the server is unreachable. */
export function useAttendance() {
  const { me } = useAuth();
  const [session, setSession] = useState<AttendanceSession | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<Action | null>(null);
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await get<{ data: AttendanceSession | null }>('/attendance/today');
      setSession(response.data);
      await store.setJson('sws.today', response.data);
    } catch (error) {
      if (error instanceof ApiError && error.isNetwork) {
        setSession(await store.getJson<AttendanceSession | null>('sws.today', null));
        setMessage({ tone: 'info', text: 'Offline — showing the last synced status.' });
      } else if (error instanceof ApiError) {
        setMessage({ tone: 'error', text: error.firstMessage });
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const act = useCallback(async (action: Action) => {
    setBusy(action);
    setMessage(null);
    try {
      // Location is read only for check-in/out, and only when the company requires it.
      let position: Position | null = null;
      if (me?.policies?.location_required && (action === 'check-in' || action === 'check-out')) {
        try {
          position = await currentPosition();
        } catch (error) {
          setMessage({ tone: 'error', text: error instanceof Error ? error.message : 'Could not read your location.' });
          return;
        }
      }
      const place = position ? { latitude: position.latitude, longitude: position.longitude, accuracy: position.accuracy } : {};
      let updated: AttendanceSession | null = null;
      const result = await runOrQueue(
        async () => {
          updated = (await post<{ data: AttendanceSession }>(endpoint[action], { source: 'mobile', ...place })).data;
        },
        { type: offlineType[action], payload: { occurred_at: new Date().toISOString(), source: 'mobile', ...place }, label: label[action] },
      );
      if (result === 'online' && updated) {
        setSession(updated);
        await store.setJson('sws.today', updated);
        setMessage({ tone: 'success', text: `${label[action]} recorded.` });
      } else {
        const now = new Date().toISOString();
        const optimistic: AttendanceSession = session
          ? { ...session, status: nextStatus[action], check_out: action === 'check-out' ? now : session.check_out }
          : { id: 0, work_date: now.slice(0, 10), check_in: now, check_out: null, active_seconds: 0, idle_seconds: 0, break_seconds: 0, status: 'working', server_version: 0 };
        setSession(optimistic);
        await store.setJson('sws.today', optimistic);
        setMessage({ tone: 'info', text: `${label[action]} saved offline — it will sync automatically.` });
      }
    } catch (error) {
      setMessage({ tone: 'error', text: error instanceof ApiError ? error.firstMessage : 'Something went wrong.' });
    } finally {
      setBusy(null);
    }
  }, [session, me?.policies?.location_required]);

  return { session, loading, busy, message, load, act };
}
