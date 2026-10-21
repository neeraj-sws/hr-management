import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';
import { ColorValue } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors } from '../../components/ui';

/** Filled glyph when the tab is active, outline when not — the standard mobile look. */
const icon = (name: keyof typeof Ionicons.glyphMap) =>
  ({ color, size, focused }: { color: ColorValue; size: number; focused: boolean }) => (
    <Ionicons name={(focused ? name : (`${name}-outline` as keyof typeof Ionicons.glyphMap))} size={size ?? 24} color={color as string} />
  );

export default function TabsLayout() {
  // Room for the phone's own navigation buttons / gesture bar, so the tabs are never hidden behind them.
  const { bottom } = useSafeAreaInsets();
  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: colors.navy },
        headerTintColor: colors.white,
        headerTitleStyle: { fontWeight: '700' },
        tabBarActiveTintColor: colors.blue,
        tabBarInactiveTintColor: colors.faint,
        tabBarLabelStyle: { fontSize: 12, fontWeight: '600', marginBottom: 4 },
        tabBarIconStyle: { marginTop: 4 },
        tabBarStyle: { height: 64 + bottom, paddingTop: 6, paddingBottom: 10 + bottom },
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Home', tabBarIcon: icon('home') }} />
      <Tabs.Screen name="attendance" options={{ title: 'Attendance', tabBarIcon: icon('time') }} />
      <Tabs.Screen name="tasks" options={{ title: 'Tasks', tabBarIcon: icon('checkmark-circle') }} />
      <Tabs.Screen name="leave" options={{ title: 'Leave', tabBarIcon: icon('calendar') }} />
      <Tabs.Screen name="more" options={{ title: 'More', tabBarIcon: icon('menu') }} />
    </Tabs>
  );
}
