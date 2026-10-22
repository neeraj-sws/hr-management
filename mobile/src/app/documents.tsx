import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Platform, StyleSheet, Text, View } from 'react-native';
import { AddButton, FormSheet } from '../components/FormSheet';
import { Badge, Button, Card, Chips, colors, confirmAction, Empty, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, api, get, upload } from '../lib/api';

type Document = { id: number; name: string; category: string; expires_on: string | null; expired: boolean; url: string; can_delete: boolean };
type Asset = { id: number; tag: string; name: string; status: string; assigned_on: string | null; returned_on: string | null };
type ChecklistItem = { key: string; label: string; done: boolean; count: number };
type UploadType = { key: string; label: string };
type Picked = { uri: string; name: string; type: string; file?: File };

export default function DocumentsScreen() {
  const [documents, setDocuments] = useState<Document[]>([]);
  const [checklist, setChecklist] = useState<ChecklistItem[]>([]);
  const [types, setTypes] = useState<UploadType[]>([]);
  const [assets, setAssets] = useState<Asset[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  // Upload popup
  const [formOpen, setFormOpen] = useState(false);
  const [category, setCategory] = useState('resume');
  const [picked, setPicked] = useState<Picked | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const [d, a] = await Promise.all([
        get<{ data: Document[]; checklist: ChecklistItem[]; upload_types: UploadType[] }>('/my/documents'),
        get<{ data: Asset[] }>('/my/assets'),
      ]);
      setDocuments(d.data);
      setChecklist(d.checklist ?? []);
      setTypes(d.upload_types ?? []);
      setAssets(a.data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load documents.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  // Links are signed for you and expire in 5 minutes, so fetch fresh ones before opening.
  const open = async (id: number) => {
    try {
      const fresh = (await get<{ data: Document[] }>('/my/documents')).data.find((d) => d.id === id);
      if (fresh) await Linking.openURL(fresh.url);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not open the document.');
    }
  };

  const startUpload = (key?: string) => {
    setCategory(key ?? checklist.find((c) => !c.done)?.key ?? 'resume');
    setPicked(null);
    setFormError(null);
    setFormOpen(true);
  };

  const isPhoto = category === 'photo';

  // A file from the phone (PDF, Word or image) …
  const chooseFile = async () => {
    const result = await DocumentPicker.getDocumentAsync({
      type: isPhoto ? ['image/jpeg', 'image/png', 'image/webp'] : ['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
      copyToCacheDirectory: true,
    });
    if (result.canceled || !result.assets?.length) return;
    const asset = result.assets[0];
    setPicked({ uri: asset.uri, name: asset.name, type: asset.mimeType ?? 'application/octet-stream', file: (asset as { file?: File }).file });
    setFormError(null);
  };

  // … or a photo taken now (ID card, cheque).
  const takePhoto = async () => {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) { setFormError('Allow camera access to photograph the document.'); return; }
    const result = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.7 });
    if (result.canceled || !result.assets.length) return;
    const asset = result.assets[0];
    const type = asset.mimeType ?? 'image/jpeg';
    setPicked({ uri: asset.uri, name: asset.fileName ?? `${category}.${type.split('/')[1] ?? 'jpg'}`, type, file: (asset as { file?: File }).file });
    setFormError(null);
  };

  const send = async () => {
    if (!picked) { setFormError('Choose a file or take a photo first.'); return; }
    setBusy(true);
    setFormError(null);
    try {
      const form = new FormData();
      form.append('category', category);
      // Web gives a File; native needs the { uri, name, type } form React Native understands.
      if (Platform.OS === 'web' && picked.file) form.append('file', picked.file, picked.name);
      else form.append('file', { uri: picked.uri, name: picked.name, type: picked.type } as unknown as Blob);
      const response = await upload<{ message: string }>('/my/documents', form);
      setFormOpen(false);
      setMessage(response.message ?? 'Uploaded.');
      await load();
    } catch (e) {
      setFormError(e instanceof ApiError ? (e.isNetwork ? 'You are offline. Try again when you are connected.' : e.firstMessage) : 'Could not upload the file.');
    } finally {
      setBusy(false);
    }
  };

  const remove = async (doc: Document) => {
    if (!(await confirmAction('Delete document', `Delete ${doc.name}?`, 'Delete'))) return;
    try {
      await api('DELETE', `/my/documents/${doc.id}`);
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not delete the document.');
    }
  };

  const missing = checklist.filter((c) => !c.done).length;

  return (
    <Screen refreshing={false} onRefresh={load}>
      {error ? <Notice tone="error" text={error} /> : null}
      {message ? <Notice tone="success" text={message} /> : null}
      <AddButton title="Upload a document" onPress={() => startUpload()} />

      {checklist.length ? (
        <Card>
          <H2>Onboarding documents</H2>
          <Muted>{missing ? `${missing} of ${checklist.length} still missing — upload each one; HR is told when you do.` : `All ${checklist.length} handed in.`}</Muted>
          {checklist.map((item) => (
            <View key={item.key} style={styles.check}>
              <View style={[styles.mark, item.done && styles.markDone]}><Text style={styles.markText}>{item.done ? '✓' : '!'}</Text></View>
              <View style={{ flex: 1 }}>
                <Text style={styles.checkTitle}>{item.label}</Text>
                <Muted>{item.done ? (item.key === 'photo' ? 'Photo added' : `${item.count} file${item.count > 1 ? 's' : ''}`) : 'Missing'}</Muted>
              </View>
              <Button small title={item.done ? 'Add more' : 'Upload'} tone={item.done ? 'default' : 'primary'} onPress={() => startUpload(item.key)} />
            </View>
          ))}
        </Card>
      ) : null}

      <Card>
        <H2>My documents</H2>
        {documents.length ? documents.map((d) => (
          <Row key={d.id} title={d.name} subtitle={`${d.category}${d.expires_on ? ` · expires ${formatDate(d.expires_on)}` : ''}`} onPress={() => open(d.id)}
            right={<View style={styles.rowRight}>{d.expired ? <Badge label="expired" tone="red" /> : <Muted>Open ›</Muted>}{d.can_delete ? <Text style={styles.delete} onPress={() => remove(d)}>Delete</Text> : null}</View>} />
        )) : <Empty text={loaded ? 'No documents yet' : 'Loading…'} />}
      </Card>
      <Card>
        <H2>My assets</H2>
        {assets.length ? assets.map((a) => (
          <Row key={a.id} title={`${a.name}`} subtitle={`${a.tag}${a.assigned_on ? ` · since ${formatDate(a.assigned_on)}` : ''}${a.returned_on ? ` · returned ${formatDate(a.returned_on)}` : ''}`} right={<Badge label={a.status.replace('_', ' ')} tone={a.status === 'assigned' ? 'blue' : a.status === 'returned' ? 'green' : 'red'} />} />
        )) : <Empty text={loaded ? 'No assets assigned' : 'Loading…'} />}
      </Card>

      <FormSheet visible={formOpen} title="Upload a document" onClose={() => setFormOpen(false)}>
        {formError ? <Notice tone="error" text={formError} /> : null}
        <Muted>Document</Muted>
        <Chips options={types.map((t) => ({ label: t.label, value: t.key }))} value={category} onChange={(value) => { setCategory(value); setPicked(null); }} />
        <Muted style={{ marginTop: 10 }}>{isPhoto ? 'Your profile photo: JPG, PNG or WebP, max 3 MB.' : 'PDF, Word or a photo, max 10 MB.'}</Muted>
        <View style={styles.pickRow}>
          <View style={{ flex: 1 }}><Button title={isPhoto ? 'Choose photo' : 'Choose file'} onPress={chooseFile} /></View>
          {Platform.OS !== 'web' ? <View style={{ flex: 1 }}><Button title="Take photo" onPress={takePhoto} /></View> : null}
        </View>
        {picked ? <Text style={styles.picked}>Selected: {picked.name}</Text> : null}
        <View style={styles.pickRow}>
          <View style={{ flex: 1 }}><Button title="Cancel" onPress={() => setFormOpen(false)} /></View>
          <View style={{ flex: 1 }}><Button title="Upload" tone="primary" onPress={send} loading={busy} disabled={busy || !picked} /></View>
        </View>
      </FormSheet>
    </Screen>
  );
}

const styles = StyleSheet.create({
  check: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.line },
  mark: { width: 26, height: 26, borderRadius: 13, backgroundColor: '#f59e0b', alignItems: 'center', justifyContent: 'center' },
  markDone: { backgroundColor: '#16a34a' },
  markText: { color: colors.white, fontWeight: '800' },
  checkTitle: { fontWeight: '700', color: colors.ink },
  rowRight: { alignItems: 'flex-end', gap: 4 },
  delete: { color: colors.red, fontWeight: '700', fontSize: 13 },
  pickRow: { flexDirection: 'row', gap: 10, marginTop: 12 },
  picked: { marginTop: 8, color: colors.ink },
});
