import 'dart:async';
import 'dart:io';
import 'dart:ui';

import 'package:battery_plus/battery_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'db.dart';
import 'sync.dart';

const _channelId = 'stafftrack_tracking';

/// GPS tracking that keeps working when the app is closed / offline.
/// Android: foreground service isolate.  iOS: background location stream.
class Tracker {
  static StreamSubscription<Position>? _iosSub;
  static int _iosLast = 0;

  static Future<void> init() async {
    if (!Platform.isAndroid) return;
    final n = FlutterLocalNotificationsPlugin();
    await n
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(const AndroidNotificationChannel(
            _channelId, 'Duty tracking', description: 'Shown while you are on duty', importance: Importance.low));
    await FlutterBackgroundService().configure(
      androidConfiguration: AndroidConfiguration(
        onStart: _serviceMain,
        autoStart: false,
        autoStartOnBoot: true,
        isForegroundMode: true,
        notificationChannelId: _channelId,
        initialNotificationTitle: 'StaffTrack',
        initialNotificationContent: 'On duty – location tracking active',
        foregroundServiceNotificationId: 888,
        foregroundServiceTypes: [AndroidForegroundType.location],
      ),
      iosConfiguration: IosConfiguration(autoStart: false),
    );
  }

  static Future<bool> ensurePermissions() async {
    try {
      await Permission.notification.request();
      await Permission.camera.request();
    } catch (_) {}

    if (!await Geolocator.isLocationServiceEnabled()) {
      await Geolocator.openLocationSettings();
      return false;
    }
    var p = await Geolocator.checkPermission();
    if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
    if (p == LocationPermission.whileInUse) {
      try {
        await Permission.locationAlways.request();
      } catch (_) {
        p = await Geolocator.requestPermission();
      }
    }
    if (p == LocationPermission.deniedForever) {
      await Geolocator.openAppSettings();
      return false;
    }
    return p == LocationPermission.always || p == LocationPermission.whileInUse;
  }

  static Future<void> start() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setBool('tracking', true);
    if (Platform.isAndroid) {
      final s = FlutterBackgroundService();
      if (!await s.isRunning()) await s.startService();
    } else {
      await _startIos();
    }
  }

  static Future<void> stop() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setBool('tracking', false);
    if (Platform.isAndroid) {
      FlutterBackgroundService().invoke('stop');
    } else {
      await _iosSub?.cancel();
      _iosSub = null;
    }
  }

  /// Call on app launch: resume tracking if staff was on duty.
  static Future<void> resumeIfNeeded() async {
    final sp = await SharedPreferences.getInstance();
    if (sp.getBool('tracking') == true) await start();
  }

  static Future<void> _startIos() async {
    if (_iosSub != null) return;
    final settings = AppleSettings(
      accuracy: LocationAccuracy.high,
      activityType: ActivityType.otherNavigation,
      distanceFilter: 0,
      pauseLocationUpdatesAutomatically: false,
      allowBackgroundLocationUpdates: true,
      showBackgroundLocationIndicator: true,
    );
    _iosSub = Geolocator.getPositionStream(locationSettings: settings).listen((pos) async {
      final interval = await effectiveInterval();
      final now = DateTime.now().millisecondsSinceEpoch;
      if (now - _iosLast < interval * 1000 - 1500) return;
      _iosLast = now;
      await recordPosition(pos);
      Sync.run();
    });
  }

  static Future<void> recordPosition(Position pos) async {
    int? battery;
    try { battery = await Battery().batteryLevel; } catch (_) {}
    await LocalDb.addPoint({
      'lat': pos.latitude, 'lng': pos.longitude, 'accuracy': pos.accuracy,
      'speed': pos.speed, 'battery': battery, 't': pos.timestamp.millisecondsSinceEpoch,
    });
  }
}

/// Android background isolate entry point with Standard Telematics Smart Filtering.
@pragma('vm:entry-point')
void _serviceMain(ServiceInstance service) async {
  DartPluginRegistrant.ensureInitialized();
  WidgetsFlutterBinding.ensureInitialized();
  var running = true;
  StreamSubscription<Position>? streamSub;

  service.on('stop').listen((_) {
    running = false;
    streamSub?.cancel();
    service.stopSelf();
  });

  final sp = await SharedPreferences.getInstance();
  await sp.reload();
  if (sp.getBool('tracking') != true) {
    service.stopSelf();
    return;
  }

  // Telematics tracking state
  double? lastLat;
  double? lastLng;
  double lastHeading = 0.0;
  int lastTime = 0;
  int lastSync = 0;

  final locationSettings = AndroidSettings(
    accuracy: LocationAccuracy.high,
    distanceFilter: 3, // wake hardware on 3m change
    intervalDuration: const Duration(seconds: 2), // poll hardware stream every 2s
    foregroundNotificationConfig: const ForegroundNotificationConfig(
      notificationTitle: 'Galoya Telematics – Active',
      notificationText: 'Tracking location in background',
      enableWakeLock: true,
    ),
  );

  streamSub = Geolocator.getPositionStream(locationSettings: locationSettings).listen((pos) async {
    if (!running) return;

    // 1. Accuracy Filter: Reject wild multipath jumps (> 30 meters)
    if (pos.accuracy > 30.0) return;

    final now = DateTime.now().millisecondsSinceEpoch;
    final int interval = await effectiveInterval(); // e.g. 15s

    bool shouldRecord = false;

    if (lastLat == null || lastLng == null) {
      // First fix
      shouldRecord = true;
    } else {
      final double dist = Geolocator.distanceBetween(lastLat!, lastLng!, pos.latitude, pos.longitude);
      final double headingDelta = (pos.heading - lastHeading).abs();
      final double normalizedAngle = headingDelta > 180 ? 360 - headingDelta : headingDelta;
      final double elapsedSec = (now - lastTime) / 1000.0;
      final bool isMoving = pos.speed > 0.8; // > ~3 km/h

      if (isMoving) {
        // MOVING RULES (Follow roads accurately & capture curves):
        // A) Cornering / Turn: Heading changed >= 20 deg and moved >= 8m
        if (normalizedAngle >= 20.0 && dist >= 8.0) {
          shouldRecord = true;
        }
        // B) Distance trigger: Moved >= 15 meters along road
        else if (dist >= 15.0) {
          shouldRecord = true;
        }
        // C) Max time elapsed: Interval reached (e.g. 15s)
        else if (elapsedSec >= interval) {
          shouldRecord = true;
        }
      } else {
        // STATIONARY RULES (Prevent GPS jitter / drift while stopped):
        // Filter out small random GPS drifts under 14 meters!
        if (dist >= 16.0) {
          shouldRecord = true; // genuine motion resumed
        } else if (elapsedSec >= 120.0) {
          shouldRecord = true; // 2-minute stationary heartbeat
        }
      }
    }

    if (shouldRecord) {
      lastLat = pos.latitude;
      lastLng = pos.longitude;
      lastHeading = pos.heading;
      lastTime = now;

      await Tracker.recordPosition(pos);

      // Update foreground notification
      if (service is AndroidServiceInstance) {
        final pending = await LocalDb.countPoints();
        final speedKmh = (pos.speed >= 0 ? pos.speed * 3.6 : 0.0).toStringAsFixed(1);
        service.setForegroundNotificationInfo(
          title: 'Galoya Telematics – Live',
          content: pos.speed > 0.8
              ? 'Speed: $speedKmh km/h · $pending points waiting'
              : 'Stationary · all synced',
        );
      }

      // Sync data to server smoothly
      if (now - lastSync > 3000) {
        lastSync = now;
        Sync.run();
      }
    }
  });
}
