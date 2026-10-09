import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';
import { DateField } from '../../components/DateField';
import { Badge, Button, Card, Chips, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../../components/ui';
import { ApiError, get, post } from '../../lib/api';
import type { LeaveBalance, LeaveRequest, LeaveType, Paginated } from '../../lib/types';

export default function Leave() {
  const [types, setTypes] = useState<LeaveType[]>([]);
  const [balances, setBalances] = useState<LeaveBalance[]>([]);
  const [requests, setRequests] = useState<LeaveRequest[]>([]);
  const [typeId, setTypeId] = useState<number | null>(null);
  const [startsOn, setStartsOn] = useState('');
  const [endsOn, setEndsOn] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    try {
      const [t, b, r] = await Promise.all([
        get<{ data: LeaveType[] }>('/leave/types'),
        get<{ data: LeaveBalance[] }>('/leave/balances'),
        get<Paginated<LeaveRequest>>('/leave/requests'),
      ]);
      setTypes(t.data);
      setBalances(b.data);
      setRequests(r.data);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: e.isNetwork ? 'info' : 'error', text: e.isNetwork ? 'Offline — leave needs a connection.' : e.firstMessage });
    }
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!typeId) local.leave_type_id = 'Choose a leave type.';
    if (!startsOn) local.starts_on = 'Choose the first day of leave.';
    if (!endsOn) local.ends_on = 'Choose the last day of leave.';
    else if (startsOn && endsOn < startsOn) local.ends_on = 'End date must be on or after the start date.';
    if (!reason.trim()) local.reason = 'Give a reason.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    try {
      const created = await post<{ data: LeaveRequest }>('/leave/requests', { leave_type_id: typeId, starts_on: startsOn, ends_on: endsOn, reason });
      // Show the new request immediately; balances refresh in the background.
      setRequests((current) => [created.data, ...current.filter((r) => r.id !== created.data.id)]);
      setMessage({ tone: 'success', text: 'Leave request submitted for approval.' });
      setStartsOn(''); setEndsOn(''); setReason('');
      load();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const pickStart = (value: string) => {
    setStartsOn(value);
    if (!endsOn || endsOn < value) setEndsOn(value);
  };

  const cancel = async (id: number) => {
    if (!(await confirmAction('Cancel leave request?', 'The request will be withdrawn.', 'Cancel request'))) return;
    try {
      await post(`/leave/requests/${id}/cancel`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  const refresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  return (
    <Screen refreshing={refreshing} onRefresh={refresh}>
      <Card>
        <H2>Balance {new Date().getFullYear()}</H2>
        {balances.length ? balances.map((b) => (
          <Row key={b.leave_type.id} title={b.leave_type.name} subtitle={b.leave_type.paid ? `used ${b.used} · pending ${b.booked}${b.carried ? ` · carried ${b.carried}` : ''}` : 'Unpaid'} right={b.leave_type.paid ? <H2>{`${Math.round(b.remaining * 100) / 100}`}</H2> : undefined} />
        )) : <Empty text="No balances" />}
      </Card>
      <Card>
        <H2>Request leave</H2>
        {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
        <Muted style={{ marginBottom: 6 }}>Leave type</Muted>
        <Chips value={typeId} onChange={setTypeId} options={types.map((t) => ({ label: t.paid ? t.name : `${t.name} (unpaid)`, value: t.id }))} />
        {errors.leave_type_id ? <Muted style={{ color: '#bd3446', marginTop: -6, marginBottom: 8 }}>{errors.leave_type_id}</Muted> : null}
        <DateField label="From" value={startsOn} onChange={pickStart} error={errors.starts_on} />
        <DateField label="To" value={endsOn} onChange={setEndsOn} minimumDate={startsOn || undefined} error={errors.ends_on} />
        <Field label="Reason" value={reason} onChangeText={setReason} error={errors.reason} multiline />
        <Button title="Submit request" tone="primary" onPress={submit} loading={busy} />
      </Card>
      <Card>
        <H2>My requests</H2>
        {requests.length ? requests.map((r) => (
          <Row key={r.id} title={`${r.leave_type} · ${r.days} day(s)`} subtitle={`${formatDate(r.starts_on)} – ${formatDate(r.ends_on)}${r.decision_note ? ` · ${r.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={r.status} />{r.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(r.id)} /> : null}</View>} />
        )) : <Empty text={loaded ? 'No leave requests' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
