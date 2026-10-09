import * as Location from 'expo-location';

export type Position = { latitude: number; longitude: number; accuracy: number | null };

/**
 * One-off position for check-in/out when the company requires it.
 * Never watches or tracks: a single reading, then nothing.
 */
export async function currentPosition(): Promise<Position> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (permission.status !== 'granted') throw new Error('Allow location access for SWS HR to check in. Your company requires check-in at an office.');
  if (!(await Location.hasServicesEnabledAsync())) throw new Error('Turn on location (GPS) on your phone, then try again.');
  const reading = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });

  return { latitude: reading.coords.latitude, longitude: reading.coords.longitude, accuracy: reading.coords.accuracy ?? null };
}
