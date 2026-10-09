import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { Badge, Button, Card, Empty, Muted, Notice, Row, Screen } from '../components/ui';
import { discard, flush, QueuedAction, subscribe } from '../lib/queue';

export default function Sync() {
  const [items, setItems] = useState<QueuedAction[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => subscribe(setItems), []);

  const syncNow = async () => {
    setBusy(true);
    try {
      const accepted = await flush();
      setMessage(accepted ? `${accepted} action(s) synced.` : 'Nothing was synced — check your connection or the items below.');
    } catch {
      setMessage('Sync failed. It will retry automatically.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <Muted>Actions done offline are stored on this phone and sent in order when you are back online. Each has a unique ID, so retries never create duplicates. Conflicts happen when the record changed on the server — refresh and redo the action.</Muted>
      {message ? <Notice text={message} /> : null}
      <Button title="Sync now" tone="primary" onPress={syncNow} loading={busy} />
      <Card>
        {items.length ? items.map((item) => (
          <Row key={item.action_id} title={item.label} subtitle={`${new Date(item.queued_at).toLocaleString()}${item.last_error ? ` · ${item.last_error}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={item.status} />{item.status !== 'queued' ? <Button small title="Discard" onPress={() => discard(item.action_id)} /> : null}</View>} />
        )) : <Empty text="Everything is synced" />}
      </Card>
    </Screen>
  );
}
