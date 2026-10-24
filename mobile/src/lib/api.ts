import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * Default server from app.json `extra.apiUrl`. On a phone running a dev build
 * (Expo Go), "localhost" would mean the phone itself, so it is replaced with
 * the computer's LAN address that the app bundle was loaded from.
 */
function defaultApiUrl(): string {
  const configured = (Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl ?? 'http://localhost:8020';
  const devHost = Constants.expoConfig?.hostUri?.split(':')[0];
  if (Platform.OS !== 'web' && devHost && /\/\/(localhost|127\.0\.0\.1)(:|\/|$)/.test(configured)) {
    return configured.replace(/\/\/(localhost|127\.0\.0\.1)/, `//${devHost}`);
  }
  return configured;
}

export const DEFAULT_API_URL: string = defaultApiUrl();

/** Error raised for any non-2xx response, or with status 0 when the server could not be reached. */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: Record<string, string[]> = {},
    public readonly body: unknown = null,
  ) {
    super(message);
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }

  /** First validation message, falling back to the general message. */
  get firstMessage(): string {
    const first = Object.values(this.errors)[0]?.[0];
    return first ?? this.message;
  }
}

let baseUrl = DEFAULT_API_URL;
let token: string | null = null;
let onUnauthorized: (() => void) | null = null;

export function configureApi(options: { baseUrl?: string; token?: string | null; onUnauthorized?: () => void }) {
  if (options.baseUrl !== undefined) baseUrl = options.baseUrl.replace(/\/+$/, '');
  if (options.token !== undefined) token = options.token;
  if (options.onUnauthorized !== undefined) onUnauthorized = options.onUnauthorized;
}

export function getBaseUrl(): string {
  return baseUrl;
}

/** The sign-in token, e.g. to load the profile photo (an image request cannot go through api()). */
export function getToken(): string | null {
  return token;
}

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE';

/** body may be a FormData (file uploads); anything else is sent as JSON. */
export async function api<T = any>(method: Method, path: string, body?: unknown, timeoutMs = 15000, extraHeaders: Record<string, string> = {}): Promise<T> {
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  let response: Response;
  try {
    response = await fetch(`${baseUrl}/api${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        ...(isForm ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...extraHeaders,
      },
      body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
      signal: controller.signal,
    });
  } catch (err) {
    // Keep the phone's own reason (e.g. "Network request failed", a timeout) for screens that show details.
    throw new ApiError('Cannot reach the SWS HR server. Check your connection.', 0, {}, err instanceof Error ? err.message : String(err));
  } finally {
    clearTimeout(timer);
  }

  const text = await response.text();
  let data: any = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = text;
  }

  // 207 is used by /sync/actions for partially successful batches.
  if (response.ok) return data as T;
  if (response.status === 401) onUnauthorized?.();
  // Server faults carry debug details (SQL, stack) that mean nothing to an employee.
  if (response.status >= 500) throw new ApiError('The SWS HR server had a problem. Please try again in a minute, or tell your HR administrator.', response.status, {}, data);
  const message = (data && typeof data === 'object' && data.message) || `Request failed (${response.status})`;
  throw new ApiError(message, response.status, (data && data.errors) || {}, data);
}

export const get = <T = any>(path: string) => api<T>('GET', path);
export const post = <T = any>(path: string, body?: unknown, headers?: Record<string, string>) => api<T>('POST', path, body ?? {}, 15000, headers);
export const upload = <T = any>(path: string, form: FormData, headers?: Record<string, string>) => api<T>('POST', path, form, 60000, headers);
