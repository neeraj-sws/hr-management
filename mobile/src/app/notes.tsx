import { MaterialCommunityIcons } from '@expo/vector-icons';
import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { FormSheet } from '../components/FormSheet';
import { Button, Card, colors, confirmAction, Empty, Field, Muted, Notice, Screen } from '../components/ui';
import { ApiError, api, get, post } from '../lib/api';

type Note = { id: number; title: string; body: string; updated_at: string | null; author: string | null; mine: boolean; can_edit: boolean; shared: string };

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

/** More → Notes: write your own notes (add, edit, delete); notes your manager or team shared with you are read only. */
export default function Notes() {
  const [notes, setNotes] = useState<Note[]>([]);
  const [search, setSearch] = useState('');
  const [loaded, setLoaded] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<Note | 'new' | null>(null);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const [sheetTitle, setSheetTitle] = useState('New note');

  const load = useCallback(async () => {
    try {
      const r = await get<{ data: Note[] }>('/notes');
      setNotes(r.data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? (e.isNetwork ? 'No internet. Pull down to try again.' : e.firstMessage) : 'Could not load notes.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const startNew = () => { setSheetTitle('New note'); setEditing('new'); setTitle(''); setBody(''); setErrors({}); };
  const startEdit = (n: Note) => { setSheetTitle('Edit note'); setEditing(n); setTitle(n.title); setBody(n.body); setErrors({}); };

  const save = async () => {
    setSaving(true);
    try {
      if (editing === 'new') await post('/notes', { title, body });
      else if (editing) await api('PATCH', `/notes/${editing.id}`, { title, body });
      setEditing(null);
      await load();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      else setErrors({ title: e instanceof ApiError ? (e.isNetwork ? 'Not saved: no internet.' : e.firstMessage) : 'Could not save.' });
    } finally {
      setSaving(false);
    }
  };

  const remove = async (n: Note) => {
    if (!(await confirmAction('Delete note?', `"${n.title}" will be deleted.`, 'Delete'))) return;
    try { await api('DELETE', `/notes/${n.id}`); setNotes((list) => list.filter((x) => x.id !== n.id)); }
    catch (e) { setError(e instanceof ApiError ? e.firstMessage : 'Could not delete.'); }
  };

  const term = search.trim().toLowerCase();
  const shown = term ? notes.filter((n) => `${n.title} ${n.body}`.toLowerCase().includes(term)) : notes;

  return (
    <Screen refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }}>
      <View style={styles.top}>
        <View style={styles.searchBox}>
          <MaterialCommunityIcons name="magnify" size={18} color={colors.faint} />
          <TextInput value={search} onChangeText={setSearch} placeholder="Search notes" placeholderTextColor={colors.faint} style={styles.searchInput} />
        </View>
        <Button title="+ New note" tone="primary" small onPress={startNew} />
      </View>
      {error ? <Notice tone="error" text={error} /> : null}
      {loaded && !shown.length ? <Empty text={term ? 'No note matches.' : 'No notes yet. Tap "+ New note" to write one.'} /> : null}
      {shown.map((n) => (
        <Card key={n.id}>
          <Pressable onPress={() => setOpen(open === n.id ? null : n.id)}>
            <View style={styles.head}>
              <Text style={styles.title} numberOfLines={open === n.id ? undefined : 1}>{n.title}</Text>
              <View style={[styles.tag, n.shared === 'Private' ? styles.tagPrivate : styles.tagShared]}><Text style={[styles.tagText, n.shared === 'Private' ? { color: colors.blue } : { color: colors.violet }]}>{n.shared}</Text></View>
            </View>
            <Text style={styles.body} numberOfLines={open === n.id ? undefined : 3}>{n.body}</Text>
            <Muted style={{ marginTop: 6 }}>{n.mine ? 'You' : n.author} · {when(n.updated_at)}</Muted>
          </Pressable>
          {n.can_edit ? (
            <View style={styles.actions}>
              <Pressable style={styles.iconBtn} onPress={() => startEdit(n)} accessibilityRole="button" accessibilityLabel={`Edit ${n.title}`}><MaterialCommunityIcons name="pencil-outline" size={18} color={colors.blue} /><Text style={[styles.iconText, { color: colors.blue }]}>Edit</Text></Pressable>
              <Pressable style={styles.iconBtn} onPress={() => remove(n)} accessibilityRole="button" accessibilityLabel={`Delete ${n.title}`}><MaterialCommunityIcons name="trash-can-outline" size={18} color={colors.red} /><Text style={[styles.iconText, { color: colors.red }]}>Delete</Text></Pressable>
            </View>
          ) : <Muted style={{ marginTop: 8 }}>Shared with you · read only</Muted>}
        </Card>
      ))}

      <FormSheet visible={editing !== null} title={sheetTitle} onClose={() => setEditing(null)}>
        <Field label="Title" value={title} onChangeText={setTitle} placeholder="e.g. Call the client" error={errors.title} />
        <Field label="Note" value={body} onChangeText={setBody} placeholder="Write your note" multiline numberOfLines={6} style={{ minHeight: 130, textAlignVertical: 'top' }} error={errors.body} />
        <Muted style={{ marginBottom: 10 }}>Only you can see notes you write here.</Muted>
        <Button title={saving ? 'Saving…' : 'Save note'} tone="primary" onPress={save} loading={saving} />
      </FormSheet>
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', gap: 10, alignItems: 'center' },
  searchBox: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 10, paddingHorizontal: 10 },
  searchInput: { flex: 1, paddingVertical: 9, fontSize: 14, color: colors.ink },
  head: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 4 },
  title: { flex: 1, fontSize: 15, fontWeight: '800', color: colors.ink },
  tag: { borderRadius: 12, paddingHorizontal: 8, paddingVertical: 2 },
  tagPrivate: { backgroundColor: colors.blueSoft },
  tagShared: { backgroundColor: colors.violetSoft },
  tagText: { fontSize: 11, fontWeight: '700' },
  body: { color: colors.ink2, fontSize: 14, lineHeight: 20 },
  actions: { flexDirection: 'row', gap: 10, marginTop: 10 },
  iconBtn: { flexDirection: 'row', alignItems: 'center', gap: 4, borderWidth: 1, borderColor: colors.line, borderRadius: 8, paddingHorizontal: 10, paddingVertical: 6 },
  iconText: { fontSize: 13, fontWeight: '700' },
});
