import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { Badge, Button, Card, confirmAction, H2, Muted, Notice, Row, Screen } from '../../components/ui';
import { authenticate, biometricAvailable, biometricEnabled, setBiometricEnabled } from '../../lib/biometric';
import { get, getBaseUrl } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { subscribe } from '../../lib/queue';
import type { Approval } from '../../lib/types';

export default function More() {
  const { me, signOut } = useAuth();
  const router = useRouter();
  const [approvals, setApprovals] = useState(0);
  const [queue, setQueue] = useState({ queued: 0, problems: 0 });
  const [bioAvailable, setBioAvailable] = useState(false);
  const [bioOn, setBioOn] = useState(false);
  const [bioMessage, setBioMessage] = useState<string | null>(null);
  const isManager = !!me && (me.roles.includes('Manager') || me.roles.includes('HR') || me.roles.includes('Master Admin'));
  useEffect(() => { (async () => { setBioAvailable(await biometricAvailable()); setBioOn(await biometricEnabled()); })(); }, []);
  const toggleBiometric = async () => {
    if (bioOn) { await setBiometricEnabled(false); setBioOn(false); setBioMessage('Biometric unlock turned off.'); return; }
    if (await authenticate('Confirm to turn on biometric unlock')) { await setBiometricEnabled(true); setBioOn(true); setBioMessage('The app will ask for your fingerprint/face when opened.'); }
  };

  useFocusEffect(useCallback(() => {
    get<{ data: Approval[] }>('/approvals').then((r) => setApprovals(r.data.length)).catch(() => undefined);
  }, []));
  useEffect(() => subscribe((items) => setQueue({ queued: items.filter((i) => i.status === 'queued').length, problems: items.filter((i) => i.status !== 'queued').length })), []);

  return (
    <Screen>
      <Card>
        <H2>{me?.name ?? ''}</H2>
        <Muted>{me?.email}</Muted>
        <Muted>{me?.roles.join(', ')}</Muted>
        {me?.employee ? (
          <View style={{ marginTop: 10 }}>
            <Row title="Employee code" right={<Muted>{me.employee.employee_code}</Muted>} />
            <Row title="Department" right={<Muted>{me.employee.department?.name ?? '—'}</Muted>} />
            <Row title="Manager" right={<Muted>{me.employee.manager?.name ?? '—'}</Muted>} />
            <Row title="Shift today" right={<Muted>{me.schedule?.shift ? `${me.schedule.shift} · ${me.schedule.starts_at}` : me.schedule?.starts_at ?? 'Flexible'}</Muted>} />
          </View>
        ) : null}
      </Card>
      <Card>
        {isManager ? <Row title="My team today" subtitle="Who is in, late, on break, blockers" onPress={() => router.push('/team')} right={<Muted>›</Muted>} /> : null}
        <Row title="Daily status" subtitle="Plan, completed work and blockers" onPress={() => router.push('/daily-status')} right={<Muted>›</Muted>} />
        <Row title="Overtime" subtitle="Request and track overtime" onPress={() => router.push('/overtime')} right={<Muted>›</Muted>} />
        <Row title="Expense claims" subtitle="Claim travel, food and other work costs" onPress={() => router.push('/expenses')} right={<Muted>›</Muted>} />
        <Row title="Attendance correction" subtitle="Forgot to check out? Ask your manager" onPress={() => router.push('/corrections')} right={<Muted>›</Muted>} />
        <Row title="My training" subtitle="Courses assigned to you" onPress={() => router.push('/training')} right={<Muted>›</Muted>} />
        <Row title="Payslips" subtitle="Salary slips and payments" onPress={() => router.push('/payslips')} right={<Muted>›</Muted>} />
        <Row title="Documents & assets" subtitle="Your files and company equipment" onPress={() => router.push('/documents')} right={<Muted>›</Muted>} />
        <Row title="My performance" subtitle="Task stats and reviews" onPress={() => router.push('/performance')} right={<Muted>›</Muted>} />
        <Row title="Announcements" subtitle="Company news and notices" onPress={() => router.push('/announcements')} right={<Muted>›</Muted>} />
        <Row title="Holidays" subtitle="Holiday calendar and weekly off" onPress={() => router.push('/holidays')} right={<Muted>›</Muted>} />
        <Row title="Notifications" subtitle={`${me?.unread_notifications ?? 0} unread`} onPress={() => router.push('/notifications')} right={me?.unread_notifications ? <Badge label={String(me.unread_notifications)} tone="red" /> : <Muted>›</Muted>} />
        {approvals > 0 ? <Row title="Approvals" subtitle="Requests waiting for you" onPress={() => router.push('/approvals')} right={<Badge label={String(approvals)} tone="amber" />} /> : null}
        <Row title="Offline sync" subtitle={`${queue.queued} queued${queue.problems ? ` · ${queue.problems} need attention` : ''}`} onPress={() => router.push('/sync')} right={<Muted>›</Muted>} />
      </Card>
      {bioAvailable ? (
        <Card>
          <Row title="Unlock with fingerprint / face" subtitle={bioOn ? 'On — asked when the app opens' : 'Off'} onPress={toggleBiometric} right={<Badge label={bioOn ? 'on' : 'off'} tone={bioOn ? 'green' : 'gray'} />} />
          {bioMessage ? <Notice tone="info" text={bioMessage} /> : null}
        </Card>
      ) : null}
      <Muted>Server: {getBaseUrl()}</Muted>
      <Button title="Sign out" tone="danger" onPress={async () => { if (await confirmAction('Sign out?', queue.queued ? `${queue.queued} offline action(s) have not synced yet and will be deleted from this phone.` : 'You will need your email and password to sign in again.', 'Sign out')) signOut(); }} />
    </Screen>
  );
}
