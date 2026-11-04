import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Image, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { FormSheet } from '../components/FormSheet';
import { SupportFilePicker, supportTone, useSupportFiles } from '../components/SupportFiles';
import { Badge, Button, Card, colors, Empty, Field, formatDate, H2, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post, upload } from '../lib/api';
import type { SupportAttachment, SupportMessage, SupportTicketDetail } from '../lib/types';

const when = (iso: string) => new Date(iso).toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

/** One support request: the chat (refreshes every 10 seconds while open), reply with files, reopen when resolved. */
export default function SupportRequestScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [ticket, setTicket] = useState<SupportTicketDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [reopenOpen, setReopenOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reopenError, setReopenError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const files = useSupportFiles();
  const loading = useRef(false);

  const load = useCallback(async () => {
    if (!id || loading.current) return;
    loading.current = true;
    try {
      setTicket((await get<{ data: SupportTicketDetail }>(`/support/tickets/${id}`)).data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load the request.');
    } finally {
      loading.current = false;
    }
  }, [id]);

  // New replies show by themselves while the screen is open.
  useFocusEffect(useCallback(() => {
    load();
    const timer = setInterval(load, 10000);
    return () => clearInterval(timer);
  }, [load]));

  const send = async () => {
    if (!body.trim() && !files.files.length) { setSendError('Write a message or attach a file.'); return; }
    setBusy(true);
    setSendError(null);
    try {
      const form = new FormData();
      form.append('body', body.trim());
      files.appendTo(form);
      setTicket((await upload<{ data: SupportTicketDetail }>(`/support/tickets/${id}/messages`, form)).data);
      setBody('');
      files.clear();
    } catch (e) {
      setSendError(e instanceof ApiError ? (e.isNetwork ? 'The message did not reach the server. Check the internet, or attach smaller files.' : e.firstMessage) : 'Could not send.');
    } finally {
      setBusy(false);
    }
  };

  const reopen = async () => {
    if (reason.trim().length < 3) { setReopenError('Write what is still not solved.'); return; }
    setBusy(true);
    setReopenError(null);
    try {
      setTicket((await post<{ data: SupportTicketDetail }>(`/support/tickets/${id}/reopen`, { reason: reason.trim() })).data);
      setReopenOpen(false);
      setReason('');
    } catch (e) {
      setReopenError(e instanceof ApiError ? e.firstMessage : 'Could not reopen.');
    } finally {
      setBusy(false);
    }
  };

  const refresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  if (!ticket) {
    return <Screen><Stack.Screen options={{ title: 'Support request' }} />{error ? <Notice tone="error" text={error} /> : <Empty text="Loading…" />}</Screen>;
  }

  return (
    <Screen refreshing={refreshing} onRefresh={refresh}>
      <Stack.Screen options={{ title: ticket.number }} />
      <Card>
        <H2>{ticket.subject}</H2>
        <View style={styles.meta}>
          <Badge label={ticket.status_label} tone={supportTone(ticket.status)} />
          <Muted>{ticket.category} · {ticket.handled_by ?? 'Support team'} · raised {formatDate(ticket.created_at)}</Muted>
        </View>
      </Card>
      {error ? <Notice tone="error" text={error} /> : null}

      <View style={styles.chat}>
        {ticket.messages.map((m) => <Bubble key={m.id} m={m} />)}
      </View>

      {ticket.can_reply ? (
        <Card>
          {sendError ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={sendError} /></View> : null}
          <Field label="Your message" value={body} onChangeText={setBody} multiline maxLength={5000} placeholder="Write a message…" style={{ minHeight: 80, textAlignVertical: 'top' }} />
          <SupportFilePicker picker={files} />
          <Button title="Send" tone="primary" onPress={send} loading={busy} />
          <Muted style={{ marginTop: 8 }}>Messages cannot be edited or deleted after sending.</Muted>
        </Card>
      ) : ticket.status === 'resolved' ? (
        <Card>
          <Muted>This request is resolved.{ticket.can_reopen && ticket.reopen_until ? ` Not solved? You can reopen it until ${formatDate(ticket.reopen_until)}.` : ' To ask something more, raise a new request.'}</Muted>
          {ticket.can_reopen ? <View style={{ marginTop: 10 }}><Button title="Reopen request" tone="primary" onPress={() => { setReopenError(null); setReopenOpen(true); }} /></View> : null}
        </Card>
      ) : (
        <Card><Muted>This request is closed. To ask something more, raise a new request.</Muted></Card>
      )}

      <FormSheet visible={reopenOpen} title="Reopen request" onClose={() => setReopenOpen(false)}>
        {reopenError ? <View style={{ marginBottom: 10 }}><Notice tone="error" text={reopenError} /></View> : null}
        <Field label="What is still not solved?" value={reason} onChangeText={setReason} multiline maxLength={2000} style={{ minHeight: 80, textAlignVertical: 'top' }} />
        <Button title="Reopen" tone="primary" onPress={reopen} loading={busy} />
      </FormSheet>
    </Screen>
  );
}

function Bubble({ m }: { m: SupportMessage }) {
  return (
    <View style={[styles.bubble, m.mine ? styles.mine : styles.theirs]}>
      <Text style={styles.sender}>{m.mine ? 'You' : m.sender} <Text style={styles.role}>· {m.role} · {when(m.created_at)}</Text></Text>
      {m.removed ? <Text style={[styles.body, { fontStyle: 'italic', color: colors.muted }]}>{m.removed_text}</Text> : m.body ? <Text style={styles.body} selectable>{m.body}</Text> : null}
      {m.attachments.length ? (
        <View style={styles.files}>
          {m.attachments.map((a) => <Attachment key={a.id} a={a} />)}
        </View>
      ) : null}
    </View>
  );
}

function Attachment({ a }: { a: SupportAttachment }) {
  const open = () => Linking.openURL(a.url);
  return a.is_image ? (
    <Pressable onPress={open} accessibilityRole="imagebutton" accessibilityLabel={`Open ${a.name}`}>
      <Image source={{ uri: a.url }} style={styles.thumb} resizeMode="cover" />
    </Pressable>
  ) : (
    <Pressable onPress={open} style={styles.file} accessibilityRole="button" accessibilityLabel={`Open ${a.name}`}>
      <Text style={styles.fileText} numberOfLines={1}>📄 {a.name}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  meta: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginTop: 6 },
  chat: { gap: 10, marginBottom: 12 },
  bubble: { borderRadius: 12, padding: 12, borderWidth: 1, maxWidth: '92%' },
  mine: { alignSelf: 'flex-end', backgroundColor: colors.blueSoft, borderColor: '#cfdcfb' },
  theirs: { alignSelf: 'flex-start', backgroundColor: colors.white, borderColor: colors.line },
  sender: { fontSize: 13, fontWeight: '700', color: colors.ink2, marginBottom: 4 },
  role: { fontSize: 11, fontWeight: '400', color: colors.faint },
  body: { fontSize: 14, lineHeight: 20, color: colors.ink },
  files: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8 },
  thumb: { width: 110, height: 84, borderRadius: 8, backgroundColor: colors.canvas },
  file: { paddingVertical: 6, paddingHorizontal: 10, borderRadius: 8, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, maxWidth: 240 },
  fileText: { fontSize: 13, color: colors.blue },
});
