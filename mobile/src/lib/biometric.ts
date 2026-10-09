import * as LocalAuthentication from 'expo-local-authentication';
import { Platform } from 'react-native';
import { store } from './storage';

const KEY = 'sws.biometric';

/** True when the phone has a fingerprint/face sensor with something enrolled. */
export async function biometricAvailable(): Promise<boolean> {
  if (Platform.OS === 'web') return false;
  return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
}

export async function biometricEnabled(): Promise<boolean> {
  return (await store.getJson<boolean>(KEY, false)) && (await biometricAvailable());
}

export async function setBiometricEnabled(enabled: boolean): Promise<void> {
  await store.setJson(KEY, enabled);
}

/** Asks for fingerprint/face (or the phone's PIN as fallback). */
export async function authenticate(reason = 'Unlock SWS HR'): Promise<boolean> {
  const result = await LocalAuthentication.authenticateAsync({ promptMessage: reason, cancelLabel: 'Cancel', disableDeviceFallback: false });
  return result.success;
}
