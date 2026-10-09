import { useState } from 'react';
import { KeyboardAvoidingView, Linking, Platform, Pressable, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, Card, colors, Field, Muted, Notice } from '../components/ui';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';

export default function Login() {
  const { signIn, serverUrl } = useAuth();
  const [server, setServer] = useState(serverUrl);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [editServer, setEditServer] = useState(!serverUrl);

  const submit = async () => {
    setError(null);
    if (!server.trim() || !email.trim() || !password) {
      if (!server.trim()) setEditServer(true);
      setError(!email.trim() || !password ? 'Enter your email and password.' : 'Enter the server address.');
      return;
    }
    setBusy(true);
    try {
      await signIn(server, email, password);
    } catch (e) {
      setError(e instanceof ApiError ? (e.status === 422 ? 'These credentials do not match our records, or the account is inactive.' : e.firstMessage) : 'Sign-in failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.navy }}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, justifyContent: 'center', padding: 20 }}>
        <View style={{ alignItems: 'center', marginBottom: 24 }}>
          <View style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: colors.white, alignItems: 'center', justifyContent: 'center' }}>
            <Text style={{ color: colors.navy, fontWeight: '900', fontSize: 20 }}>SW</Text>
          </View>
          <Text style={{ color: colors.white, fontSize: 24, fontWeight: '800', marginTop: 12 }}>SWS HR</Text>
          <Text style={{ color: '#9fb3d1' }}>HR & Work Management</Text>
        </View>
        <Card>
          {error ? <View style={{ marginBottom: 12 }}><Notice tone="error" text={error} /></View> : null}
          <Field label="Email" value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" textContentType="username" />
          <Field label="Password" value={password} onChangeText={setPassword} secureTextEntry={!showPassword} textContentType="password" returnKeyType="go" onSubmitEditing={submit} />
          <Pressable onPress={() => setShowPassword((v) => !v)} style={{ alignSelf: 'flex-end', marginTop: -6, marginBottom: 12 }}>
            <Text style={{ color: colors.blue, fontWeight: '600', fontSize: 13 }}>{showPassword ? 'Hide password' : 'Show password'}</Text>
          </Pressable>
          {editServer
            ? <Field label="Server" value={server} onChangeText={setServer} autoCapitalize="none" autoCorrect={false} keyboardType="url" placeholder="https://hr.yourcompany.com" />
            : <Pressable onPress={() => setEditServer(true)} style={{ marginBottom: 12 }}><Muted>Server: {server} · <Text style={{ color: colors.blue, fontWeight: '600' }}>Change</Text></Muted></Pressable>}
          <Button title="Sign in" tone="primary" onPress={submit} loading={busy} />
          <Pressable onPress={() => Linking.openURL(`${server.replace(/\/+$/, '')}/forgot-password`)} style={{ marginTop: 12 }}>
            <Text style={{ color: colors.blue, textAlign: 'center', fontWeight: '600' }}>Forgot your password?</Text>
          </Pressable>
        </Card>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
