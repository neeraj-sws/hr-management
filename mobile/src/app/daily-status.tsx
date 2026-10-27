import AsyncStorage from '@react-native-async-storage/async-storage';
import { MaterialCommunityIcons } from '@expo/vector-icons';
import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { Button, Card, colors, Empty, Field, formatDate, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import { enqueue } from '../lib/queue';
import { todayYmd } from '../components/DateField';

type DayInfo = { check_in: string | null; check_out: string | null; worked_seconds: number; break_seconds: number; task_count: number; task_seconds: number; tasks?: { id: number; title: string; status: string; seconds: number }[] };
type Status = { id: number; work_date: string; yesterday: string | null; plan: string | null; summary: string; blockers: string | null; notes: string | null; submitted_at: string | null; late?: boolean; day?: DayInfo };
type MonthSummary = { days_at_work: number; submitted: number; missed: string[]; late: number; blockers: number };
type TeamRow = { id: number; name: string; checked_in: boolean; submitted: boolean; blocker: string | null };

const navy = '#13254F';
const green = { bg: '#E1F5EE', fg: '#085041', line: '#9FE1CB' };
const amber = { bg: '#FAEEDA', fg: '#854F0B' };
const red = { bg: '#FCEBEB', fg: '#A32D2D', line: '#F3C3C3' };
const blue = { bg: '#E6F1FB', fg: '#185FA5' };
const DRAFT = 'sws.dailyStatusDraft';
const hm = (s: number) => `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m`;
const timeOf = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '');
const weekday = (ymd: string) => new Date(`${ymd}T00:00:00`).toLocaleDateString([], { weekday: 'short' });
const dayNum = (ymd: string) => Number(ymd.slice(8, 10));

/**
 * Daily status: today's card (written or not, timing, tasks), the month in four numbers, the history as
 * day cards (missed days in red) and, for a manager, who in the team has written today's status.
 */
