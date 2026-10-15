import 'dart:async';
import 'dart:io';
import 'dart:ui';

import 'package:battery_plus/battery_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:geolocator/geolocator.dart';
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
    if (!await Geolocator.isLocationServiceEnabled()) {
      await Geolocator.openLocationSettings();
      return false;
    }
    var p = await Geolocator.checkPermission();
    if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
    if (p == LocationPermission.whileInUse) p = await Geolocator.requestPermission(); // ask for "Always"
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

/// Android background isolate entry point.
@pragma('vm:entry-point')
void _serviceMain(ServiceInstance service) async {
  DartPluginRegistrant.ensureInitialized();
  WidgetsFlutterBinding.ensureInitialized();
  var running = true;
  service.on('stop').listen((_) { running = false; service.stopSelf(); });

  final sp = await SharedPreferences.getInstance();
  await sp.reload();
  if (sp.getBool('tracking') != true) { service.stopSelf(); return; } // e.g. boot while off duty

  while (running) {
    final interval = await effectiveInterval();
    try {
      final pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.high, timeLimit: const Duration(seconds: 25));
      await Tracker.recordPosition(pos);
      if (service is AndroidServiceInstance) {
        final pending = await LocalDb.countPoints();
        service.setForegroundNotificationInfo(
          title: 'StaffTrack – On duty',
          content: pending > 0 ? 'Tracking · $pending points waiting to upload' : 'Tracking · all synced',
        );
      }
    } catch (_) {/* no GPS fix this time */}
    await Sync.run();
    await Future.delayed(Duration(seconds: interval));
  }
}
