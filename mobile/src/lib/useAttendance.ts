import { useCallback, useEffect, useState } from 'react';
import { Platform } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { ApiError, get, post, upload } from './api';
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
      // Selfie at check-in/out when the company requires it (front camera, taken now).
      let photo: { uri: string; name: string; type: string; file?: File } | null = null;
      if (me?.policies?.selfie_required && (action === 'check-in' || action === 'check-out')) {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) {
          setMessage({ tone: 'error', text: 'Allow camera access: your company requires a selfie to check in and out.' });
          return;
        }
        const shot = await ImagePicker.launchCameraAsync({ cameraType: ImagePicker.CameraType.front, quality: 0.5, allowsEditing: false });
        if (shot.canceled || !shot.assets?.[0]) {
          setMessage({ tone: 'info', text: `A selfie is needed to ${label[action].toLowerCase()}.` });
          return;
        }
        const asset = shot.assets[0];
        photo = { uri: asset.uri, name: asset.fileName ?? 'selfie.jpg', type: asset.mimeType ?? 'image/jpeg', file: (asset as { file?: File }).file };
      }
      let updated: AttendanceSession | null = null;
      if (photo) {
        // A selfie cannot wait in the offline queue: it is sent now or the action is not recorded.
        const form = new FormData();
        form.append('source', 'mobile');
        Object.entries(place).forEach(([key, value]) => form.append(key, String(value)));
        if (Platform.OS === 'web' && photo.file) form.append('photo', photo.file, photo.name);
        else form.append('photo', { uri: photo.uri, name: photo.name, type: photo.type } as unknown as Blob);
        try {
          updated = (await upload<{ data: AttendanceSession }>(endpoint[action], form)).data;
        } catch (error) {
          setMessage({ tone: 'error', text: error instanceof ApiError && error.isNetwork ? 'You are offline. A selfie check-in needs the internet — try again when connected.' : error instanceof ApiError ? error.firstMessage : 'Could not send the selfie.' });
          return;
        }
        setSession(updated);
        await store.setJson('sws.today', updated);
        setMessage({ tone: 'success', text: `${label[action]} recorded with your selfie.` });
        return;
      }
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
  }, [session, me?.policies?.location_required, me?.policies?.selfie_required]);

  return { session, loading, busy, message, load, act };
}
