import { useEffect, useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { getBaseUrl, getToken } from '../lib/api';
import { colors } from './ui';

/** "RG" for Risha Gangrade. */
export function initials(name?: string): string {
  return (name ?? '').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || 'SW';
}

/** Photos already loaded, by address (a new photo has a new ?v= address). */
const loaded = new Map<string, string>();

/**
 * The address on the server the app is signed in to. The server may hand back its own name with http://
 * (blocked on phones) or a different host, so only the path and ?v= are kept.
 */
function photoAddress(photoUrl?: string | null): string | null {
  if (!photoUrl) return null;
  const at = photoUrl.indexOf('/api/');
  return at >= 0 ? `${getBaseUrl()}${photoUrl.slice(at)}` : photoUrl.startsWith('/') ? `${getBaseUrl()}${photoUrl}` : photoUrl;
}

/** Downloads the photo with the sign-in token and keeps it as a data: address (works on phones and in a browser). */
async function loadPhoto(uri: string, token: string): Promise<string> {
  const cached = loaded.get(uri);
  if (cached) return cached;
  const response = await fetch(uri, { headers: { Authorization: `Bearer ${token}`, Accept: 'image/*' } });
  if (!response.ok) throw new Error(`photo ${response.status}`);
  const blob = await response.blob();
  const dataUri = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => (typeof reader.result === 'string' ? resolve(reader.result) : reject(new Error('photo unreadable')));
    reader.onerror = () => reject(new Error('photo unreadable'));
    reader.readAsDataURL(blob);
  });
  loaded.set(uri, dataUri);
  return dataUri;
}

/**
 * The profile photo set on the website (or here), loaded from /api/me/photo with the sign-in token;
 * the URL changes when the photo changes, so a new photo shows at once. Initials when there is none.
 */
export function Avatar({ name, photoUrl, size = 46, light = false }: { name?: string; photoUrl?: string | null; size?: number; light?: boolean }) {
  const token = getToken();
  const uri = photoAddress(photoUrl);
  const [source, setSource] = useState<string | null>(uri ? loaded.get(uri) ?? null : null);
  const round = { width: size, height: size, borderRadius: size / 2 };
  // A new photo (set here or on the website) has a new address: load it, even if an older one failed.
  useEffect(() => {
    let alive = true;
    if (!uri || !token) { setSource(null); return; }
    setSource(loaded.get(uri) ?? null);
    loadPhoto(uri, token).then((data) => { if (alive) setSource(data); }).catch(() => { if (alive) setSource(null); });
    return () => { alive = false; };
  }, [uri, token]);

  if (source) {
    return <Image source={{ uri: source }} style={[round, styles.photo]} onError={() => setSource(null)} accessibilityLabel={name} />;
  }

  return (
    <View style={[round, styles.initials, light && styles.initialsLight]}>
      <Text style={[styles.text, light && styles.textLight, { fontSize: size * 0.35 }]}>{initials(name)}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  photo: { backgroundColor: colors.line },
  initials: { backgroundColor: colors.navy, alignItems: 'center', justifyContent: 'center' },
  text: { color: colors.white, fontWeight: '800' },
  // On a dark header: cream circle with brown initials.
  initialsLight: { backgroundColor: '#f8ecd4' },
  textLight: { color: '#6b4500' },
});
