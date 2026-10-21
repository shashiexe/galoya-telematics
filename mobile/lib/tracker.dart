import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:ui';

import 'package:battery_plus/battery_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'db.dart';
import 'sync.dart';
import 'vault.dart';

const _channelId = 'stafftrack_tracking';

/// GPS tracking that keeps working when the app is closed / offline.
/// Android: foreground service isolate.  iOS: background location stream.
class Tracker {
  static StreamSubscription<Position>? _iosSub;
  static int _iosLast = 0;

  static Future<void> init() async {
    if (!Platform.isAndroid) return;
    try {
      final n = FlutterLocalNotificationsPlugin();
      await n
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(const AndroidNotificationChannel(
              _channelId, 'Duty tracking', description: 'Shown while you are on duty', importance: Importance.low));
    } catch (_) {}

    try {
      await FlutterBackgroundService().configure(
        androidConfiguration: AndroidConfiguration(
          onStart: _serviceMain,
          autoStart: true,
          autoStartOnBoot: true,
          isForegroundMode: true,
          notificationChannelId: _channelId,
          initialNotificationTitle: 'Galoya Telematics',
          initialNotificationContent: '24/7 Field Tracking Active (Daemon)',
          foregroundServiceNotificationId: 888,
          foregroundServiceTypes: [AndroidForegroundType.location],
        ),
        iosConfiguration: IosConfiguration(autoStart: false),
      );
    } catch (_) {}
  }

