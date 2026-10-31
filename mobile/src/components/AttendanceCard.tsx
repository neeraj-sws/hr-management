import { useEffect, useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { clock as liveClock, liveFigures } from '../lib/live';
import type { useAttendance } from '../lib/useAttendance';
import { useAuth } from '../lib/auth';
import { Badge, Button, Card, colors, confirmAction, Eyebrow, formatDuration, formatTime, Muted, Notice } from './ui';

/** Today's attendance with a live worked-time counter and the next allowed actions. */
export function AttendanceCard({ attendance }: { attendance: ReturnType<typeof useAttendance> }) {
  const { session, busy, message, act } = attendance;
  const { me } = useAuth();
  const [tick, setTick] = useState(Date.now());
  // worked_seconds is computed by the server when the session was fetched; count up from then.
  const fetchedAt = useRef(Date.now());

  useEffect(() => {
    fetchedAt.current = Date.now();
    setTick(Date.now());
  }, [session]);

  useEffect(() => {
    if (session?.status !== 'working' && session?.status !== 'on_break') return;
    const timer = setInterval(() => setTick(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [session?.status]);

  // The same live rule as the website, the desktop app and the home header (lib/live.ts).
  const v = liveFigures(session, (tick - fetchedAt.current) / 1000);
  const worked = v.worked;
  const clock = liveClock(worked);

  // Hooks above always run (React rule); decide what to show afterwards.
  const mobileAllowed = (me?.policies?.attendance_sources ?? ['mobile']).includes('mobile');

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
      {session ? (
        // The same figures as the website and the desktop app (from the server).
        <Muted style={{ marginBottom: 8 }}>
          {`Break ${formatDuration(v.brk)} · Idle ${formatDuration(v.idle)}`
            + (v.meeting ? ` · Meeting ${formatDuration(v.meeting)}` : '')
            + (v.active + v.sIdle + v.meeting > 0
              ? `\nScreen time: ${formatDuration(v.active + v.meeting)} in use` + (v.meeting ? ` (incl. ${formatDuration(v.meeting)} meeting)` : '')
                + ((session.screen_corrected_seconds ?? 0) >= 60 ? ` · ${formatDuration(session.screen_corrected_seconds ?? 0)} added by correction` : '')
                + ((session.screen_untracked_seconds ?? 0) >= 60 ? ` · ${formatDuration(session.screen_untracked_seconds ?? 0)} not tracked` : '')
              : '')}
        </Muted>
      ) : null}
      {message ? <View style={{ marginBottom: 10 }}><Notice tone={message.tone} text={message.text} /></View> : null}
      {mobileAllowed ? (
        <View style={{ gap: 8 }}>
          {!session && <Button title="Check in" tone="primary" onPress={() => act('check-in')} loading={busy === 'check-in'} />}
          {session && !session.check_out && session.status !== 'on_break' && <Button title="Start break" onPress={() => act('break-start')} loading={busy === 'break-start'} />}
          {session && session.status === 'on_break' && <Button title="Resume work" tone="success" onPress={() => act('break-end')} loading={busy === 'break-end'} />}
          {session && !session.check_out && <Button title="Check out" tone="danger" onPress={async () => { if (await confirmAction('Check out?', 'This ends your work day. You cannot check in again today.', 'Check out')) act('check-out'); }} loading={busy === 'check-out'} />}
          {session?.check_out ? <Badge label="Day completed" tone="green" /> : null}
        </View>
      ) : (
        <Muted style={{ marginTop: 2 }}>Attendance from the phone is turned off for you. Please use the website or desktop app.</Muted>
      )}
    </Card>
  );
}
