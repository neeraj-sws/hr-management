import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { Badge, Button, Card, colors, confirmAction, Empty, Field, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Approval } from '../lib/types';

export default function Approvals() {
  const [items, setItems] = useState<Approval[]>([]);
  const [notes, setNotes] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      setItems((await get<{ data: Approval[] }>('/approvals')).data);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const decide = async (approval: Approval, approve: boolean) => {
    if (!approve && !(notes[approval.id] ?? '').trim()) {
      setMessage({ tone: 'error', text: 'Write a comment explaining why before rejecting.' });
      return;
    }
    if (!(await confirmAction(approve ? 'Approve request?' : 'Reject request?', `${approval.label}: ${approval.summary}`, approve ? 'Approve' : 'Reject'))) return;
    setBusy(approval.id);
    setMessage(null);
    try {
      await post(`/approvals/${approval.id}/decision`, { approve, note: notes[approval.id] || null });
      setMessage({ tone: 'success', text: approve ? 'Approved.' : 'Rejected.' });
      await load();
    } catch (e) {
      setMessage({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Decision failed.' });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Screen>
      {message ? <Notice tone={message.tone} text={message.text} /> : null}
      {items.length === 0 ? <Card><Empty text="Nothing waiting for your approval" /></Card> : null}
      {items.map((a) => (
        <Card key={a.id}>
          <Badge label={a.label} tone="blue" />
          <Text style={{ color: colors.ink, fontWeight: '600', marginVertical: 8 }}>{a.summary}</Text>
          <Muted>{new Date(a.created_at).toLocaleString()}</Muted>
          <View style={{ marginTop: 10 }}>
            <Field label="Comment (required to reject)" value={notes[a.id] ?? ''} onChangeText={(v) => setNotes({ ...notes, [a.id]: v })} />
          </View>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <View style={{ flex: 1 }}><Button small tone="danger" title="Reject" onPress={() => decide(a, false)} loading={busy === a.id} disabled={busy !== null} /></View>
            <View style={{ flex: 1 }}><Button small tone="success" title="Approve" onPress={() => decide(a, true)} loading={busy === a.id} disabled={busy !== null} /></View>
          </View>
        </Card>
      ))}
    </Screen>
  );
}
