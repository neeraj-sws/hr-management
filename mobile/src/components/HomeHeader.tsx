import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../lib/auth';
import { clock, liveFigures } from '../lib/live';
import type { useAttendance } from '../lib/useAttendance';
import { Avatar } from './Avatar';
import { NotificationBell } from './NotificationBell';
import { RoleBadge } from './RoleBadge';
import { colors } from './ui';

/**
 * Home screen header: photo (dot = working / on a break), name with the role badge, today, code and
 * department, the bell, and below the live status ("Working · 00:48:05") and the team size for managers.
 */
export function HomeHeader({ attendance }: { attendance: ReturnType<typeof useAttendance> }) {
  const { me } = useAuth();
  const router = useRouter();
  const { top } = useSafeAreaInsets();
  const session = attendance.session;
  const [tick, setTick] = useState(Date.now());
  const fetchedAt = useRef(Date.now());

  useEffect(() => { fetchedAt.current = Date.now(); setTick(Date.now()); }, [session]);
  useEffect(() => {
    if (session?.status !== 'working' && session?.status !== 'on_break') return;
    const timer = setInterval(() => setTick(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [session?.status]);

  const v = liveFigures(session, (tick - fetchedAt.current) / 1000);
  const status = !session
    ? { dot: colors.faint, text: 'Not checked in' }
    : session.check_out ? { dot: colors.faint, text: `Day done · ${clock(v.worked)}` }
    : session.status === 'on_break' ? { dot: '#f2b33d', text: `On break · ${clock(v.brk)}` }
    : { dot: '#3ccf7a', text: `Working · ${clock(v.worked)}` };
  const today = new Date().toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' });
  const sub = [today, me?.employee?.employee_code, me?.employee?.department?.name].filter(Boolean).join(' · ');
  const role = me?.badges?.[0]?.label;
  const team = me?.team_size ?? 0;

  return (
    <View style={[styles.card, { marginTop: top }]}>
      <View style={styles.top}>
        <View>
          <Avatar name={me?.name} photoUrl={me?.photo_url} size={48} light />
          <View style={[styles.dot, { backgroundColor: status.dot }]} />
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <View style={styles.nameRow}>
            <Text style={styles.name} numberOfLines={1}>{me?.name ?? ''}</Text>
            {role ? <RoleBadge label={role} /> : null}
          </View>
          <Text style={styles.sub} numberOfLines={1}>{sub}</Text>
        </View>
        <Pressable onPress={() => router.push('/search')} hitSlop={8} style={styles.searchBtn} accessibilityRole="button" accessibilityLabel="Search">
          <Ionicons name="search" size={22} color={colors.white} />
        </Pressable>
        <NotificationBell />
      </View>
      <View style={styles.pills}>
        <View style={styles.pill}>
          <View style={[styles.pillDot, { backgroundColor: status.dot }]} />
          <Text style={styles.pillText}>{status.text}</Text>
        </View>
        {team > 0 ? (
          <Pressable style={styles.pill} onPress={() => router.push('/team')} accessibilityRole="button" accessibilityLabel={`Team, ${team} people`}>
            <Ionicons name="people-outline" size={15} color="#c9d6ee" />
            <Text style={[styles.pillText, { color: '#c9d6ee' }]}>Team {team}</Text>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  searchBtn: { padding: 6, marginRight: 2 },
  card: { backgroundColor: colors.navy, borderRadius: 18, padding: 14, marginBottom: 12 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  dot: { position: 'absolute', right: 1, bottom: 1, width: 12, height: 12, borderRadius: 6, borderWidth: 2, borderColor: colors.navy },
  nameRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  name: { color: colors.white, fontSize: 16, fontWeight: '800', flexShrink: 1 },
  sub: { color: '#b9c6dd', fontSize: 13, marginTop: 2 },
  pills: { flexDirection: 'row', gap: 8, marginTop: 12, flexWrap: 'wrap' },
  pill: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#22396a', borderRadius: 20, paddingHorizontal: 12, paddingVertical: 6 },
  pillDot: { width: 8, height: 8, borderRadius: 4 },
  pillText: { color: colors.white, fontWeight: '700', fontSize: 14, fontVariant: ['tabular-nums'] },
});
