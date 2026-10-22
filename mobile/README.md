# SWS HR — Mobile app (Expo / React Native)

Employee app for the SWS HR API: login, dashboard, attendance (check-in, break, resume, check-out, 30-day history), tasks with a live timer, leave (balance, request, cancel), payslips, notifications, approvals (for managers/HR) and an offline queue.

## Run

```bash
npm install
npx expo start          # scan the QR code with Expo Go (Android/iOS)
npx expo start --web    # quick check in a browser
```

On the sign-in screen enter the **server address** of the Laravel app (for a phone on the same Wi-Fi use the PC's LAN IP, e.g. `http://192.168.1.20:8010`, and start Laravel with `php artisan serve --host=0.0.0.0 --port=8010`).

## Offline behaviour

If the server cannot be reached, attendance and task actions are stored on the phone (with the time they happened) and sent through `POST /api/sync/actions` when the connection returns — automatically every 30 s or from **More → Offline sync → Sync now**. Every action has a unique id, so retries never create duplicates; if a task changed on the server meanwhile the action shows as a *conflict* and can be discarded.

## Security

The API token is stored in the OS keychain/keystore (`expo-secure-store`); on web it falls back to `localStorage` (preview only).

## Checks

```bash
npx tsc --noEmit
```

## Release builds

Use EAS: `npx eas-cli@latest build -p android` (or `-p ios`). Change `extra.apiUrl` in `app.json` to your production URL first.
