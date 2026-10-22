import { type ReactNode, useEffect, useRef } from 'react';
import { KeyboardAvoidingView, Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors } from './ui';

/**
 * A form in a popup that slides up from the bottom, opened from an "Add" button —
 * so a screen shows its list first instead of a form you have to scroll past.
 * Moves up with the keyboard and scrolls when the form is long.
 */
export function FormSheet({ visible, title, onClose, children }: { visible: boolean; title: string; onClose: () => void; children: ReactNode }) {
  const { bottom } = useSafeAreaInsets();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <KeyboardAvoidingView behavior="padding" style={styles.wrap}>
        <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Close" />
        <View style={[styles.sheet, { paddingBottom: 16 + bottom }]}>
          <View style={styles.handle} />
          <View style={styles.head}>
            <Text style={styles.title}>{title}</Text>
            <Pressable onPress={onClose} hitSlop={10} accessibilityRole="button" accessibilityLabel="Close"><Text style={styles.close}>✕</Text></Pressable>
          </View>
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: 8 }}>{children}</ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

/** "+ Add …" button shown at the top of a screen to open its form. */
export function AddButton({ title, onPress }: { title: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.add, pressed && { opacity: 0.85 }]} accessibilityRole="button">
      <Text style={styles.addText}>＋ {title}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, justifyContent: 'flex-end' },
  backdrop: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: 'rgba(15, 23, 42, 0.45)' },
  sheet: { backgroundColor: colors.white, borderTopLeftRadius: 22, borderTopRightRadius: 22, paddingHorizontal: 18, paddingTop: 8, maxHeight: '90%' },
  handle: { alignSelf: 'center', width: 42, height: 5, borderRadius: 3, backgroundColor: colors.line, marginBottom: 8 },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  title: { fontSize: 18, fontWeight: '800', color: colors.ink },
  close: { fontSize: 18, color: colors.muted, paddingHorizontal: 4 },
  add: { backgroundColor: colors.blue, borderRadius: 12, paddingVertical: 13, alignItems: 'center' },
  addText: { color: colors.white, fontWeight: '700', fontSize: 15 },
});

/**
 * Closes a form popup once its submit finished without an error (sent, or saved offline);
 * on an error it stays open so the person can fix the form.
 */
export function useCloseOnSuccess(busy: boolean, message: { tone: string } | null, close: () => void) {
  const wasBusy = useRef(false);
  useEffect(() => {
    if (wasBusy.current && !busy && message && message.tone !== 'error') close();
    wasBusy.current = busy;
  }, [busy, message, close]);
}
