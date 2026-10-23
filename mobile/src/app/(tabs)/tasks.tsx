import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { AddButton, FormSheet } from '../../components/FormSheet';
import { DayFilter, MonthSwitcher, useMonth } from '../../components/MonthSwitcher';
import { DateField, todayYmd } from '../../components/DateField';
import { Badge, Button, Card, Chips, colors, confirmAction, Empty, Field, formatDate, formatDuration, Muted, Notice, Screen } from '../../components/ui';
import { ApiError, get, post } from '../../lib/api';
import { runOrQueue } from '../../lib/queue';
import type { Paginated, Task } from '../../lib/types';

function RunningClock({ since, base }: { since: string; base: number }) {
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  const running = Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000));
  const total = base + running;
  return <Text style={{ color: colors.green, fontWeight: '800', fontVariant: ['tabular-nums'] }}>● {String(Math.floor(total / 3600)).padStart(2, '0')}:{String(Math.floor((total % 3600) / 60)).padStart(2, '0')}:{String(total % 60).padStart(2, '0')}</Text>;
}

export default function Tasks() {
  const [filter, setFilter] = useState<'open' | 'completed'>('open');
  // Completed tasks are history: one month at a time (open tasks are always all shown).
  const [month, setMonth] = useMonth();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const router = useRouter();
  const [adding, setAdding] = useState(false);
  const [title, setTitle] = useState('');
  const [priority, setPriority] = useState('normal');
  const [workDate, setWorkDate] = useState(todayYmd());
  const [dueOn, setDueOn] = useState('');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  // Open tasks: narrow the list down.
  const [show, setShow] = useState<'all' | 'overdue' | 'today' | 'high'>('all');
  // "What did I do on 10 Feb 2026?": every task worked on, planned or completed that day.
  const [day, setDay] = useState('');

  const addTask = async () => {
    if (!title.trim()) { setFormError('Give the task a title.'); return; }
    setFormError(null);
    setSaving(true);
    try {
      await post('/tasks', { title, priority, work_date: workDate, due_on: dueOn || null });
      setTitle(''); setDueOn(''); setAdding(false);
      setMessage({ tone: 'success', text: 'Task added.' });
      await load();
    } catch (e) {
      setFormError(e instanceof ApiError ? e.firstMessage : 'Could not add the task.');
    } finally {
      setSaving(false);
    }
  };

  const load = useCallback(async () => {
    try {
      const response = await get<Paginated<Task>>(day ? `/tasks?date=${day}` : `/tasks?status=${filter}${filter === 'completed' ? `&month=${month}` : ''}`);
      setTasks(response.data);
    } catch (e) {
      if (e instanceof ApiError && e.isNetwork) setMessage({ tone: 'info', text: 'Offline — task actions will be queued.' });
      else if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, [filter, month, day]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const act = async (task: Task, action: 'start' | 'pause' | 'complete') => {
    setBusy(`${task.id}-${action}`);
    setMessage(null);
    try {
      const result = await runOrQueue(
        () => post(`/tasks/${task.id}/${action}`),
        { type: action === 'start' ? (task.logged_seconds ? 'task.resume' : 'task.start') : action === 'pause' ? 'task.pause' : 'task.complete', entity_id: task.id, base_version: task.server_version, payload: {}, label: `${action} · ${task.title}` },
      );
      setMessage(result === 'online'
        ? { tone: 'success', text: action === 'start' ? 'Timer started.' : action === 'pause' ? 'Timer paused.' : 'Task completed.' }
        : { tone: 'info', text: 'Saved offline — it will sync when you are back online.' });
      if (result === 'online') await load();
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Action failed.' });
    } finally {
      setBusy(null);
    }
  };

  const today = todayYmd();
  const shown = day || filter === 'completed' ? tasks : tasks.filter((t) => show === 'all'
    || (show === 'overdue' && !!t.overdue)
    || (show === 'today' && (t.due_on ?? '').slice(0, 10) === today)
    || (show === 'high' && ['high', 'urgent'].includes(t.priority)));

  const refresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  return (
    <Screen refreshing={refreshing} onRefresh={refresh}>
      <DayFilter value={day} onChange={(value) => { setTasks([]); setDay(value); }} max={todayYmd()} />
      {day ? <Muted style={{ marginBottom: 10 }}>{`Tasks on ${formatDate(day)}${tasks.length ? ` · ${formatDuration(tasks.reduce((sum, t) => sum + (t.day_seconds ?? 0), 0))} logged that day` : ''}`}</Muted> : (
        <>
          <Chips value={filter} onChange={setFilter} options={[{ label: 'Open', value: 'open' }, { label: 'Completed', value: 'completed' }]} />
          {filter === 'completed' ? <MonthSwitcher month={month} onChange={setMonth} /> : (
            <Chips value={show} onChange={setShow} options={[{ label: 'All', value: 'all' }, { label: 'Overdue', value: 'overdue' }, { label: 'Due today', value: 'today' }, { label: 'High priority', value: 'high' }]} />
          )}
        </>
      )}
      <AddButton title="Add my task" onPress={() => { setFormError(null); setAdding(true); }} />
      <FormSheet visible={adding} title="Add my task" onClose={() => setAdding(false)}>
        {formError ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={formError} /></View> : null}
        <Field label="What are you working on?" value={title} onChangeText={setTitle} placeholder="Task title" />
        <Muted style={{ marginBottom: 6 }}>Priority</Muted>
        <Chips value={priority} onChange={setPriority} options={['low', 'normal', 'high', 'urgent'].map((p) => ({ label: p, value: p }))} />
        <DateField label="For which day" value={workDate} onChange={setWorkDate} maximumDate={todayYmd()} />
        <DateField label="Due (optional)" value={dueOn} onChange={setDueOn} minimumDate={todayYmd()} />
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 4 }}>
          <View style={{ flex: 1 }}><Button title="Cancel" onPress={() => setAdding(false)} /></View>
          <View style={{ flex: 1 }}><Button title="Add task" tone="primary" onPress={addTask} loading={saving} /></View>
        </View>
      </FormSheet>
      {message ? <Notice tone={message.tone} text={message.text} /> : null}
      {shown.length === 0 ? <Card><Empty text={day ? `No tasks on ${formatDate(day)}` : filter === 'completed' ? 'No tasks completed this month' : show === 'all' ? 'No open tasks' : 'No tasks match this filter'} /></Card> : null}
      {shown.map((task) => (
        <Card key={task.id}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
            <Text style={{ flex: 1, fontWeight: '700', fontSize: 15, color: colors.ink }} onPress={() => router.push({ pathname: '/task', params: { id: String(task.id) } })}>{task.title} ›</Text>
            <Badge label={task.overdue ? 'overdue' : task.status} />
          </View>
          <Muted style={{ marginTop: 4 }}>{task.project?.name ?? 'No project'} · {task.priority} · {task.due_on ? `due ${formatDate(task.due_on)}` : 'no due date'}</Muted>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
            <Muted>{day ? `${formatDuration(task.day_seconds ?? 0)} that day · ` : ''}Est {task.estimated_hours ?? '—'}h · Logged {formatDuration(task.logged_seconds)}</Muted>
            {task.timer_running_since ? <RunningClock since={task.timer_running_since} base={0} /> : null}
          </View>
          {task.status !== 'completed' && (
            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <View style={{ flex: 1 }}>
                {task.timer_running_since
                  ? <Button small title="Pause" onPress={() => act(task, 'pause')} loading={busy === `${task.id}-pause`} />
                  : <Button small tone="primary" title={task.logged_seconds ? 'Resume' : 'Start'} onPress={() => act(task, 'start')} loading={busy === `${task.id}-start`} />}
              </View>
              <View style={{ flex: 1 }}><Button small tone="success" title="Complete" onPress={async () => { if (await confirmAction('Complete task?', `Mark "${task.title}" as completed?`, 'Complete')) act(task, 'complete'); }} loading={busy === `${task.id}-complete`} /></View>
            </View>
          )}
        </Card>
      ))}
    </Screen>
  );
}
