import { useCallback, useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { Card, colors, Empty, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get } from '../lib/api';

type Section = { key: string; title: string; items: { title: string; text: string }[] };

/** The rules everyone must know (attendance, leave, salary, e-mails, apps), as on the website. */
export default function InstructionsScreen() {
  const [sections, setSections] = useState<Section[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await get<{ data: Section[] }>('/instructions');
      setSections(response.data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load the instructions.');
    } finally {
      setLoaded(true);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  const q = query.trim().toLowerCase();
  const shown = sections
    .map((s) => ({ ...s, items: q ? s.items.filter((i) => s.title.toLowerCase().includes(q) || i.title.toLowerCase().includes(q) || i.text.toLowerCase().includes(q)) : s.items }))
    .filter((s) => s.items.length);

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      <Muted style={{ marginBottom: 8 }}>What everyone must know. Times and limits are today's company settings.</Muted>
      <TextInput value={query} onChangeText={setQuery} placeholder="Search, e.g. half day, leave, payslip" placeholderTextColor={colors.muted} style={styles.search} />
      {shown.length ? shown.map((s) => {
        const expanded = !!q || open === s.key;
        return (
          <Card key={s.key}>
            <Pressable onPress={() => setOpen(open === s.key ? null : s.key)} style={styles.head} accessibilityRole="button">
              <Text style={styles.title}>{s.title}</Text>
              <Text style={styles.chev}>{expanded ? '▲' : '▼'}</Text>
            </Pressable>
            {expanded ? s.items.map((i) => (
              <View key={i.title} style={styles.item}>
                <Text style={styles.itemTitle}>{i.title}</Text>
                <Text style={styles.itemText}>{i.text}</Text>
              </View>
            )) : null}
          </Card>
        );
      }) : <Empty text={loaded ? 'Nothing found' : 'Loading…'} />}
    </Screen>
  );
}

const styles = StyleSheet.create({
  search: { borderWidth: 1, borderColor: colors.line, borderRadius: 10, paddingHorizontal: 12, paddingVertical: 9, marginBottom: 10, backgroundColor: colors.white, color: colors.ink },
  head: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  title: { fontSize: 16, fontWeight: '800', color: colors.navy, flex: 1 },
  chev: { color: colors.muted, fontSize: 12, marginLeft: 8 },
  item: { borderTopWidth: 1, borderTopColor: colors.line, paddingTop: 8, marginTop: 8 },
  itemTitle: { fontWeight: '700', color: colors.navy },
  itemText: { color: colors.ink, marginTop: 2, lineHeight: 20 },
});
