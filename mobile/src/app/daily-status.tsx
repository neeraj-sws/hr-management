import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
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

  const load = useCallback(async () => {
    try {
      const response = await get<{ data: Status[]; today: Status | null }>('/daily-status');
      setHistory(response.data);
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
  }, []);
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
      <Card>
        <H2>Today</H2>
        {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
        <Field label="Yesterday" value={yesterday} onChangeText={setYesterday} multiline placeholder="What you did yesterday" />
        <Field label="Plan for today" value={plan} onChangeText={setPlan} multiline placeholder="What you intend to do" />
        <Field label="Completed" value={summary} onChangeText={setSummary} multiline placeholder="What you finished or moved forward" error={errors.summary} />
        <Field label="Blockers" value={blockers} onChangeText={setBlockers} multiline placeholder="Anything stopping you (your manager is notified)" />
        <Field label="Notes" value={notes} onChangeText={setNotes} multiline />
        <Button title="Save status" tone="primary" onPress={save} loading={busy} />
      </Card>
      <Card>
        <H2>History</H2>
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
