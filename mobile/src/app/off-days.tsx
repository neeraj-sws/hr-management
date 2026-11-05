import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { AddButton, FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { Badge, Button, Card, colors, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { OffDay } from '../lib/types';

/** Off day request: a week-of-month off (e.g. 2nd Saturday) that counts only when asked for and approved by HR. */
export default function OffDaysScreen() {
  const [items, setItems] = useState<OffDay[]>([]);
  const [dates, setDates] = useState<string[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [offDate, setOffDate] = useState('');
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  const load = useCallback(async () => {
    try {
      const res = await get<{ data: OffDay[]; dates: string[] }>('/off-days');
      setItems(res.data);
      setDates(res.dates);
      setOffDate((current) => (current && res.dates.includes(current) ? current : res.dates[0] ?? ''));
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const submit = async () => {
    if (!offDate) { setErrors({ off_date: 'Choose the day.' }); return; }
    setErrors({});
    setBusy(true);
    setMessage(null);
    try {
      await post<{ data: OffDay }>('/off-days', { off_date: offDate, note: note.trim() || null });
      setMessage({ tone: 'success', text: 'Sent to HR. The day is your off once HR approves it.' });
      setNote('');
      await load();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (id: number) => {
    if (!(await confirmAction('Cancel request?', 'The off day request will be withdrawn.', 'Cancel request'))) return;
    try {
      await post(`/off-days/${id}/cancel`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  return (
    <Screen>
      <AddButton title="Ask for an off day" onPress={() => setFormOpen(true)} />
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={formOpen} title="Ask for an off day" onClose={closeForm}>
        <Muted style={{ marginBottom: 10 }}>Your off on these days counts only when you ask for it and HR approves. Otherwise it is a working day.</Muted>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        {dates.length ? (
          <View style={{ gap: 6, marginBottom: 10 }}>
            {dates.map((d) => {
              const on = d === offDate;
              return (
                <Pressable key={d} onPress={() => setOffDate(d)} style={{ flexDirection: 'row', alignItems: 'center', gap: 8, padding: 10, borderRadius: 10, borderWidth: 1, borderColor: on ? colors.blue : colors.line, backgroundColor: on ? colors.blueSoft : colors.white }}>
                  <Text style={{ fontWeight: '700', color: on ? colors.blue : colors.muted }}>{on ? '◉' : '○'}</Text>
                  <Text style={{ flex: 1, color: colors.ink }}>{formatDate(d)}</Text>
                </Pressable>
              );
            })}
          </View>
        ) : <Muted style={{ marginBottom: 10 }}>No day to ask for right now.</Muted>}
        {errors.off_date ? <Notice tone="error" text={errors.off_date} /> : null}
        <Field label="Note (optional)" value={note} onChangeText={setNote} placeholder="e.g. Taking my 2nd Saturday off" error={errors.note} multiline />
        <Button title="Send to HR" tone="primary" onPress={submit} loading={busy} disabled={!dates.length} />
      </FormSheet>
      <Card>
        <H2>My off day requests</H2>
        {items.length ? items.map((o) => (
          <Row key={o.id} title={formatDate(o.off_date)}
            subtitle={`${o.note ?? ''}${o.decision_note ? `${o.note ? ' · ' : ''}${o.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={o.status} />{o.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(o.id)} /> : null}</View>} />
        )) : <Empty text={loaded ? 'No off day requests' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
