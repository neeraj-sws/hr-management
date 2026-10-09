import { useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';
import { DateField, TimeField, todayYmd } from '../components/DateField';
import { Badge, Button, Card, confirmAction, Empty, Field, formatDate, formatTime, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Correction, Paginated } from '../lib/types';

export default function CorrectionsScreen() {
  const params = useLocalSearchParams<{ date?: string }>();
  const [items, setItems] = useState<Correction[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [workDate, setWorkDate] = useState(params.date && /^\d{4}-\d{2}-\d{2}$/.test(params.date) ? params.date : todayYmd());
  const [checkIn, setCheckIn] = useState('');
  const [checkOut, setCheckOut] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setItems((await get<Paginated<Correction>>('/attendance/corrections')).data);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!checkIn && !checkOut) local.check_in = 'Set the correct check-in time, check-out time, or both.';
    if (reason.trim().length < 5) local.reason = 'Explain briefly what happened.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    try {
      const created = await post<{ data: Correction }>('/attendance/corrections', { work_date: workDate, check_in: checkIn || null, check_out: checkOut || null, reason });
      setItems((current) => [created.data, ...current]);
      setMessage({ tone: 'success', text: 'Sent to your manager for approval.' });
      setCheckIn(''); setCheckOut(''); setReason('');
    } catch (e) {
      if (e instanceof ApiError) {
        const mapped = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [({ workDate: 'work_date', checkIn: 'check_in', checkOut: 'check_out' } as Record<string, string>)[k] ?? k, v[0]]));
        setErrors(mapped);
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (id: number) => {
    if (!(await confirmAction('Cancel request?', 'The correction request will be withdrawn.', 'Cancel request'))) return;
    try {
      await post(`/attendance/corrections/${id}/cancel`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  return (
    <Screen>
      <Card>
        <H2>Request a correction</H2>
        <Muted style={{ marginBottom: 10 }}>Forgot to check out, or checked in at the wrong time? Set the correct times — your manager approves, then your attendance is updated.</Muted>
        {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
        <DateField label="Day" value={workDate} onChange={setWorkDate} maximumDate={todayYmd()} error={errors.work_date} />
        <TimeField label="Correct check-in" value={checkIn} onChange={setCheckIn} error={errors.check_in} />
        <TimeField label="Correct check-out" value={checkOut} onChange={setCheckOut} error={errors.check_out} />
        <Field label="Reason" value={reason} onChangeText={setReason} placeholder="e.g. Forgot to check out at 6:30 PM" error={errors.reason} multiline />
        <Button title="Send for approval" tone="primary" onPress={submit} loading={busy} />
      </Card>
      <Card>
        <H2>My requests</H2>
        {items.length ? items.map((c) => (
          <Row key={c.id} title={formatDate(c.work_date)}
            subtitle={`${c.check_in ? `in ${formatTime(c.check_in)}` : ''}${c.check_in && c.check_out ? ' · ' : ''}${c.check_out ? `out ${formatTime(c.check_out)}` : ''} — ${c.reason}${c.decision_note ? ` · ${c.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={c.status} />{c.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(c.id)} /> : null}</View>} />
        )) : <Empty text={loaded ? 'No correction requests' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
