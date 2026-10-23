import { ReactNode } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, TextInputProps, View, ViewStyle, KeyboardAvoidingView } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

export const colors = {
  navy: '#14284b', blue: '#3461d1', blueSoft: '#ecf2ff', ink: '#172033', ink2: '#334258', muted: '#6b778a', faint: '#94a0b2',
  line: '#e4e9f0', canvas: '#f4f6fa', white: '#ffffff', green: '#12804a', greenSoft: '#e6f6ee', amber: '#9a6412', amberSoft: '#fff4dc',
  red: '#bd3446', redSoft: '#ffedf0', violet: '#6b4bd1', violetSoft: '#f1edff',
};

export function Screen({ children, refreshing, onRefresh, padded = true }: { children: ReactNode; refreshing?: boolean; onRefresh?: () => void; padded?: boolean }) {
  // Extra scroll room at the end so the last item is never under the phone's navigation buttons.
  const { bottom } = useSafeAreaInsets();
  return (
    <SafeAreaView style={styles.safe} edges={['left', 'right']}>
      {/* The page moves up with the keyboard and scrolls, so no field or button is left behind it. */}
      <KeyboardAvoidingView behavior="padding" style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={padded ? [styles.content, { paddingBottom: 40 + bottom }] : { paddingBottom: bottom }} refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} /> : undefined} keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag">
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

