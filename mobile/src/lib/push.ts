import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { Platform } from 'react-native';
import { post } from './api';
import { store } from './storage';

/**
 * Phone notifications. The phone asks for permission once after sign-in and
 * sends its Expo push token to the server; sign-out removes it again.
 *
 * Needs a real phone and an app build with an EAS project id
 * (app.json → extra.eas.projectId). Expo Go on Android cannot receive remote
 * pushes, so there it quietly does nothing — in-app notifications still work.
 */
const TOKEN_KEY = 'sws.push.token';

Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
});

function projectId(): string | undefined {
  const extra = Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined;
  return extra?.eas?.projectId ?? Constants.easConfig?.projectId;
}

/** Why push cannot work on this phone/build, or null when it can. */
export function pushUnavailableReason(): string | null {
  if (Platform.OS === 'web') return 'Phone notifications work in the phone app only.';
  if (!Device.isDevice) return 'Phone notifications need a real phone, not an emulator.';
  if (Platform.OS === 'android' && Constants.executionEnvironment === ExecutionEnvironment.StoreClient) return 'Expo Go cannot receive phone notifications; the installed SWS HR app can.';
  if (!projectId()) return 'This app build is not set up for phone notifications yet.';
  return null;
}

export async function registerForPush(): Promise<void> {
  if (pushUnavailableReason()) return;
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', { name: 'SWS HR', importance: Notifications.AndroidImportance.DEFAULT });
  }
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
  if (status !== 'granted') return;
  const token = (await Notifications.getExpoPushTokenAsync({ projectId: projectId() })).data;
  await post('/push-tokens', { token, platform: Platform.OS });
  await store.setJson(TOKEN_KEY, token);
}

/** Called before the sign-in token is revoked so this phone stops getting someone else's notifications. */
export async function unregisterPush(): Promise<void> {
  const token = await store.getJson<string | null>(TOKEN_KEY, null);
  if (!token) return;
  try {
    await post('/push-tokens/remove', { token });
  } finally {
    await store.remove(TOKEN_KEY);
  }
}

let listening = false;
/** Tapping a notification opens the notifications screen. */
export function listenForTaps() {
  if (listening || Platform.OS === 'web') return;
  listening = true;
  Notifications.addNotificationResponseReceivedListener(() => router.push('/notifications'));
}
