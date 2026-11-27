# UANG Companion for Android

Android companion app prototype that forwards only clearly recognized SeaBank transaction notifications to the existing UANG PHP API.

## Current scope

- Listens only to package `id.co.bankbkemobile.digitalbank` (SeaBank listing on Google Play).
- Requires the user to enable Android Notification Access manually.
- Parses only notifications containing an explicit Rupiah amount and an unambiguous incoming/outgoing phrase.
- Does not upload or retain the full notification text.
- Stores the device bearer token encrypted with an AES-GCM key protected by Android Keystore.
- Queues delivery with WorkManager while network is unavailable.
- Uses stable event IDs and server-side deduplication.
- Requires HTTPS and a per-device revocable token.

This is a notification-based integration, not a direct SeaBank account API. A transaction without a visible notification, or a notification whose content is hidden/ambiguous, cannot be imported by this method.

## Backend prerequisites

1. Deploy the same UANG branch/files to a staging copy first.
2. Ensure the site uses HTTPS.
3. Ensure the SeaBank account exists in UANG under **Dompet**.
4. Log in to UANG and open `/companion.php`.
5. Create a device token and copy it immediately. It is shown only once.
6. Build/install this Android app, paste the API URL ending in `/api.php` and the device token, then save.
7. Grant Notification Access to **UANG Companion** in Android Settings.
8. Return to the app and use **Uji koneksi ke UANG**.

## Build

Open the `android-companion` folder in Android Studio (JDK 17 and Android SDK 35), allow Gradle sync, then run the app or build:

```bash
gradle :app:assembleDebug
gradle :app:testDebugUnitTest
```

Debug APK output:

```
app/build/outputs/apk/debug/app-debug.apk
```

## API

- `GET api.php?action=companion.ping` – test a registered device token.
- `POST api.php?action=companion.ingest` – ingest a parsed transaction.
- `POST api.php?action=companion.register` – create a device token (web session + CSRF required).
- `POST api.php?action=companion.revoke` – revoke a registered device (web session + CSRF required).
- `GET api.php?action=companion.devices` – list devices (web session required).

The Android app sends only the fields needed to create a pending transaction: event ID, amount, direction, date and a short generic description. Imported entries are marked with `source=android_companion` and `sourceStatus=needs_review`, so category and correctness should be checked in UANG.

## Security notes

- Never enter a bank PIN, password or OTP.
- Treat the device token like a password; anyone holding it can submit transactions to this UANG account.
- Revoke a lost device from `companion.php`.
- Do not use plain HTTP or expose the `data/` directory.
- Notification parsing rules are deliberately conservative and may need adjustment after testing with real, redacted notification examples.
