import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { DateField } from './DateField';
import { colors, formatDate } from './ui';

/** 'YYYY-MM' of today. */
export const currentMonth = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
};

/** 'YYYY-MM' moved by n months. */
export const shiftMonth = (month: string, n: number) => {
  const [y, m] = month.split('-').map(Number);
  const d = new Date(y, m - 1 + n, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

/** "October 2026". */
export const monthLabel = (month: string) => {
  const [y, m] = month.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
};

/** First and last day ('YYYY-MM-DD') of a month; the last day never goes past today. */
export const monthBounds = (month: string) => {
  const [y, m] = month.split('-').map(Number);
  const last = new Date(y, m, 0);
  const today = new Date();
  const end = last > today ? today : last;
  const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  return { from: `${month}-01`, to: ymd(end) };
};

/** The month a history screen shows (this month to start). */
export function useMonth() {
  return useState(currentMonth());
}

/**
 * ‹ October 2026 › — one month at a time. History never moves past this month;
 * with `futureMonths` (leave) it goes that many months ahead.
 */
export function MonthSwitcher({ month, onChange, futureMonths = 0 }: { month: string; onChange: (month: string) => void; futureMonths?: number }) {
  const atCurrent = month >= shiftMonth(currentMonth(), futureMonths);
  return (
    <View style={styles.bar}>
      <Pressable accessibilityRole="button" accessibilityLabel="Previous month" onPress={() => onChange(shiftMonth(month, -1))} style={styles.arrow} hitSlop={8}>
        <Text style={styles.arrowText}>‹</Text>
      </Pressable>
      <Text style={styles.label}>{monthLabel(month)}</Text>
      <Pressable accessibilityRole="button" accessibilityLabel="Next month" disabled={atCurrent} onPress={() => onChange(shiftMonth(month, 1))} style={[styles.arrow, atCurrent && styles.off]} hitSlop={8}>
        <Text style={styles.arrowText}>›</Text>
      </Pressable>
    </View>
  );
}

/**
 * "Pick a date" — narrows a list to one day (e.g. 5 Mar 2026). Clear goes back to the month.
 * `max` / `min` limit the calendar ('YYYY-MM-DD').
 */
export function DayFilter({ value, onChange, max, min }: { value: string; onChange: (day: string) => void; max?: string; min?: string }) {
  return (
    <View style={styles.day}>
      <View style={{ flex: 1 }}>
        <DateField label="Filter by date" value={value} onChange={onChange} placeholder="Pick a date" maximumDate={max} minimumDate={min} />
      </View>
      {value ? (
        <Pressable accessibilityRole="button" accessibilityLabel={`Clear date ${formatDate(value)}`} onPress={() => onChange('')} style={styles.clear} hitSlop={6}>
          <Text style={styles.clearText}>Clear</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  day: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 },
  clear: { marginBottom: 12, paddingHorizontal: 14, paddingVertical: 11, borderRadius: 10, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white },
  clearText: { color: colors.blue, fontWeight: '700' },
  bar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.white, borderRadius: 14, borderWidth: 1, borderColor: colors.line, paddingHorizontal: 6, paddingVertical: 4 },
  arrow: { width: 44, height: 40, alignItems: 'center', justifyContent: 'center', borderRadius: 10 },
  arrowText: { fontSize: 26, fontWeight: '700', color: colors.blue, lineHeight: 30 },
  off: { opacity: 0.25 },
  label: { fontSize: 16, fontWeight: '700', color: colors.ink },
});
