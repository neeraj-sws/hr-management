import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { AttendanceCard } from '../../components/AttendanceCard';
import { DayFilter, monthBounds, MonthSwitcher, useMonth } from '../../components/MonthSwitcher';
import { todayYmd } from '../../components/DateField';
import { Badge, Button, Card, Chips, Empty, formatDate, formatDuration, formatTime, Muted, Notice, Row, Screen } from '../../components/ui';
import { ApiError, get } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import type { AttendanceSession } from '../../lib/types';
import { useAttendance } from '../../lib/useAttendance';

type Day = { date: string; status: string; session: AttendanceSession | null };

export default function Attendance() {
  const attendance = useAttendance();
  const router = useRouter();
  const { me } = useAuth();
  const [days, setDays] = useState<Day[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  // One month at a time (this month first) instead of a long rolling list.
  const [month, setMonth] = useMonth();
  // Narrow the month down to one kind of day.
  const [dayFilter, setDayFilter] = useState<'all' | 'present' | 'late' | 'leave' | 'absent' | 'off'>('all');
  // One exact day (e.g. 5 Mar 2026): opens that month and shows only that day.
  const [day, setDay] = useState('');
  const pickDay = (value: string) => {
    setDay(value);
    if (value && value.slice(0, 7) !== month) { setDays([]); setMonth(value.slice(0, 7)); }
  };

  const load = useCallback(async () => {
    const { from, to } = monthBounds(month);
    try {
      const response = await get<{ data: Day[] }>(`/attendance/history?from=${from}&to=${to}`);
      setDays(response.data.reverse());
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load history.');
    }
  }, [month]);

  useEffect(() => { load(); }, [load, attendance.session?.status]);

  const kinds: Record<typeof dayFilter, string[]> = { all: [], present: ['present', 'extra_work'], late: ['late', 'half_day'], leave: ['on_leave', 'leave_conflict'], absent: ['absent'], off: ['weekend', 'holiday'] };
  const shownDays = days.filter((d) => d.status !== 'upcoming' && (day ? d.date === day : dayFilter === 'all' || kinds[dayFilter].includes(d.status)));
  const counts = days.reduce<Record<string, number>>((acc, d) => ({ ...acc, [d.status]: (acc[d.status] ?? 0) + 1 }), {});
  const refresh = async () => {
    setRefreshing(true);
    await Promise.all([load(), attendance.load()]);
    setRefreshing(false);
  };

  return (
    <Screen refreshing={refreshing} onRefresh={refresh}>
      <AttendanceCard attendance={attendance} />
      {me?.schedule?.starts_at ? <Muted>{me.schedule.shift ? `${me.schedule.shift} shift` : 'Your start time'}: {me.schedule.starts_at}{me.schedule.grace_minutes ? ` (+${me.schedule.grace_minutes} min grace)` : ''}{me.policies?.location_required ? ' · check-in needs your location at an office' : ''}</Muted> : null}
      <Card>
        <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={(m) => { setDays([]); setDay(''); setMonth(m); }} /></View>
        <DayFilter value={day} onChange={pickDay} max={todayYmd()} />
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 8 }}>
          {['present', 'late', 'half_day', 'on_leave', 'absent'].map((s) => <Badge key={s} label={`${s} ${counts[s] ?? 0}`} tone={({ present: 'green', late: 'amber', half_day: 'amber', on_leave: 'violet', absent: 'red' } as const)[s as 'present']} />)}
        </View>
        {error ? <Notice tone="error" text={error} /> : null}
        {day ? null : <Chips value={dayFilter} onChange={setDayFilter} options={[{ label: 'All', value: 'all' }, { label: 'Present', value: 'present' }, { label: 'Late', value: 'late' }, { label: 'Leave', value: 'leave' }, { label: 'Absent', value: 'absent' }, { label: 'Off days', value: 'off' }]} />}
        {shownDays.length ? shownDays.map((d) => (
          <Row key={d.date} title={formatDate(d.date)} subtitle={d.session ? `${formatTime(d.session.check_in)} – ${formatTime(d.session.check_out)} · ${formatDuration(d.session.worked_seconds ?? d.session.active_seconds)} worked${d.session.idle_counted_seconds ? ` · ${formatDuration(d.session.idle_counted_seconds)} idle` : ''}` : undefined} right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={d.status} />{['absent', 'late', 'half_day'].includes(d.status) || (d.session && !d.session.check_out && d.date !== attendance.session?.work_date) ? <Button small title="Correct" onPress={() => router.push({ pathname: '/corrections', params: { date: d.date } })} /> : null}</View>} />
        )) : <Empty text={day ? `No attendance on ${formatDate(day)}` : dayFilter === 'all' ? 'No attendance this month' : 'No days match this filter'} />}
      </Card>
    </Screen>
  );
}
