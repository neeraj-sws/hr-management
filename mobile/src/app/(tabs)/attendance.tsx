import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { AttendanceCard } from '../../components/AttendanceCard';
import { Badge, Button, Card, Empty, formatDate, formatDuration, formatTime, H2, Muted, Notice, Row, Screen } from '../../components/ui';
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

  const load = useCallback(async () => {
    const to = new Date();
    const from = new Date(to.getTime() - 29 * 86400000);
    const fmt = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    try {
      const response = await get<{ data: Day[] }>(`/attendance/history?from=${fmt(from)}&to=${fmt(to)}`);
      setDays(response.data.reverse());
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load history.');
    }
  }, []);

  useEffect(() => { load(); }, [load, attendance.session?.status]);

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
        <H2>Last 30 days</H2>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 8 }}>
          {['present', 'late', 'half_day', 'on_leave', 'absent'].map((s) => <Badge key={s} label={`${s} ${counts[s] ?? 0}`} tone={({ present: 'green', late: 'amber', half_day: 'amber', on_leave: 'violet', absent: 'red' } as const)[s as 'present']} />)}
        </View>
        {error ? <Notice tone="error" text={error} /> : null}
        {days.length ? days.filter((d) => d.status !== 'upcoming').map((d) => (
          <Row key={d.date} title={formatDate(d.date)} subtitle={d.session ? `${formatTime(d.session.check_in)} – ${formatTime(d.session.check_out)} · ${formatDuration(d.session.active_seconds)} worked` : undefined} right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={d.status} />{['absent', 'late', 'half_day'].includes(d.status) || (d.session && !d.session.check_out && d.date !== attendance.session?.work_date) ? <Button small title="Correct" onPress={() => router.push({ pathname: '/corrections', params: { date: d.date } })} /> : null}</View>} />
        )) : <Empty text="No attendance history" />}
      </Card>
    </Screen>
  );
}
