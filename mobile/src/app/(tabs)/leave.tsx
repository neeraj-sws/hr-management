import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { AddButton, FormSheet } from '../../components/FormSheet';
import { DayFilter, MonthSwitcher, useMonth } from '../../components/MonthSwitcher';
import { DateField, toYmd, todayYmd } from '../../components/DateField';
import { Badge, Button, Card, Chips, colors, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../../components/ui';
import { ApiError, get, post } from '../../lib/api';
import { enqueue } from '../../lib/queue';
import { store } from '../../lib/storage';
import type { LeaveBalance, LeaveRequest, LeaveType, Paginated } from '../../lib/types';

const TYPES_CACHE = 'sws.cache.leave_types';

/** A past day can be asked for back to the start of last month (marked "Old date" for the approver). */
const earliestYmd = (() => { const d = new Date(); return toYmd(new Date(d.getFullYear(), d.getMonth() - 1, 1)); })();

export default function Leave() {
  const [types, setTypes] = useState<LeaveType[]>([]);
  const [balances, setBalances] = useState<LeaveBalance[]>([]);
  const [requests, setRequests] = useState<LeaveRequest[]>([]);
  const [typeId, setTypeId] = useState<number | null>(null);
  // One day (one date), several days (From / To), half day or short leave (one date).
  const [dayChoice, setDayChoice] = useState<'one' | 'several' | 'half' | 'short'>('one');
  const duration: 'full' | 'half' | 'short' = dayChoice === 'half' || dayChoice === 'short' ? dayChoice : 'full';
  const several = dayChoice === 'several';
  const [startsOn, setStartsOn] = useState('');
  const [endsOn, setEndsOn] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [loaded, setLoaded] = useState(false);
  // My requests: one month at a time.
  const [month, setMonth] = useMonth();
  // The request form opens in a popup; My requests can be narrowed by status.
  const [formOpen, setFormOpen] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<'all' | 'pending' | 'approved' | 'rejected'>('all');
  // One day (requests covering it). Pending shows every pending request, in any month.
  const [day, setDay] = useState('');
  const requestsUrl = day ? `/leave/requests?date=${day}` : statusFilter === 'pending' ? '/leave/requests?status=pending' : `/leave/requests?month=${month}`;

  const load = useCallback(async () => {
    try {
      const [t, b, r] = await Promise.all([
        get<{ data: LeaveType[] }>('/leave/types'),
        get<{ data: LeaveBalance[] }>('/leave/balances'),
        get<Paginated<LeaveRequest>>(requestsUrl),
      ]);
      setTypes(t.data);
      store.setJson(TYPES_CACHE, t.data);
      setBalances(b.data);
      setRequests(r.data);
      setLoaded(true);
    } catch (e) {
      if (!(e instanceof ApiError)) return;
      if (e.isNetwork) setTypes(await store.getJson<LeaveType[]>(TYPES_CACHE, []));
      setMessage({ tone: e.isNetwork ? 'info' : 'error', text: e.isNetwork ? 'Offline — you can still request leave; it is sent for approval when you are back online.' : e.firstMessage });
    }
  }, [requestsUrl]);

  useFocusEffect(useCallback(() => { load(); }, [load]));

  const submit = async () => {
    const endDate = several ? endsOn : startsOn;
    const local: Record<string, string> = {};
    if (!typeId) local.leave_type_id = 'Choose a leave type.';
    if (!startsOn) local.starts_on = 'Choose the first day of leave.';
    if (several && !endsOn) local.ends_on = 'Choose the last day of leave.';
    else if (several && startsOn && endsOn < startsOn) local.ends_on = 'End date must be on or after the start date.';
    if (!reason.trim()) local.reason = 'Give a reason.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    setFormError(null);
    try {
      const created = await post<{ data: LeaveRequest }>('/leave/requests', { leave_type_id: typeId, duration, starts_on: startsOn, ends_on: endDate, reason });
      // Show the new request immediately; balances refresh in the background.
      setRequests((current) => [created.data, ...current.filter((r) => r.id !== created.data.id)]);
      setMessage({ tone: 'success', text: `Leave request for ${formatDate(startsOn)} submitted for approval.` });
      // Open the month the leave is in (e.g. November), so the new request stays in the list.
      if (!day && statusFilter !== 'pending') setMonth(startsOn.slice(0, 7));
      setStartsOn(''); setEndsOn(''); setReason(''); setDayChoice('one');
      setFormOpen(false);
      load();
    } catch (e) {
      if (e instanceof ApiError && e.isNetwork) {
        await enqueue({ type: 'leave.request', payload: { leave_type_id: typeId, duration, starts_on: startsOn, ends_on: endDate, reason }, label: `Leave ${formatDate(startsOn)}${several ? ` – ${formatDate(endDate)}` : ''}` });
        setMessage({ tone: 'info', text: 'You are offline. The request is saved on this phone and will be sent for approval automatically.' });
        setStartsOn(''); setEndsOn(''); setReason(''); setDayChoice('one');
        setFormOpen(false);
      } else if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setFormError(e.firstMessage);
      }
    } finally {
      setBusy(false);
    }
  };

  const shownRequests = statusFilter === 'all' ? requests : requests.filter((r) => r.status === statusFilter);
  const emptyText = !loaded ? 'Loading…' : day ? `No leave on ${formatDate(day)}` : statusFilter === 'pending' ? 'No pending requests' : statusFilter === 'all' ? 'No leave requests this month' : `No ${statusFilter} requests this month`;

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
      <AddButton title="Request leave" onPress={() => { setFormError(null); setErrors({}); setFormOpen(true); }} />
      {message ? <Notice tone={message.tone} text={message.text} /> : null}
      <Card>
        <H2>Balance {new Date().getFullYear()}</H2>
        {balances.length ? balances.map((b) => (
          <Row key={b.leave_type.id} title={b.leave_type.name} subtitle={b.leave_type.paid ? `used ${b.used} · pending ${b.booked}${b.carried ? ` · carried ${b.carried}` : ''}` : 'Unpaid'} right={b.leave_type.paid ? <H2>{`${Math.round(b.remaining * 100) / 100}`}</H2> : undefined} />
        )) : <Empty text="No balances" />}
      </Card>
      <FormSheet visible={formOpen} title="Request leave" onClose={() => setFormOpen(false)}>
        {formError ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={formError} /></View> : null}
        <Muted style={{ marginBottom: 6 }}>Leave type</Muted>
        <Chips value={typeId} onChange={setTypeId} options={types.map((t) => ({ label: t.paid ? t.name : `${t.name} (unpaid)`, value: t.id }))} />
        {errors.leave_type_id ? <Muted style={{ color: '#bd3446', marginTop: -6, marginBottom: 8 }}>{errors.leave_type_id}</Muted> : null}
        <Muted style={{ marginBottom: 6 }}>Duration</Muted>
        <Chips<'one' | 'several' | 'half' | 'short'> value={dayChoice} onChange={setDayChoice} options={[{ label: 'One day', value: 'one' }, { label: 'Several days', value: 'several' }, { label: 'Half day', value: 'half' }, { label: 'Short leave', value: 'short' }]} />
        <DateField label={several ? 'From' : 'Date'} value={startsOn} onChange={pickStart} minimumDate={earliestYmd} error={errors.starts_on} />
        {startsOn && startsOn < todayYmd() ? <Text style={{ color: colors.amber, fontSize: 12, marginTop: -6, marginBottom: 10 }}>Past date ("Old date"): your approver must give a reason, and a late-leave fine may apply.</Text> : null}
        {several ? <DateField label="To" value={endsOn} onChange={setEndsOn} minimumDate={startsOn || earliestYmd} error={errors.ends_on} /> : null}
        <Field label="Reason" value={reason} onChangeText={setReason} error={errors.reason} multiline />
        <Button title="Submit request" tone="primary" onPress={submit} loading={busy} />
      </FormSheet>
      <Card>
        <H2>My requests</H2>
        {day || statusFilter === 'pending' ? null : <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={setMonth} futureMonths={12} /></View>}
        <DayFilter value={day} onChange={setDay} />
        <Chips value={statusFilter} onChange={setStatusFilter} options={[{ label: 'All', value: 'all' }, { label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' }, { label: 'Rejected', value: 'rejected' }]} />
        {shownRequests.length ? shownRequests.map((r) => (
          <Row key={r.id} title={`${r.leave_type} · ${r.days} day(s)${r.duration && r.duration !== 'full' ? ` · ${r.duration === 'half' ? 'Half day' : 'Short leave'}` : ''}`} subtitle={`${r.duration && r.duration !== 'full' ? formatDate(r.starts_on) : `${formatDate(r.starts_on)} – ${formatDate(r.ends_on)}`}${r.decision_note ? ` · ${r.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={r.status} />{r.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(r.id)} /> : null}</View>} />
        )) : <Empty text={emptyText} />}
      </Card>
    </Screen>
  );
}
