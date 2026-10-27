import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, Text, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { Button, colors } from '../components/ui';
import { AuthProvider, useAuth } from '../lib/auth';

function RootNavigator() {
  const { ready, me, locked, unlock, signOut } = useAuth();
  if (!ready) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.canvas }}>
        <ActivityIndicator color={colors.blue} />
      </View>
    );
  }

  if (me && locked) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.navy, padding: 24, gap: 14 }}>
        <Text style={{ color: colors.white, fontSize: 22, fontWeight: '800' }}>SWS HR is locked</Text>
        <Text style={{ color: '#9fb3d1', textAlign: 'center' }}>Use your fingerprint, face or phone PIN to continue, {me.name.split(' ')[0]}.</Text>
        <View style={{ width: '100%', maxWidth: 320, gap: 10 }}>
          <Button title="Unlock" tone="primary" onPress={unlock} />
          <Button title="Sign out" onPress={signOut} />
        </View>
      </View>
    );
  }

  return (
    <Stack screenOptions={{ headerStyle: { backgroundColor: colors.navy }, headerTintColor: colors.white, headerTitleStyle: { fontWeight: '700' } }}>
      <Stack.Protected guard={!!me}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="salary" options={{ title: 'My salary' }} />
        <Stack.Screen name="payslips" options={{ title: 'Payslips' }} />
        <Stack.Screen name="notifications" options={{ title: 'Notifications' }} />
        <Stack.Screen name="search" options={{ headerShown: false }} />
        <Stack.Screen name="approvals" options={{ title: 'Approvals' }} />
        <Stack.Screen name="sync" options={{ title: 'Offline sync' }} />
        <Stack.Screen name="corrections" options={{ title: 'Attendance correction' }} />
        <Stack.Screen name="expenses" options={{ title: 'Expense claims' }} />
        <Stack.Screen name="announcements" options={{ title: 'Announcements' }} />
        <Stack.Screen name="holidays" options={{ title: 'Holidays' }} />
        <Stack.Screen name="instructions" options={{ title: 'Instructions' }} />
        <Stack.Screen name="training" options={{ title: 'My training' }} />
        <Stack.Screen name="daily-status" options={{ title: 'Daily status' }} />
        <Stack.Screen name="documents" options={{ title: 'Documents & assets' }} />
        <Stack.Screen name="performance" options={{ title: 'My performance' }} />
        <Stack.Screen name="team" options={{ title: 'My team today' }} />
        <Stack.Screen name="task" options={{ title: 'Task' }} />
        <Stack.Screen name="settings" options={{ title: 'My profile & settings' }} />
        <Stack.Screen name="notes" options={{ title: 'My notes' }} />
      </Stack.Protected>
      <Stack.Protected guard={!me}>
        <Stack.Screen name="login" options={{ headerShown: false }} />
      </Stack.Protected>
    </Stack>
  );
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <StatusBar style="light" />
        <RootNavigator />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
