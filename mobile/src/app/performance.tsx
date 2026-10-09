import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { Card, Empty, H2, Muted, Notice, Screen, Stat } from '../components/ui';
import { ApiError, get } from '../lib/api';

type Month = { completed_tasks: number; completed_late: number; on_time_rate: number | null; open_tasks: number; overdue_tasks: number; logged_hours: number; active_hours: number; utilization: number | null };
type Review = { id: number; period: string; rating: number | null; strengths: string | null; improvements: string | null; reviewer: string | null; status: string };

/** Numbers come from tasks, timers and attendance on the server — nothing is calculated on the phone. */
export default function PerformanceScreen() {
  const [month, setMonth] = useState<Month | null>(null);
  const [reviews, setReviews] = useState<Review[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await get<{ month: Month | null; reviews: Review[] }>('/my/performance');
      setMonth(response.month);
      setReviews(response.reviews);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load performance.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      <H2>This month</H2>
      {month ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
          <Stat label="Tasks completed" value={month.completed_tasks} hint={`${month.completed_late} late`} />
          <Stat label="On time" value={month.on_time_rate !== null ? `${month.on_time_rate}%` : '—'} />
          <Stat label="Open / overdue" value={`${month.open_tasks} / ${month.overdue_tasks}`} />
          <Stat label="Hours on tasks" value={month.logged_hours} hint={`${month.active_hours} h at work`} />
        </View>
      ) : <Card><Empty text={loaded ? 'No data yet this month' : 'Loading…'} /></Card>}
      <Card>
        <H2>Reviews</H2>
        {reviews.length ? reviews.map((r) => (
          <View key={r.id} style={{ paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#eef1f6' }}>
            <H2>{r.rating !== null ? `★ ${r.rating}/5` : 'Not rated'} · {r.period}</H2>
            {r.strengths ? <Muted>Strengths: {r.strengths}</Muted> : null}
            {r.improvements ? <Muted>To improve: {r.improvements}</Muted> : null}
            <Muted>{r.reviewer ? `By ${r.reviewer}` : ''}</Muted>
          </View>
        )) : <Empty text={loaded ? 'No reviews yet' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
