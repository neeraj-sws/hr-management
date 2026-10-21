import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Badge, Button, Card, colors, confirmAction, H2, Muted, Notice, Row, Screen } from '../../components/ui';
import { authenticate, biometricAvailable, biometricEnabled, setBiometricEnabled } from '../../lib/biometric';
import { get, getBaseUrl } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { subscribe } from '../../lib/queue';
import type { Approval } from '../../lib/types';

export default function More() {
  const { me, signOut } = useAuth();
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
  }, []));
  useEffect(() => subscribe((items) => setQueue({ queued: items.filter((i) => i.status === 'queued').length, problems: items.filter((i) => i.status !== 'queued').length })), []);

  type Tile = { label: string; icon: string; href: string; badge?: number; tone?: 'red' | 'amber' };
  const sections: { title: string; tiles: Tile[] }[] = [
    { title: 'Work', tiles: [
      ...(isManager ? [{ label: 'My team', icon: '👥', href: '/team' }] : []),
      { label: 'Daily status', icon: '📝', href: '/daily-status' },
      // Hidden when the company switched overtime off (Settings > Payroll).
      ...(me?.policies?.overtime === false ? [] : [{ label: 'Overtime', icon: '⏱️', href: '/overtime' }]),
      { label: 'Correction', icon: '🕘', href: '/corrections' },
      { label: 'Training', icon: '🎓', href: '/training' },
      { label: 'Performance', icon: '📈', href: '/performance' },
      ...(approvals > 0 ? [{ label: 'Approvals', icon: '✅', href: '/approvals', badge: approvals, tone: 'amber' as const }] : []),
    ] },
    { title: 'Money & documents', tiles: [
      { label: 'Payslips', icon: '💰', href: '/payslips' },
      { label: 'Expenses', icon: '🧾', href: '/expenses' },
      { label: 'Documents', icon: '📁', href: '/documents' },
    ] },
    { title: 'Company', tiles: [
      { label: 'Notifications', icon: '🔔', href: '/notifications', badge: me?.unread_notifications ?? 0, tone: 'red' },
      { label: 'Announcements', icon: '📢', href: '/announcements' },
      { label: 'Holidays', icon: '📅', href: '/holidays' },
      { label: 'Offline sync', icon: '🔄', href: '/sync', badge: queue.queued + queue.problems, tone: queue.problems ? 'red' : 'amber' },
    ] },
  ];

  return (
    <Screen>
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <View style={styles.avatar}><Text style={styles.avatarText}>{initials(me?.name)}</Text></View>
          <View style={{ flex: 1 }}>
            <H2>{me?.name ?? ''}</H2>
            <Muted>{[me?.employee?.employee_code, me?.employee?.department?.name, me?.roles.join(', ')].filter(Boolean).join(' · ')}</Muted>
          </View>
        </View>
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
