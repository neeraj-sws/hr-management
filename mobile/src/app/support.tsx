import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';
import { AddButton, FormSheet } from '../components/FormSheet';
import { SupportFilePicker, supportTone, useSupportFiles } from '../components/SupportFiles';
import { Badge, Button, Card, Chips, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get, upload } from '../lib/api';
import type { SupportCategory, SupportTicket, SupportTicketDetail } from '../lib/types';

type Tab = 'active' | 'finished' | '';

/** Help & Support: my requests, and "New request" (category, subject, problem, screenshots / PDF). */
export default function SupportScreen() {
  const router = useRouter();
  const [tab, setTab] = useState<Tab>('active');
  const [items, setItems] = useState<SupportTicket[]>([]);
  const [categories, setCategories] = useState<SupportCategory[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const files = useSupportFiles();

  const load = useCallback(async () => {
    try {
      const [list, cats] = await Promise.all([
        get<{ data: SupportTicket[] }>(`/support/tickets?per_page=50${tab ? `&status=${tab}` : ''}`),
        get<{ data: SupportCategory[] }>('/support/categories'),
      ]);
      setItems(list.data);
      setCategories(cats.data);
      setLoaded(true);
      setMessage(null);
    } catch (e) {
      setMessage(e instanceof ApiError ? e.firstMessage : 'Could not load your requests.');
    }
  }, [tab]);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const openForm = () => {
    setCategoryId(null);
    setSubject('');
    setDescription('');
    setErrors({});
    setFormError(null);
    files.clear();
    setFormOpen(true);
  };

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!categoryId) local.category_id = 'Choose a category.';
    if (subject.trim().length < 3) local.subject = 'Write a short subject.';
    if (description.trim().length < 5) local.description = 'Describe the problem.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setFormError(null);
    try {
      const form = new FormData();
      form.append('category_id', String(categoryId));
      form.append('subject', subject.trim());
      form.append('description', description.trim());
      files.appendTo(form);
      const created = await upload<{ data: SupportTicketDetail }>('/support/tickets', form);
      setFormOpen(false);
      files.clear();
      router.push({ pathname: '/support-request', params: { id: String(created.data.id) } });
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.startsWith('attachments') ? 'attachments' : k, v[0]])));
        setFormError(e.isNetwork ? 'The request did not reach the server. Check the internet, or attach smaller files.' : e.firstMessage);
      } else setFormError('Could not send the request.');
    } finally {
      setBusy(false);
    }
  };

  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  return (
    <Screen refreshing={refreshing} onRefresh={refresh}>
      <AddButton title="New request" onPress={openForm} />
      {message ? <Notice tone="error" text={message} /> : null}
      <FormSheet visible={formOpen} title="New support request" onClose={() => setFormOpen(false)}>
        <Muted style={{ marginBottom: 10 }}>Ask the support team about attendance, leave, salary, tasks, documents or a technical problem. You are notified when they reply.</Muted>
        {formError ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={formError} /></View> : null}
        <Muted style={{ marginBottom: 6 }}>Category</Muted>
        <Chips options={categories.map((c) => ({ label: c.name, value: c.id }))} value={categoryId} onChange={setCategoryId} />
        {errors.category_id ? <Muted style={{ color: '#bd3446', marginTop: 4 }}>{errors.category_id}</Muted> : null}
        <View style={{ height: 12 }} />
        <Field label="Subject" value={subject} onChangeText={setSubject} placeholder="e.g. Check-out not saved on 2 Nov" maxLength={200} error={errors.subject} />
        <Field label="Describe the problem" value={description} onChangeText={setDescription} placeholder="What happened, when, and what you expected" multiline maxLength={5000} error={errors.description} style={{ minHeight: 100, textAlignVertical: 'top' }} />
        <SupportFilePicker picker={files} />
        {errors.attachments ? <Muted style={{ color: '#bd3446', marginBottom: 8 }}>{errors.attachments}</Muted> : null}
        <Button title="Send request" tone="primary" onPress={submit} loading={busy} />
      </FormSheet>
      <Card>
        <H2>My requests</H2>
        <View style={{ marginBottom: 10 }}>
          <Chips options={[{ label: 'Open', value: 'active' as Tab }, { label: 'Resolved & closed', value: 'finished' as Tab }, { label: 'All', value: '' as Tab }]} value={tab} onChange={setTab} />
        </View>
        {items.length ? items.map((t) => (
          <Row key={t.id} onPress={() => router.push({ pathname: '/support-request', params: { id: String(t.id) } })}
            title={`${t.unread ? '🔵 ' : ''}${t.subject}`}
            subtitle={`${t.number} · ${t.category ?? ''} · ${t.handled_by ?? 'Support team'} · ${formatDate(t.last_message_at ?? t.created_at)}${t.unread ? ' · New reply' : ''}`}
            right={<Badge label={t.status_label} tone={supportTone(t.status)} />} />
        )) : <Empty text={loaded ? 'No requests here. Tap “New request” to ask the support team.' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
