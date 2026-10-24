import { MaterialCommunityIcons } from '@expo/vector-icons';
import { StyleSheet, Text, View } from 'react-native';

type Look = { icon: keyof typeof MaterialCommunityIcons.glyphMap; bg: string; fg: string };

/** One look per role, the same names as the website badges (App\Support\PersonBadges). */
const LOOKS: Record<string, Look> = {
  'Master Admin': { icon: 'shield-crown-outline', bg: '#e3e8f3', fg: '#14284b' },
  HR: { icon: 'account-heart-outline', bg: '#dce7ff', fg: '#1f47b8' },
  Accounts: { icon: 'calculator-variant-outline', bg: '#fff1cc', fg: '#8a5a00' },
  Manager: { icon: 'crown-outline', bg: '#fdf0d5', fg: '#7a4b00' },
  'Team lead': { icon: 'account-star-outline', bg: '#dff5e8', fg: '#0f6b3d' },
  Employee: { icon: 'account-outline', bg: '#eef1f5', fg: '#334258' },
  Trainee: { icon: 'school-outline', bg: '#efe9ff', fg: '#5b3cc4' },
  'No login': { icon: 'account-off-outline', bg: '#eef1f5', fg: '#6b778a' },
};

export function RoleBadge({ label }: { label: string }) {
  const look = LOOKS[label] ?? { icon: 'account-outline', bg: '#dce7ff', fg: '#1f47b8' };
  return (
    <View style={[styles.badge, { backgroundColor: look.bg }]} accessibilityLabel={label}>
      <MaterialCommunityIcons name={look.icon} size={13} color={look.fg} />
      <Text style={[styles.text, { color: look.fg }]}>{label}</Text>
    </View>
  );
}

export function RoleBadges({ badges }: { badges?: { label: string }[] }) {
  if (!badges?.length) return null;
  return <View style={styles.row}>{badges.map((b) => <RoleBadge key={b.label} label={b.label} />)}</View>;
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 5 },
  badge: { flexDirection: 'row', alignItems: 'center', gap: 4, borderRadius: 8, paddingHorizontal: 7, paddingVertical: 2 },
  text: { fontSize: 12, fontWeight: '700' },
});
