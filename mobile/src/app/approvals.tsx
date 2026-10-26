import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { FormSheet } from '../components/FormSheet';
import { Badge, Button, Card, colors, confirmAction, Empty, Field, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Approval } from '../lib/types';

export default function Approvals() {
  const [items, setItems] = useState<Approval[]>([]);
  const [rejecting, setRejecting] = useState<Approval | null>(null);
  const [reason, setReason] = useState('');
  const [sheetError, setSheetError] = useState<string | null>(null);
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
    if (!approve && !reason.trim()) {
      setSheetError('Write a comment explaining why before rejecting.');
      return;
    }
    if (approve && !(await confirmAction('Approve request?', `${approval.label}: ${approval.summary}`, 'Approve'))) return;
    setBusy(approval.id);
    setMessage(null);
    try {
      await post(`/approvals/${approval.id}/decision`, { approve, note: approve ? null : reason.trim() });
      setMessage({ tone: 'success', text: approve ? 'Approved.' : 'Rejected.' });
      setRejecting(null);
      setReason('');
      await load();
    } catch (e) {
      const text = e instanceof ApiError ? e.firstMessage : 'Decision failed.';
      if (approve) setMessage({ tone: 'error', text }); else setSheetError(text);
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
          <View style={{ flexDirection: 'row', gap: 8, marginTop: 10 }}>
            <View style={{ flex: 1 }}><Button small tone="danger" title="Reject" onPress={() => { setRejecting(a); setReason(''); setSheetError(null); }} disabled={busy !== null} /></View>
            <View style={{ flex: 1 }}><Button small tone="success" title="Approve" onPress={() => decide(a, true)} loading={busy === a.id} disabled={busy !== null} /></View>
          </View>
        </Card>
      ))}
      <FormSheet visible={!!rejecting} title="Reject request" onClose={() => setRejecting(null)}>
        {sheetError ? <Notice tone="error" text={sheetError} /> : null}
        {rejecting ? <Muted style={{ marginBottom: 10 }}>{rejecting.label}: {rejecting.summary}</Muted> : null}
        <Field label="Reason (the employee sees it)" value={reason} onChangeText={setReason} multiline placeholder="Why it is rejected" />
        <Button title="Reject" tone="danger" loading={busy !== null} onPress={() => rejecting && decide(rejecting, false)} />
      </FormSheet>
    </Screen>
  );
}
