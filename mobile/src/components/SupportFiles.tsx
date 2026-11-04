import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { Button, colors } from './ui';

/** Help & Support files: screenshots / photos / PDF, up to 5, 5 MB each (the same limits as the server). */
export const SUPPORT_MAX_FILES = 5;
const MAX_BYTES = 5 * 1024 * 1024;

/** Badge colour for a request status. */
export const supportTone = (status: string) =>
  ({ open: 'blue', assigned: 'violet', in_progress: 'amber', waiting_for_employee: 'amber', resolved: 'green', closed: 'gray' } as const)[status] ?? 'gray';

export type PickedFile = { uri: string; name: string; type: string; size?: number | null; file?: File };

export function useSupportFiles() {
  const [files, setFiles] = useState<PickedFile[]>([]);
  const [error, setError] = useState<string | null>(null);

  const add = (more: PickedFile[]) => {
    const tooBig = more.find((f) => f.size && f.size > MAX_BYTES);
    if (tooBig) { setError(`${tooBig.name} is ${((tooBig.size ?? 0) / 1048576).toFixed(1)} MB. Each file can be up to 5 MB.`); return; }
    setFiles((current) => {
      const next = [...current, ...more];
      if (next.length > SUPPORT_MAX_FILES) setError(`Up to ${SUPPORT_MAX_FILES} files.`);
      else setError(null);
      return next.slice(0, SUPPORT_MAX_FILES);
    });
  };

  const choose = async () => {
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], multiple: true, copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.length) return;
    add(result.assets.map((a) => ({ uri: a.uri, name: a.name, type: a.mimeType ?? 'application/octet-stream', size: a.size ?? null, file: (a as { file?: File }).file })));
  };

  const takePhoto = async () => {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) { setError('Allow camera access to take a photo.'); return; }
    const result = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 0.6, exif: false });
    if (result.canceled || !result.assets.length) return;
    const a = result.assets[0];
    const type = a.mimeType ?? 'image/jpeg';
    add([{ uri: a.uri, name: a.fileName ?? `photo.${type.split('/')[1] ?? 'jpg'}`, type, size: a.fileSize ?? null, file: (a as { file?: File }).file }]);
  };

  const remove = (index: number) => setFiles((current) => current.filter((_, i) => i !== index));
  const clear = () => { setFiles([]); setError(null); };

  /** attachments[] in the form; web gives a File, native the { uri, name, type } React Native understands. */
  const appendTo = (form: FormData) => {
    for (const f of files) {
      if (Platform.OS === 'web' && f.file) form.append('attachments[]', f.file, f.name);
      else form.append('attachments[]', { uri: f.uri, name: f.name, type: f.type } as unknown as Blob);
    }
  };

  return { files, error, choose, takePhoto, remove, clear, appendTo };
}

export function SupportFilePicker({ picker }: { picker: ReturnType<typeof useSupportFiles> }) {
  return (
    <View style={{ marginBottom: 12 }}>
      <View style={styles.actions}>
        <Button small title="Attach file" onPress={picker.choose} disabled={picker.files.length >= SUPPORT_MAX_FILES} />
        {Platform.OS !== 'web' ? <Button small title="Take photo" onPress={picker.takePhoto} disabled={picker.files.length >= SUPPORT_MAX_FILES} /> : null}
      </View>
      {picker.files.map((f, i) => (
        <View key={`${f.uri}-${i}`} style={styles.file}>
          <Text style={styles.fileName} numberOfLines={1}>📎 {f.name}</Text>
          <Pressable onPress={() => picker.remove(i)} accessibilityRole="button" accessibilityLabel={`Remove ${f.name}`} hitSlop={8}><Text style={styles.remove}>✕</Text></Pressable>
        </View>
      ))}
      <Text style={styles.hint}>Screenshots, photos or PDF · up to {SUPPORT_MAX_FILES} files, 5 MB each</Text>
      {picker.error ? <Text style={styles.error}>{picker.error}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  actions: { flexDirection: 'row', gap: 8, flexWrap: 'wrap' },
  file: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 8, paddingVertical: 6, paddingHorizontal: 10, borderRadius: 8, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white },
  fileName: { flex: 1, fontSize: 13, color: colors.ink },
  remove: { fontSize: 14, color: colors.red, fontWeight: '700' },
  hint: { fontSize: 11, color: colors.faint, marginTop: 6 },
  error: { fontSize: 12, color: colors.red, marginTop: 4 },
});
