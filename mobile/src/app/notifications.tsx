import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';
import { Badge, Button, Card, Empty, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import type { Notification, Paginated } from '../lib/types';

export default function Notifications() {
  const { refreshMe } = useAuth();
  const [items, setItems] = useState<Notification[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    try {
      setItems((await get<Paginated<Notification>>('/notifications')).data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load notifications.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const markRead = async (id: string) => {
    await post(`/notifications/${id}/read`).catch(() => undefined);
    await Promise.all([load(), refreshMe().catch(() => undefined)]);
  };
  const markAll = async () => {
    await post('/notifications/read-all').catch(() => undefined);
    await Promise.all([load(), refreshMe().catch(() => undefined)]);
  };

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      {items.some((n) => !n.read_at) ? <Button title="Mark all as read" onPress={markAll} /> : null}
      <Card>
        {items.length ? items.map((n) => (
          <Row key={n.id} title={n.message} subtitle={new Date(n.created_at).toLocaleString()} onPress={n.read_at ? undefined : () => markRead(n.id)}
            right={n.read_at ? undefined : <View><Badge label="new" tone="blue" /></View>} />
        )) : <Empty text={loaded ? 'No notifications' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
