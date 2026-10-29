import { HomeHeader } from '../../components/HomeHeader';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { AttendanceCard } from '../../components/AttendanceCard';
import { Badge, Card, Empty, formatDate, H1, H2, Muted, Row, Screen, Stat } from '../../components/ui';
import { get } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { subscribe } from '../../lib/queue';
import type { Announcement, Celebration } from '../../lib/types';
import type { Approval, LeaveBalance, Paginated, Task } from '../../lib/types';
import { useAttendance } from '../../lib/useAttendance';

export default function Home() {
  const { me, refreshMe } = useAuth();
  const router = useRouter();
  const attendance = useAttendance();
  const [tasks, setTasks] = useState<Task[]>([]);
  const [balances, setBalances] = useState<LeaveBalance[]>([]);
  const [approvals, setApprovals] = useState<Approval[]>([]);
  const [queued, setQueued] = useState(0);
  const [refreshing, setRefreshing] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [celebrations, setCelebrations] = useState<Celebration[]>([]);

  const load = useCallback(async () => {
    const results = await Promise.allSettled([
      get<Paginated<Task>>('/tasks?status=open'),
      get<{ data: LeaveBalance[] }>('/leave/balances'),
      get<{ data: Approval[] }>('/approvals'),
      refreshMe(),
      get<{ data: Announcement[] }>('/announcements'),
      get<{ data: Celebration[] }>('/celebrations'),
    ]);
    if (results[4].status === 'fulfilled') setAnnouncements(results[4].value.data.slice(0, 2));
    if (results[5].status === 'fulfilled') setCelebrations(results[5].value.data.filter((c) => c.date <= new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10)).slice(0, 4));
    if (results[0].status === 'fulfilled') setTasks(results[0].value.data);
    if (results[1].status === 'fulfilled') setBalances(results[1].value.data.filter((b) => b.leave_type.paid));
    if (results[2].status === 'fulfilled') setApprovals(results[2].value.data);
    setLoaded(true);
  }, [refreshMe]);

  useFocusEffect(useCallback(() => { load(); }, [load]));
  useEffect(() => subscribe((items) => setQueued(items.filter((i) => i.status === 'queued').length)), []);

  const refresh = async () => {
    setRefreshing(true);
    await Promise.all([load(), attendance.load()]);
    setRefreshing(false);
  };

  return (
    <Screen refreshing={refreshing} onRefresh={refresh}>
      <HomeHeader attendance={attendance} />
      {queued > 0 && <Card><Row title={`${queued} action(s) waiting to sync`} subtitle="They will be sent automatically when you are online." right={<Badge label="queued" />} onPress={() => router.push('/sync')} /></Card>}
      {/* Daily status not written (last 2 days): straight to "Write the missed day" (reason first). */}
      {(me?.missed_status_days ?? []).map((d) => (
        <Card key={`missed-${d}`} style={{ backgroundColor: '#FCEBEB', borderColor: '#F3C3C3' }}>
          <Row title={`Daily status not written · ${formatDate(d)}`} subtitle="Give the reason and write it now — any fine for that day is then removed." right={<Badge label="write now" tone="red" />} onPress={() => router.push({ pathname: '/daily-status', params: { missed: d } })} />
        </Card>
      ))}
      {me?.employee ? <AttendanceCard attendance={attendance} /> : null}
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
        <Stat label="Open tasks" value={tasks.length} hint={`${tasks.filter((t) => t.overdue).length} overdue`} />
        <Stat label="Notifications" value={me?.unread_notifications ?? 0} hint="unread" />
        {approvals.length > 0 && <Stat label="To approve" value={approvals.length} hint="pending" />}
      </View>
      {approvals.length > 0 && (
        <Card>
          <H2>Waiting for your approval</H2>
          {approvals.slice(0, 3).map((a) => <Row key={a.id} title={a.label} subtitle={a.summary} onPress={() => router.push('/approvals')} />)}
        </Card>
      )}
      {announcements.length > 0 && (
        <Card>
          <H2>Announcements</H2>
          {announcements.map((a) => <Row key={a.id} title={`${a.pinned ? '📌 ' : ''}${a.title}`} subtitle={a.body.length > 90 ? `${a.body.slice(0, 90)}…` : a.body} onPress={() => router.push('/announcements')} />)}
        </Card>
      )}
      {celebrations.length > 0 && (
        <Card>
          <H2>Celebrations this week</H2>
          {celebrations.map((c) => <Row key={`${c.type}-${c.employee}`} title={`${c.type === 'birthday' ? '🎂' : '🎉'} ${c.employee}`} subtitle={`${c.type === 'birthday' ? 'Birthday' : `${c.years} year${c.years === 1 ? '' : 's'} at the company`} · ${c.today ? 'today' : formatDate(c.date)}`} right={c.today ? <Badge label="today" tone="green" /> : undefined} />)}
        </Card>
      )}
      <Card>
        <H2>My open tasks</H2>
        {tasks.length ? tasks.slice(0, 4).map((t) => <Row key={t.id} title={t.title} subtitle={t.due_on ? `Due ${formatDate(t.due_on)}` : 'No due date'} right={<Badge label={t.overdue ? 'overdue' : t.status} />} onPress={() => router.push('/tasks')} />) : <Empty text={loaded ? 'No open tasks' : 'Loading…'} />}
      </Card>
      <Card>
        <H2>Leave balance</H2>
        {balances.length ? balances.map((b) => <Row key={b.leave_type.id} title={b.leave_type.name} right={<H2>{`${Math.round(b.remaining * 100) / 100} days`}</H2>} />) : <Empty text={loaded ? 'No leave balances' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
