import { MaterialCommunityIcons } from '@expo/vector-icons';
import { Stack, useRouter, type Href } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors } from '../components/ui';
import { getBaseUrl, get } from '../lib/api';
import { useAuth } from '../lib/auth';
import { store } from '../lib/storage';

const navy = '#13254F';
type Item = { id: number | string; title: string; subtitle?: string; url?: string | null; initials?: string; status?: { key: string; label: string; color?: string } | null };
type Group = { label: string; total: number; items: Item[] };
type Result = { query: string; groups: Record<string, Group>; did_you_mean: string | null };
const GROUP_ICON: Record<string, keyof typeof MaterialCommunityIcons.glyphMap> = { people: 'account-outline', tasks: 'checkbox-marked-outline', projects: 'folder-outline', status: 'message-text-outline', notes: 'note-text-outline', pages: 'file-document-outline' };

/** The screen in the app for a result; anything else opens on the website. */
function target(group: string, item: Item): Href | string | null {
  if (group === 'tasks') return { pathname: '/task', params: { id: String(item.id) } };
  if (group === 'projects') return '/(tabs)/tasks';
  if (group === 'status') return '/daily-status';
  if (group === 'people') return '/team';
  const path = (item.url ?? '').toLowerCase();
  const map: [string, Href][] = [['salary', '/salary'], ['payslip', '/payslips'], ['attendance/correction', '/corrections'], ['attendance', '/(tabs)/attendance'], ['leave', '/(tabs)/leave'], ['daily-status', '/daily-status'], ['task', '/(tabs)/tasks'], ['document', '/documents'], ['notification', '/notifications'], ['holiday', '/holidays'], ['training', '/training'], ['expense', '/expenses'], ['approval', '/approvals'], ['announcement', '/announcements'], ['performance', '/performance']];
  const hit = map.find(([key]) => path.includes(key));
  return hit ? hit[1] : item.url ?? null;
}

