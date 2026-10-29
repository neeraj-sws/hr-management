import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Linking, View } from 'react-native';
import { AddButton, FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { Badge, Button, Card, Empty, Field, formatDate, H2, money, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Paginated, Payslip, PayslipRequest } from '../lib/types';

export default function Payslips() {
  const [items, setItems] = useState<Payslip[]>([]);
  const [requests, setRequests] = useState<PayslipRequest[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  // Request a month's payslip: this system's opens at once; an older month goes to HR.
  const [formOpen, setFormOpen] = useState(false);
  const [month, setMonth] = useMonth();
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  const load = useCallback(() => {
    get<Paginated<Payslip>>('/payslips')
      .then((r) => { setItems(r.data); setError(null); })
      .catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Could not load payslips.'))
      .finally(() => setLoaded(true));
    get<{ data: PayslipRequest[] }>('/payslip-requests').then((r) => setRequests(r.data)).catch(() => undefined);
  }, []);
  useFocusEffect(load);

  const send = async () => {
    setBusy(true); setMessage(null);
    try {
      const made = await post<{ data: PayslipRequest }>('/payslip-requests', { month, note: note.trim() || null });
      setMessage({ tone: 'success', text: made.data.status === 'sent' ? `The ${made.data.month_label} payslip is ready: tap Open below.` : 'Request sent to HR. You will be told when it is ready.' });
      setNote('');
      load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    } finally {
      setBusy(false);
    }
  };

  const label: Record<string, [string, 'green' | 'red' | 'amber']> = { sent: ['ready', 'green'], rejected: ['not given', 'red'], pending: ['waiting for HR', 'amber'] };

  return (
    <Screen>
      <AddButton title="Request a payslip" onPress={() => { setMessage(null); setFormOpen(true); }} />
      {error ? <Notice tone="error" text={error} /> : null}
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={formOpen} title="Request a payslip" onClose={closeForm}>
        <Muted style={{ marginBottom: 10 }}>Any month. If this system made it, it opens at once; for older months HR sends it and you are told.</Muted>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        <View style={{ marginBottom: 12 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        <Field label="Note (optional)" value={note} onChangeText={setNote} placeholder="e.g. Needed for a bank loan" />
        <Button title="Request payslip" tone="primary" onPress={send} loading={busy} />
      </FormSheet>
      {requests.length ? (
        <Card>
          <H2>My payslip requests</H2>
          {requests.map((r) => {
            const [text, tone] = label[r.status] ?? [r.status, 'amber'];
            return (
              <Row key={r.id} title={r.month_label} subtitle={r.decision_note ?? (r.status === 'sent' && r.decided_at ? `Sent${r.decided_by ? ` by ${r.decided_by}` : ''} on ${formatDate(r.decided_at)}` : r.note ?? undefined)}
                right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={text} tone={tone} />{r.url ? <Button small title="Open" onPress={() => Linking.openURL(r.url as string)} /> : null}</View>} />
            );
          })}
        </Card>
      ) : null}
      <Card>
        {items.length ? items.map((p) => (
          <Row key={p.id} title={`${p.reference} · ${formatDate(p.period_start)} – ${formatDate(p.period_end)}`} subtitle={`Net ${money(p.net)} · Paid ${money(p.paid)}`} right={<Badge label={p.balance > 0 ? 'partially paid' : 'paid'} tone={p.balance > 0 ? 'amber' : 'green'} />} />
        )) : <Empty text={loaded ? 'No payslips yet' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