export function Card({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function H1({ children }: { children: ReactNode }) {
  return <Text style={styles.h1}>{children}</Text>;
}

export function H2({ children }: { children: ReactNode }) {
  return <Text style={styles.h2}>{children}</Text>;
}

export function Muted({ children, style }: { children: ReactNode; style?: object }) {
  return <Text style={[styles.muted, style]}>{children}</Text>;
}

export function Eyebrow({ children }: { children: ReactNode }) {
  return <Text style={styles.eyebrow}>{children}</Text>;
}

type ButtonTone = 'primary' | 'success' | 'danger' | 'default';
export function Button({ title, onPress, tone = 'default', disabled, loading, small }: { title: string; onPress: () => void; tone?: ButtonTone; disabled?: boolean; loading?: boolean; small?: boolean }) {
  const toneStyle = { primary: styles.btnPrimary, success: styles.btnSuccess, danger: styles.btnDanger, default: styles.btnDefault }[tone];
  const textStyle = tone === 'default' ? styles.btnTextDefault : tone === 'danger' ? styles.btnTextDanger : styles.btnText;
  return (
    <Pressable accessibilityRole="button" onPress={onPress} disabled={disabled || loading} style={({ pressed }) => [styles.btn, small && styles.btnSmall, toneStyle, (disabled || loading) && styles.btnDisabled, pressed && { opacity: 0.85 }]}>
      {loading ? <ActivityIndicator color={tone === 'default' || tone === 'danger' ? colors.blue : colors.white} /> : <Text style={[textStyle, small && { fontSize: 13 }]}>{title}</Text>}
    </Pressable>
  );
}

const badgeTones: Record<string, [string, string]> = {
  green: [colors.greenSoft, colors.green], amber: [colors.amberSoft, colors.amber], red: [colors.redSoft, colors.red], blue: [colors.blueSoft, colors.blue], violet: [colors.violetSoft, colors.violet], gray: ['#eef1f5', '#5d6a7c'],
};
const statusTone: Record<string, string> = {
  approved: 'green', completed: 'green', paid: 'green', working: 'green', present: 'green',
  pending: 'amber', on_break: 'amber', in_progress: 'amber', late: 'amber', half_day: 'amber', queued: 'amber',
  rejected: 'red', overdue: 'red', absent: 'red', conflict: 'red', failed: 'red',
  todo: 'blue', on_leave: 'violet', high: 'amber', urgent: 'red', cancelled: 'gray',
};
export function Badge({ label, tone }: { label: string; tone?: keyof typeof badgeTones }) {
  const [bg, fg] = badgeTones[tone ?? statusTone[label] ?? 'gray'];
  return (
    <View style={[styles.badge, { backgroundColor: bg }]}>
      <Text style={[styles.badgeText, { color: fg }]}>{label.replace(/_/g, ' ')}</Text>
    </View>
  );
}

export function Field({ label, error, ...props }: TextInputProps & { label: string; error?: string }) {
  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={styles.label}>{label}</Text>
      <TextInput placeholderTextColor={colors.faint} style={[styles.input, error ? { borderColor: colors.red } : null]} {...props} />
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

export function Chips<T extends string | number>({ options, value, onChange }: { options: { label: string; value: T }[]; value: T | null; onChange: (value: T) => void }) {
  return (
    <View style={styles.chips}>
      {options.map((option) => (
        <Pressable key={String(option.value)} onPress={() => onChange(option.value)} style={[styles.chip, value === option.value && styles.chipActive]}>
          <Text style={[styles.chipText, value === option.value && { color: colors.white }]}>{option.label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

export function Notice({ text, tone = 'info' }: { text: string; tone?: 'info' | 'error' | 'success' }) {
  const palette = { info: [colors.blueSoft, '#24448f'], error: [colors.redSoft, '#8f1f30'], success: [colors.greenSoft, '#0e5f37'] }[tone];
  return <View style={[styles.notice, { backgroundColor: palette[0] }]}><Text style={{ color: palette[1], fontSize: 13 }}>{text}</Text></View>;
}

export function Empty({ text }: { text: string }) {
  return <Text style={styles.empty}>{text}</Text>;
}

export function Row({ title, subtitle, right, onPress }: { title: string; subtitle?: string; right?: ReactNode; onPress?: () => void }) {
  return (
    <Pressable onPress={onPress} disabled={!onPress} style={styles.row}>
      <View style={{ flex: 1, paddingRight: 10 }}>
        <Text style={styles.rowTitle}>{title}</Text>
        {subtitle ? <Text style={styles.rowSub}>{subtitle}</Text> : null}
      </View>
      {right}
    </Pressable>
  );
}

export function Stat({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <View style={styles.stat}>
      <Text style={styles.statLabel}>{label}</Text>
      <Text style={styles.statValue}>{value}</Text>
      {hint ? <Text style={styles.statHint}>{hint}</Text> : null}
    </View>
  );
}

/** Asks before an action that cannot be undone; resolves true when the user agrees. */
export function confirmAction(title: string, message: string, confirmLabel = 'Yes'): Promise<boolean> {
  if (Platform.OS === 'web') return Promise.resolve(window.confirm(`${title}

${message}`));
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'No', style: 'cancel', onPress: () => resolve(false) },
      { text: confirmLabel, style: 'destructive', onPress: () => resolve(true) },
    ], { cancelable: true, onDismiss: () => resolve(false) });
  });
}

/** HH:MM:SS, the same format as the website and the desktop app. */
export const formatDuration = (seconds: number) => {
  const s = Math.max(0, Math.floor(seconds || 0));
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(Math.floor(s / 3600))}:${p(Math.floor((s % 3600) / 60))}:${p(s % 60)}`;
};
export const formatTime = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—');
export const formatDate = (date: string | null | undefined) => (date ? new Date(date.length === 10 ? `${date}T00:00:00` : date).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
export const money = (value: number) => Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  content: { padding: 16, paddingBottom: 40, gap: 14 },
  card: { backgroundColor: colors.white, borderRadius: 14, borderWidth: 1, borderColor: colors.line, padding: 16 },
  h1: { fontSize: 22, fontWeight: '800', color: colors.ink },
  h2: { fontSize: 16, fontWeight: '700', color: colors.ink, marginBottom: 8 },
  muted: { color: colors.muted, fontSize: 13 },
  eyebrow: { color: colors.faint, fontSize: 11, fontWeight: '800', letterSpacing: 1, textTransform: 'uppercase', marginBottom: 4 },
  btn: { borderRadius: 10, paddingVertical: 12, paddingHorizontal: 16, alignItems: 'center', justifyContent: 'center', borderWidth: 1 },
  btnSmall: { paddingVertical: 7, paddingHorizontal: 12 },
  btnPrimary: { backgroundColor: colors.blue, borderColor: colors.blue },
  btnSuccess: { backgroundColor: colors.green, borderColor: colors.green },
  btnDanger: { backgroundColor: colors.white, borderColor: '#f0c3cb' },
  btnDefault: { backgroundColor: colors.white, borderColor: '#d6dde8' },
  btnDisabled: { opacity: 0.5 },
  btnText: { color: colors.white, fontWeight: '700', fontSize: 15 },
  btnTextDefault: { color: colors.ink2, fontWeight: '700', fontSize: 15 },
  btnTextDanger: { color: colors.red, fontWeight: '700', fontSize: 15 },
  badge: { borderRadius: 20, paddingHorizontal: 9, paddingVertical: 3, alignSelf: 'flex-start' },
  badgeText: { fontSize: 11, fontWeight: '700', textTransform: 'capitalize' },
  label: { color: colors.ink2, fontSize: 13, fontWeight: '600', marginBottom: 5 },
  input: { borderWidth: 1, borderColor: '#d8dfe9', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 10, fontSize: 15, color: colors.ink, backgroundColor: colors.white },
  error: { color: colors.red, fontSize: 12, marginTop: 4 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 },
  chip: { borderWidth: 1, borderColor: '#d6dde8', borderRadius: 20, paddingHorizontal: 12, paddingVertical: 7, backgroundColor: colors.white },
  chipActive: { backgroundColor: colors.blue, borderColor: colors.blue },
  chipText: { color: colors.ink2, fontSize: 13, fontWeight: '600' },
  notice: { borderRadius: 10, padding: 12 },
  empty: { color: colors.muted, textAlign: 'center', paddingVertical: 18 },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: '#eef1f6' },
  rowTitle: { color: colors.ink2, fontWeight: '600', fontSize: 14 },
  rowSub: { color: colors.faint, fontSize: 12, marginTop: 2 },
  stat: { flex: 1, minWidth: 140, backgroundColor: colors.white, borderRadius: 14, borderWidth: 1, borderColor: colors.line, padding: 14 },
  statLabel: { color: colors.muted, fontSize: 12, fontWeight: '600' },
  statValue: { color: colors.navy, fontSize: 22, fontWeight: '800', marginTop: 2 },
  statHint: { color: colors.faint, fontSize: 11 },
});
