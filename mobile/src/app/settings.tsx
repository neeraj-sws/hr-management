import { MaterialCommunityIcons } from '@expo/vector-icons';
import Constants from 'expo-constants';
import { useFocusEffect, useRouter } from 'expo-router';
import { ComponentProps, ReactNode, useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, Switch, Text, View } from 'react-native';
import { DateField, todayYmd } from '../components/DateField';
import { Button, Card, colors, confirmAction, Field, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, getBaseUrl, post } from '../lib/api';
import { useAuth } from '../lib/auth';

type Editable = { phone: string; date_of_birth: string | null; share_birthday: boolean; address: string; emergency_contact_name: string; emergency_contact_relation: string; emergency_contact_phone: string };
type Profile = { editable: Editable | null; hr_only: { label: string; value: string }[]; two_factor: { on: boolean; required: boolean; email: string; mail_available: boolean; apps_too: boolean } };
type Pref = { group: string; label: string; can_mute: boolean; in_app: boolean; email: boolean; push: boolean; telegram?: boolean };
type Telegram = { available: boolean; connected: boolean; username: string | null; linked_at: string | null; bot: string | null };
type Note = { tone: 'success' | 'error' | 'info'; text: string } | null;

/** More → My profile & settings: the same choices an employee has on the website's My profile page. */
export default function Settings() {
  const router = useRouter();
  const { refreshMe } = useAuth();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [form, setForm] = useState<Editable | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [detailsNote, setDetailsNote] = useState<Note>(null);
  const [prefs, setPrefs] = useState<Pref[]>([]);
  const [pushAvailable, setPushAvailable] = useState(true);
  // Telegram: connect here (people who use only the app); the bot opens in Telegram, the person taps Start.
  const [telegram, setTelegram] = useState<Telegram | null>(null);
  const [tgBusy, setTgBusy] = useState(false);
  const [tgNote, setTgNote] = useState<Note>(null);
  const [tgWaiting, setTgWaiting] = useState(false);
  const [prefsSaving, setPrefsSaving] = useState(false);
  const [prefsNote, setPrefsNote] = useState<Note>(null);
  const [twoFaBusy, setTwoFaBusy] = useState(false);
  const [securityNote, setSecurityNote] = useState<Note>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    setLoadError(null);
    try {
      const [p, n, t] = await Promise.all([get<{ data: Profile }>('/me/profile'), get<{ data: Pref[]; push_available: boolean }>('/notifications/preferences'), get<{ data: Telegram }>('/me/telegram').catch(() => null)]);
      if (t) setTelegram(t.data);
      setProfile(p.data);
      setForm(p.data.editable);
      setPrefs(n.data);
      setPushAvailable(n.push_available);
    } catch (e) {
      setLoadError(e instanceof ApiError ? (e.isNetwork ? 'No internet. Pull down to try again.' : e.firstMessage) : 'Could not load your settings.');
    }
  }, []);
  useEffect(() => { load(); }, [load]);
  // Back from Telegram after tapping Start: check whether it is connected now.
  useFocusEffect(useCallback(() => {
    if (!tgWaiting) return;
    get<{ data: Telegram }>('/me/telegram').then((r) => {
      setTelegram(r.data);
      if (r.data.connected) { setTgWaiting(false); setTgNote({ tone: 'success', text: 'Telegram connected. Your notifications will come there too.' }); load(); }
    }).catch(() => undefined);
  }, [tgWaiting, load]));

  const connectTelegram = async () => {
    setTgBusy(true); setTgNote(null);
    try {
      const r = await post<{ data: { url: string } }>('/me/telegram/connect');
      setTgWaiting(true);
      await Linking.openURL(r.data.url);
      setTgNote({ tone: 'info', text: 'In Telegram tap Start, then come back here and tap "Check connection". The link works once, for 10 minutes.' });
    } catch (e) {
      setTgNote({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not open Telegram. Is it installed?' });
    } finally {
      setTgBusy(false);
    }
  };
  const checkTelegram = async () => {
    try {
      const r = await get<{ data: Telegram }>('/me/telegram');
      setTelegram(r.data);
      if (r.data.connected) { setTgWaiting(false); setTgNote({ tone: 'success', text: 'Telegram connected.' }); load(); }
      else setTgNote({ tone: 'error', text: 'Not connected yet: open the bot, tap Start, then check again.' });
    } catch (e) {
      setTgNote({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not check.' });
    }
  };
  const disconnectTelegram = async () => {
    if (!(await confirmAction('Disconnect Telegram?', 'You will not get SWS HRMS notifications on Telegram any more.', 'Disconnect'))) return;
    try {
      const r = await post<{ data: Telegram }>('/me/telegram/disconnect');
      setTelegram(r.data); setTgNote({ tone: 'success', text: 'Telegram disconnected.' }); load();
    } catch (e) {
      setTgNote({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not disconnect.' });
    }
  };

  const set = <K extends keyof Editable>(key: K, value: Editable[K]) => { setForm((f) => (f ? { ...f, [key]: value } : f)); setErrors((e) => ({ ...e, [key]: '' })); setDetailsNote(null); };

  const saveDetails = async () => {
    if (!form) return;
    setSaving(true);
    setDetailsNote(null);
    try {
      const r = await post<{ data: Profile; message: string }>('/me/profile', form);
      setProfile(r.data);
      setForm(r.data.editable);
      setErrors({});
      setDetailsNote({ tone: 'success', text: r.message });
      refreshMe().catch(() => undefined);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      setDetailsNote({ tone: 'error', text: e instanceof ApiError ? (e.isNetwork ? 'Not saved: no internet. Try again.' : e.firstMessage) : 'Could not save.' });
    } finally {
      setSaving(false);
    }
  };

  const togglePref = (group: string, channel: 'in_app' | 'push' | 'email' | 'telegram') => { setPrefs((list) => list.map((p) => (p.group === group ? { ...p, [channel]: !p[channel] } : p))); setPrefsNote(null); };
  const savePrefs = async () => {
    setPrefsSaving(true);
    try {
      const r = await post<{ data: Pref[] }>('/notifications/preferences', { preferences: Object.fromEntries(prefs.map((p) => [p.group, { in_app: p.in_app, push: p.push, email: p.email, ...(p.telegram !== undefined ? { telegram: p.telegram } : {}) }])) });
      setPrefs(r.data);
      setPrefsNote({ tone: 'success', text: 'Notification choices saved. The website shows the same.' });
    } catch (e) {
      setPrefsNote({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not save.' });
    } finally {
      setPrefsSaving(false);
    }
  };

  const toggleTwoFactor = async () => {
    if (!profile) return;
    const on = !profile.two_factor.on;
    const ok = await confirmAction(on ? 'Turn on 2-step sign-in?' : 'Turn off 2-step sign-in?', on ? `From your next sign-in, a 6-digit code will be e-mailed to ${profile.two_factor.email}.` : 'You will sign in with only your password.', on ? 'Turn on' : 'Turn off');
    if (!ok) return;
    setTwoFaBusy(true);
    try {
      const r = await post<{ data: Profile; message: string }>('/me/two-factor', { on });
      setProfile(r.data);
      setSecurityNote({ tone: 'success', text: r.message });
    } catch (e) {
      setSecurityNote({ tone: 'error', text: e instanceof ApiError ? e.firstMessage : 'Could not change it.' });
    } finally {
      setTwoFaBusy(false);
    }
  };

  if (!profile && !loadError) return <Screen><ActivityIndicator color={colors.blue} style={{ marginTop: 40 }} /></Screen>;

  return (
    <Screen refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }}>
      {loadError ? <Notice tone="error" text={loadError} /> : null}

      {form ? (
        <Section icon="account-edit-outline" title="My details" hint="You can change these yourself.">
          <Field label="📞 Phone number" value={form.phone} onChangeText={(v) => set('phone', v)} keyboardType="phone-pad" autoComplete="tel" placeholder="e.g. 98765 43210" error={errors.phone} />
          <DateField label="🎂 Date of birth" value={form.date_of_birth ?? ''} onChange={(v) => set('date_of_birth', v || null)} maximumDate={todayYmd()} error={errors.date_of_birth} />
          <SwitchRow label="Let colleagues see my birthday" hint="Only the day and month are shown." value={form.share_birthday} onChange={(v) => set('share_birthday', v)} />
          <Field label="🏠 Current address" value={form.address} onChangeText={(v) => set('address', v)} multiline numberOfLines={3} placeholder="House / flat, street, area, city, PIN" style={{ minHeight: 76, textAlignVertical: 'top' }} error={errors.address} />
          <Muted style={{ marginTop: -6, marginBottom: 12 }}>Only HR sees your address.</Muted>

          <Text style={styles.sub}>🚨 Emergency contact</Text>
          <Muted style={{ marginBottom: 8 }}>Who the company should call if something happens to you at work.</Muted>
          <Field label="Name" value={form.emergency_contact_name} onChangeText={(v) => set('emergency_contact_name', v)} placeholder="e.g. Rakesh Sharma" error={errors.emergency_contact_name} />
          <Field label="Relation" value={form.emergency_contact_relation} onChangeText={(v) => set('emergency_contact_relation', v)} placeholder="e.g. Father, Wife, Brother" error={errors.emergency_contact_relation} />
          <Field label="Phone" value={form.emergency_contact_phone} onChangeText={(v) => set('emergency_contact_phone', v)} keyboardType="phone-pad" placeholder="e.g. 98111 22222" error={errors.emergency_contact_phone} />
          {detailsNote ? <View style={{ marginBottom: 10 }}><Notice tone={detailsNote.tone} text={detailsNote.text} /></View> : null}
          <Button title={saving ? 'Saving…' : 'Save my details'} tone="primary" onPress={saveDetails} loading={saving} />
        </Section>
      ) : null}

      {profile?.hr_only.length ? (
        <Section icon="lock-outline" title="Set by HR" hint="To change any of these, ask HR.">
          {profile.hr_only.map((row) => (
            <View key={row.label} style={styles.kv}>
              <Text style={styles.kvLabel}>{row.label}</Text>
              <Text style={styles.kvValue}>{row.value}</Text>
            </View>
          ))}
        </Section>
      ) : null}

      {telegram?.available ? (
        <Section icon="send-outline" title="Telegram" hint="Get your own SWS HRMS notifications (leave, salary slip, payments, tasks, attendance) in Telegram.">
          {telegram.connected ? (
            <>
              <Muted style={{ marginBottom: 10 }}>Connected{telegram.username ? ` as @${telegram.username}` : ''}. Choose below which kinds go to Telegram.</Muted>
              <Button title="Disconnect Telegram" tone="danger" onPress={disconnectTelegram} />
            </>
          ) : (
            <>
              <Muted style={{ marginBottom: 10 }}>Not connected. Tap Connect: the {telegram.bot ? `@${telegram.bot}` : 'SWS HRMS'} bot opens in Telegram - tap Start there, then come back.</Muted>
              <Button title="Connect Telegram" tone="primary" onPress={connectTelegram} loading={tgBusy} />
              {tgWaiting ? <View style={{ marginTop: 8 }}><Button title="Check connection" onPress={checkTelegram} /></View> : null}
            </>
          )}
          {tgNote ? <View style={{ marginTop: 10 }}><Notice tone={tgNote.tone} text={tgNote.text} /></View> : null}
        </Section>
      ) : null}

      {prefs.length ? (
        <Section icon="bell-outline" title="Notifications" hint="Choose what reaches you. Important updates always show in the app.">
          <View style={styles.prefHead}>
            <Text style={[styles.prefCol, { flex: 1, textAlign: 'left' }]}>Type</Text>
            <Text style={styles.prefCol}>In app</Text>
            <Text style={styles.prefCol}>Phone</Text>
            <Text style={styles.prefCol}>E-mail</Text>
            {telegram?.available ? <Text style={styles.prefCol}>Telegram</Text> : null}
          </View>
          {prefs.map((p) => (
            <View key={p.group} style={styles.prefRow}>
              <Text style={styles.prefLabel}>{p.label}</Text>
              <View style={styles.prefCell}>{p.can_mute ? <Switch value={p.in_app} onValueChange={() => togglePref(p.group, 'in_app')} /> : <Text style={styles.always}>always</Text>}</View>
              <View style={styles.prefCell}><Switch value={p.push} onValueChange={() => togglePref(p.group, 'push')} /></View>
              <View style={styles.prefCell}><Switch value={p.email} onValueChange={() => togglePref(p.group, 'email')} /></View>
              {telegram?.available ? <View style={styles.prefCell}><Switch value={!!p.telegram} onValueChange={() => togglePref(p.group, 'telegram')} disabled={!telegram.connected} /></View> : null}
            </View>
          ))}
          {!pushAvailable ? <View style={{ marginTop: 10 }}><Notice tone="info" text="Phone notifications are not switched on for the company yet. Your choice is kept for when they are." /></View> : null}
          {prefsNote ? <View style={{ marginTop: 10 }}><Notice tone={prefsNote.tone} text={prefsNote.text} /></View> : null}
          <View style={{ marginTop: 12 }}><Button title={prefsSaving ? 'Saving…' : 'Save notification choices'} tone="primary" onPress={savePrefs} loading={prefsSaving} /></View>
        </Section>
      ) : null}

      {profile ? (
        <Section icon="shield-lock-outline" title="Security">
          {profile.two_factor.required ? (
            <SwitchRow label="2-step sign-in" hint={`On (company rule). A code is e-mailed to ${profile.two_factor.email}.`} value disabled />
          ) : (
            <SwitchRow label="2-step sign-in" hint={`After your password, a 6-digit code is e-mailed to ${profile.two_factor.email}${profile.two_factor.apps_too ? '' : ' (on the website)'}.`} value={profile.two_factor.on} onChange={toggleTwoFactor} disabled={twoFaBusy || (!profile.two_factor.on && !profile.two_factor.mail_available)} />
          )}
          {!profile.two_factor.mail_available && !profile.two_factor.on ? <Muted>The company has e-mail turned off, so this cannot be turned on. Ask HR.</Muted> : null}
          {securityNote ? <View style={{ marginTop: 8 }}><Notice tone={securityNote.tone} text={securityNote.text} /></View> : null}
          <View style={[styles.kv, { borderBottomWidth: 0, marginTop: 6 }]}>
            <Text style={styles.kvLabel}>Password</Text>
            <Text style={styles.kvValue}>Forgot it? Ask HR to reset it.</Text>
          </View>
          <Muted>Fingerprint / face lock is on the More screen.</Muted>
        </Section>
      ) : null}

      <Section icon="cellphone" title="App">
        <View style={styles.kv}><Text style={styles.kvLabel}>App version</Text><Text style={styles.kvValue}>{Constants.expoConfig?.version ?? '—'}</Text></View>
        <View style={styles.kv}><Text style={styles.kvLabel}>Server</Text><Text style={styles.kvValue} numberOfLines={1}>{getBaseUrl()}</Text></View>
        <Pressable onPress={() => router.push('/instructions')} style={[styles.kv, { borderBottomWidth: 0 }]}>
          <Text style={[styles.kvLabel, { color: colors.blue }]}>📘 Instructions: how to use the app</Text>
          <MaterialCommunityIcons name="chevron-right" size={20} color={colors.blue} />
        </Pressable>
      </Section>
    </Screen>
  );
}

function Section({ icon, title, hint, children }: { icon: ComponentProps<typeof MaterialCommunityIcons>['name']; title: string; hint?: string; children: ReactNode }) {
  return (
    <Card>
      <View style={styles.sectionHead}>
        <View style={styles.sectionIcon}><MaterialCommunityIcons name={icon} size={18} color={colors.blue} /></View>
        <View style={{ flex: 1 }}>
          <Text style={styles.sectionTitle}>{title}</Text>
          {hint ? <Muted>{hint}</Muted> : null}
        </View>
      </View>
      {children}
    </Card>
  );
}

function SwitchRow({ label, hint, value, onChange, disabled }: { label: string; hint?: string; value: boolean; onChange?: (v: boolean) => void; disabled?: boolean }) {
  return (
    <View style={styles.switchRow}>
      <View style={{ flex: 1, paddingRight: 10 }}>
        <Text style={styles.switchLabel}>{label}</Text>
        {hint ? <Muted>{hint}</Muted> : null}
      </View>
      <Switch value={value} onValueChange={onChange} disabled={disabled} />
    </View>
  );
}

const styles = StyleSheet.create({
  sectionHead: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 14 },
  sectionIcon: { width: 34, height: 34, borderRadius: 10, backgroundColor: colors.blueSoft, alignItems: 'center', justifyContent: 'center' },
  sectionTitle: { fontSize: 16, fontWeight: '800', color: colors.ink },
  sub: { fontSize: 14, fontWeight: '800', color: colors.ink, marginTop: 4, marginBottom: 2 },
  kv: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#eef1f6' },
  kvLabel: { color: colors.muted, fontSize: 13, fontWeight: '600' },
  kvValue: { color: colors.ink, fontSize: 14, fontWeight: '600', flexShrink: 1, textAlign: 'right' },
  switchRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 8, marginBottom: 8 },
  switchLabel: { color: colors.ink2, fontSize: 14, fontWeight: '700' },
  prefHead: { flexDirection: 'row', alignItems: 'center', paddingBottom: 6, borderBottomWidth: 1, borderBottomColor: colors.line },
  prefCol: { width: 56, textAlign: 'center', fontSize: 11, fontWeight: '800', color: colors.muted, textTransform: 'uppercase' },
  prefRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 6, borderBottomWidth: 1, borderBottomColor: '#eef1f6' },
  prefLabel: { flex: 1, color: colors.ink2, fontSize: 13, fontWeight: '600', paddingRight: 6 },
  prefCell: { width: 56, alignItems: 'center' },
  always: { fontSize: 11, color: colors.faint },
});
