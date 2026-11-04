import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { AddButton, FormSheet, useCloseOnSuccess } from '../components/FormSheet';
import { MonthSwitcher, useMonth } from '../components/MonthSwitcher';
import { DateField, TimeField, todayYmd } from '../components/DateField';
import { Badge, Button, Card, Chips, colors, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { TimesheetLine, TimesheetProject } from '../lib/types';

const hm = (minutes: number) => `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
const minutesBetween = (start: string, end: string) => {
  if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(end)) return 0;
  const [sh, sm] = start.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  let diff = eh * 60 + em - (sh * 60 + sm);
  if (diff <= 0) diff += 1440;
  return diff;
};

/** My Timesheet: the work I did on my projects, line by line; the manager shows it to the client and bills it. */
export default function TimesheetScreen() {
  const [lines, setLines] = useState<TimesheetLine[]>([]);
  const [projects, setProjects] = useState<TimesheetProject[]>([]);
  const [statuses, setStatuses] = useState<string[]>(['Done', 'In progress', 'Not done']);
  const [total, setTotal] = useState(0);
  const [loaded, setLoaded] = useState(false);

  const [editingId, setEditingId] = useState<number | null>(null);
  const [projectId, setProjectId] = useState<number | null>(null);
  const [workDate, setWorkDate] = useState(todayYmd());
  const [startTime, setStartTime] = useState('');
  const [endTime, setEndTime] = useState('');
  const [description, setDescription] = useState('');
  const [status, setStatus] = useState('Done');
  const [remarks, setRemarks] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const closeForm = useCallback(() => setFormOpen(false), []);
  useCloseOnSuccess(busy, message, closeForm);

  const [month, setMonth] = useMonth();
  const load = useCallback(async () => {
    try {
      const res = await get<{ data: TimesheetLine[]; projects: TimesheetProject[]; total_minutes: number; statuses: string[] }>(`/timesheet?month=${month}`);
      setLines(res.data);
      setProjects(res.projects);
      setTotal(res.total_minutes);
      setStatuses(res.statuses);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  }, [month]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const openForm = (line?: TimesheetLine) => {
    setEditingId(line?.id ?? null);
    setProjectId(line?.project_id ?? projectId ?? projects[0]?.id ?? null);
    setWorkDate(line?.work_date ?? todayYmd());
    setStartTime(line?.start_time ?? '');
    setEndTime(line?.end_time ?? '');
    setDescription(line?.description ?? '');
    setStatus(line?.status ?? 'Done');
    setRemarks(line?.remarks ?? '');
    setErrors({});
    setMessage(null);
    setFormOpen(true);
  };

  const worked = minutesBetween(startTime, endTime);

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!projectId) local.project_id = 'Project choose karo.';
    if (!startTime || !endTime) local.minutes = 'Start aur End time bharo.';
    if (description.trim().length < 3) local.description = 'Kya kaam kiya likho.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    try {
      const body = { project_id: projectId, work_date: workDate, start_time: startTime, end_time: endTime, description: description.trim(), status, remarks: remarks.trim() };
      if (editingId) await post(`/timesheet/${editingId}`, body);
      else await post('/timesheet', body);
      await load();
      setMessage({ tone: 'success', text: editingId ? 'Line updated.' : 'Line added to the project timesheet.' });
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const remove = async (id: number) => {
    if (!(await confirmAction('Remove line?', 'This timesheet line will be removed.', 'Remove'))) return;
    try {
      await post(`/timesheet/${id}/delete`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  return (
    <Screen>
      {projects.length ? <AddButton title="Add timesheet line" onPress={() => openForm()} /> : null}
      {message && (!formOpen || message.tone !== 'error') ? <Notice tone={message.tone} text={message.text} /> : null}
      <FormSheet visible={formOpen} title={editingId ? 'Edit line' : 'Add timesheet line'} onClose={closeForm}>
        {message?.tone === 'error' ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={message.text} /></View> : null}
        <Muted style={{ marginBottom: 6 }}>Project</Muted>
        <Chips options={projects.map((p) => ({ label: p.name, value: p.id }))} value={projectId} onChange={setProjectId} />
        {errors.project_id ? <Notice tone="error" text={errors.project_id} /> : null}
        <DateField label="Date (working day)" value={workDate} onChange={setWorkDate} maximumDate={todayYmd()} error={errors.work_date} />
        <TimeField label="Start" value={startTime} onChange={setStartTime} placeholder="e.g. 10:30 AM" error={errors.start_time} />
        <TimeField label="End" value={endTime} onChange={setEndTime} placeholder="e.g. 01:15 PM" error={errors.end_time ?? errors.minutes} />
        {worked ? <Muted style={{ marginBottom: 8 }}>Worked: {hm(worked)} ({worked} min)</Muted> : null}
        <Field label="Task / kya kaam kiya" value={description} onChangeText={setDescription} placeholder="e.g. Search filter fix and code upload on React" error={errors.description} multiline />
        <Muted style={{ marginBottom: 6 }}>Status</Muted>
        <Chips options={statuses.map((s) => ({ label: s, value: s }))} value={status} onChange={setStatus} />
        <Field label="Remarks (optional)" value={remarks} onChangeText={setRemarks} error={errors.remarks} />
        <Button title={editingId ? 'Save line' : 'Add line'} tone="primary" onPress={submit} loading={busy} />
      </FormSheet>
      <Card>
        <H2>My Timesheet</H2>
        <View style={{ marginBottom: 10 }}><MonthSwitcher month={month} onChange={setMonth} /></View>
        {loaded && !projects.length ? <Notice text="Aap abhi kisi project me nahi ho. Manager se project me add karwao." /> : null}
        {lines.length ? <Text style={{ color: colors.muted, marginBottom: 8 }}>{lines.length} lines · total {hm(total)}</Text> : null}
        {lines.length ? lines.map((l) => (
          <Row key={l.id} title={`${formatDate(l.work_date)} · ${l.project ?? ''}${l.start_time ? ` · ${l.start_time}–${l.end_time ?? ''}` : ''} (${hm(l.minutes)})`}
            subtitle={`${l.description}${l.remarks ? ` · ${l.remarks}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}>
              <Badge label={l.status || (l.source === 'timer' ? 'timer' : l.source)} />
              {l.editable ? <View style={{ flexDirection: 'row', gap: 6 }}><Button small title="Edit" onPress={() => openForm(l)} /><Button small tone="danger" title="×" onPress={() => remove(l.id)} /></View>
                : <Muted>{l.source === 'timer' ? 'task timer' : l.source === 'import' ? 'imported' : 'locked'}</Muted>}
            </View>} />
        )) : <Empty text={loaded ? 'No timesheet lines this month' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
