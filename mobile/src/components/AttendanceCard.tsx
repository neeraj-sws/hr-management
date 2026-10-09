import { useEffect, useRef, useState } from 'react';
import { Text, View } from 'react-native';
import type { useAttendance } from '../lib/useAttendance';
import { Badge, Button, Card, colors, confirmAction, Eyebrow, formatTime, Notice } from './ui';

/** Today's attendance with a live worked-time counter and the next allowed actions. */
export function AttendanceCard({ attendance }: { attendance: ReturnType<typeof useAttendance> }) {
  const { session, busy, message, act } = attendance;
  const [tick, setTick] = useState(Date.now());
  // worked_seconds is computed by the server when the session was fetched; count up from then.
  const fetchedAt = useRef(Date.now());

  useEffect(() => {
    fetchedAt.current = Date.now();
    setTick(Date.now());
  }, [session]);

  useEffect(() => {
    if (session?.status !== 'working') return;
    const timer = setInterval(() => setTick(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [session?.status]);

  const live = session?.status === 'working' ? Math.floor((tick - fetchedAt.current) / 1000) : 0;
  const worked = Math.max(0, (session?.worked_seconds ?? session?.active_seconds ?? 0) + Math.max(0, live));
  const clock = `${String(Math.floor(worked / 3600)).padStart(2, '0')}:${String(Math.floor((worked % 3600) / 60)).padStart(2, '0')}:${String(worked % 60).padStart(2, '0')}`;

  return (
    <Card>
      <Eyebrow>Today</Eyebrow>
      <Text style={{ fontSize: 40, fontWeight: '800', color: colors.navy, fontVariant: ['tabular-nums'] }}>{session ? clock : '--:--:--'}</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'center', marginVertical: 8 }}>
        {session ? (
          <>
            <Text style={{ color: colors.ink2 }}>In {formatTime(session.check_in)}</Text>
            {session.check_out ? <Text style={{ color: colors.ink2 }}>· Out {formatTime(session.check_out)}</Text> : null}
            <Badge label={session.status} />
          </>
        ) : (
          <Text style={{ color: colors.muted }}>Not checked in yet</Text>
        )}
      </View>
      {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
      <View style={{ gap: 8 }}>
        {!session && <Button title="Check in" tone="primary" onPress={() => act('check-in')} loading={busy === 'check-in'} />}
        {session && !session.check_out && session.status !== 'on_break' && <Button title="Start break" onPress={() => act('break-start')} loading={busy === 'break-start'} />}
        {session && session.status === 'on_break' && <Button title="Resume work" tone="success" onPress={() => act('break-end')} loading={busy === 'break-end'} />}
        {session && !session.check_out && <Button title="Check out" tone="danger" onPress={async () => { if (await confirmAction('Check out?', 'This ends your work day. You cannot check in again today.', 'Check out')) act('check-out'); }} loading={busy === 'check-out'} />}
        {session?.check_out ? <Badge label="Day completed" tone="green" /> : null}
      </View>
    </Card>
  );
}
