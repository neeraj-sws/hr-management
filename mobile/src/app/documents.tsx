import { useCallback, useEffect, useState } from 'react';
import { Linking } from 'react-native';
import { Badge, Card, Empty, formatDate, H2, Muted, Notice, Row, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';

type Document = { id: number; name: string; category: string; expires_on: string | null; expired: boolean; url: string };
type Asset = { id: number; tag: string; name: string; status: string; assigned_on: string | null; returned_on: string | null };

export default function DocumentsScreen() {
  const [documents, setDocuments] = useState<Document[]>([]);
  const [assets, setAssets] = useState<Asset[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [d, a] = await Promise.all([get<{ data: Document[] }>('/my/documents'), get<{ data: Asset[] }>('/my/assets')]);
      setDocuments(d.data);
      setAssets(a.data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load documents.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  // Links are signed for you and expire in 5 minutes, so fetch fresh ones before opening.
  const open = async (id: number) => {
    try {
      const fresh = (await get<{ data: Document[] }>('/my/documents')).data.find((d) => d.id === id);
      if (fresh) await Linking.openURL(fresh.url);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not open the document.');
    }
  };

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      <Card>
        <H2>My documents</H2>
        {documents.length ? documents.map((d) => (
          <Row key={d.id} title={d.name} subtitle={`${d.category}${d.expires_on ? ` · expires ${formatDate(d.expires_on)}` : ''}`} onPress={() => open(d.id)} right={d.expired ? <Badge label="expired" tone="red" /> : <Muted>Open ›</Muted>} />
        )) : <Empty text={loaded ? 'No documents' : 'Loading…'} />}
      </Card>
      <Card>
        <H2>My assets</H2>
        {assets.length ? assets.map((a) => (
          <Row key={a.id} title={`${a.name}`} subtitle={`${a.tag}${a.assigned_on ? ` · since ${formatDate(a.assigned_on)}` : ''}${a.returned_on ? ` · returned ${formatDate(a.returned_on)}` : ''}`} right={<Badge label={a.status.replace('_', ' ')} tone={a.status === 'assigned' ? 'blue' : a.status === 'returned' ? 'green' : 'red'} />} />
        )) : <Empty text={loaded ? 'No assets assigned' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
