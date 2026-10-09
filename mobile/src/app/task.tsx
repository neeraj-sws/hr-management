import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { Badge, Button, Card, colors, Empty, Field, formatDate, formatDuration, H2, Muted, Notice, Screen } from '../components/ui';
import { ApiError, get, post } from '../lib/api';
import type { Paginated, Task } from '../lib/types';

type Comment = { id: number; author: string | null; body: string; created_at: string };

/** One task: details, its subtasks and the comment thread. */
export default function TaskScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [task, setTask] = useState<(Task & { parent_id?: number | null }) | null>(null);
  const [subtasks, setSubtasks] = useState<Task[]>([]);
  const [comments, setComments] = useState<Comment[]>([]);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [all, thread] = await Promise.all([get<Paginated<Task & { parent_id?: number | null }>>('/tasks?status='), get<{ data: Comment[] }>(`/tasks/${id}/comments`)]);
      setTask(all.data.find((t) => String(t.id) === String(id)) ?? null);
      setSubtasks(all.data.filter((t) => String(t.parent_id) === String(id)));
      setComments(thread.data);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not load the task.');
    }
  }, [id]);
  useEffect(() => { load(); }, [load]);

  const send = async () => {
    if (!body.trim()) return;
    setBusy(true);
    try {
      const created = (await post<{ data: Comment }>(`/tasks/${id}/comments`, { body })).data;
      setComments((current) => [created, ...current]);
      setBody('');
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Could not post the comment.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      {error ? <Notice tone="error" text={error} /> : null}
      {task ? (
        <Card>
          <H2>{task.title}</H2>
          <View style={{ flexDirection: 'row', gap: 6, marginBottom: 6 }}><Badge label={task.overdue ? 'overdue' : task.status} /><Badge label={task.priority} /></View>
          <Muted>{task.project?.name ?? 'No project'} · {task.due_on ? `due ${formatDate(task.due_on)}` : 'no due date'} · logged {formatDuration(task.logged_seconds)}</Muted>
          {task.description ? <Text style={{ marginTop: 8, color: colors.ink2 }}>{task.description}</Text> : null}
        </Card>
      ) : <Card><Empty text="Loading…" /></Card>}
      <Card>
        <H2>Subtasks</H2>
        {subtasks.length ? subtasks.map((s) => <View key={s.id} style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 }}><Text style={{ flex: 1, color: colors.ink2 }}>{s.title}</Text><Badge label={s.status} /></View>) : <Muted>No subtasks.</Muted>}
      </Card>
      <Card>
        <H2>Comments</H2>
        <Field label="Add a comment" value={body} onChangeText={setBody} multiline placeholder="Question, update or blocker" />
        <Button title="Post" tone="primary" small onPress={send} loading={busy} />
        <View style={{ marginTop: 10 }}>
          {comments.length ? comments.map((c) => (
            <View key={c.id} style={{ paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: '#eef1f6' }}>
              <Text style={{ fontWeight: '700', color: colors.ink2 }}>{c.author ?? 'Former user'} <Text style={{ fontWeight: '400', color: colors.faint }}>· {formatDate(c.created_at)}</Text></Text>
              <Text style={{ color: colors.ink2 }}>{c.body}</Text>
            </View>
          )) : <Muted>No comments yet.</Muted>}
        </View>
      </Card>
    </Screen>
  );
}
