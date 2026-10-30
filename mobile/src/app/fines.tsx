import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { Badge, Button, Card, colors, Empty, Field, formatDate, H2, money, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Fine, Paginated } from '../lib/types';

/** My fines: why each was made; give a reason for any fine (late, absence, daily status, task …) — HR / admin accepting it removes that day's fine. */
export default function FinesScreen() {
  const [items, setItems] = useState<Fine[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [month, setMonth] = useMonth();
  const [giving, setGiving] = useState<Fine | null>(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const closeForm = useCallback(() => setGiving(null), []);
  useCloseOnSuccess(busy, message, closeForm);

  const load = useCallback(async () => {
    try {
      setItems((await get<Paginated<Fine>>(`/my/fines?month=${month}`)).data);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, [month]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const send = async () => {
    if (!giving) return;
    if (reason.trim().length < 5) { setError('Write the reason (at least 5 characters).'); return; }
    setError(undefined);
    setBusy(true);
    setMessage(null);
    try {
      const saved = await post<{ data: Fine }>(`/my/fines/${giving.id}/reason`, { reason: reason.trim() });
      setItems((current) => current.map((f) => (f.id === saved.data.id ? saved.data : f)));
      setMessage(saved.data.my_reason?.status === 'rejected'
        ? { tone: 'error', text: saved.data.my_reason.note || 'Your reason was rejected automatically.' }
        : { tone: 'success', text: 'Reason sent to HR. If it is accepted, the fine is removed.' });
      setReason('');
    } catch (e) {
      if (e instanceof ApiError) { setError(e.errors.reason?.[0]); setMessage({ tone: 'error', text: e.firstMessage }); }
    } finally {
      setBusy(false);
    }
  };

  const active = items.filter((f) => f.status === 'pending' || f.status === 'approved');
  const total = active.reduce((sum, f) => sum + f.amount, 0);

  return (
    <Screen>
      {message && (!giving || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={!!giving} title="Give your reason" onClose={closeForm}>
        {giving ? <Muted style={{ marginBottom: 10 }}>{formatDate(giving.date)} · ₹{money(giving.amount)} · {giving.reason}</Muted> : null}
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        <Field label="Your reason" value={reason} onChangeText={setReason} placeholder="e.g. Train was 40 minutes late / was ill, sent the leave mail late" error={error} multiline />
        <Muted style={{ marginBottom: 12 }}>HR or an admin reads it. If they accept it, this day's fine is removed; if not, they tell you why. One reason per fine.</Muted>
        <Button title="Send to HR" tone="primary" onPress={send} loading={busy} />
      </FormSheet>
      <Card>
        <H2>My fines</H2>
        <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        {active.length ? <Muted style={{ marginBottom: 8 }}>{active.length} {active.length === 1 ? 'fine' : 'fines'} this month · ₹{money(total)}</Muted> : null}
        {items.length ? items.map((f) => <FineRow key={f.id} fine={f} onGiveReason={() => { setGiving(f); setReason(''); setError(undefined); setMessage(null); }} />) : <Empty text={loaded ? 'No fines this month' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}

const statusLabel: Record<string, [string, 'green' | 'amber' | 'red' | 'gray']> = {
  approved: ['deducted', 'red'], pending: ['waiting for HR', 'amber'], waived: ['removed', 'green'], rejected: ['not applied', 'gray'],
};

/** One fine with the whole story: why it was made, my reason, and why it stays or was removed. */
function FineRow({ fine, onGiveReason }: { fine: Fine; onGiveReason: () => void }) {
  const [label, tone] = statusLabel[fine.status] ?? [fine.status, 'gray'];
  const mine = fine.my_reason;
  const when = (iso: string | null) => (iso ? formatDate(iso) : '');
  return (
    <View style={{ paddingVertical: 12, borderTopWidth: 1, borderTopColor: colors.line, gap: 4 }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
        <Text style={{ fontWeight: '700', color: colors.ink, flex: 1 }}>{formatDate(fine.date)} · ₹{money(fine.amount)}</Text>
        <Badge label={label} tone={tone} />
      </View>
      <Text style={{ color: colors.ink }}><Text style={{ fontWeight: '700' }}>Why applied: </Text>{fine.reason}</Text>
      {mine ? (
        <>
          <Muted>My reason ({when(mine.sent_at)}): “{mine.reason}”</Muted>
          {mine.status === 'pending' ? <Text style={{ color: colors.amber }}>Reason waiting for HR</Text> : null}
          {mine.status === 'approved' ? <Text style={{ color: colors.green }}>Reverted: {mine.decided_by ? `reason accepted by ${mine.decided_by}` : 'automatically, the missed daily status was written'} on {when(mine.decided_at)}{mine.paid_back_next_payroll ? ' · paid back in the next payroll' : ' · the fine is removed'}{mine.note ? ` · Note: ${mine.note}` : ''}</Text> : null}
          {mine.status === 'rejected' ? <Text style={{ color: colors.red }}>Reason not accepted by {mine.decided_by ?? 'HR'} on {when(mine.decided_at)}: {mine.note} · the fine stays</Text> : null}
        </>
      ) : fine.decision_note && (fine.status === 'waived' || fine.status === 'rejected') ? (
        <Muted>{fine.status === 'waived' ? 'Why removed' : 'Why not applied'} ({fine.decided_by ?? 'HR'}, {when(fine.decided_at)}): {fine.decision_note}</Muted>
      ) : null}
      {fine.can_give_reason ? <View style={{ alignSelf: 'flex-start', marginTop: 4 }}><Button small tone="primary" title="Give reason" onPress={onGiveReason} /></View> : null}
    </View>
  );
}
