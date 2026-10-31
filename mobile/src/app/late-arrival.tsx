import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';
import { AddButton, FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { DateField, TimeField, todayYmd } from '../components/DateField';
import { Badge, Button, Card, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { LateArrival } from '../lib/types';

/** Late arrival: tell in advance (expected check-in + valid reason). Approved and in by that time: no late fine / cut. */
export default function LateArrivalScreen() {
  const [items, setItems] = useState<LateArrival[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [workDate, setWorkDate] = useState(todayYmd());
  const [expectedAt, setExpectedAt] = useState('11:00');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  const [month, setMonth] = useMonth();
  const load = useCallback(async () => {
    try {
      setItems((await get<{ data: LateArrival[] }>(`/late-arrival?month=${month}`)).data);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, [month]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!expectedAt) local.expected_at = 'Set your expected check-in time.';
    if (reason.trim().length < 10) local.reason = 'Give a valid reason (at least 10 characters).';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    try {
      const created = await post<{ data: LateArrival }>('/late-arrival', { work_date: workDate, expected_at: expectedAt, reason: reason.trim() });
      setItems((current) => [created.data, ...current]);
      setMessage(created.data.status === 'rejected'
        ? { tone: 'error', text: created.data.decision_note || 'Rejected automatically.' }
        : { tone: 'success', text: 'Sent to your manager. It is not approved yet - you will be told.' });
      setReason('');
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (id: number) => {
    if (!(await confirmAction('Cancel request?', 'The late arrival request will be withdrawn.', 'Cancel request'))) return;
    try {
      await post(`/late-arrival/${id}/cancel`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  return (
    <Screen>
      <AddButton title="I will come late" onPress={() => setFormOpen(true)} />
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={formOpen} title="I will come late" onClose={closeForm}>
        <Muted style={{ marginBottom: 10 }}>Tell in advance - before your day starts - with your expected check-in time and a valid reason. Management decides; sending it does not mean approval. If you check in later than the time you give, the normal late rules apply.</Muted>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        <DateField label="Date" value={workDate} onChange={setWorkDate} minimumDate={todayYmd()} error={errors.work_date} />
        <TimeField label="Expected check-in" value={expectedAt} onChange={setExpectedAt} error={errors.expected_at} placeholder="e.g. 11:30 AM" />
        <Field label="Reason" value={reason} onChangeText={setReason} placeholder="e.g. Bank visit for the company account" error={errors.reason} multiline />
        <Button title="Send" tone="primary" onPress={submit} loading={busy} />
      </FormSheet>
      <Card>
        <H2>My late arrivals</H2>
        <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        {items.length ? items.map((r) => (
          <Row key={r.id} title={`${formatDate(r.work_date)} · expected ${r.expected_at}`}
            subtitle={`${r.reason}${r.decision_note ? ` · ${r.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={r.status} />{r.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(r.id)} /> : null}</View>} />
        )) : <Empty text={loaded ? 'No late arrival requests' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
