import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { Badge, Button, Card, colors, Empty, H2, money, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';
import type { SalaryMonth } from '../lib/types';

/** My salary: this month so far, then every month with what was earned, each deduction and what was paid. */
export default function Salary() {
  const router = useRouter();
  const [current, setCurrent] = useState<SalaryMonth | null>(null);
  const [history, setHistory] = useState<SalaryMonth[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  useFocusEffect(useCallback(() => {
    get<{ current: SalaryMonth | null; history: SalaryMonth[] }>('/my/salary')
      .then((r) => { setCurrent(r.current); setHistory(r.history); setError(null); })
      .catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Could not load your salary.'))
      .finally(() => setLoaded(true));
  }, []));

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      <Card>
        <H2>This month so far</H2>
        {current ? <MonthDetails month={current} /> : <Empty text={loaded ? 'No salary set up yet' : 'Loading…'} />}
        {current ? <Muted style={{ marginTop: 8 }}>What payroll would pay if the month ended today. It changes as you work this month.</Muted> : null}
      </Card>
      <Card>
        <H2>Salary history</H2>
        {history.length ? history.map((m) => (
          <View key={m.month} style={styles.month}>
            <Pressable onPress={() => setOpen(open === m.month ? null : m.month)} style={styles.head} accessibilityRole="button" accessibilityLabel={`${m.label}, net ${money(m.net)}`}>
              <View style={{ flex: 1 }}>
                <Text style={styles.title}>{m.label}</Text>
                <Muted>Net {money(m.net)} · deductions {money(m.deductions_total)}</Muted>
              </View>
              <View style={{ alignItems: 'flex-end', gap: 4 }}>
                <Badge label={m.remaining > 0 ? `due ${money(m.remaining)}` : 'paid'} tone={m.remaining > 0 ? 'amber' : 'green'} />
                <Text style={styles.chev}>{open === m.month ? '▲' : '▼'}</Text>
              </View>
            </Pressable>
            {open === m.month ? (
              <View style={{ paddingTop: 8 }}>
                <MonthDetails month={m} />
                {m.payslip_id ? <View style={{ marginTop: 8 }}><Button small title="Open payslips" onPress={() => router.push('/payslips')} /></View> : null}
              </View>
            ) : null}
          </View>
        )) : <Empty text={loaded ? 'No salary months yet' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}

function MonthDetails({ month: m }: { month: SalaryMonth }) {
  const source = m.source === 'estimate' ? `${m.label} · up to today` : m.source === 'old_sheet' ? `${m.label} · old salary sheet` : `${m.label} · payroll ${m.status}`;
  return (
    <View>
      <Muted style={{ marginBottom: 6 }}>{source} · {m.days.present} of {m.days.working} working days present{m.days.paid_leave ? ` · ${m.days.paid_leave} paid leave` : ''}{m.days.unpaid_leave ? ` · ${m.days.unpaid_leave} unpaid leave` : ''}</Muted>
      {m.monthly_salary ? <Line label="Monthly salary" amount={money(m.monthly_salary)} muted /> : null}
      {m.earnings.map(([label, amount, note], i) => <Line key={`e${i}`} label={label} note={note} amount={`+ ${money(amount)}`} />)}
      {m.deductions.length ? m.deductions.map(([label, amount, note], i) => <Line key={`d${i}`} label={label} note={note} amount={amount > 0 ? `− ${money(amount)}` : '—'} negative />) : <Line label="Deductions" amount="none" muted />}
      <Line label={m.source === 'estimate' ? 'Net so far' : 'Net salary'} amount={money(m.net)} strong />
      {m.source !== 'estimate' ? <Line label="Paid" amount={money(m.paid)} /> : null}
      {m.source !== 'estimate' && m.remaining > 0 ? <Line label="Still due" amount={money(m.remaining)} strong /> : null}
    </View>
  );
}

function Line({ label, amount, note, negative, strong, muted }: { label: string; amount: string; note?: string | null; negative?: boolean; strong?: boolean; muted?: boolean }) {
  return (
    <View style={[styles.line, strong && styles.lineStrong]}>
      <View style={{ flex: 1, paddingRight: 8 }}>
        <Text style={[styles.lineLabel, strong && styles.bold, muted && { color: colors.muted }]}>{label}</Text>
        {note ? <Text style={styles.note}>{note}</Text> : null}
      </View>
      <Text style={[styles.amount, negative && { color: colors.red }, strong && styles.bold]}>{amount}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  month: { borderTopWidth: 1, borderTopColor: colors.line, paddingVertical: 10 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  title: { fontSize: 15, fontWeight: '700', color: colors.ink },
  chev: { color: colors.faint, fontSize: 11 },
  line: { flexDirection: 'row', alignItems: 'flex-start', paddingVertical: 6, borderBottomWidth: 1, borderBottomColor: colors.line },
  lineStrong: { backgroundColor: colors.canvas, paddingHorizontal: 6, borderRadius: 6, borderBottomWidth: 0, marginTop: 4 },
  lineLabel: { color: colors.ink2, fontSize: 14 },
  note: { color: colors.muted, fontSize: 12, marginTop: 1 },
  amount: { color: colors.ink, fontSize: 14, fontVariant: ['tabular-nums'] },
  bold: { fontWeight: '800', color: colors.ink },
});