  static Future<bool> ensurePermissions() async {
    try {
      await Permission.notification.request();
      await Permission.camera.request();
      if (!await Permission.ignoreBatteryOptimizations.isGranted) {
        await Permission.ignoreBatteryOptimizations.request();
      }
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

  static Future<bool> isAlwaysLocationGranted() async {
    try {
      final p = await Geolocator.checkPermission();
      return p == LocationPermission.always;
    } catch (_) {
      return false;
    }
  }

  static Future<bool> isBatteryOptimizationIgnored() async {
    try {
      return await Permission.ignoreBatteryOptimizations.isGranted;
    } catch (_) {
      return true;
    }
  }

  static Future<void> start() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setBool('tracking', true);
    if (Platform.isAndroid) {
      try {
        final s = FlutterBackgroundService();
        if (!await s.isRunning()) await s.startService();
      } catch (e) {
        debugPrint('Tracker start error: $e');
      }
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
    if (sp.getBool('tracking') ?? true) {
      await start();
      try {
        int? battery;
        try { battery = await Battery().batteryLevel; } catch (_) {}
        final pos = await Geolocator.getLastKnownPosition();
        await LocalDb.enqueue(
          'duty',
          jsonEncode({
            'client_id': 'resume_${DateTime.now().millisecondsSinceEpoch}',
            'status': 'on',
            'event_type': 'boot_resume',
            'note': 'Device Boot / Tracking Auto-Resumed',
            'lat': pos?.latitude,
            'lng': pos?.longitude,
            'battery': battery,
            't': DateTime.now().millisecondsSinceEpoch,
          }),
        );
        Sync.run();
      } catch (_) {}
    }
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

  static Future<void> recordPosition(Position pos, [double? speedOverride]) async {
    int? battery;
    try { battery = await Battery().batteryLevel; } catch (_) {}
    final speedKmh = speedOverride ?? (pos.speed >= 0 ? (pos.speed * 3.6) : 0.0);
    await LocalDb.addPoint({
      'lat': pos.latitude, 'lng': pos.longitude, 'accuracy': pos.accuracy,
      'speed': speedKmh, 'battery': battery, 't': pos.timestamp.millisecondsSinceEpoch,
    });
  }
}

/// Android background isolate entry point with Standard Telematics Smart Filtering.
@pragma('vm:entry-point')
void _serviceMain(ServiceInstance service) async {
  DartPluginRegistrant.ensureInitialized();
  WidgetsFlutterBinding.ensureInitialized();
  try {
    HttpOverrides.global = AppHttpOverrides();
  } catch (_) {}

  // Promote to Android Foreground Service immediately with active notification info
  if (service is AndroidServiceInstance) {
    service.setAsForegroundService();
    service.setForegroundNotificationInfo(
      title: 'Galoya Telematics – 24/7 Active',
      content: 'Background tracking active (daemon mode)',
    );
  }

  var running = true;
  StreamSubscription<Position>? streamSub;

  service.on('stop').listen((_) {
    running = false;
    streamSub?.cancel();
    service.stopSelf();
  });

  await AuthVault.restoreIfMissing();
  final sp = await SharedPreferences.getInstance();
  await sp.reload();
  final isTracking = sp.getBool('tracking') ?? true;
  if (!isTracking) {
    service.stopSelf();
    return;
  }

  // Telematics tracking state
  double? lastLat;
  double? lastLng;
  double lastHeading = 0.0;
  int lastTime = 0;
  int lastSync = 0;
  int stationaryStartTime = 0;
  bool isParkedLogged = false;

  final locationSettings = AndroidSettings(
    accuracy: LocationAccuracy.bestForNavigation,
    distanceFilter: 3, // Hardware-level micro-jitter suppression for indoor/roof stability
    intervalDuration: const Duration(seconds: 2), // poll hardware stream every 2s
    foregroundNotificationConfig: const ForegroundNotificationConfig(
      notificationText: "Tracking vehicle & field operations 24/7",
      notificationTitle: "Galoya Telematics – GPS Active",
      enableWakeLock: true,
    ),
  );

  // Periodic offline sync flush (every 20s: auto-sync all accumulated SQLite points as soon as signal returns)
  Timer.periodic(const Duration(seconds: 20), (_) async {
    if (!running) return;
    try {
      final pCount = await LocalDb.countPoints();
      final qCount = await LocalDb.countQueue();
      if (pCount > 0 || qCount > 0) {
        Sync.run();
      }
    } catch (_) {}
  });

  // Stationary Heartbeat Timer (Every 60s):
  // Guarantees that when staff or machinery is stationary (not moving >= 12m),
  // a stationary heartbeat point (speed: 0 km/h) is sent to the server every 60 seconds.
  // This prevents the Web Monitor / Radar from marking the unit offline (which happens after 5 minutes of silence)!
  Timer.periodic(const Duration(seconds: 60), (_) async {
    if (!running) return;
    final now = DateTime.now().millisecondsSinceEpoch;
    // If no point has been recorded in the last 45 seconds (i.e. stopped / stationary):
    if (now - lastTime >= 45000) {
      Position? pos;
      try {
        pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.medium,
          timeLimit: const Duration(seconds: 4),
        );
      } catch (_) {}
      pos ??= await Geolocator.getLastKnownPosition();
      if (pos != null) {
        final double distFromLast = (lastLat != null && lastLng != null)
            ? Geolocator.distanceBetween(lastLat!, lastLng!, pos.latitude, pos.longitude)
            : 0.0;
        final double elapsed = lastTime > 0 ? ((now - lastTime) / 1000.0) : 1.0;
        final double computedKmh = (elapsed > 0 && distFromLast >= 20.0) ? ((distFromLast / elapsed) * 3.6) : 0.0;
        final double speedToLog = pos.speed >= 1.2
            ? pos.speed * 3.6
            : ((computedKmh >= 4.0 && computedKmh <= 140.0) ? computedKmh : 0.0);
        final bool isMoving = speedToLog >= 4.0;

        lastTime = now;
        lastLat = pos.latitude;
        lastLng = pos.longitude;
        await Tracker.recordPosition(pos, speedToLog);
        if (service is AndroidServiceInstance) {
          service.setForegroundNotificationInfo(
            title: 'Galoya Telematics – Live',
            content: isMoving
                ? 'Speed: ${speedToLog.toStringAsFixed(1)} km/h · Live Synced 🟢'
                : 'Stationary (On Duty) · Live Synced 🟢',
          );
        }
        Sync.run();
      }
    }
  });

  streamSub = Geolocator.getPositionStream(locationSettings: locationSettings).listen((pos) async {
    if (!running) return;

    // 1. In-Vehicle & Field Accuracy Filter:
    // Allow up to 80m so in-car attenuation / rural plantation canopy never silently drops points!
    if (pos.accuracy > 80.0) return;

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
      final double dispKmh = (elapsedSec > 0 && dist >= 20.0) ? ((dist / elapsedSec) * 3.6) : 0.0;
      // Vehicle / Person motion: Hardware Doppler speed >= 1.2 m/s OR physical displacement speed >= 4.0 km/h
      final bool isMoving = (pos.speed >= 1.2 || (dispKmh >= 4.0 && dispKmh <= 140.0)) && dist >= 20.0;

      if (isMoving) {
        // If vehicle was parked, log parking_end upon motion resumption!
        if (isParkedLogged) {
          final stopMins = stationaryStartTime > 0 ? (((now - stationaryStartTime) / 60000).round()) : 0;
          try {
            int? bat;
            try { bat = await Battery().batteryLevel; } catch (_) {}
            await LocalDb.enqueue(
              'duty',
              jsonEncode({
                'client_id': 'parkend_$now',
                'status': 'on',
                'event_type': 'parking_end',
                'note': 'Vehicle Departed (Stop Duration: $stopMins mins)',
                'lat': pos.latitude,
                'lng': pos.longitude,
                'battery': bat,
                't': now,
              }),
            );
          } catch (_) {}
          isParkedLogged = false;
          stationaryStartTime = 0;
        }

        // MOVING RULES (Follow roads accurately & capture curves):
        // A) Cornering / Turn: Heading changed >= 20 deg and moved >= 10m
        if (normalizedAngle >= 20.0 && dist >= 10.0) {
          shouldRecord = true;
        }
        // B) Distance trigger: Moved >= 20 meters along road
        else if (dist >= 20.0) {
          shouldRecord = true;
        }
        // C) Max time elapsed: Interval reached (e.g. 15s)
        else if (elapsedSec >= interval) {
          shouldRecord = true;
        }
      } else {
        // STATIONARY RULES (Prevent GPS jitter / drift while stopped):
        if (stationaryStartTime == 0) {
          stationaryStartTime = now;
        } else if (!isParkedLogged && (now - stationaryStartTime) >= 180000) { // >= 3 minutes stationary
          isParkedLogged = true;
          try {
            int? bat;
            try { bat = await Battery().batteryLevel; } catch (_) {}
            await LocalDb.enqueue(
              'duty',
              jsonEncode({
                'client_id': 'parkstart_$now',
                'status': 'on',
                'event_type': 'parking_start',
                'note': 'Vehicle Parked / Stationary Stop (>= 3 mins)',
                'lat': pos.latitude,
                'lng': pos.longitude,
                'battery': bat,
                't': now,
              }),
            );
          } catch (_) {}
        }

        // Filter out indoor / solar panel GPS bounce under 25 meters!
        if (dist >= 25.0) {
          shouldRecord = true; // genuine physical motion resumed
        } else if (elapsedSec >= 60.0) {
          shouldRecord = true; // 60-second stationary heartbeat
        }
      }
    }

    if (shouldRecord) {
      final double distFromLast = (lastLat != null && lastLng != null)
          ? Geolocator.distanceBetween(lastLat!, lastLng!, pos.latitude, pos.longitude)
          : 999.0;
      final double elapsed = lastTime > 0 ? ((now - lastTime) / 1000.0) : 1.0;
      final double computedKmh = (elapsed > 0 && distFromLast >= 20.0) ? ((distFromLast / elapsed) * 3.6) : 0.0;
      final bool isGenuineMotion = (pos.speed >= 1.2 || (computedKmh >= 4.0 && computedKmh <= 140.0)) && distFromLast >= 20.0;
      final double actualSpeedKmh = isGenuineMotion 
          ? (pos.speed >= 1.2 ? pos.speed * 3.6 : (computedKmh >= 4.0 ? computedKmh : 0.0))
          : 0.0;

      lastLat = pos.latitude;
      lastLng = pos.longitude;
      lastHeading = pos.heading;
      lastTime = now;

      // Always save locally in SQLite first (offline durability)
      await Tracker.recordPosition(pos, actualSpeedKmh);

      // Update foreground notification
      if (service is AndroidServiceInstance) {
        final pending = await LocalDb.countPoints();
        final speedDisplay = actualSpeedKmh.toStringAsFixed(1);
        final syncStatus = pending > 1 
            ? '· $pending offline points' 
            : (pending == 1 ? '· Syncing Live' : '· Live Synced 🟢');
        service.setForegroundNotificationInfo(
          title: 'Galoya Telematics – Live',
          content: isGenuineMotion
              ? 'Speed: $speedDisplay km/h $syncStatus'
              : 'Stationary $syncStatus',
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
