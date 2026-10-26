import { useEffect, useState } from 'react';
import { Image, Platform, StyleSheet, Text, View } from 'react-native';
import { getBaseUrl, getToken } from '../lib/api';
import { colors } from './ui';

/** "RG" for Risha Gangrade. */
export function initials(name?: string): string {
  return (name ?? '').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || 'SW';
}

/**
 * The profile photo set on the website (or here), loaded from /api/me/photo with the sign-in token;
 * the URL changes when the photo changes, so a new photo shows at once. Initials when there is none.
 */
export function Avatar({ name, photoUrl, size = 46, light = false }: { name?: string; photoUrl?: string | null; size?: number; light?: boolean }) {
  const [failed, setFailed] = useState(false);
  const token = getToken();
  const round = { width: size, height: size, borderRadius: size / 2 };
  const uri = photoUrl && photoUrl.startsWith('/') ? `${getBaseUrl()}${photoUrl}` : photoUrl;
  // A new photo (set here or on the website) has a new address: try it, even if an older one failed to load.
  useEffect(() => { setFailed(false); }, [uri]);
  // A browser <img> cannot send the sign-in token, so the web preview shows initials.
  if (uri && token && !failed && Platform.OS !== 'web') {
    return <Image source={{ uri, headers: { Authorization: `Bearer ${token}`, Accept: 'image/*' } }} style={[round, styles.photo]} onError={() => setFailed(true)} accessibilityLabel={name} />;
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