/** Full-screen search: recent searches, quick actions, then results grouped like on the website. */
export default function Search() {
  const router = useRouter();
  const { me } = useAuth();
  const { top } = useSafeAreaInsets();
  const [q, setQ] = useState('');
  const [type, setType] = useState('all');
  const [result, setResult] = useState<Result | null>(null);
  const [loading, setLoading] = useState(false);
  const [recent, setRecent] = useState<string[]>([]);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const seq = useRef(0);
  const key = `sws.search.recent.${me?.id ?? 0}`;

  useEffect(() => { store.getJson<string[]>(key, []).then(setRecent); }, [key]);

  const run = (text: string, kind = type) => {
    if (timer.current) clearTimeout(timer.current);
    if (text.trim().length < 2) { setResult(null); setLoading(false); return; }
    timer.current = setTimeout(async () => {
      const mine = ++seq.current; // only the latest answer is shown
      setLoading(true);
      try {
        const r = await get<Result>(`/search?q=${encodeURIComponent(text.trim())}&type=${kind}&limit=${kind === 'all' ? 5 : 20}`);
        if (mine === seq.current) setResult(r);
      } catch {
        if (mine === seq.current) setResult({ query: text, groups: {}, did_you_mean: null });
      } finally {
        if (mine === seq.current) setLoading(false);
      }
    }, 300);
  };
  const remember = async () => {
    const t = q.trim();
    if (t.length < 2) return;
    const next = [t, ...recent.filter((r) => r.toLowerCase() !== t.toLowerCase())].slice(0, 5);
    setRecent(next);
    await store.setJson(key, next);
  };
  const forget = async (index: number) => {
    const next = recent.filter((_, i) => i !== index);
    setRecent(next);
    await store.setJson(key, next);
  };
  const open = async (group: string, item: Item) => {
    await remember();
    Keyboard.dismiss();
    const to = target(group, item);
    if (typeof to === 'string' && to.startsWith('/') && !to.startsWith('/(') && !['/salary', '/payslips', '/corrections', '/daily-status', '/documents', '/notifications', '/holidays', '/training', '/expenses', '/approvals', '/announcements', '/performance', '/team'].includes(to)) {
      await Linking.openURL(`${getBaseUrl()}${to}`);
    } else if (to) {
      router.push(to as Href);
    }
  };

  const groups = result ? Object.entries(result.groups) : [];
  const total = groups.reduce((n, [, g]) => n + g.total, 0);
  const quick: { label: string; icon: keyof typeof MaterialCommunityIcons.glyphMap; bg: string; fg: string; to: Href }[] = [
    { label: 'Apply leave', icon: 'calendar-plus', bg: '#E1F5EE', fg: '#085041', to: '/(tabs)/leave' },
    { label: 'Submit daily status', icon: 'message-plus-outline', bg: '#E6F1FB', fg: '#185FA5', to: '/daily-status' },
    { label: 'Upload document', icon: 'file-upload-outline', bg: '#EEEDFE', fg: '#3C3489', to: '/documents' },
  ];

  return (
    <View style={{ flex: 1, backgroundColor: colors.white }}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={[styles.head, { paddingTop: top + 8 }]}>
        <Pressable onPress={() => router.back()} hitSlop={10} accessibilityRole="button" accessibilityLabel="Back"><MaterialCommunityIcons name="arrow-left" size={24} color={colors.white} /></Pressable>
        <View style={styles.box}>
          <MaterialCommunityIcons name="magnify" size={20} color={colors.muted} />
          <TextInput value={q} onChangeText={(t) => { setQ(t); run(t); }} placeholder="Search people, tasks, pages…" placeholderTextColor={colors.faint} style={styles.input} autoFocus returnKeyType="search" onSubmitEditing={remember} accessibilityLabel="Search" />
          {loading ? <ActivityIndicator size="small" color={colors.blue} /> : q ? <Pressable onPress={() => { setQ(''); run(''); }} hitSlop={8} accessibilityLabel="Clear search"><MaterialCommunityIcons name="close-circle" size={18} color={colors.faint} /></Pressable> : null}
        </View>
      </View>

      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: 30 }}>
        {q.trim().length < 2 ? (
          <View>
            {recent.length ? (
              <View style={styles.group}>
                <View style={styles.groupHead}><Text style={styles.groupLabel}>RECENT</Text><Text style={styles.link} onPress={async () => { setRecent([]); await store.setJson(key, []); }}>Clear</Text></View>
                {recent.map((r, i) => (
                  <Pressable key={r} style={styles.row} onPress={() => { setQ(r); run(r); }}>
                    <MaterialCommunityIcons name="history" size={20} color={colors.muted} />
                    <Text style={[styles.title, { flex: 1 }]}>{r}</Text>
                    <Pressable onPress={() => forget(i)} hitSlop={8} accessibilityLabel={`Remove ${r}`}><MaterialCommunityIcons name="close" size={16} color={colors.faint} /></Pressable>
                  </Pressable>
                ))}
              </View>
            ) : null}
            <View style={styles.group}>
              <View style={styles.groupHead}><Text style={styles.groupLabel}>QUICK ACTIONS</Text></View>
              {quick.map((a) => (
                <Pressable key={a.label} style={styles.row} onPress={() => router.push(a.to)}>
                  <View style={[styles.icon, { backgroundColor: a.bg }]}><MaterialCommunityIcons name={a.icon} size={18} color={a.fg} /></View>
                  <Text style={styles.title}>{a.label}</Text>
                </Pressable>
              ))}
            </View>
          </View>
        ) : (
          <View>
            {groups.length ? (
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
                <Pressable onPress={() => { setType('all'); run(q, 'all'); }} style={[styles.chip, type === 'all' && styles.chipOn]}><Text style={[styles.chipText, type === 'all' && { color: colors.white }]}>All · {total}</Text></Pressable>
                {groups.map(([g, group]) => (
                  <Pressable key={g} onPress={() => { setType(g); run(q, g); }} style={[styles.chip, type === g && styles.chipOn]}><Text style={[styles.chipText, type === g && { color: colors.white }]}>{group.label} · {group.total}</Text></Pressable>
                ))}
              </ScrollView>
            ) : null}
            {groups.map(([g, group]) => (
              <View key={g} style={styles.group}>
                <View style={styles.groupHead}>
                  <Text style={styles.groupLabel}>{group.label.toUpperCase()}</Text>
                  {group.total > group.items.length && type === 'all' ? <Text style={styles.link} onPress={() => { setType(g); run(q, g); }}>See all {group.total}</Text> : null}
                </View>
                {group.items.map((item) => (
                  <Pressable key={`${g}-${item.id}`} style={styles.row} onPress={() => open(g, item)} accessibilityRole="button">
                    {g === 'people'
                      ? <View style={styles.avatar}><Text style={styles.avatarText}>{item.initials}</Text></View>
                      : <View style={[styles.icon, { backgroundColor: '#EEF1F5' }]}><MaterialCommunityIcons name={GROUP_ICON[g] ?? 'file-outline'} size={18} color="#334258" /></View>}
                    <View style={{ flex: 1, minWidth: 0 }}>
                      <Text style={styles.title} numberOfLines={1}><Highlight text={item.title} q={q} /></Text>
                      {item.subtitle ? <Text style={styles.sub} numberOfLines={1}><Highlight text={item.subtitle} q={q} /></Text> : null}
                    </View>
                    {item.status ? <Text style={[styles.pill, item.status.color ? { color: item.status.color } : null]}>{item.status.label}</Text> : null}
                  </Pressable>
                ))}
              </View>
            ))}
            {result && !groups.length && !loading ? (
              <View style={styles.empty}>
                <MaterialCommunityIcons name="magnify-close" size={36} color={colors.faint} />
                <Text style={styles.emptyTitle}>No results for “{result.query}”</Text>
                {result.did_you_mean ? <Text style={styles.sub}>Did you mean: <Text style={styles.link} onPress={() => { setQ(result.did_you_mean as string); run(result.did_you_mean as string); }}>{result.did_you_mean}</Text>?</Text> : null}
              </View>
            ) : null}
          </View>
        )}
      </ScrollView>
    </View>
  );
}

