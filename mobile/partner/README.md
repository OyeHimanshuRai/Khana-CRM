# Restro One Partner

Staff app (captain and owner) for Restro One. API: `docs/PARTNER-APP-API.md`.

## Run

```
flutter pub get
flutter run --dart-define=PARTNER_CLIENT_ID=<id from khana:clients> --dart-define=BASE_URL=https://khana.factualtechnologies.tech
```

- Without `PARTNER_CLIENT_ID` the app shows only email and password sign-in (works where no Accounts is set up, e.g. local).
- Local server from the Android emulator: `--dart-define=BASE_URL=http://10.0.2.2:8000`.
- Release APK: `flutter build apk --release --dart-define=...` (needs your own signing key in `android/app/build.gradle.kts`).

## Screens

Sign in, outlet switch (tap the title), tables, table bill, captain order, dashboard. A tab shows only when the role's abilities allow it.
Push notifications (Firebase) come later.
