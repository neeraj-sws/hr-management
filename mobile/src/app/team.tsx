import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { Badge, Card, colors, Empty, formatDuration, formatTime, H2, Muted, Notice, Row, Screen, Stat } from '../components/ui';
import { ApiError, get } from '../lib/api';

type Person = { id: number; name: string; designation: string | null; state: string; check_in: string | null; late: boolean; worked_seconds: number; daily_status: boolean; blockers: string | null };
type Summary = { team: number; working: number; on_break: number; late: number; on_leave: number; not_in: number; pending_approvals: number };

export default function TeamScreen() {
  const router = useRouter();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [people, setPeople] = useState<Person[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await get<{ summary: Summary; people: Person[] }>('/team/today');
      setSummary(response.summary);
      setPeople(response.people);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load your team.');
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const blockers = people.filter((p) => p.blockers);
  return (
    <Screen refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }}>
      {error ? <Notice tone="error" text={error} /> : null}
      {summary ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
          <Stat label="Working" value={summary.working} hint={`${summary.on_break} on break`} />
          <Stat label="Not in yet" value={summary.not_in} hint={`${summary.on_leave} on leave`} />
          <Stat label="Late today" value={summary.late} />
          <Stat label="To approve" value={summary.pending_approvals} />
        </View>
      ) : null}
      {summary && summary.pending_approvals > 0 ? <Card><Row title="Approvals waiting" subtitle="Leave, overtime, corrections, expenses" onPress={() => router.push('/approvals')} right={<Badge label={String(summary.pending_approvals)} tone="amber" />} /></Card> : null}
      {blockers.length ? (
        <Card>
          <H2>Blockers today</H2>
          {blockers.map((p) => <View key={p.id} style={{ paddingVertical: 8 }}><Text style={{ fontWeight: '700', color: colors.ink2 }}>{p.name}</Text><Text style={{ color: colors.red }}>{p.blockers}</Text></View>)}
        </Card>
      ) : null}
      <Card>
        <H2>My team today</H2>
        {people.length ? people.map((p) => (
          <Row key={p.id} title={p.name} subtitle={p.check_in ? `In ${formatTime(p.check_in)} · ${formatDuration(p.worked_seconds)} worked${p.daily_status ? ' · status ✓' : ''}` : p.designation ?? undefined}
            right={<View style={{ alignItems: 'flex-end', gap: 4 }}><Badge label={p.state === 'not_in' ? 'not in' : p.state} tone={p.state === 'not_in' ? 'gray' : undefined} />{p.late ? <Badge label="late" /> : null}</View>} />
        )) : <Empty text={summary ? 'No direct reports' : 'Loading…'} />}
      </Card>
      <Muted>Pull down to refresh.</Muted>
    </Screen>
  );
}
