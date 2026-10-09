import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';
import { DateField, todayYmd } from '../components/DateField';
import { Badge, Button, Card, Chips, Empty, Field, formatDate, H2, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Overtime, Paginated } from '../lib/types';

export default function OvertimeScreen() {
  const [items, setItems] = useState<Overtime[]>([]);
  const [workDate, setWorkDate] = useState(todayYmd());
  const [minutes, setMinutes] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    try {
      setItems((await get<Paginated<Overtime>>('/overtime')).data);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const submit = async () => {
    setBusy(true);
    setMessage(null);
    setErrors({});
    try {
      const created = await post<{ data: Overtime }>('/overtime', { work_date: workDate, minutes: Number(minutes), reason });
      setItems((current) => [created.data, ...current.filter((o) => o.id !== created.data.id)]);
      setMessage({ tone: 'success', text: 'Overtime submitted for approval.' });
      setMinutes('');
      setReason('');
      load();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <Card>
        <H2>Request overtime</H2>
        {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
        <DateField label="Work date" value={workDate} onChange={setWorkDate} maximumDate={todayYmd()} error={errors.work_date} />
        <Field label="Extra time (minutes, 15–720)" value={minutes} onChangeText={setMinutes} keyboardType="number-pad" placeholder="e.g. 90" error={errors.minutes} />
        <Chips value={Number(minutes) || null} onChange={(m) => setMinutes(String(m))} options={[30, 60, 90, 120, 180].map((m) => ({ label: m < 60 ? `${m} min` : `${m / 60} h`, value: m }))} />
        <Field label="Reason" value={reason} onChangeText={setReason} error={errors.reason} multiline />
        <Button title="Submit" tone="primary" onPress={submit} loading={busy} />
      </Card>
      <Card>
        <H2>History</H2>
        {items.length ? items.map((o) => (
          <Row key={o.id} title={`${formatDate(o.work_date)} · ${o.requested_minutes} min`} subtitle={`${o.reason}${o.approved_minutes !== null ? ` · approved ${o.approved_minutes} min` : ''}`} right={<Badge label={o.status} />} />
        )) : <Empty text={loaded ? 'No overtime requests' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
