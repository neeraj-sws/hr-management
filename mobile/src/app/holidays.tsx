import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { todayYmd } from '../components/DateField';
import { Badge, Button, Card, Empty, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';
import type { Holiday } from '../lib/types';

const longDate = (ymd: string) => new Date(`${ymd}T00:00:00`).toLocaleDateString([], { weekday: 'short', day: '2-digit', month: 'short' });

export default function HolidaysScreen() {
  const [year, setYear] = useState(new Date().getFullYear());
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
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <View style={{ flex: 1 }}><Button small title={`‹ ${year - 1}`} onPress={() => setYear(year - 1)} /></View>
        <View style={{ flex: 1 }}><Button small title={`${year + 1} ›`} onPress={() => setYear(year + 1)} /></View>
      </View>
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