export default function DailyStatusScreen() {
  const [history, setHistory] = useState<Status[]>([]);
  const [todayStatus, setTodayStatus] = useState<Status | null>(null);
  const [todayInfo, setTodayInfo] = useState<DayInfo | null>(null);
  const [summary, setSummary] = useState<MonthSummary | null>(null);
  const [team, setTeam] = useState<TeamRow[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [plan, setPlan] = useState('');
  const [completed, setCompleted] = useState('');
  const [blockers, setBlockers] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const [onlyBlockers, setOnlyBlockers] = useState(false);
  const [draftSaved, setDraftSaved] = useState(false);
  // The form opens in a sheet from the button; it closes itself after a successful submit.
  const [formOpen, setFormOpen] = useState(false);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  const [month, setMonth] = useMonth();
  const load = useCallback(async () => {
    try {
      const response = await get<{ data: Status[]; today: Status | null; today_info?: DayInfo; month_summary?: MonthSummary; team?: TeamRow[] }>(`/daily-status?month=${month}`);
      setHistory(response.data);
      setTodayStatus(response.today);
      setTodayInfo(response.today_info ?? null);
      setSummary(response.month_summary ?? null);
      setTeam(response.team ?? []);
      if (response.today) {
        setPlan(response.today.plan ?? '');
        setCompleted(response.today.summary ?? '');
        setBlockers(response.today.blockers ?? '');
        setNotes(response.today.notes ?? '');
      } else {
        // A draft typed earlier today (the app was closed before saving) comes back.
        const raw = await AsyncStorage.getItem(DRAFT).catch(() => null);
        const draft = raw ? JSON.parse(raw) : null;
        if (draft && draft.day === todayYmd()) { setPlan(draft.plan ?? ''); setCompleted(draft.summary ?? ''); setBlockers(draft.blockers ?? ''); setNotes(draft.notes ?? ''); }
      }
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: e.isNetwork ? 'info' : 'error', text: e.isNetwork ? 'Offline — you can still write today’s status; it is sent when you are back online.' : e.firstMessage });
    } finally {
      setLoaded(true);
    }
  }, [month]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  // Keep what is being typed as a draft on this phone.
  useEffect(() => {
    if (!formOpen || todayStatus) return;
    const t = setTimeout(() => {
      AsyncStorage.setItem(DRAFT, JSON.stringify({ day: todayYmd(), plan, summary: completed, blockers, notes })).then(() => setDraftSaved(true)).catch(() => undefined);
    }, 600);
    return () => clearTimeout(t);
  }, [plan, completed, blockers, notes, formOpen, todayStatus]);

  const addTasks = () => {
    const tasks = todayInfo?.tasks ?? [];
    if (!tasks.length) return;
    const lines = tasks.map((t) => `• ${t.title} (${hm(t.seconds)})${t.status ? ` — ${t.status.replace('_', ' ')}` : ''}`).join('\n');
    setCompleted((c) => (c.trim() ? `${c.trim()}\n${lines}` : lines));
  };

  const save = async () => {
    if (completed.trim().length < 5) {
      setErrors({ summary: 'Write what you completed today.' });
      return;
    }
    setErrors({});
    setBusy(true);
    setMessage(null);
    try {
      const saved = (await post<{ data: Status }>('/daily-status', { plan: plan || null, summary: completed, blockers: blockers || null, notes: notes || null })).data;
      setTodayStatus(saved);
      setHistory((current) => [saved, ...current.filter((s) => s.work_date !== saved.work_date)]);
      await AsyncStorage.removeItem(DRAFT).catch(() => undefined);
      setMessage({ tone: 'success', text: blockers ? 'Saved. Your manager was told about the blocker.' : 'Daily status saved.' });
      load();
    } catch (e) {
      if (e instanceof ApiError && e.isNetwork) {
        const day = todayYmd();
        await enqueue({ type: 'daily_status.submit', payload: { plan: plan || null, summary: completed, blockers: blockers || null, notes: notes || null, work_date: day }, label: `Daily status ${formatDate(day)}` });
        setMessage({ tone: 'info', text: 'You are offline. Your status is saved on this phone and will be sent automatically.' });
      } else if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const remind = async (row: TeamRow) => {
    try {
      const r = await post<{ message: string }>(`/daily-status/remind/${row.id}`, {});
      setMessage({ tone: 'success', text: r.message });
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not send the reminder.' });
    }
  };

  // History: written days and missed days (no status) together, newest first.
  const missed = (summary?.missed ?? []).filter((d) => !history.some((s) => s.work_date === d));
  const sorted: ({ kind: 'status'; s: Status } | { kind: 'missed'; date: string })[] = [
    ...history.filter((s) => !onlyBlockers || s.blockers).map((s) => ({ kind: 'status' as const, s })),
    ...(onlyBlockers ? [] : missed.map((date) => ({ kind: 'missed' as const, date }))),
  ].sort((a, b) => (b.kind === 'status' ? b.s.work_date : b.date).localeCompare(a.kind === 'status' ? a.s.work_date : a.date));
  // Missed days next to each other (no status in between) are one card: "Missed · 12–16 Oct · 5 days".
  const cards: ({ kind: 'status'; s: Status } | { kind: 'missed'; date: string; dates: string[] })[] = [];
  for (const c of sorted) {
    const last = cards[cards.length - 1];
    if (c.kind === 'missed' && last && last.kind === 'missed') last.dates.push(c.date);
    else cards.push(c.kind === 'missed' ? { ...c, dates: [c.date] } : c);
  }
  const shortDate = (ymd: string) => new Date(`${ymd}T00:00:00`).toLocaleDateString([], { day: 'numeric', month: 'short' });

  const written = !!todayStatus;
  return (
    <Screen>
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}

      {/* Today */}
      <View style={[styles.today, { borderColor: written ? green.line : red.line }]}>
        <View style={styles.rowBetween}>
          <Text style={styles.todayTitle}>Today · {new Date().toLocaleDateString([], { weekday: 'short', day: '2-digit', month: 'short' })}</Text>
          <Pill text={written ? `Submitted ${timeOf(todayStatus?.submitted_at ?? null)}` : 'Not submitted'} color={written ? green : red} icon={written ? 'check' : 'alert-circle-outline'} />
        </View>
        {todayInfo?.check_in ? (
          <Text style={styles.meta}><MaterialCommunityIcons name="clock-outline" size={13} /> In {todayInfo.check_in} · worked {hm(todayInfo.worked_seconds)} · break {hm(todayInfo.break_seconds)}</Text>
        ) : <Text style={styles.meta}>Not checked in yet</Text>}
        <Text style={styles.meta}><MaterialCommunityIcons name="format-list-checks" size={13} /> {todayInfo?.task_count ?? 0} tasks · {hm(todayInfo?.task_seconds ?? 0)} on timer</Text>
        {!written ? <Text style={[styles.meta, { color: amber.fg }]}>Write it before you leave · a reminder comes at {data?.reminder_at ?? '6:00 PM'}</Text> : null}
        <View style={{ marginTop: 10 }}><Button title={written ? "Edit today's status" : "Write today's status"} tone={written ? 'default' : 'primary'} onPress={() => setFormOpen(true)} /></View>
      </View>

      {/* Month in numbers */}
      {summary ? (
        <View style={styles.tiles}>
          <Tile label="submitted" value={`${summary.submitted}/${Math.max(summary.days_at_work, summary.submitted)}`} />
          <Tile label="missed" value={String(summary.missed.length)} color={summary.missed.length ? red : undefined} />
          <Tile label="late" value={String(summary.late)} color={summary.late ? amber : undefined} />
          <Tile label="blockers" value={String(summary.blockers)} />
        </View>
      ) : null}

      {/* Manager: my team today */}
      {team.length ? (
        <Card>
          <Text style={styles.sectionTitle}>My team today</Text>
          {team.map((r) => (
            <View key={r.id} style={styles.teamRow}>
              <MaterialCommunityIcons name={r.submitted ? 'check-circle' : 'close-circle-outline'} size={18} color={r.submitted ? green.fg : red.fg} />
              <View style={{ flex: 1 }}>
                <Text style={styles.teamName}>{r.name}</Text>
                <Text style={[styles.meta, r.blocker ? { color: red.fg } : null]} numberOfLines={1}>{r.blocker ? `Blocker: ${r.blocker}` : r.submitted ? 'Submitted' : r.checked_in ? 'Not submitted yet' : 'Not checked in'}</Text>
              </View>
              {!r.submitted && r.checked_in ? <Pressable onPress={() => remind(r)} style={styles.remind} accessibilityRole="button"><Text style={styles.remindText}>Remind</Text></Pressable> : null}
            </View>
          ))}
        </Card>
      ) : null}

      {/* History */}
      <View style={styles.rowBetween}>
        <View style={{ flex: 1 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        <Pressable onPress={() => setOnlyBlockers((v) => !v)} style={[styles.chip, onlyBlockers && styles.chipOn]} accessibilityRole="button">
          <Text style={[styles.chipText, onlyBlockers && { color: red.fg }]}>Only blockers</Text>
        </Pressable>
      </View>
      {cards.length ? cards.map((c) => c.kind === 'missed' ? (
        <View key={`m${c.date}`} style={[styles.dayCard, { backgroundColor: red.bg, borderColor: red.line }]}>
          <DateBadge ymd={c.date} color={red} />
          <View style={{ flex: 1, justifyContent: 'center' }}>
            <Text style={[styles.dayLine, { color: red.fg }]}>{c.dates.length > 1 ? `Missed · ${c.dates.length} days` : 'Missed — no status written'}</Text>
            {c.dates.length > 1 ? <Text style={[styles.meta, { color: red.fg }]}>{shortDate(c.dates[c.dates.length - 1])} – {shortDate(c.dates[0])} · no status written</Text> : null}
          </View>
        </View>
      ) : (
        <Pressable key={c.s.id} onPress={() => setOpen(open === c.s.id ? null : c.s.id)} style={styles.dayCard} accessibilityRole="button">
          <DateBadge ymd={c.s.work_date} color={blue} />
          <View style={{ flex: 1, minWidth: 0 }}>
            <View style={styles.rowBetween}>
              <Text style={styles.dayLine}>{c.s.day ? `${hm(c.s.day.worked_seconds)} · ${c.s.day.task_count} tasks` : formatDate(c.s.work_date)}</Text>
              <Pill text={c.s.late ? 'Late' : 'On time'} color={c.s.late ? amber : green} />
            </View>
            {c.s.plan ? <Text style={styles.meta} numberOfLines={open === c.s.id ? undefined : 1}>Plan: {c.s.plan}</Text> : null}
            {open === c.s.id ? <Text style={styles.body}>Completed: {c.s.summary}</Text> : null}
            {c.s.blockers ? <Text style={[styles.meta, { color: red.fg }]} numberOfLines={open === c.s.id ? undefined : 1}><MaterialCommunityIcons name="alert-outline" size={13} /> Blocker: {c.s.blockers}</Text> : null}
            {open === c.s.id && c.s.notes ? <Text style={styles.meta}>Notes: {c.s.notes}</Text> : null}
            {open === c.s.id && c.s.day?.check_in ? <Text style={styles.meta}>In {c.s.day.check_in}{c.s.day.check_out ? ` · out ${c.s.day.check_out}` : ''} · break {hm(c.s.day.break_seconds)}</Text> : null}
          </View>
          <MaterialCommunityIcons name={open === c.s.id ? 'chevron-up' : 'chevron-down'} size={18} color={colors.faint} />
        </Pressable>
      )) : <Empty text={loaded ? (onlyBlockers ? 'No blockers this month' : 'No updates this month') : 'Loading…'} />}

      <FormSheet visible={formOpen} title="Today's status" onClose={closeForm}>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        {todayInfo?.tasks?.length ? (
          <View style={styles.tasksBox}>
            <View style={styles.rowBetween}>
              <Text style={styles.sectionTitle}><MaterialCommunityIcons name="format-list-checks" size={15} /> Today's tasks</Text>
              <Text style={styles.meta}>{hm(todayInfo.task_seconds)}</Text>
            </View>
            {todayInfo.tasks.map((t) => (
              <View key={t.id} style={[styles.rowBetween, { marginTop: 5 }]}>
                <Text style={[styles.body, { flex: 1 }]} numberOfLines={1}>{t.title}</Text>
                <Pill text={`${t.status === 'done' || t.status === 'completed' ? 'Done' : 'In progress'} · ${hm(t.seconds)}`} color={t.status === 'done' || t.status === 'completed' ? green : blue} />
              </View>
            ))}
            <View style={{ marginTop: 8 }}><Button small title="Add my tasks to Completed" onPress={addTasks} /></View>
          </View>
        ) : null}
        <Field label="🎯 Plan for today" value={plan} onChangeText={setPlan} multiline placeholder="What you intend to do" />
        <Field label="✅ Completed" value={completed} onChangeText={setCompleted} multiline placeholder="What you finished or moved forward" error={errors.summary} />
        <Field label="⚠️ Blockers · your manager is told" value={blockers} onChangeText={setBlockers} multiline placeholder="Anything stopping you" style={blockers ? styles.blockerInput : undefined} />
        <Field label="📝 Notes" value={notes} onChangeText={setNotes} multiline placeholder="Optional" />
        {draftSaved && !written ? <Text style={[styles.meta, { textAlign: 'center', marginBottom: 8 }]}>Draft saved on this phone</Text> : null}
        <Button title="Save status" tone="primary" onPress={save} loading={busy} />
      </FormSheet>
    </Screen>
  );
}

function Pill({ text, color, icon }: { text: string; color: { bg: string; fg: string }; icon?: React.ComponentProps<typeof MaterialCommunityIcons>['name'] }) {
  return (
    <View style={[styles.pill, { backgroundColor: color.bg }]}>
      {icon ? <MaterialCommunityIcons name={icon} size={12} color={color.fg} /> : null}
      <Text style={[styles.pillText, { color: color.fg }]}>{text}</Text>
    </View>
  );
}

function Tile({ label, value, color }: { label: string; value: string; color?: { bg: string; fg: string } }) {
  return (
    <View style={[styles.tile, color ? { backgroundColor: color.bg } : null]}>
      <Text style={[styles.tileValue, color ? { color: color.fg } : null]}>{value}</Text>
      <Text style={[styles.tileLabel, color ? { color: color.fg } : null]}>{label}</Text>
    </View>
  );
}

function DateBadge({ ymd, color }: { ymd: string; color: { bg: string; fg: string } }) {
  return (
    <View style={[styles.badge, { backgroundColor: color.bg }]}>
      <Text style={[styles.badgeNum, { color: color.fg }]}>{dayNum(ymd)}</Text>
      <Text style={[styles.badgeDay, { color: color.fg }]}>{weekday(ymd)}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  today: { backgroundColor: colors.white, borderWidth: 1.5, borderRadius: 14, padding: 14, marginBottom: 12 },
  todayTitle: { color: navy, fontWeight: '800', fontSize: 15, flexShrink: 1 },
  meta: { color: colors.muted, fontSize: 12.5, marginTop: 4 },
  body: { color: colors.ink2, fontSize: 13, marginTop: 4, lineHeight: 19 },
  pill: { flexDirection: 'row', alignItems: 'center', gap: 4, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 },
  pillText: { fontSize: 11.5, fontWeight: '700' },
  tiles: { flexDirection: 'row', gap: 8, marginBottom: 12 },
  tile: { flex: 1, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 10, paddingVertical: 8, alignItems: 'center' },
  tileValue: { color: navy, fontWeight: '800', fontSize: 16 },
  tileLabel: { color: colors.muted, fontSize: 11.5 },
  sectionTitle: { color: navy, fontWeight: '800', fontSize: 14, marginBottom: 4 },
  teamRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.line },
  teamName: { color: navy, fontWeight: '700', fontSize: 13.5 },
  remind: { borderWidth: 1, borderColor: '#D5E2F5', backgroundColor: '#F2F7FE', borderRadius: 8, paddingHorizontal: 10, paddingVertical: 5 },
  remindText: { color: '#2B5BD7', fontWeight: '700', fontSize: 12.5 },
  chip: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 6 },
  chipOn: { backgroundColor: red.bg, borderColor: red.line },
  chipText: { color: colors.muted, fontSize: 12.5, fontWeight: '700' },
  dayCard: { flexDirection: 'row', gap: 10, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 12, padding: 10, marginTop: 8 },
  dayLine: { color: navy, fontWeight: '700', fontSize: 13.5, flexShrink: 1 },
  badge: { width: 42, borderRadius: 9, alignItems: 'center', paddingVertical: 5 },
  badgeNum: { fontWeight: '800', fontSize: 16 },
  badgeDay: { fontSize: 11 },
  tasksBox: { backgroundColor: colors.canvas, borderRadius: 12, padding: 10, marginBottom: 12 },
  blockerInput: { borderColor: red.line, backgroundColor: '#FFF7F7' },
});
