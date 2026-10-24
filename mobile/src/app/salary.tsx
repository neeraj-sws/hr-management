import { MaterialCommunityIcons } from '@expo/vector-icons';
import { useFocusEffect } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, Empty, formatDate, money, Notice, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';
import type { SalaryMonth } from '../lib/types';

const navy = '#13254F';
const brown = '#854F0B';
const rupees = (v: number) => `₹${money(v)}`;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const shortMonth = (ym: string) => MONTHS[Number(ym.slice(5, 7)) - 1] ?? ym;

/**
 * My salary: what is still to be paid on top, then one card per month (net, deductions, paid or due).
 * Tap a month for the days paid, every deduction, the payment date and the payslip.
 */
export default function Salary() {
  const [current, setCurrent] = useState<SalaryMonth | null>(null);
  const [history, setHistory] = useState<SalaryMonth[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [year, setYear] = useState<string | null>(null);
  const [dueOnly, setDueOnly] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(() => get<{ current: SalaryMonth | null; history: SalaryMonth[] }>('/my/salary')
    .then((r) => { setCurrent(r.current); setHistory(r.history); setError(null); })
    .catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Could not load your salary.'))
    .finally(() => { setLoaded(true); setRefreshing(false); }), []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const years = useMemo(() => [...new Set([...(current ? [current.month] : []), ...history.map((m) => m.month)].map((m) => m.slice(0, 4)))].sort().reverse(), [current, history]);
  const activeYear = year ?? years[0] ?? String(new Date().getFullYear());
  const due = history.filter((m) => m.remaining > 0);
  const pending = due.reduce((sum, m) => sum + m.remaining, 0);
  const yearMonths = history.filter((m) => m.month.startsWith(activeYear));
  const earned = yearMonths.reduce((sum, m) => sum + m.net, 0);
  const deducted = yearMonths.reduce((sum, m) => sum + m.deductions_total, 0);
  const list = [...(current && current.month.startsWith(activeYear) && !dueOnly ? [current] : []), ...yearMonths.filter((m) => !dueOnly || m.remaining > 0)];

  return (
    <Screen padded={false} refreshing={refreshing} onRefresh={() => { setRefreshing(true); load(); }}>
      <View style={styles.band} />
      <View style={styles.body}>
        <View style={styles.summary}>
          <Text style={styles.summaryLabel}>Pending amount</Text>
          <Text style={[styles.pending, !pending && { color: colors.green }]}>{rupees(pending)}</Text>
          <Text style={styles.summarySub}>{due.length ? `${due.length} month${due.length > 1 ? 's' : ''} due · ${due.map((m) => shortMonth(m.month)).reverse().join(', ')}` : 'Nothing due · all months paid'}</Text>
          <View style={styles.tiles}>
            <View style={styles.tile}><Text style={styles.tileLabel}>Earned in {activeYear}</Text><Text style={styles.tileValue}>{rupees(earned)}</Text></View>
            <View style={styles.tile}><Text style={styles.tileLabel}>Total deductions</Text><Text style={[styles.tileValue, { color: '#A32D2D' }]}>{rupees(deducted)}</Text></View>
          </View>
        </View>

        {error ? <Notice tone="error" text={error} /> : null}

        <View style={styles.filters}>
          {years.map((y) => (
            <Pressable key={y} onPress={() => setYear(y)} style={[styles.chip, activeYear === y && styles.chipOn]} accessibilityRole="button" accessibilityState={{ selected: activeYear === y }}>
              <Text style={[styles.chipText, activeYear === y && styles.chipTextOn]}>{y}</Text>
            </Pressable>
          ))}
          <View style={{ flex: 1 }} />
          <Pressable onPress={() => setDueOnly(!dueOnly)} style={[styles.chip, dueOnly && styles.chipDue]} accessibilityRole="switch" accessibilityState={{ checked: dueOnly }}>
            <MaterialCommunityIcons name={dueOnly ? 'checkbox-marked' : 'checkbox-blank-outline'} size={15} color={dueOnly ? brown : colors.muted} />
            <Text style={[styles.chipText, dueOnly && { color: brown }]}>Due only</Text>
          </Pressable>
        </View>

        {list.length ? list.map((m) => <MonthCard key={m.month} month={m} open={open === m.month} onToggle={() => setOpen(open === m.month ? null : m.month)} />)
          : <View style={styles.card}><Empty text={loaded ? (dueOnly ? 'Nothing due in this year' : 'No salary months yet') : 'Loading…'} /></View>}
      </View>
    </Screen>
  );
}

function MonthCard({ month: m, open, onToggle }: { month: SalaryMonth; open: boolean; onToggle: () => void }) {
  const estimate = m.source === 'estimate';
  const tone = estimate ? { bg: '#E6F1FB', fg: '#185FA5' } : m.remaining > 0 ? { bg: '#FAEEDA', fg: brown } : { bg: '#E1F5EE', fg: '#085041' };
  const daysPaid = m.days_paid ?? m.days.present + m.days.paid_leave;
  const working = Math.max(m.days.working, 1);
  const salaryLine = m.earnings[0];
  const extras = m.earnings.slice(1);

  return (
    <View style={styles.card}>
      <Pressable onPress={onToggle} style={styles.head} accessibilityRole="button" accessibilityState={{ expanded: open }} accessibilityLabel={`${m.label}, net ${rupees(m.net)}, ${m.remaining > 0 ? `due ${rupees(m.remaining)}` : 'paid'}`}>
        <View style={[styles.badge, { backgroundColor: tone.bg }]}>
          <Text style={[styles.badgeMonth, { color: tone.fg }]}>{shortMonth(m.month).toUpperCase()}</Text>
          <Text style={[styles.badgeYear, { color: tone.fg }]}>{m.month.slice(0, 4)}</Text>
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.net}>{rupees(m.net)}</Text>
          <Text style={styles.sub}>{estimate ? 'This month so far' : `Deductions ${rupees(m.deductions_total)}`}</Text>
        </View>
        <View style={{ alignItems: 'flex-end', gap: 6 }}>
          {estimate ? <Pill text="So far" bg="#E6F1FB" fg="#185FA5" />
            : m.remaining > 0 ? <Pill text={`Due ${rupees(m.remaining)}`} bg="#FAEEDA" fg={brown} /> : <Pill text="Paid" bg="#E1F5EE" fg="#085041" />}
          <MaterialCommunityIcons name={open ? 'chevron-up' : 'chevron-down'} size={18} color={colors.faint} />
        </View>
      </Pressable>

      {open ? (
        <View style={styles.details}>
          <View style={styles.attHead}>
            <Text style={styles.attLabel}>Attendance</Text>
            <Text style={styles.attLabel}>{daysPaid}/{m.days.working} days</Text>
          </View>
          <View style={styles.bar}>
            <View style={{ flex: m.days.present / working, backgroundColor: '#2EAD5B' }} />
            {m.days.paid_leave ? <View style={{ flex: m.days.paid_leave / working, backgroundColor: '#378ADD' }} /> : null}
            {m.days.unpaid_leave ? <View style={{ flex: m.days.unpaid_leave / working, backgroundColor: '#E24B4A' }} /> : null}
            <View style={{ flex: Math.max(0, working - daysPaid - m.days.unpaid_leave) / working }} />
          </View>
          <View style={styles.legend}>
            <Legend color="#2EAD5B" text={`Present ${m.days.present}`} />
            {m.days.paid_leave ? <Legend color="#378ADD" text={`Paid leave ${m.days.paid_leave}`} /> : null}
            {m.days.unpaid_leave ? <Legend color="#E24B4A" text={`Unpaid leave ${m.days.unpaid_leave}`} /> : null}
          </View>

          {m.monthly_salary ? <Line label="Monthly salary" amount={rupees(m.monthly_salary)} /> : null}
          {salaryLine ? <Line label={m.per_day_rate ? `Days paid ${daysPaid} × ${rupees(m.per_day_rate)}` : salaryLine[0]} note={m.per_day_rate ? null : salaryLine[2]} amount={rupees(salaryLine[1])} /> : null}
          {extras.map(([label, amount, note], i) => <Line key={`e${i}`} label={label} note={note} amount={`+ ${rupees(amount)}`} />)}
          {m.deductions.map(([label, amount, note], i) => <Line key={`d${i}`} label={label} note={note} amount={amount > 0 ? `− ${rupees(amount)}` : '—'} negative />)}
          <Line label={estimate ? 'Net so far' : 'Net salary'} amount={rupees(m.net)} strong />
          {!estimate && m.paid > 0 ? <Text style={styles.paidOn}>{m.paid_on ? `Paid on ${formatDate(m.paid_on)}` : 'Paid'}{m.remaining > 0 ? ` · ${rupees(m.paid)} so far, ${rupees(m.remaining)} still due` : ''}</Text> : null}
          {estimate ? <Text style={styles.paidOn}>What payroll would pay if the month ended today.</Text> : null}
          {m.payslip_url ? (
            <Pressable onPress={() => Linking.openURL(m.payslip_url as string)} style={styles.download} accessibilityRole="button">
              <MaterialCommunityIcons name="download" size={18} color="#2B5BD7" />
              <Text style={styles.downloadText}>Download payslip</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
    </View>
  );
}

function Pill({ text, bg, fg }: { text: string; bg: string; fg: string }) {
  return <View style={[styles.pill, { backgroundColor: bg }]}><Text style={[styles.pillText, { color: fg }]}>{text}</Text></View>;
}

function Legend({ color, text }: { color: string; text: string }) {
  return <View style={styles.legendItem}><View style={[styles.dot, { backgroundColor: color }]} /><Text style={styles.legendText}>{text}</Text></View>;
}

function Line({ label, amount, note, negative, strong }: { label: string; amount: string; note?: string | null; negative?: boolean; strong?: boolean }) {
  return (
    <View style={[styles.line, strong && styles.lineStrong]}>
      <View style={{ flex: 1, paddingRight: 8 }}>
        <Text style={[styles.lineLabel, strong && styles.bold]}>{label}</Text>
        {note ? <Text style={styles.note}>{note}</Text> : null}
      </View>
      <Text style={[styles.amount, negative && { color: '#A32D2D' }, strong && styles.bold]}>{amount}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  band: { backgroundColor: navy, height: 70 },
  body: { paddingHorizontal: 16, marginTop: -58, gap: 12, paddingBottom: 16 },
  summary: { backgroundColor: colors.white, borderRadius: 12, padding: 16, borderWidth: 1, borderColor: colors.line },
  summaryLabel: { color: colors.muted, fontSize: 13, fontWeight: '600' },
  pending: { color: brown, fontSize: 28, fontWeight: '800', marginTop: 2, fontVariant: ['tabular-nums'] },
  summarySub: { color: colors.muted, fontSize: 13, marginTop: 2 },
  tiles: { flexDirection: 'row', gap: 10, marginTop: 14 },
  tile: { flex: 1, backgroundColor: colors.canvas, borderRadius: 10, padding: 10 },
  tileLabel: { color: colors.muted, fontSize: 12 },
  tileValue: { color: navy, fontSize: 16, fontWeight: '800', marginTop: 2, fontVariant: ['tabular-nums'] },
  filters: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 5, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 7 },
  chipOn: { backgroundColor: navy, borderColor: navy },
  chipDue: { borderColor: brown, backgroundColor: '#FAEEDA' },
  chipText: { color: colors.ink2, fontWeight: '600', fontSize: 13 },
  chipTextOn: { color: colors.white },
  card: { backgroundColor: colors.white, borderRadius: 12, borderWidth: 1, borderColor: colors.line, padding: 12 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  badge: { width: 48, height: 48, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  badgeMonth: { fontWeight: '800', fontSize: 13 },
  badgeYear: { fontSize: 10, fontWeight: '600' },
  net: { color: navy, fontSize: 16, fontWeight: '800', fontVariant: ['tabular-nums'] },
  sub: { color: colors.muted, fontSize: 12, marginTop: 2 },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  pillText: { fontSize: 12, fontWeight: '700' },
  details: { marginTop: 12, borderTopWidth: 1, borderTopColor: colors.line, paddingTop: 12 },
  attHead: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 },
  attLabel: { color: colors.ink2, fontSize: 13, fontWeight: '600' },
  bar: { flexDirection: 'row', height: 8, borderRadius: 99, overflow: 'hidden', backgroundColor: '#EDF0F5' },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, marginTop: 6, marginBottom: 8 },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  legendText: { color: colors.muted, fontSize: 12 },
  line: { flexDirection: 'row', alignItems: 'flex-start', paddingVertical: 7, borderBottomWidth: 1, borderBottomColor: colors.line },
  lineStrong: { backgroundColor: colors.canvas, paddingHorizontal: 8, borderRadius: 8, borderBottomWidth: 0, marginTop: 6 },
  lineLabel: { color: colors.ink2, fontSize: 14 },
  note: { color: colors.muted, fontSize: 12, marginTop: 1 },
  amount: { color: colors.ink, fontSize: 14, fontVariant: ['tabular-nums'] },
  bold: { fontWeight: '800', color: navy },
  paidOn: { color: colors.muted, fontSize: 12, marginTop: 8 },
  download: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 12, borderWidth: 1, borderColor: '#2B5BD7', borderRadius: 10, paddingVertical: 10 },
  downloadText: { color: '#2B5BD7', fontWeight: '700', fontSize: 14 },
});
