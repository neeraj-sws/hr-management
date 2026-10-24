import { MaterialCommunityIcons } from '@expo/vector-icons';
import { Stack, useFocusEffect, useRouter, type Href } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Animated, PanResponder, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { FormSheet } from '../components/FormSheet';
import { Button, colors, Empty, Field, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import type { NotificationItem } from '../lib/types';

const navy = '#13254F';
const blue = '#2B5BD7';
type Filter = 'all' | 'action' | 'attendance' | 'tasks' | 'payroll';
type Counts = { all: number; unread: number; needs_action: number; attendance: number; tasks: number; payroll: number; daily_status: number };
type Response = { data: NotificationItem[]; current_page: number; last_page: number; meta: { counts: Counts } };

/** Where a tap on a notification goes in the app (requests waiting for a decision go to Approvals). */
function screenFor(n: NotificationItem): Href | null {
  if (n.needs_action) return '/approvals';
  const kind = n.kind ?? '';
  if (kind === 'AttendanceCorrection') return '/corrections';
  if (kind === 'LeaveRequest' || kind === 'LeaveBalance' || kind === 'OvertimeRequest') return '/(tabs)/leave';
  if (kind.startsWith('DailyStatus')) return '/daily-status';
  if (kind.startsWith('Task') || kind.startsWith('Project')) return n.record_id && kind.startsWith('Task') ? { pathname: '/task', params: { id: String(n.record_id) } } : '/(tabs)/tasks';
  if (kind === 'Payslip' || kind === 'PayrollRun' || kind === 'SalaryPayment') return '/payslips';
  if (kind.startsWith('Salary')) return '/salary';
  if (kind === 'EmployeeDocument') return '/documents';
  if (kind === 'ExpenseClaim') return '/expenses';
  if (kind === 'TrainingAssignment') return '/training';
  if (kind === 'Announcement') return '/announcements';
  if (kind.startsWith('Performance')) return '/performance';
  if (kind.startsWith('Attendance') || kind.includes('Shift') || kind.includes('Checkout')) return '/(tabs)/attendance';
  return null;
}

export default function Notifications() {
  const router = useRouter();
  const { refreshMe } = useAuth();
  const [filter, setFilter] = useState<Filter>('all');
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [counts, setCounts] = useState<Counts | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<NotificationItem | null>(null);
  const [reason, setReason] = useState('');
  const [rejectError, setRejectError] = useState<string | null>(null);

  const load = useCallback(async (which: Filter = filter, pageNo = 1) => {
    try {
      const r = await get<Response>(`/notifications?filter=${which}&page=${pageNo}`);
      setItems((old) => (pageNo === 1 ? r.data : [...old, ...r.data]));
      setCounts(r.meta.counts);
      setPage(r.current_page);
      setLastPage(r.last_page);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load notifications.');
    } finally {
      setLoaded(true);
      setRefreshing(false);
    }
  }, [filter]);
  useFocusEffect(useCallback(() => { load(filter, 1); }, [load, filter]));

  const markRead = async (n: NotificationItem) => {
    if (!n.unread) return;
    setItems((old) => old.map((x) => (x.id === n.id ? { ...x, unread: false } : x)));
    setCounts((c) => (c ? { ...c, unread: Math.max(0, c.unread - 1) } : c));
    await post(`/notifications/${n.id}/read`).catch(() => undefined);
    refreshMe().catch(() => undefined);
  };
  const markAll = async () => {
    await post('/notifications/read-all').catch(() => undefined);
    await Promise.all([load(filter, 1), refreshMe().catch(() => undefined)]);
  };
  const open = (n: NotificationItem) => {
    markRead(n);
    const to = screenFor(n);
    if (to) router.push(to);
  };
  const decide = async (n: NotificationItem, approve: boolean, note?: string) => {
    if (!n.approval_id) return;
    setBusy(n.id);
    try {
      await post(`/approvals/${n.approval_id}/decision`, { approve, note: note || null });
      await post(`/notifications/${n.id}/read`).catch(() => undefined);
      setRejecting(null);
      setReason('');
      await load(filter, 1);
      refreshMe().catch(() => undefined);
    } catch (e) {
      const text = e instanceof ApiError ? e.firstMessage : 'Could not save the decision.';
      if (approve) setError(text); else setRejectError(text); // e.g. a correction needs a reason
    } finally {
      setBusy(null);
    }
  };

  const chips: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'action', label: `Action · ${counts?.needs_action ?? 0}` },
    { key: 'attendance', label: 'Attendance' },
    { key: 'tasks', label: 'Tasks' },
    { key: 'payroll', label: 'Payroll' },
  ];
  const days = ['Today', 'Yesterday', 'Earlier'].map((day) => ({ day, list: items.filter((n) => n.day === day) })).filter((d) => d.list.length);

  return (
    <Screen padded={false} refreshing={refreshing} onRefresh={() => { setRefreshing(true); load(filter, 1); }}>
      <Stack.Screen options={{
        headerStyle: { backgroundColor: navy },
        headerTitle: () => (
          <View>
            <Text style={styles.headTitle}>Notifications</Text>
            <Text style={styles.headSub}>{counts ? `${counts.unread} unread · ${counts.needs_action} need action` : ' '}</Text>
          </View>
        ),
        headerRight: () => (
          <Pressable onPress={markAll} hitSlop={10} accessibilityRole="button" accessibilityLabel="Mark all as read" style={{ paddingHorizontal: 6 }}>
            <MaterialCommunityIcons name="check-all" size={24} color={colors.white} />
          </Pressable>
        ),
      }} />

      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
        {chips.map((c) => {
          const on = filter === c.key;
          return (
            <Pressable key={c.key} onPress={() => { setFilter(c.key); setLoaded(false); }} style={[styles.chip, c.key === 'action' && styles.chipAction, on && (c.key === 'action' ? styles.chipActionOn : styles.chipOn)]} accessibilityRole="button" accessibilityState={{ selected: on }}>
              <Text style={[styles.chipText, c.key === 'action' && { color: '#A3401C' }, on && { color: colors.white }]}>{c.label}</Text>
            </Pressable>
          );
        })}
      </ScrollView>

      <View style={styles.body}>
        {error ? <Notice tone="error" text={error} /> : null}
        {days.length ? days.map(({ day, list }) => (
          <View key={day}>
            <Text style={styles.day}>{day.toUpperCase()}</Text>
            {list.map((n) => (
              <SwipeCard key={n.id} enabled={n.unread} onSwipe={() => markRead(n)}>
                <View style={[styles.card, n.unread && styles.cardUnread]}>
                  <Pressable onPress={() => open(n)} style={styles.cardRow} accessibilityRole="button" accessibilityLabel={`${n.title}${n.unread ? ', unread' : ''}`} accessibilityHint={n.unread ? 'Swipe left to mark read' : undefined}>
                    <View style={[styles.icon, { backgroundColor: n.bg }]}>
                      <MaterialCommunityIcons name={n.app_icon as keyof typeof MaterialCommunityIcons.glyphMap} size={20} color={n.fg} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.title} numberOfLines={3}>
                        {n.parts.map((p, i) => <Text key={i} style={p.bold ? styles.bold : undefined}>{p.text}</Text>)}
                      </Text>
                      {n.quote ? <Text style={styles.quote} numberOfLines={2}>“{n.quote}”</Text> : null}
                      <Text style={styles.meta} numberOfLines={1}>{[n.details, n.decision, n.time].filter(Boolean).join(' · ')}</Text>
                    </View>
                    {n.unread ? <View style={styles.dot} accessibilityLabel="Unread" /> : null}
                  </Pressable>
                  {n.needs_action ? (
                    <View style={styles.actions}>
                      <Pressable onPress={() => decide(n, true)} disabled={busy === n.id} style={[styles.approve, busy === n.id && { opacity: 0.6 }]} accessibilityRole="button">
                        <Text style={styles.approveText}>{busy === n.id ? 'Saving…' : 'Approve'}</Text>
                      </Pressable>
                      <Pressable onPress={() => { setRejecting(n); setReason(''); setRejectError(null); }} disabled={busy === n.id} style={styles.reject} accessibilityRole="button">
                        <Text style={styles.rejectText}>Reject</Text>
                      </Pressable>
                    </View>
                  ) : null}
                </View>
              </SwipeCard>
            ))}
          </View>
        )) : (
          <View style={styles.empty}>
            {loaded ? <MaterialCommunityIcons name="check-circle-outline" size={40} color="#085041" /> : null}
            <Empty text={loaded ? "You're all caught up" : 'Loading…'} />
          </View>
        )}
        {page < lastPage ? <Button title="Load more" onPress={() => load(filter, page + 1)} /> : null}
        {items.some((n) => n.unread) ? <Text style={styles.hint}>Swipe left on a card to mark it read</Text> : null}
      </View>

      <FormSheet visible={!!rejecting} title="Reject request" onClose={() => setRejecting(null)}>
        {rejectError ? <Notice tone="error" text={rejectError} /> : null}
        <Field label="Reason" value={reason} onChangeText={setReason} multiline placeholder="Why is it rejected?" maxLength={1000} />
        <Button title={busy ? 'Saving…' : 'Reject'} tone="danger" loading={!!busy} onPress={() => { if (!reason.trim()) { setRejectError('Add a reason when rejecting.'); return; } if (rejecting) decide(rejecting, false, reason.trim()); }} />
      </FormSheet>
    </Screen>
  );
}

