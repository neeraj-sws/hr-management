import { Avatar } from '../../components/Avatar';
import { RoleBadges } from '../../components/RoleBadge';
import * as ImagePicker from 'expo-image-picker';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Badge, Button, Card, colors, confirmAction, H2, Muted, Notice, Row, Screen } from '../../components/ui';
import { authenticate, biometricAvailable, biometricEnabled, setBiometricEnabled } from '../../lib/biometric';
import { ApiError, api, get, getBaseUrl, upload } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { subscribe } from '../../lib/queue';
import type { Approval } from '../../lib/types';

export default function More() {
  const { me, signOut, refreshMe } = useAuth();
  const [photoBusy, setPhotoBusy] = useState(false);
  const [photoNote, setPhotoNote] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);

  // Profile picture: its own button here (a photo uploaded under Documents is a document, not the picture).
  const changePhoto = async (source: 'library' | 'camera') => {
    if (source === 'camera' && !(await ImagePicker.requestCameraPermissionsAsync()).granted) { setPhotoNote({ tone: 'error', text: 'Allow camera access to take a photo.' }); return; }
    // base64: the photo is sent as text in a normal request (a file upload from the phone could be dropped).
    const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.5, allowsEditing: true, aspect: [1, 1], exif: false, base64: true };
    const result = source === 'camera' ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
    if (result.canceled || !result.assets.length) return;
    const asset = result.assets[0];
    const tooBig = asset.base64 ? asset.base64.length * 0.75 > 5 * 1024 * 1024 : !!asset.fileSize && asset.fileSize > 5 * 1024 * 1024;
    if (tooBig) { setPhotoNote({ tone: 'error', text: 'The photo is larger than 5 MB. Choose a smaller one, or crop it closer.' }); return; }
    setPhotoBusy(true);
    setPhotoNote(null);
    try {
      if (asset.base64) {
        await api('POST', '/me/photo', { photo_base64: asset.base64 }, 90000);
      } else {
        const form = new FormData();
        const type = asset.mimeType ?? 'image/jpeg';
        const file = (asset as { file?: File }).file;
        if (file) form.append('photo', file, file.name);
        else form.append('photo', { uri: asset.uri, name: `profile.${type.split('/')[1] ?? 'jpg'}`, type } as unknown as Blob);
        await upload('/me/photo', form);
      }
      await refreshMe();
      setPhotoNote({ tone: 'success', text: 'Profile picture updated.' });
    } catch (e) {
      setPhotoNote({ tone: 'error', text: e instanceof ApiError ? (e.isNetwork ? `The photo did not reach the server${typeof e.body === 'string' && e.body ? ` (${e.body})` : ''}. Check the internet and try again.` : e.firstMessage) : 'Could not update the photo.' });
    } finally {
      setPhotoBusy(false);
    }
  };
  const removePhoto = async () => {
    if (!(await confirmAction('Remove photo', 'Remove your profile picture?', 'Remove'))) return;
    try { await api('DELETE', '/me/photo'); await refreshMe(); setPhotoNote({ tone: 'success', text: 'Profile picture removed.' }); }
    catch (e) { setPhotoNote({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not remove the photo.' }); }
  };
  const router = useRouter();
  const [approvals, setApprovals] = useState(0);
  const [queue, setQueue] = useState({ queued: 0, problems: 0 });
  const [bioAvailable, setBioAvailable] = useState(false);
  const [bioOn, setBioOn] = useState(false);
  const [bioMessage, setBioMessage] = useState<string | null>(null);
  const [showDetails, setShowDetails] = useState(false);
  const isManager = !!me && (me.roles.includes('Manager') || me.roles.includes('HR') || me.roles.includes('Master Admin'));
  useEffect(() => { (async () => { setBioAvailable(await biometricAvailable()); setBioOn(await biometricEnabled()); })(); }, []);
  const toggleBiometric = async () => {
    if (bioOn) { await setBiometricEnabled(false); setBioOn(false); setBioMessage('Biometric unlock turned off.'); return; }
    if (await authenticate('Confirm to turn on biometric unlock')) { await setBiometricEnabled(true); setBioOn(true); setBioMessage('The app will ask for your fingerprint/face when opened.'); }
  };

  useFocusEffect(useCallback(() => {
    get<{ data: Approval[] }>('/approvals').then((r) => setApprovals(r.data.length)).catch(() => undefined);
    // A profile picture changed on the website shows here too.
    refreshMe().catch(() => undefined);
  }, [refreshMe]));
  useEffect(() => subscribe((items) => setQueue({ queued: items.filter((i) => i.status === 'queued').length, problems: items.filter((i) => i.status !== 'queued').length })), []);

  type Tile = { label: string; icon: string; href: string; badge?: number; tone?: 'red' | 'amber' };
  const sections: { title: string; tiles: Tile[] }[] = [
    { title: 'Work', tiles: [
      ...(isManager ? [{ label: 'My team', icon: '👥', href: '/team' }] : []),
      { label: 'Daily status', icon: '📝', href: '/daily-status' },
      { label: 'Notes', icon: '🗒️', href: '/notes' },
      { label: 'Correction', icon: '🕘', href: '/corrections' },
      { label: 'Training', icon: '🎓', href: '/training' },
      { label: 'Performance', icon: '📈', href: '/performance' },
      ...(approvals > 0 ? [{ label: 'Approvals', icon: '✅', href: '/approvals', badge: approvals, tone: 'amber' as const }] : []),
    ] },
    { title: 'Money & documents', tiles: [
      { label: 'My salary', icon: '📊', href: '/salary' },
      { label: 'My fines', icon: '⚠️', href: '/fines' },
      { label: 'Payslips', icon: '💰', href: '/payslips' },
      { label: 'Expenses', icon: '🧾', href: '/expenses' },
      { label: 'Documents', icon: '📁', href: '/documents' },
    ] },
    { title: 'Company', tiles: [
      { label: 'Notifications', icon: '🔔', href: '/notifications', badge: me?.unread_notifications ?? 0, tone: 'red' },
      { label: 'Announcements', icon: '📢', href: '/announcements' },
      { label: 'Holidays', icon: '📅', href: '/holidays' },
      { label: 'Instructions', icon: '📘', href: '/instructions' },
      { label: 'Offline sync', icon: '🔄', href: '/sync', badge: queue.queued + queue.problems, tone: queue.problems ? 'red' : 'amber' },
    ] },
  ];

  return (
    <Screen>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <Pressable onPress={() => changePhoto('library')} accessibilityRole="button" accessibilityLabel="Change profile picture">
            <Avatar name={me?.name} photoUrl={me?.photo_url} />
          </Pressable>
          <View style={{ flex: 1 }}>
            <H2>{me?.name ?? ''}</H2>
            <Muted>{[me?.employee?.employee_code, me?.employee?.department?.name].filter(Boolean).join(' · ')}</Muted>
            <View style={{ marginTop: 4 }}><RoleBadges badges={me?.badges ?? me?.roles.map((r) => ({ label: r }))} /></View>
          </View>
        </View>
        {me?.employee ? (
          <View style={styles.photoActions}>
            <Button small title={photoBusy ? 'Uploading…' : me?.photo_url ? 'Change photo' : 'Add photo'} onPress={() => changePhoto('library')} disabled={photoBusy} />
            <Button small title="Take photo" onPress={() => changePhoto('camera')} disabled={photoBusy} />
            {me?.photo_url ? <Button small title="Remove" tone="danger" onPress={removePhoto} disabled={photoBusy} /> : null}
          </View>
        ) : null}
        {photoNote ? <Notice tone={photoNote.tone} text={photoNote.text} /> : null}
        {me?.employee ? (
          <Pressable onPress={() => router.push('/settings')} style={({ pressed }) => [styles.settingsLink, pressed && { backgroundColor: colors.blueSoft }]} accessibilityRole="button">
            <Text style={styles.settingsText}>⚙️  My profile & settings</Text>
            <Text style={styles.settingsHint}>Phone, address, emergency contact, notifications ›</Text>
          </Pressable>
        ) : null}
        {me?.employee ? (
          <Pressable onPress={() => setShowDetails((v) => !v)} style={{ marginTop: 8 }}>
            <Muted style={{ color: colors.blue }}>{showDetails ? 'Hide details ▲' : 'Show details ▼'}</Muted>
          </Pressable>
        ) : null}
        {me?.employee && showDetails ? (
          <View style={{ marginTop: 6 }}>
            <Row title="Email" right={<Muted>{me.email}</Muted>} />
            <Row title="Manager" right={<Muted>{me.employee.manager?.name ?? '—'}</Muted>} />
            <Row title="Shift today" right={<Muted>{me.schedule?.shift ? `${me.schedule.shift} · ${me.schedule.starts_at}` : me.schedule?.starts_at ?? 'Flexible'}</Muted>} />
          </View>
        ) : null}
      </Card>
      {sections.map((section) => (
        <View key={section.title} style={{ marginBottom: 14 }}>
          <Text style={styles.section}>{section.title}</Text>
          <View style={styles.grid}>
            {section.tiles.map((tile) => (
              <Pressable key={tile.href} onPress={() => router.push(tile.href as never)} style={({ pressed }) => [styles.tile, pressed && { backgroundColor: colors.blueSoft }]} accessibilityRole="button" accessibilityLabel={tile.badge ? `${tile.label}, ${tile.badge}` : tile.label}>
                <Text style={styles.icon}>{tile.icon}</Text>
                <Text style={styles.label} numberOfLines={1}>{tile.label}</Text>
                {tile.badge ? <View style={[styles.badge, { backgroundColor: tile.tone === 'red' ? colors.red : colors.amber }]}><Text style={styles.badgeText}>{tile.badge > 99 ? '99+' : tile.badge}</Text></View> : null}
              </Pressable>
            ))}
          </View>
        </View>
      ))}
      {bioAvailable ? (
        <Card>
          <Row title="Unlock with fingerprint / face" subtitle={bioOn ? 'On — asked when the app opens' : 'Off'} onPress={toggleBiometric} right={<Badge label={bioOn ? 'on' : 'off'} tone={bioOn ? 'green' : 'gray'} />} />
          {bioMessage ? <Notice tone="info" text={bioMessage} /> : null}
        </Card>
      ) : null}
      <Muted>Server: {getBaseUrl()}</Muted>
      <Button title="Sign out" tone="danger" onPress={async () => { if (await confirmAction('Sign out?', queue.queued ? `${queue.queued} offline action(s) have not synced yet and will be deleted from this phone.` : 'You will need your email and password to sign in again.', 'Sign out')) signOut(); }} />
    </Screen>
  );
}

function initials(name?: string): string {
  return (name ?? '').split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || '?';
}

const styles = StyleSheet.create({
  settingsLink: { marginTop: 10, borderWidth: 1, borderColor: colors.line, borderRadius: 12, paddingVertical: 10, paddingHorizontal: 12 },
  settingsText: { color: colors.blue, fontWeight: '800', fontSize: 14 },
  settingsHint: { color: colors.muted, fontSize: 12, marginTop: 2 },
  photoActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  avatar: { width: 46, height: 46, borderRadius: 23, backgroundColor: colors.navy, alignItems: 'center', justifyContent: 'center' },
  avatarText: { color: colors.white, fontWeight: '800', fontSize: 16 },
  section: { fontSize: 12, fontWeight: '800', color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.8, marginBottom: 8, marginLeft: 2 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  tile: { width: '31%', minHeight: 86, backgroundColor: colors.white, borderRadius: 14, borderWidth: 1, borderColor: colors.line, alignItems: 'center', justifyContent: 'center', paddingVertical: 12, paddingHorizontal: 6 },
  icon: { fontSize: 26, marginBottom: 6 },
  label: { fontSize: 12.5, fontWeight: '600', color: colors.ink2, textAlign: 'center' },
  badge: { position: 'absolute', top: 6, right: 6, minWidth: 20, height: 20, borderRadius: 10, paddingHorizontal: 5, alignItems: 'center', justifyContent: 'center' },
  badgeText: { color: colors.white, fontSize: 11, fontWeight: '800' },
});
