import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import { createElement, useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, formatDate } from './ui';

/** Local calendar date as YYYY-MM-DD (not UTC, so it never shifts a day). */
export const toYmd = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
export const fromYmd = (value: string) => {
  const [y, m, d] = value.split('-').map(Number);
  return new Date(y, m - 1, d);
};
export const todayYmd = () => toYmd(new Date());

type Props = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  placeholder?: string;
  minimumDate?: string;
  maximumDate?: string;
};

/** Date input that opens the platform calendar; value is always YYYY-MM-DD or ''. */
export function DateField({ label, value, onChange, error, placeholder = 'Select date', minimumDate, maximumDate }: Props) {
  const [iosOpen, setIosOpen] = useState(false);
  const current = value ? fromYmd(value) : minimumDate ? fromYmd(minimumDate) : new Date();
  const min = minimumDate ? fromYmd(minimumDate) : undefined;
  const max = maximumDate ? fromYmd(maximumDate) : undefined;

  const picked = (_event: unknown, date: Date) => {
    setIosOpen(false);
    onChange(toYmd(date));
  };
  const dismissed = () => setIosOpen(false);

  const open = () => {
    if (Platform.OS === 'android') {
      DateTimePickerAndroid.open({ value: current, mode: 'date', onValueChange: picked, onDismiss: dismissed, minimumDate: min, maximumDate: max });
    } else {
      setIosOpen((v) => !v);
    }
  };

  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={styles.label}>{label}</Text>
      {Platform.OS === 'web' ? (
        createElement('input', {
          type: 'date',
          value,
          min: minimumDate,
          max: maximumDate,
          'aria-label': label,
          onChange: (e: { target: { value: string } }) => onChange(e.target.value),
          style: { ...webInput, borderColor: error ? colors.red : '#d8dfe9' },
        })
      ) : (
        <Pressable accessibilityRole="button" accessibilityLabel={`${label}: ${value ? formatDate(value) : placeholder}`} onPress={open} style={[styles.input, error ? { borderColor: colors.red } : null]}>
          <Text style={[styles.value, !value && { color: colors.faint }]}>{value ? formatDate(value) : placeholder}</Text>
          <Text style={styles.icon}>📅</Text>
        </Pressable>
      )}
      {Platform.OS === 'ios' && iosOpen ? (
        <DateTimePicker value={current} mode="date" display="inline" onValueChange={picked} onDismiss={dismissed} minimumDate={min} maximumDate={max} />
      ) : null}
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

const webInput = { border: '1px solid', borderRadius: 10, padding: '10px 12px', fontSize: 15, color: colors.ink, backgroundColor: colors.white, fontFamily: 'inherit' };

const styles = StyleSheet.create({
  label: { color: colors.ink2, fontSize: 13, fontWeight: '600', marginBottom: 5 },
  input: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderWidth: 1, borderColor: '#d8dfe9', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 11, backgroundColor: colors.white },
  value: { fontSize: 15, color: colors.ink },
  icon: { fontSize: 16 },
  error: { color: colors.red, fontSize: 12, marginTop: 4 },
});

const pad = (n: number) => String(n).padStart(2, '0');
const toHm = (date: Date) => `${pad(date.getHours())}:${pad(date.getMinutes())}`;
const fromHm = (value: string) => {
  const [h, m] = value.split(':').map(Number);
  const date = new Date();
  date.setHours(h, m, 0, 0);
  return date;
};
const formatHm = (value: string) => fromHm(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

/** Time input (HH:mm or '') that opens the platform clock; tap "Clear" to leave it unchanged. */
export function TimeField({ label, value, onChange, error, placeholder = 'Not changed' }: { label: string; value: string; onChange: (value: string) => void; error?: string; placeholder?: string }) {
  const [iosOpen, setIosOpen] = useState(false);
  const current = value ? fromHm(value) : fromHm('09:30');
  const picked = (_event: unknown, date: Date) => {
    setIosOpen(false);
    onChange(toHm(date));
  };
  const open = () => {
    if (Platform.OS === 'android') DateTimePickerAndroid.open({ value: current, mode: 'time', is24Hour: false, onValueChange: picked, onDismiss: () => undefined });
    else setIosOpen((v) => !v);
  };

  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={styles.label}>{label}</Text>
      {Platform.OS === 'web' ? (
        createElement('input', { type: 'time', value, 'aria-label': label, onChange: (e: { target: { value: string } }) => onChange(e.target.value), style: { ...webInput, borderColor: error ? colors.red : '#d8dfe9' } })
      ) : (
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <Pressable accessibilityRole="button" accessibilityLabel={`${label}: ${value ? formatHm(value) : placeholder}`} onPress={open} style={[styles.input, { flex: 1 }, error ? { borderColor: colors.red } : null]}>
            <Text style={[styles.value, !value && { color: colors.faint }]}>{value ? formatHm(value) : placeholder}</Text>
            <Text style={styles.icon}>🕘</Text>
          </Pressable>
          {value ? <Pressable onPress={() => onChange('')} style={[styles.input, { paddingHorizontal: 14 }]}><Text style={{ color: colors.muted }}>Clear</Text></Pressable> : null}
        </View>
      )}
      {Platform.OS === 'ios' && iosOpen ? <DateTimePicker value={current} mode="time" display="spinner" onValueChange={picked} onDismiss={() => setIosOpen(false)} /> : null}
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}
