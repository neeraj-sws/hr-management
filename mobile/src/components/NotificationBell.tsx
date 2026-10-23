import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useEffect } from 'react';
import { AppState, Pressable, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../lib/auth';
import { colors } from './ui';

/** Bell in the top bar of every main screen, with the unread count; opens Notifications. */
export function NotificationBell() {
  const { me, refreshMe } = useAuth();
  const router = useRouter();
  const unread = me?.unread_notifications ?? 0;

  // Keep the count fresh: every minute, and whenever the app comes back to the front.
  useEffect(() => {
    const timer = setInterval(() => { refreshMe().catch(() => undefined); }, 60_000);
    const sub = AppState.addEventListener('change', (state) => { if (state === 'active') refreshMe().catch(() => undefined); });
    return () => { clearInterval(timer); sub.remove(); };
  }, [refreshMe]);

  return (
    <Pressable onPress={() => router.push('/notifications')} hitSlop={10} style={styles.bell}
      accessibilityRole="button" accessibilityLabel={unread ? `Notifications, ${unread} unread` : 'Notifications'}>
      <Ionicons name={unread ? 'notifications' : 'notifications-outline'} size={24} color={colors.white} />
      {unread > 0 ? <View style={styles.badge}><Text style={styles.badgeText}>{unread > 99 ? '99+' : unread}</Text></View> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  bell: { marginRight: 16, padding: 4 },
  badge: { position: 'absolute', top: -2, right: -4, minWidth: 18, height: 18, borderRadius: 9, backgroundColor: colors.red, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4, borderWidth: 1.5, borderColor: colors.navy },
  badgeText: { color: colors.white, fontSize: 10, fontWeight: '800' },
});
