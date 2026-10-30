import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { AddButton, FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { DateField, TimeField, todayYmd } from '../components/DateField';
import { Badge, Button, Card, colors, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { NightWork } from '../lib/types';

type TaskOption = { id: number; title: string; project: string | null };
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const hm = (minutes: number) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;

/** Night work: ask for work done at night with the tasks and what was done; approved time is paid with the salary. */
export default function NightWorkScreen() {
  const [items, setItems] = useState<NightWork[]>([]);
  const [tasks, setTasks] = useState<TaskOption[]>([]);
  const [daysBack, setDaysBack] = useState(7);
  const [loaded, setLoaded] = useState(false);
  const [workDate, setWorkDate] = useState(() => ymd(new Date(Date.now() - 86400000)));
  const [startsAt, setStartsAt] = useState('22:00');
  const [endsAt, setEndsAt] = useState('');
  const [taskIds, setTaskIds] = useState<number[]>([]);
  const [workDone, setWorkDone] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  const [month, setMonth] = useMonth();
  const load = useCallback(async () => {
    try {
      const res = await get<{ data: NightWork[]; tasks: TaskOption[]; days_back: number }>(`/night-work?month=${month}`);
      setItems(res.data);
      setTasks(res.tasks);
      setDaysBack(res.days_back);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, [month]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const toggleTask = (id: number) => setTaskIds((current) => (current.includes(id) ? current.filter((t) => t !== id) : [...current, id]));

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!endsAt) local.ends_at = 'Set the time you stopped.';
    if (workDone.trim().length < 10) local.work_done = 'Write what you did (at least 10 characters).';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    try {
      const created = await post<{ data: NightWork }>('/night-work', { work_date: workDate, starts_at: startsAt, ends_at: endsAt, task_ids: taskIds, work_done: workDone.trim() });
      setItems((current) => [created.data, ...current]);
      setMessage({ tone: 'success', text: 'Sent to your manager for approval. Approved time is paid with your salary.' });
      setEndsAt(''); setTaskIds([]); setWorkDone('');
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
    if (!(await confirmAction('Cancel request?', 'The night work request will be withdrawn.', 'Cancel request'))) return;
    try {
      await post(`/night-work/${id}/cancel`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  return (
    <Screen>
      <AddButton title="Add night work" onPress={() => setFormOpen(true)} />
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={formOpen} title="Add night work" onClose={closeForm}>
        <Muted style={{ marginBottom: 10 }}>Worked at night after the office day? Add the time, the tasks and what you did. Your manager approves it, then it is paid with your salary.</Muted>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        <DateField label="Date" value={workDate} onChange={setWorkDate} maximumDate={todayYmd()} minimumDate={ymd(new Date(Date.now() - daysBack * 86400000))} error={errors.work_date} />
        <TimeField label="From" value={startsAt} onChange={setStartsAt} error={errors.starts_at} placeholder="e.g. 10:00 PM" />
        <TimeField label="To (after midnight is fine)" value={endsAt} onChange={setEndsAt} error={errors.ends_at} placeholder="e.g. 01:30 AM" />
        <Muted style={{ marginTop: 6, marginBottom: 6 }}>Tasks you worked on</Muted>
        {tasks.length ? (
          <View style={{ gap: 6, marginBottom: 10 }}>
            {tasks.map((t) => {
              const on = taskIds.includes(t.id);
              return (
                <Pressable key={t.id} onPress={() => toggleTask(t.id)} style={{ flexDirection: 'row', alignItems: 'center', gap: 8, padding: 10, borderRadius: 10, borderWidth: 1, borderColor: on ? colors.blue : colors.line, backgroundColor: on ? colors.blueSoft : colors.white }}>
                  <Text style={{ fontWeight: '700', color: on ? colors.blue : colors.muted }}>{on ? '☑' : '☐'}</Text>
                  <Text style={{ flex: 1, color: colors.ink }}>{t.title}{t.project ? ` · ${t.project}` : ''}</Text>
                </Pressable>
              );
            })}
          </View>
        ) : <Muted style={{ marginBottom: 10 }}>No open tasks - write what you did below.</Muted>}
        {errors.task_ids ? <Notice tone="error" text={errors.task_ids} /> : null}
        <Field label="What did you do?" value={workDone} onChangeText={setWorkDone} placeholder="e.g. Fixed the live search bug and uploaded the code" error={errors.work_done} multiline />
        <Button title="Send for approval" tone="primary" onPress={submit} loading={busy} />
      </FormSheet>
      <Card>
        <H2>My night work</H2>
        <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        {items.length ? items.map((n) => (
          <Row key={n.id} title={`${formatDate(n.work_date)} · ${n.starts_at}–${n.ends_at} (${hm(n.minutes)})`}
            subtitle={`${n.tasks.map((t) => t.title).join(', ')}${n.tasks.length ? ' — ' : ''}${n.work_done}${n.decision_note ? ` · ${n.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={n.status} />{n.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(n.id)} /> : null}</View>} />
        )) : <Empty text={loaded ? 'No night work requests' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
