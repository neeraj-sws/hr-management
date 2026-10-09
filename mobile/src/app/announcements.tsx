import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text } from 'react-native';
import { Badge, Card, colors, Empty, formatDate, H2, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';
import type { Announcement } from '../lib/types';

export default function AnnouncementsScreen() {
  const [items, setItems] = useState<Announcement[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      setItems((await get<{ data: Announcement[] }>('/announcements')).data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load announcements.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  return (
    <Screen refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }}>
      {error ? <Notice tone="error" text={error} /> : null}
      {items.length ? items.map((a) => (
        <Card key={a.id}>
          {a.pinned ? <Badge label="Pinned" tone="violet" /> : null}
          <H2>{a.title}</H2>
          <Muted style={{ marginTop: -4, marginBottom: 8 }}>{formatDate(a.published_at)}{a.author ? ` · ${a.author}` : ''}</Muted>
          <Text style={{ color: colors.ink2, fontSize: 14, lineHeight: 21 }}>{a.body}</Text>
        </Card>
      )) : <Card><Empty text={loaded ? 'No announcements' : 'Loading…'} /></Card>}
    </Screen>
  );
}
