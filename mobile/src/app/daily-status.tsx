import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { AddButton, FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { Button, Card, colors, Empty, Field, formatDate, H2, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import { enqueue } from '../lib/queue';
import { todayYmd } from '../components/DateField';

type Status = { id: number; work_date: string; yesterday: string | null; plan: string | null; summary: string; blockers: string | null; notes: string | null; submitted_at: string | null };

export default function DailyStatusScreen() {
  const [history, setHistory] = useState<Status[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [yesterday, setYesterday] = useState('');
  const [plan, setPlan] = useState('');
  const [summary, setSummary] = useState('');
  const [blockers, setBlockers] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [hasToday, setHasToday] = useState(false);
  // The form opens in a popup from the button at the top; it closes itself after a successful submit.
  const [formOpen, setFormOpen] = useState(false);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  // History: one month at a time.
  const [month, setMonth] = useMonth();
  const load = useCallback(async () => {
    try {
      const response = await get<{ data: Status[]; today: Status | null }>(`/daily-status?month=${month}`);
      setHistory(response.data);
      setHasToday(!!response.today);
      if (response.today) {
        setYesterday(response.today.yesterday ?? '');
        setPlan(response.today.plan ?? '');
        setSummary(response.today.summary ?? '');
        setBlockers(response.today.blockers ?? '');
        setNotes(response.today.notes ?? '');
      }
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: e.isNetwork ? 'info' : 'error', text: e.isNetwork ? 'Offline — you can still write today’s status; it is sent when you are back online.' : e.firstMessage });
    } finally {
      setLoaded(true);
    }
  }, [month]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const save = async () => {
    if (summary.trim().length < 5) {
      setErrors({ summary: 'Write what you completed today.' });
      return;
    }
    setErrors({});
    setBusy(true);
    setMessage(null);
    try {
      const saved = (await post<{ data: Status }>('/daily-status', { yesterday: yesterday || null, plan: plan || null, summary, blockers: blockers || null, notes: notes || null })).data;
      setHistory((current) => [saved, ...current.filter((s) => s.work_date !== saved.work_date)]);
      setMessage({ tone: 'success', text: blockers ? 'Saved. Your manager was told about the blocker.' : 'Daily status saved.' });
    } catch (e) {
      if (e instanceof ApiError && e.isNetwork) {
        const day = todayYmd();
        await enqueue({ type: 'daily_status.submit', payload: { yesterday: yesterday || null, plan: plan || null, summary, blockers: blockers || null, notes: notes || null, work_date: day }, label: `Daily status ${formatDate(day)}` });
        setMessage({ tone: 'info', text: 'You are offline. Your status is saved on this phone and will be sent automatically.' });
      } else if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AddButton title={hasToday ? "Edit today's status" : "Submit today's status"} onPress={() => setFormOpen(true)} />
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={formOpen} title="Today's status" onClose={closeForm}>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        <Field label="Yesterday" value={yesterday} onChangeText={setYesterday} multiline placeholder="What you did yesterday" />
        <Field label="Plan for today" value={plan} onChangeText={setPlan} multiline placeholder="What you intend to do" />
        <Field label="Completed" value={summary} onChangeText={setSummary} multiline placeholder="What you finished or moved forward" error={errors.summary} />
        <Field label="Blockers" value={blockers} onChangeText={setBlockers} multiline placeholder="Anything stopping you (your manager is notified)" />
        <Field label="Notes" value={notes} onChangeText={setNotes} multiline />
        <Button title="Save status" tone="primary" onPress={save} loading={busy} />
      </FormSheet>
      <Card>
        <H2>History</H2>
        <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        {history.length ? history.map((s) => (
          <View key={s.id} style={{ paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#eef1f6' }}>
            <Text style={{ fontWeight: '700', color: colors.ink2 }}>{formatDate(s.work_date)}</Text>
            {s.yesterday ? <Muted>Yesterday: {s.yesterday}</Muted> : null}
            {s.plan ? <Muted>Plan: {s.plan}</Muted> : null}
            <Muted>Completed: {s.summary}</Muted>
            {s.blockers ? <Text style={{ color: colors.red, fontSize: 13 }}>Blockers: {s.blockers}</Text> : null}
          </View>
        )) : <Empty text={loaded ? 'No updates yet' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
