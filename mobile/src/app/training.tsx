import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Linking, View } from 'react-native';
import { Badge, Button, Card, confirmAction, Empty, formatDate, H2, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Training } from '../lib/types';

export default function TrainingScreen() {
  const [items, setItems] = useState<Training[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      setItems((await get<{ data: Training[] }>('/training')).data);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    } finally {
      setLoaded(true);
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const act = async (item: Training, action: 'start' | 'complete') => {
    if (action === 'complete' && !(await confirmAction('Mark as completed?', item.title, 'Completed'))) return;
    setBusy(item.id);
    setMessage(null);
    try {
      const updated = (await post<{ data: Training }>(`/training/${item.id}/${action}`)).data;
      setItems((current) => current.map((t) => (t.id === updated.id ? updated : t)));
      setMessage({ tone: 'success', text: action === 'start' ? 'Started. Good luck!' : 'Well done — marked as completed.' });
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Something went wrong.' });
    } finally {
      setBusy(null);
    }
  };

  const open = items.filter((t) => t.status !== 'completed');
  const done = items.filter((t) => t.status === 'completed');
  return (
    <Screen>
      {message ? <Notice tone={message.tone} text={message.text} /> : null}
      {open.length ? open.map((t) => (
        <Card key={t.id}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
            <View style={{ flex: 1 }}><H2>{t.title}</H2></View>
            <Badge label={t.status} />
          </View>
          <Muted>{[t.mandatory ? 'Mandatory' : null, t.category, t.duration_hours ? `${t.duration_hours} h` : null, t.due_on ? `due ${formatDate(t.due_on)}` : null].filter(Boolean).join(' · ')}</Muted>
          {t.description ? <Muted style={{ marginTop: 6 }}>{t.description}</Muted> : null}
          <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
            {t.material_url ? <View style={{ flex: 1 }}><Button small title="Open material" onPress={() => Linking.openURL(t.material_url!)} /></View> : null}
            {t.status === 'assigned' ? <View style={{ flex: 1 }}><Button small tone="primary" title="Start" onPress={() => act(t, 'start')} loading={busy === t.id} /></View> : null}
            <View style={{ flex: 1 }}><Button small tone="success" title="Completed" onPress={() => act(t, 'complete')} loading={busy === t.id} disabled={busy !== null} /></View>
          </View>
        </Card>
      )) : <Card><Empty text={loaded ? 'No training to do — all caught up!' : 'Loading…'} /></Card>}
      {done.length ? (
        <Card>
          <H2>Completed</H2>
          {done.map((t) => <Muted key={t.id} style={{ paddingVertical: 6 }}>✓ {t.title}{t.completed_at ? ` · ${formatDate(t.completed_at)}` : ''}</Muted>)}
        </Card>
      ) : null}
    </Screen>
  );
}
