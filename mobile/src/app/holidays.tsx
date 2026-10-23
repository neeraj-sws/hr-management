import { useCallback, useEffect, useState } from 'react';
import { todayYmd } from '../components/DateField';
import { Badge, Card, Empty, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';
import type { Holiday } from '../lib/types';

const longDate = (ymd: string) => new Date(`${ymd}T00:00:00`).toLocaleDateString([], { weekday: 'short', day: '2-digit', month: 'short' });

export default function HolidaysScreen() {
  // Only this year: other years usually have no holidays set yet, which showed an empty page.
  const year = new Date().getFullYear();
  const [items, setItems] = useState<Holiday[]>([]);
  const [weeklyOff, setWeeklyOff] = useState<string[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoaded(false);
    try {
      const response = await get<{ data: Holiday[]; weekly_off: string[] }>(`/holidays?year=${year}`);
      setItems(response.data);
      setWeeklyOff(response.weekly_off);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load holidays.');
    } finally {
      setLoaded(true);
    }
  }, [year]);
  useEffect(() => { load(); }, [load]);

  const today = todayYmd();
  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      <Card>
        <H2>Holidays {year}</H2>
        <Muted style={{ marginBottom: 6 }}>Weekly off: {weeklyOff.join(', ') || 'none'}</Muted>
        {items.length ? items.map((h) => (
          <Row key={h.date} title={h.name} subtitle={longDate(h.date)} right={h.date === today ? <Badge label="today" tone="green" /> : h.date < today ? <Muted>past</Muted> : undefined} />
        )) : <Empty text={loaded ? 'No holidays set for this year' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
