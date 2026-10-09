import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { configureApi, DEFAULT_API_URL, get, post } from './api';
import { authenticate, biometricEnabled } from './biometric';
import { clearQueue, flush, startAutoSync } from './queue';
import { secure, store } from './storage';
import type { Me } from './types';

const TOKEN_KEY = 'sws.token';
const SERVER_KEY = 'sws.server';

type AuthState = {
  ready: boolean;
  me: Me | null;
  serverUrl: string;
  signIn: (serverUrl: string, email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  refreshMe: () => Promise<void>;
  locked: boolean;
  unlock: () => Promise<void>;
};

const LOCK_AFTER_MS = 5 * 60 * 1000;

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [me, setMe] = useState<Me | null>(null);
  const [serverUrl, setServerUrl] = useState(DEFAULT_API_URL);
  const [locked, setLocked] = useState(false);
  const backgroundSince = useRef<number | null>(null);

  /** Sign-out cleanup: token, cached profile/attendance and queued offline actions. */
  const clearSession = useCallback(async () => {
    await secure.remove(TOKEN_KEY);
    await Promise.all([store.remove('sws.me'), store.remove('sws.today'), clearQueue()]);
    configureApi({ token: null });
    setMe(null);
    setLocked(false);
  }, []);

  const refreshMe = useCallback(async () => {
    const response = await get<{ data: Me }>('/me');
    setMe(response.data);
    await store.setJson('sws.me', response.data); // shown while offline
  }, []);

  useEffect(() => {
    (async () => {
      const savedServer = (await store.getJson<string | null>(SERVER_KEY, null)) ?? DEFAULT_API_URL;
      const token = await secure.get(TOKEN_KEY);
      setServerUrl(savedServer);
      configureApi({ baseUrl: savedServer, token, onUnauthorized: () => void clearSession() });
      if (token) {
        if (await biometricEnabled()) setLocked(true);
        try {
          await refreshMe();
        } catch {
          // Offline: keep the session and show the last known profile.
          setMe(await store.getJson<Me | null>('sws.me', null));
        }
        startAutoSync();
      }
      setReady(true);
    })();
  }, [clearSession, refreshMe]);

  // Lock again when the app comes back after a while in the background.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', async (state) => {
      if (state === 'background') backgroundSince.current = Date.now();
      if (state === 'active' && backgroundSince.current && Date.now() - backgroundSince.current > LOCK_AFTER_MS && (await biometricEnabled())) setLocked(true);
      if (state === 'active') backgroundSince.current = null;
    });
    return () => subscription.remove();
  }, []);

  const unlock = useCallback(async () => {
    if (await authenticate()) setLocked(false);
  }, []);

  const signIn = useCallback(async (url: string, email: string, password: string) => {
    const cleanUrl = url.trim().replace(/\/+$/, '');
    configureApi({ baseUrl: cleanUrl, token: null });
    const response = await post<{ token: string }>('/auth/token', { email: email.trim(), password, device_name: 'SWS HR Mobile' });
    await secure.set(TOKEN_KEY, response.token);
    await store.setJson(SERVER_KEY, cleanUrl);
    setServerUrl(cleanUrl);
    configureApi({ token: response.token });
    await refreshMe();
    startAutoSync();
    flush().catch(() => undefined);
  }, [refreshMe]);

  const signOut = useCallback(async () => {
    try {
      await post('/auth/revoke');
    } catch {
      // Token is discarded locally even if the server is unreachable.
    }
    await clearSession();
  }, [clearSession]);

  const value = useMemo(() => ({ ready, me, serverUrl, signIn, signOut, refreshMe, locked, unlock }), [ready, me, serverUrl, signIn, signOut, refreshMe, locked, unlock]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used inside AuthProvider');
  return context;
}

export function can(me: Me | null, ...permissions: string[]): boolean {
  return !!me && (me.roles.includes('Master Admin') || permissions.some((permission) => me.permissions.includes(permission)));
}
