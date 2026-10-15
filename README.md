# StaffTrack — Setup Guide

## 1. Server (InMotion cPanel)
1. cPanel → **MySQL Databases**: create DB + user, add user to DB (ALL PRIVILEGES).
2. Edit `backend/config.php` → `db_name`, `db_user`, `db_pass`.
3. Upload the whole `backend/` folder to e.g. `public_html/stafftrack/` (File Manager → Upload zip → Extract).
4. Make `uploads/` writable (permission 755).
5. Open `https://YOUR-DOMAIN/stafftrack/install.php` → create the admin account.
6. **Delete `install.php`** from the server.
7. Admin panel: `https://YOUR-DOMAIN/stafftrack/admin/` → add staff (phone + password).

Switch to Google Maps later: set `'map_provider' => 'google'` and `'google_maps_key'` in `config.php`.

## 2. Mobile app (Flutter — Android + iOS)
Install Flutter: https://docs.flutter.dev/get-started/install/windows

```powershell
cd "d:\mobile app\mobile"
flutter create --org lk.galoya --platforms android,ios .
flutter pub get
```
Edit `lib/config.dart` → `kServerBase = 'https://YOUR-DOMAIN/stafftrack'`.

### Android — `android/app/src/main/AndroidManifest.xml`
Inside `<manifest>`:
```xml
<uses-permission android:name="android.permission.INTERNET"/>
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION"/>
<uses-permission android:name="android.permission.FOREGROUND_SERVICE"/>
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION"/>
<uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>
<uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED"/>
<uses-permission android:name="android.permission.WAKE_LOCK"/>
<uses-permission android:name="android.permission.CAMERA"/>
```
Inside `<application>`:
```xml
<service android:name="id.flutter.flutter_background_service.BackgroundService"
    android:foregroundServiceType="location" android:exported="false" tools:replace="android:exported"/>
```
and add `xmlns:tools="http://schemas.android.com/tools"` to the `<manifest>` tag.
In `android/app/build.gradle`: `minSdkVersion 23`.

### iOS — `ios/Runner/Info.plist`
```xml
<key>NSLocationWhenInUseUsageDescription</key><string>Needed to record your work location.</string>
<key>NSLocationAlwaysAndWhenInUseUsageDescription</key><string>Needed to track your location while on duty.</string>
<key>NSCameraUsageDescription</key><string>Needed for task before/after photos.</string>
<key>UIBackgroundModes</key><array><string>location</string><string>fetch</string></array>
```
iOS build needs a Mac with Xcode (or Codemagic cloud build).

### Run / build
```powershell
flutter run                      # test on phone
flutter build apk --release      # Android APK
flutter build ipa                # iOS (on Mac)
```

## How offline works
- Every GPS point is saved in SQLite on the phone first.
- Duty on/off and task photos go into an upload queue.
- When internet returns (or every interval tick) everything uploads in batches of 500.
- Server ignores duplicates, so retries are safe.

## Staff phones (Android)
Tell staff: Location permission → **Allow all the time**, and Battery → **Unrestricted** for StaffTrack,
otherwise some phones (Xiaomi, Oppo, Samsung) stop background tracking.