/** Swipe a card to the left to mark it read: a blue "✓ Read" shows behind it. Built on React Native's own PanResponder. */
function SwipeCard({ children, enabled, onSwipe }: { children: React.ReactNode; enabled: boolean; onSwipe: () => void }) {
  const x = useRef(new Animated.Value(0)).current;
  const pan = useRef(PanResponder.create({
    onMoveShouldSetPanResponder: (_, g) => Math.abs(g.dx) > 12 && Math.abs(g.dx) > Math.abs(g.dy) * 1.5,
    onPanResponderMove: (_, g) => x.setValue(Math.min(0, Math.max(-140, g.dx))),
    onPanResponderRelease: (_, g) => {
      if (g.dx < -80) onSwipeRef.current();
      Animated.spring(x, { toValue: 0, useNativeDriver: true }).start();
    },
    onPanResponderTerminate: () => Animated.spring(x, { toValue: 0, useNativeDriver: true }).start(),
  })).current;
  const onSwipeRef = useRef(onSwipe);
  onSwipeRef.current = onSwipe;
  if (!enabled) return <View style={styles.swipeWrap}>{children}</View>;

  return (
    <View style={styles.swipeWrap}>
      <View style={styles.swipeBack}>
        <MaterialCommunityIcons name="check" size={18} color={colors.white} />
        <Text style={styles.swipeText}>Read</Text>
      </View>
      <Animated.View style={{ transform: [{ translateX: x }] }} {...pan.panHandlers}>{children}</Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  headTitle: { color: colors.white, fontSize: 18, fontWeight: '700' },
  headSub: { color: '#C9D3E8', fontSize: 12 },
  chips: { gap: 8, paddingHorizontal: 16, paddingTop: 14, paddingBottom: 4 },
  chip: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 7 },
  chipOn: { backgroundColor: navy, borderColor: navy },
  chipAction: { borderColor: '#D85A30' },
  chipActionOn: { backgroundColor: '#D85A30', borderColor: '#D85A30' },
  chipText: { color: colors.ink2, fontWeight: '600', fontSize: 13 },
  body: { paddingHorizontal: 16, paddingBottom: 24 },
  day: { color: colors.muted, fontSize: 11, fontWeight: '700', letterSpacing: 0.6, marginTop: 14, marginBottom: 8 },
  swipeWrap: { marginBottom: 10, borderRadius: 12, overflow: 'hidden' },
  swipeBack: { ...StyleSheet.absoluteFill, backgroundColor: blue, borderRadius: 12, flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', gap: 6, paddingRight: 22 },
  swipeText: { color: colors.white, fontWeight: '700' },
  card: { backgroundColor: colors.white, borderRadius: 12, borderWidth: 1, borderColor: colors.line, padding: 12 },
  cardUnread: { backgroundColor: '#F6F8FD' },
  cardRow: { flexDirection: 'row', gap: 12, alignItems: 'flex-start' },
  icon: { width: 38, height: 38, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  title: { color: '#4a5568', fontSize: 15, lineHeight: 21 },
  bold: { color: navy, fontWeight: '700' },
  quote: { color: '#4a5568', fontSize: 13, marginTop: 3 },
  meta: { color: colors.faint, fontSize: 12, marginTop: 4 },
  dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: blue, marginTop: 6 },
  actions: { flexDirection: 'row', gap: 10, marginTop: 12 },
  approve: { flex: 1, backgroundColor: '#2EAD5B', borderRadius: 10, paddingVertical: 11, alignItems: 'center' },
  approveText: { color: colors.white, fontWeight: '700', fontSize: 15 },
  reject: { flex: 1, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: 10, paddingVertical: 11, alignItems: 'center' },
  rejectText: { color: colors.ink, fontWeight: '700', fontSize: 15 },
  empty: { alignItems: 'center', paddingTop: 30 },
  hint: { color: colors.faint, fontSize: 12, textAlign: 'center', marginTop: 6 },
});