/** The typed letters on a light yellow background. */
function Highlight({ text, q }: { text: string; q: string }) {
  const t = q.trim();
  if (t.length < 2) return <>{text}</>;
  const parts = text.split(new RegExp(`(${t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'));
  return <>{parts.map((p, i) => (p.toLowerCase() === t.toLowerCase() ? <Text key={i} style={{ backgroundColor: '#FAEEDA' }}>{p}</Text> : p))}</>;
}

const styles = StyleSheet.create({
  head: { backgroundColor: navy, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 14, paddingBottom: 12 },
  box: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: colors.white, borderRadius: 10, paddingHorizontal: 10, height: 42 },
  input: { flex: 1, fontSize: 15, color: colors.ink, paddingVertical: 0 },
  chips: { gap: 8, paddingHorizontal: 16, paddingTop: 12 },
  chip: { borderWidth: 1, borderColor: colors.line, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 6 },
  chipOn: { backgroundColor: navy, borderColor: navy },
  chipText: { color: colors.ink2, fontWeight: '600', fontSize: 13 },
  group: { paddingTop: 10 },
  groupHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 16, paddingBottom: 4 },
  groupLabel: { color: colors.muted, fontSize: 11, fontWeight: '800', letterSpacing: 0.6 },
  link: { color: '#2B5BD7', fontWeight: '700', fontSize: 13 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
  icon: { width: 34, height: 34, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  avatar: { width: 36, height: 36, borderRadius: 18, backgroundColor: '#EEF0FB', alignItems: 'center', justifyContent: 'center' },
  avatarText: { color: '#3C3489', fontWeight: '800', fontSize: 12 },
  title: { color: navy, fontSize: 15, fontWeight: '600' },
  sub: { color: colors.muted, fontSize: 12, marginTop: 1 },
  pill: { fontSize: 12, fontWeight: '700', color: colors.ink2, backgroundColor: '#F1F4F9', borderRadius: 6, paddingHorizontal: 8, paddingVertical: 2, overflow: 'hidden' },
  empty: { alignItems: 'center', paddingTop: 40, gap: 6 },
  emptyTitle: { color: navy, fontWeight: '700', fontSize: 15 },
});
