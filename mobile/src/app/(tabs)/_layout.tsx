import { Tabs } from 'expo-router';
import { ColorValue, Text } from 'react-native';
import { colors } from '../../components/ui';

const icon = (glyph: string) => ({ color }: { color: ColorValue }) => <Text style={{ color, fontSize: 18 }}>{glyph}</Text>;

export default function TabsLayout() {
  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: colors.navy },
        headerTintColor: colors.white,
        headerTitleStyle: { fontWeight: '700' },
        tabBarActiveTintColor: colors.blue,
        tabBarInactiveTintColor: colors.faint,
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Home', tabBarIcon: icon('⌂') }} />
      <Tabs.Screen name="attendance" options={{ title: 'Attendance', tabBarIcon: icon('◷') }} />
      <Tabs.Screen name="tasks" options={{ title: 'Tasks', tabBarIcon: icon('✓') }} />
      <Tabs.Screen name="leave" options={{ title: 'Leave', tabBarIcon: icon('▣') }} />
      <Tabs.Screen name="more" options={{ title: 'More', tabBarIcon: icon('≡') }} />
    </Tabs>
  );
}
