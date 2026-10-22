import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors } from './ui';

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

/** ‹ October 2026 › — one month of history at a time; never moves past this month. */
export function MonthSwitcher({ month, onChange }: { month: string; onChange: (month: string) => void }) {
  const atCurrent = month >= currentMonth();
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

const styles = StyleSheet.create({
  bar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.white, borderRadius: 14, borderWidth: 1, borderColor: colors.line, paddingHorizontal: 6, paddingVertical: 4 },
  arrow: { width: 44, height: 40, alignItems: 'center', justifyContent: 'center', borderRadius: 10 },
  arrowText: { fontSize: 26, fontWeight: '700', color: colors.blue, lineHeight: 30 },
  off: { opacity: 0.25 },
  label: { fontSize: 16, fontWeight: '700', color: colors.ink },
});
