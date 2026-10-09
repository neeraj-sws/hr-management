import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Badge, Card, Empty, formatDate, money, Notice, Row, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';
import type { Paginated, Payslip } from '../lib/types';

export default function Payslips() {
  const [items, setItems] = useState<Payslip[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  useFocusEffect(useCallback(() => {
    get<Paginated<Payslip>>('/payslips')
      .then((r) => { setItems(r.data); setError(null); })
      .catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Could not load payslips.'))
      .finally(() => setLoaded(true));
  }, []));

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      <Card>
        {items.length ? items.map((p) => (
          <Row key={p.id} title={`${p.reference} · ${formatDate(p.period_start)} – ${formatDate(p.period_end)}`} subtitle={`Net ${money(p.net)} · Paid ${money(p.paid)}`} right={<Badge label={p.balance > 0 ? 'partially paid' : 'paid'} tone={p.balance > 0 ? 'amber' : 'green'} />} />
        )) : <Empty text={loaded ? 'No payslips yet' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
