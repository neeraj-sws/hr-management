import * as ImagePicker from 'expo-image-picker';
import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Image, Platform, View } from 'react-native';
import { DateField, todayYmd } from '../components/DateField';
import { Badge, Button, Card, Chips, confirmAction, Empty, Field, formatDate, H2, Muted, Notice, Row, Screen, money } from '../components/ui';
import { ApiError, get, post, upload } from '../lib/api';
import { enqueue } from '../lib/queue';
import type { ExpenseClaim, Paginated } from '../lib/types';

type Receipt = { uri: string; name: string; type: string; file?: File };

export default function ExpensesScreen() {
  const [items, setItems] = useState<ExpenseClaim[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [category, setCategory] = useState('Travel');
  const [spentOn, setSpentOn] = useState(todayYmd());
  const [amount, setAmount] = useState('');
  const [description, setDescription] = useState('');
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ tone: 'error' | 'success' | 'info'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await get<Paginated<ExpenseClaim> & { categories: string[] }>('/expenses');
      setItems(response.data);
      setCategories(response.categories);
      setLoaded(true);
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: e.isNetwork ? 'info' : 'error', text: e.isNetwork ? 'Offline — you can still add a claim; it is sent when you are back online.' : e.firstMessage });
    }
  }, []);
  useFocusEffect(useCallback(() => { load(); }, [load]));

  const pick = async (camera: boolean) => {
    const permission = camera ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      setMessage({ tone: 'error', text: camera ? 'Allow camera access to photograph the bill.' : 'Allow photo access to attach the bill.' });
      return;
    }
    const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.6 };
    const result = camera ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
    if (result.canceled || !result.assets.length) return;
    const asset = result.assets[0];
    const type = asset.mimeType ?? 'image/jpeg';
    setReceipt({ uri: asset.uri, name: asset.fileName ?? `receipt.${type.split('/')[1] ?? 'jpg'}`, type, file: (asset as { file?: File }).file });
  };

  const submit = async () => {
    const local: Record<string, string> = {};
    if (!(Number(amount) > 0)) local.amount = 'Enter the amount spent.';
    if (description.trim().length < 3) local.description = 'Say what the expense was for.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    setMessage(null);
    try {
      const form = new FormData();
      form.append('category', category);
      form.append('spent_on', spentOn);
      form.append('amount', amount);
      form.append('description', description);
      if (receipt) {
        // Web gives a File; native needs the { uri, name, type } form React Native understands.
        if (Platform.OS === 'web' && receipt.file) form.append('receipt', receipt.file, receipt.name);
        else form.append('receipt', { uri: receipt.uri, name: receipt.name, type: receipt.type } as unknown as Blob);
      }
      const created = await upload<{ data: ExpenseClaim }>('/expenses', form);
      setItems((current) => [created.data, ...current]);
      setMessage({ tone: 'success', text: 'Claim submitted. Approved claims are paid with your salary.' });
      setAmount(''); setDescription(''); setReceipt(null);
    } catch (e) {
      if (e instanceof ApiError && e.isNetwork) {
        // A browser File cannot be kept after the page closes; the phone keeps the photo's file path.
        if (receipt && Platform.OS === 'web') {
          setMessage({ tone: 'error', text: 'You are offline. Remove the bill photo to save the claim for later, or try again when online.' });
          return;
        }
        await enqueue({ type: 'expense.submit', payload: { category, spent_on: spentOn, amount, description, ...(receipt ? { receipt: { uri: receipt.uri, name: receipt.name, type: receipt.type } } : {}) }, label: `Expense ${category} · ${money(Number(amount))}` });
        setMessage({ tone: 'info', text: 'You are offline. The claim is saved on this phone and will be sent automatically.' });
        setAmount(''); setDescription(''); setReceipt(null);
      } else if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        setMessage({ tone: 'error', text: e.firstMessage });
      }
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (id: number) => {
    if (!(await confirmAction('Cancel claim?', 'The claim will be withdrawn.', 'Cancel claim'))) return;
    try {
      await post(`/expenses/${id}/cancel`);
      await load();
    } catch (e) {
      if (e instanceof ApiError) setMessage({ tone: 'error', text: e.firstMessage });
    }
  };

  const toBePaid = items.filter((c) => c.status === 'approved').reduce((sum, c) => sum + c.amount, 0);

  return (
    <Screen>
      <Card>
        <H2>New expense claim</H2>
        {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
        <Muted style={{ marginBottom: 6 }}>Category</Muted>
        <Chips value={category} onChange={setCategory} options={(categories.length ? categories : ['Travel', 'Food', 'Other']).map((c) => ({ label: c, value: c }))} />
        <DateField label="Date of expense" value={spentOn} onChange={setSpentOn} maximumDate={todayYmd()} error={errors.spent_on} />
        <Field label="Amount" value={amount} onChangeText={setAmount} keyboardType="decimal-pad" placeholder="0.00" error={errors.amount} />
        <Field label="What was it for?" value={description} onChangeText={setDescription} placeholder="e.g. Cab to client office" error={errors.description} multiline />
        <Muted style={{ marginBottom: 6 }}>Bill / receipt (optional)</Muted>
        {receipt ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 12 }}>
            <Image source={{ uri: receipt.uri }} style={{ width: 64, height: 64, borderRadius: 8, backgroundColor: '#eef1f6' }} />
            <View style={{ flex: 1 }}><Muted>{receipt.name}</Muted></View>
            <Button small title="Remove" onPress={() => setReceipt(null)} />
          </View>
        ) : (
          <View style={{ flexDirection: 'row', gap: 8, marginBottom: 12 }}>
            {Platform.OS !== 'web' ? <View style={{ flex: 1 }}><Button small title="📷 Take photo" onPress={() => pick(true)} /></View> : null}
            <View style={{ flex: 1 }}><Button small title="🖼 Choose photo" onPress={() => pick(false)} /></View>
          </View>
        )}
        {errors.receipt ? <Muted style={{ color: '#bd3446', marginTop: -6, marginBottom: 8 }}>{errors.receipt}</Muted> : null}
        <Button title="Submit claim" tone="primary" onPress={submit} loading={busy} />
      </Card>
      <Card>
        <H2>My claims</H2>
        {toBePaid > 0 ? <Muted style={{ marginBottom: 6 }}>{money(toBePaid)} approved — will be paid with your next salary.</Muted> : null}
        {items.length ? items.map((c) => (
          <Row key={c.id} title={`${c.category} · ${money(c.amount)}`} subtitle={`${formatDate(c.spent_on)} · ${c.description}${c.has_receipt ? ' · 🧾' : ''}${c.decision_note ? ` · ${c.decision_note}` : ''}`}
            right={<View style={{ alignItems: 'flex-end', gap: 6 }}><Badge label={c.status} />{c.status === 'pending' ? <Button small tone="danger" title="Cancel" onPress={() => cancel(c.id)} /> : null}</View>} />
        )) : <Empty text={loaded ? 'No expense claims yet' : 'Loading…'} />}
      </Card>
    </Screen>
  );
}
