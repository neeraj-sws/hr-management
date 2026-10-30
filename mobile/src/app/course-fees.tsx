import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';
import { Badge, Card, colors, Empty, formatDate, H2, money, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';

type Enrollment = {
  id: number; course: string; status: string; status_label: string; starts_on: string | null; ends_on: string | null;
  fee: number; discount: number; payable: number; paid: number; due: number; installments: number;
  payments: { date: string | null; amount: number; receipt: string | null; method: string | null; installment: number | null }[];
};

/** A trainee's courses (instead of salary): the fee, discount, paid and still due, and every payment with its receipt. */
export default function CourseFees() {
  const [items, setItems] = useState<Enrollment[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useFocusEffect(useCallback(() => {
    get<{ data: Enrollment[] }>('/my/course-fees')
      .then((r) => { setItems(r.data); setError(null); })
      .catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Could not load your courses.'))
      .finally(() => setLoaded(true));
  }, []));

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      {items.length ? items.map((e) => (
        <Card key={e.id}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
            <View style={{ flex: 1 }}><H2>{e.course}</H2></View>
            <Badge label={e.status_label} tone={e.status === 'completed' ? 'green' : e.status === 'dropped' ? 'gray' : 'blue'} />
          </View>
          {e.starts_on ? <Muted>{formatDate(e.starts_on)}{e.ends_on ? ` – ${formatDate(e.ends_on)}` : ''}</Muted> : null}
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginVertical: 10 }}>
            <Figure label="Course fee" value={money(e.fee)} />
            {e.discount > 0 ? <Figure label="Discount" value={`− ${money(e.discount)}`} /> : null}
            <Figure label="To pay" value={money(e.payable)} />
            <Figure label="Paid" value={money(e.paid)} color={colors.green} />
            <Figure label="Still due" value={money(e.due)} color={e.due > 0 ? colors.red : colors.green} />
          </View>
          {e.installments > 1 ? <Muted style={{ marginBottom: 6 }}>In {e.installments} installments</Muted> : null}
          {e.payments.length ? e.payments.map((p, i) => (
            <Row key={i} title={`${money(p.amount)}${p.installment ? ` · installment ${p.installment}` : ''}`} subtitle={`${p.date ? formatDate(p.date) : ''}${p.method ? ` · ${p.method}` : ''}${p.receipt ? ` · receipt ${p.receipt}` : ''}`} />
          )) : <Muted>No payment yet.</Muted>}
        </Card>
      )) : <Card><Empty text={loaded ? 'No course yet. Ask HR if this looks wrong.' : 'Loading…'} /></Card>}
    </Screen>
  );
}

function Figure({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <View style={{ minWidth: '45%', flexGrow: 1, backgroundColor: colors.canvas, borderRadius: 10, padding: 10 }}>
      <Text style={{ fontSize: 11, color: colors.muted, fontWeight: '700', textTransform: 'uppercase' }}>{label}</Text>
      <Text style={{ fontSize: 16, fontWeight: '800', color: color ?? colors.ink }}>{value}</Text>
    </View>
  );
}
