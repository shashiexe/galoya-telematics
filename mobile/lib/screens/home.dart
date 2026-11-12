import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart' hide Path;
import 'package:geolocator/geolocator.dart';
import 'package:battery_plus/battery_plus.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:camera/camera.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';

import '../api.dart';
import '../config.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';
import '../tracker.dart';
import '../updater.dart';
import '../vault.dart';
import 'login.dart';
import 'face_enrollment.dart';
import 'in_app_camera.dart';
import 'settings.dart';
import 'task_detail.dart';
import 'cane_cdn_modal.dart';

/// Task caching for offline access
class TaskStore {
  static Future<List<Map<String, dynamic>>> cached() async {
    final sp = await SharedPreferences.getInstance();
    final s = sp.getString('tasks_cache');
    return s == null ? [] : (jsonDecode(s) as List).cast<Map<String, dynamic>>();
  }

  static Future<void> save(List<Map<String, dynamic>> t) async =>
      (await SharedPreferences.getInstance()).setString('tasks_cache', jsonEncode(t));

  static Future<void> patch(int id, Map<String, dynamic> changes) async {
    final list = await cached();
    for (final t in list) {
      if (t['id'] == id) t.addAll(changes);
    }
    await save(list);
  }
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver, TickerProviderStateMixin {
  final MapController _mapController = MapController();
  
  bool _tracking = false;
  String _name = 'Field Officer';
  String _code = '';
  String _unitType = 'staff';
  String _designation = 'Field Officer';
  int _interval = 15;
  int _pendingPts = 0;
  
  Position? _currentPosition;
  double _speed = 0.0;
  double _accuracy = 15.0;
  int _batteryLevel = 100;
  String _currentStatus = 'Working';
  
  bool _isSatellite = true;
  bool _isAlwaysLocation = true;
  bool _isBatteryIgnored = true;
  Timer? _locationTimer;
  Timer? _syncTimer;
  List<Map<String, dynamic>> _tasks = [];
  final List<Map<String, dynamic>> _capturedPhotos = [];
  Map<String, dynamic>? _targetPlot;
  List<LatLng> _targetPolygonPoints = [];
  DateTime _galleryDate = DateTime.now();
  List<Map<String, dynamic>> _serverPhotos = [];
  bool _loadingGallery = false;

  // Compass & Heading Direction Follow Mode
  double _mapRotation = 0.0;
  bool _isHeadingFollowMode = false;
  bool _hasInitiallyCentered = false;

  // Visual Blinking & Nearby Plot Polygons
  late AnimationController _locationBlinkCtrl;
  late Animation<double> _locationBlinkAnim;
  bool _isBlinkingLocation = false;
  List<Map<String, dynamic>> _nearbyIdentifiedPlots = [];
  List<Polygon> _nearbyIdentifiedPolygons = [];
  List<Marker> _nearbyIdentifiedMarkers = [];

  // Default Galoya Plantation / Damana center
  static const LatLng _defaultCenter = LatLng(7.2050, 81.6580);

  @override
  void initState() {
    super.initState();
    _locationBlinkCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    );
    _locationBlinkAnim = CurvedAnimation(
      parent: _locationBlinkCtrl,
      curve: Curves.easeInOut,
    );
    WidgetsBinding.instance.addObserver(this);

    // Fast Camera: Instantly center on last saved GPS coordinates to prevent Damana jump
    _initFastCamera();

    _bootstrap();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppUpdater.checkOnLaunch(context);
      _checkPermissionsInBackground();
      LocalDb.ensureGeofencesSeeded();
    });
  }

  void _initFastCamera() {
    SharedPreferences.getInstance().then((sp) {
      final lastLat = sp.getDouble('last_lat');
      final lastLng = sp.getDouble('last_lng');
      if (lastLat != null && lastLng != null && lastLat != 0.0 && mounted) {
        if (_currentPosition == null) {
          setState(() {
            _currentPosition = Position(
              latitude: lastLat,
              longitude: lastLng,
              timestamp: DateTime.now(),
              accuracy: 10.0,
              altitude: 0.0,
              altitudeAccuracy: 0.0,
              heading: 0.0,
              headingAccuracy: 0.0,
              speed: 0.0,
              speedAccuracy: 0.0,
            );
          });
        }
        if (!_hasInitiallyCentered) {
          _mapController.move(LatLng(lastLat, lastLng), 16.5);
          _hasInitiallyCentered = true;
        }
      }
    });
  }

  Future<void> _checkPermissionsInBackground() async {
    try {
      await Tracker.ensurePermissions();
      await _refreshDaemonStatus();
    } catch (_) {}
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _locationTimer?.cancel();
    _syncTimer?.cancel();
    _locationBlinkCtrl.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final now = DateTime.now().millisecondsSinceEpoch;
    if (state == AppLifecycleState.paused || state == AppLifecycleState.detached) {
      // Guarantee background daemon is actively started when app is minimized or swiped away
      if (_tracking) {
        Tracker.start();
      }
      _loadBattery();
      LocalDb.enqueue(
        'duty',
        jsonEncode({
          'client_id': 'close_$now',
          'status': _tracking ? 'on' : 'off',
          'event_type': 'app_close',
          'note': 'App Minimized / Swiped Away',
          'lat': _currentPosition?.latitude,
          'lng': _currentPosition?.longitude,
          'battery': _batteryLevel,
          't': now,
        }),
      );
      Sync.run();
    } else if (state == AppLifecycleState.resumed) {
      _loadBattery().then((_) {
        LocalDb.enqueue(
          'duty',
          jsonEncode({
            'client_id': 'open_$now',
            'status': _tracking ? 'on' : 'off',
            'event_type': 'app_open',
            'note': 'App Restored to Foreground',
            'lat': _currentPosition?.latitude,
            'lng': _currentPosition?.longitude,
            'battery': _batteryLevel,
            't': now,
          }),
        );
        Sync.run();
      });
      _fetchLiveLocation();
      _refreshDaemonStatus();
    }
  }

  Future<void> _refreshDaemonStatus() async {
    final always = await Tracker.isAlwaysLocationGranted();
    final batt = await Tracker.isBatteryOptimizationIgnored();
    if (mounted) {
      setState(() {
        _isAlwaysLocation = always;
        _isBatteryIgnored = batt;
      });
    }
  }

  Future<void> _bootstrap() async {
    final sp = await SharedPreferences.getInstance();
    
    // Default to true so tracking runs like a background daemon (Facebook-style)
    final tracking = sp.getBool('tracking') ?? true;
    final name = sp.getString('name') ?? 'Field Officer';
    var code = sp.getString('code') ?? '';
    final unitType = sp.getString('unit_type') ?? 'staff';
    final designation = sp.getString('designation') ?? 'Field Officer';
    final interval = await effectiveInterval();

    // Clean up any legacy saved tractor number '49-1665' for staff/field officers
    if (code == '49-1665' && (unitType == 'staff' || unitType.isEmpty)) {
      code = '';
      sp.setString('code', '');
    }

    if (mounted) {
      setState(() {
        _tracking = tracking;
        _name = name;
        _code = code;
        _unitType = unitType;
        _designation = designation;
        _interval = interval;
      });
    }

    if (tracking) {
      Tracker.start();
    }

    await _loadBattery();

    // Focus camera immediately on user's last known location
    try {
      final lastPos = await Geolocator.getLastKnownPosition();
      if (lastPos != null && mounted) {
        setState(() {
          _currentPosition = lastPos;
          _speed = lastPos.speed >= 0 ? lastPos.speed : 0.0;
          _accuracy = lastPos.accuracy;
        });
        if (!_hasInitiallyCentered) {
          _mapController.move(LatLng(lastPos.latitude, lastPos.longitude), 16.5);
          _hasInitiallyCentered = true;
        }
        sp.setDouble('last_lat', lastPos.latitude);
        sp.setDouble('last_lng', lastPos.longitude);
      }
    } catch (_) {}

    // Async background location and task loading (non-blocking)
    _fetchLiveLocation();
    Future.delayed(const Duration(milliseconds: 800), () {
      if (mounted) _loadTasks();
    });

    // Log App Launch
    final now = DateTime.now().millisecondsSinceEpoch;
    LocalDb.enqueue(
      'duty',
      jsonEncode({
        'client_id': 'launch_$now',
        'status': tracking ? 'on' : 'off',
        'event_type': 'app_open',
        'note': 'App Initial Launch',
        'lat': _currentPosition?.latitude,
        'lng': _currentPosition?.longitude,
        'battery': _batteryLevel,
        't': now,
      }),
    );
    
    // Defer network sync to allow UI and map tiles to render smoothly first
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted) Sync.run();
    });

    // High frequency UI location updates
    _locationTimer = Timer.periodic(const Duration(seconds: 4), (_) => _fetchLiveLocation());
    _syncTimer = Timer.periodic(const Duration(seconds: 10), (_) => _refreshStatus());
  }

  Future<void> _loadBattery() async {
    try {
      final b = await Battery().batteryLevel;
      if (mounted) setState(() => _batteryLevel = b);
    } catch (_) {}
  }

  Future<void> _loadTasks() async {
    final cached = await TaskStore.cached();
    if (mounted) setState(() => _tasks = cached);
    try {
      final j = await Api.get('tasks');
      final list = (j['tasks'] as List).cast<Map<String, dynamic>>();
      await TaskStore.save(list);
      if (mounted) setState(() => _tasks = list);
    } catch (_) {}
  }

  Future<void> _refreshStatus() async {
    _loadBattery();
    final pts = await LocalDb.countPoints();
    final interval = await effectiveInterval();
    if (mounted) {
      setState(() {
        _pendingPts = pts;
        _interval = interval;
      });
    }
  }

  Future<void> _fetchLiveLocation() async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
        timeLimit: const Duration(seconds: 5),
      );
      if (mounted) {
        setState(() {
          _currentPosition = pos;
          _speed = pos.speed >= 0 ? pos.speed : 0.0;
          _accuracy = pos.accuracy;
        });
        if (!_hasInitiallyCentered) {
          _hasInitiallyCentered = true;
          _mapController.move(LatLng(pos.latitude, pos.longitude), 16.5);
        }
        if (_isHeadingFollowMode && pos.heading >= 0) {
          _mapController.rotate(pos.heading);
        }
        SharedPreferences.getInstance().then((sp) {
          sp.setDouble('last_lat', pos.latitude);
          sp.setDouble('last_lng', pos.longitude);
        });
      }
    } catch (_) {}
  }

  void _onCompassTap() {
    if (_mapRotation.abs() > 2.0) {
      // Map is rotated away from North: rotate back smoothly to 0 (North-Up)
      _mapController.rotate(0);
      setState(() {
        _isHeadingFollowMode = false;
        _mapRotation = 0.0;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('🧭 Map aligned North-Up (0°)'),
          duration: Duration(seconds: 2),
        ),
      );
    } else {
      // Toggle Follow Direction Mode
      final nextMode = !_isHeadingFollowMode;
      setState(() => _isHeadingFollowMode = nextMode);
      if (nextMode) {
        final heading = _currentPosition?.heading ?? 0.0;
        if (heading >= 0) {
          _mapController.rotate(heading);
        }
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            backgroundColor: Color(0xff2563eb),
            content: Text('🧭 Compass Direction Mode: Map follows your travel heading'),
            duration: Duration(seconds: 3),
          ),
        );
      } else {
        _mapController.rotate(0);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('🧭 North-Up Mode (0°)'),
            duration: Duration(seconds: 2),
          ),
        );
      }
    }
  }

  Future<void> _locateCurrentPlotAndShowNearby() async {
    // 1. Trigger visible pulsing/blinking on user's current location pin
    setState(() {
      _isBlinkingLocation = true;
    });
    _locationBlinkCtrl.repeat(reverse: true);
    Timer(const Duration(seconds: 6), () {
      if (mounted && _isBlinkingLocation) {
        setState(() => _isBlinkingLocation = false);
        _locationBlinkCtrl.stop();
      }
    });

    // 2. Refresh GPS coordinates
    if (_currentPosition == null) {
      await _fetchLiveLocation();
    }
    final lat = _currentPosition?.latitude ?? _defaultCenter.latitude;
    final lng = _currentPosition?.longitude ?? _defaultCenter.longitude;

    // ALWAYS keep camera focused directly on USER'S position
    _mapController.move(LatLng(lat, lng), 17.0);

    // 3. Ultra-fast local SQLite query (< 5ms) from pre-seeded 5,034 plantation plots
    List<Map<String, dynamic>> nearbyList = await LocalDb.getNearbyGeofences(lat, lng, limit: 12);
    
    // Background online refresh (or fallback if local cache was not ready)
    if (nearbyList.isEmpty) {
      try {
        final online = await Sync.refreshNearbyGeofences(lat, lng)
            .timeout(const Duration(milliseconds: 2500), onTimeout: () => null);
        if (online != null && online.isNotEmpty) {
          nearbyList = online;
        }
      } catch (_) {}
    } else {
      Sync.refreshNearbyGeofences(lat, lng).catchError((_) => null);
    }

    // 4. Find the plot officer is inside, or the closest plot
    Map<String, dynamic>? insidePlot;
    for (final p in nearbyList) {
      if (p['is_inside'] == true) {
        insidePlot = p;
        break;
      }
    }
    final target = insidePlot ?? (nearbyList.isNotEmpty ? nearbyList.first : null);
    final targetDist = (target?['distance'] as num?)?.toDouble() ?? double.infinity;

    // 5. Build clean green polygons and centered name badges ONLY for plots within 1500m
    final polygons = <Polygon>[];
    final markers = <Marker>[];
    final relevantPlots = nearbyList.where((p) {
      final d = (p['distance'] as num?)?.toDouble() ?? 0.0;
      return p['is_inside'] == true || d <= 1500;
    }).toList();

    for (final p in relevantPlots) {
      final isTarget = (target != null && (p['name'] == target['name'] || p['id'] == target['id']));
      final polyCoords = p['coordinates'];
      final pts = <LatLng>[];
      if (polyCoords is List && polyCoords.length >= 3) {
        for (final c in polyCoords) {
          if (c is List && c.length >= 2) {
            final pLat = (c[0] is num) ? (c[0] as num).toDouble() : double.tryParse(c[0]?.toString() ?? '') ?? 0.0;
            final pLng = (c[1] is num) ? (c[1] as num).toDouble() : double.tryParse(c[1]?.toString() ?? '') ?? 0.0;
            if (pLat != 0.0 && pLng != 0.0) {
              pts.add(LatLng(pLat, pLng));
            }
          }
        }
        if (pts.isNotEmpty) {
          polygons.add(
            Polygon(
              points: pts,
              color: isTarget ? const Color(0x3322c55e) : const Color(0x1522c55e),
              borderColor: isTarget ? const Color(0xff22c55e) : const Color(0xff16a34a),
              borderStrokeWidth: isTarget ? 3.0 : 1.8,
              isFilled: true,
            ),
          );
        }
      }

      LatLng centerPt;
      if (pts.isNotEmpty) {
        double sLat = 0.0;
        double sLng = 0.0;
        for (final pt in pts) {
          sLat += pt.latitude;
          sLng += pt.longitude;
        }
        centerPt = LatLng(sLat / pts.length, sLng / pts.length);
      } else {
        final pLat = (p['lat'] is num)
            ? (p['lat'] as num).toDouble()
            : double.tryParse(p['lat']?.toString() ?? '') ?? 0.0;
        final pLng = (p['lng'] is num)
            ? (p['lng'] as num).toDouble()
            : double.tryParse(p['lng']?.toString() ?? '') ?? 0.0;
        centerPt = LatLng(pLat, pLng);
      }

      if (centerPt.latitude != 0.0 && centerPt.longitude != 0.0) {
        markers.add(
          Marker(
            point: centerPt,
            width: 120,
            height: 34,
            child: Center(
              child: GestureDetector(
                onTap: () => _selectPlotForNavigation(p),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                  decoration: BoxDecoration(
                    color: isTarget ? const Color(0xee0f172a) : const Color(0xcc1e293b),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: isTarget ? const Color(0xff22c55e) : const Color(0xff16a34a),
                      width: isTarget ? 1.8 : 1.0,
                    ),
                    boxShadow: const [
                      BoxShadow(color: Colors.black45, blurRadius: 3, offset: Offset(0, 1.5)),
                    ],
                  ),
                  child: Text(
                    p['name'] ?? '',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: isTarget ? const Color(0xff4ade80) : Colors.white,
                      fontWeight: isTarget ? FontWeight.bold : FontWeight.w600,
                      fontSize: 11,
                    ),
                  ),
                ),
              ),
            ),
          ),
        );
      }
    }

    if (mounted) {
      setState(() {
        _targetPlot = (target != null && targetDist <= 1500) ? target : null;
        _targetPolygonPoints = [];
        _nearbyIdentifiedPlots = relevantPlots;
        _nearbyIdentifiedPolygons = polygons;
        _nearbyIdentifiedMarkers = markers;
      });

      // Keep camera centered on the user
      _mapController.move(LatLng(lat, lng), 17.0);

      String msg;
      if (insidePlot != null) {
        msg = '📍 Inside: ${insidePlot['name']}';
      } else if (target != null && targetDist <= 1500) {
        msg = '📍 Nearest: ${target['name']} (${targetDist.round()}m)';
      } else {
        msg = '📍 Outside Plantation Area (No plots within 1.5 km)';
      }

      ScaffoldMessenger.of(context).hideCurrentSnackBar();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: const Color(0xff15803d),
          behavior: SnackBarBehavior.floating,
          margin: const EdgeInsets.fromLTRB(14, 0, 14, 120),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          duration: const Duration(seconds: 10),
          dismissDirection: DismissDirection.horizontal,
          content: Row(
            children: [
              const Icon(Icons.radar, color: Color(0xfffde047), size: 18),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  msg,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              TextButton(
                style: TextButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  backgroundColor: const Color(0x33ffffff),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () {
                  ScaffoldMessenger.of(context).hideCurrentSnackBar();
                  setState(() {
                    _targetPlot = null;
                    _targetPolygonPoints = [];
                    _nearbyIdentifiedPolygons = [];
                    _nearbyIdentifiedMarkers = [];
                    _nearbyIdentifiedPlots = [];
                  });
                },
                child: const Text('Clear Polygons', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(width: 4),
              IconButton(
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(),
                icon: const Icon(Icons.close, color: Colors.white70, size: 20),
                tooltip: 'Dismiss Bar',
                onPressed: () {
                  ScaffoldMessenger.of(context).hideCurrentSnackBar();
                },
              ),
            ],
          ),
        ),
      );
    }
  }

  void _recenterMap() {
    final target = _currentPosition != null
        ? LatLng(_currentPosition!.latitude, _currentPosition!.longitude)
        : _defaultCenter;
    _mapController.move(target, 16.5);
  }

  /// Supervisor / Admin PIN protection to prevent unauthorized stopping or logout
  Future<bool> _requestAdminPassword(String actionName) async {
    final passCtrl = TextEditingController();
    String? errorText;

    final result = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => AlertDialog(
          backgroundColor: const Color(0xff1e293b),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: const BorderSide(color: Color(0xff334155))),
          title: const Row(
            children: [
              Icon(Icons.shield_outlined, color: Color(0xfff59e0b), size: 26),
              SizedBox(width: 10),
              Text('Supervisor PIN', style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Enter Admin / Supervisor password to $actionName.',
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: passCtrl,
                obscureText: true,
                autofocus: true,
                style: const TextStyle(color: Colors.white),
                decoration: InputDecoration(
                  labelText: 'Supervisor Password / PIN',
                  labelStyle: const TextStyle(color: Colors.white60),
                  prefixIcon: const Icon(Icons.lock, color: Color(0xfff59e0b)),
                  errorText: errorText,
                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff475569))),
                  focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xfff59e0b), width: 1.5)),
                  filled: true,
                  fillColor: const Color(0xff0f172a),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancel', style: TextStyle(color: Colors.white60)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xfff59e0b),
                foregroundColor: Colors.black,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              onPressed: () async {
                final input = passCtrl.text.trim();
                // Validated supervisor PINs: "2026", "galoya2026", "admin2026"
                if (input == '2026' || input == 'galoya2026' || input == 'admin2026') {
                  Navigator.pop(ctx, true);
                } else {
                  setDlgState(() {
                    errorText = 'Incorrect PIN! Contact Fleet Supervisor.';
                  });
                }
              },
              child: const Text('Authorize', style: TextStyle(fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      ),
    );

    return result ?? false;
  }

  Future<void> _toggleTracking(bool val) async {
    if (val) {
      final ok = await Tracker.ensurePermissions();
      if (!ok) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Please grant Location & Notification permissions')),
          );
        }
        return;
      }
      await Tracker.start();
      setState(() => _tracking = true);

      // Record offline-durable Tracking ON event with timestamp & battery
      try {
        final pos = _currentPosition ?? await Geolocator.getLastKnownPosition();
        int? bat;
        try { bat = await Battery().batteryLevel; } catch (_) {}
        await LocalDb.enqueue(
          'duty',
          jsonEncode({
            'client_id': 'on_${DateTime.now().millisecondsSinceEpoch}',
            'status': 'on',
            'event_type': 'tracking_on',
            'note': 'Tracking Turned ON',
            'lat': pos?.latitude,
            'lng': pos?.longitude,
            'battery': bat,
            't': DateTime.now().millisecondsSinceEpoch,
          }),
        );
        Sync.run();
      } catch (_) {}
    } else {
      // Prompt user for confirmation and reason to prevent accidental shutoff
      final String? selectedReason = await showDialog<String>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) {
          String reason = 'Shift Ended';
          return StatefulBuilder(
            builder: (context, setDialogState) {
              return AlertDialog(
                backgroundColor: const Color(0xff1e293b),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                title: Row(
                  children: const [
                    Icon(Icons.warning_amber_rounded, color: Color(0xfff59e0b), size: 28),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text('Stop Tracking?', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
                content: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Are you sure you want to stop tracking? Please select a reason:',
                      style: TextStyle(color: Color(0xff94a3b8), fontSize: 13.5),
                    ),
                    const SizedBox(height: 14),
                    RadioListTile<String>(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      activeColor: const Color(0xff22c55e),
                      title: const Text('Shift Ended (සේවා මුරය අවසන්)', style: TextStyle(color: Colors.white, fontSize: 14)),
                      value: 'Shift Ended',
                      groupValue: reason,
                      onChanged: (val) => setDialogState(() => reason = val!),
                    ),
                    RadioListTile<String>(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      activeColor: const Color(0xff22c55e),
                      title: const Text('Lunch Break (දිවා ආහාර විවේකය)', style: TextStyle(color: Colors.white, fontSize: 14)),
                      value: 'Lunch Break',
                      groupValue: reason,
                      onChanged: (val) => setDialogState(() => reason = val!),
                    ),
                  ],
                ),
                actions: [
                  TextButton(
                    onPressed: () => Navigator.of(ctx).pop(null),
                    child: const Text('Cancel', style: TextStyle(color: Color(0xff94a3b8), fontWeight: FontWeight.w600)),
                  ),
                  ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xffef4444),
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    onPressed: () => Navigator.of(ctx).pop(reason),
                    child: const Text('Confirm & Stop', style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                ],
              );
            },
          );
        },
      );

      if (selectedReason == null) {
        // User cancelled or tapped away -> keep tracking switch ON
        setState(() => _tracking = true);
        return;
      }

      await Tracker.stop();
      setState(() => _tracking = false);

      // Record offline-durable Tracking OFF event with timestamp, battery & selected reason
      try {
        final pos = _currentPosition ?? await Geolocator.getLastKnownPosition();
        int? bat;
        try { bat = await Battery().batteryLevel; } catch (_) {}
        await LocalDb.enqueue(
          'duty',
          jsonEncode({
            'client_id': 'off_${DateTime.now().millisecondsSinceEpoch}',
            'status': 'off',
            'event_type': 'tracking_off',
            'note': 'Tracking Turned OFF ($selectedReason)',
            'reason': selectedReason,
            'lat': pos?.latitude,
            'lng': pos?.longitude,
            'battery': bat,
            't': DateTime.now().millisecondsSinceEpoch,
          }),
        );
        Sync.run();
      } catch (_) {}

      try {
        await Api.post('set_offline', {'reason': selectedReason});
      } catch (_) {}
    }
  }

  void _showBackgroundDaemonHelpDialog() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff1e293b),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.security, color: Color(0xff38bdf8), size: 24),
            SizedBox(width: 8),
            Text('24/7 Background Setup', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'App එක close කරත් (Facebook මෙන්) background එකේ tracking ක්‍රියාත්මක වීමට පහත පියවර කරන්න:',
              style: TextStyle(color: Colors.white70, fontSize: 12.5),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xff0f172a), borderRadius: BorderRadius.circular(10), border: Border.all(color: const Color(0xff334155))),
              child: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(Icons.lock, color: Color(0xfff59e0b), size: 18),
                      SizedBox(width: 6),
                      Text('Redmi (X) බොත්තමෙන් බේරීමට (ප්‍රධාන පියවර):', style: TextStyle(color: Color(0xfff59e0b), fontWeight: FontWeight.bold, fontSize: 12.5)),
                    ],
                  ),
                  SizedBox(height: 6),
                  Text('• Recent Apps තිරයේ StaffTrack card එක දිගට ඔබාගෙන සිට Lock Icon (🔒) එක ඔබන්න.', style: TextStyle(color: Colors.white, fontSize: 11.5)),
                  SizedBox(height: 4),
                  Text('• එවිට පහළ ඇති (X) බොත්තම එබුවත් StaffTrack වැසෙන්නේ හෝ RAM එකෙන් ඉවත් වන්නේ නැත!', style: TextStyle(color: Color(0xff38bdf8), fontSize: 11, fontWeight: FontWeight.bold)),
                  Divider(color: Color(0xff334155), height: 16),
                  Text('1. Location -> "Allow all the time" (හැමවිටම)', style: TextStyle(color: Color(0xff38bdf8), fontWeight: FontWeight.bold, fontSize: 12)),
                  SizedBox(height: 6),
                  Text('2. Battery -> "No restrictions" / Unrestricted', style: TextStyle(color: Color(0xff22c55e), fontWeight: FontWeight.bold, fontSize: 12)),
                  SizedBox(height: 6),
                  Text('3. Redmi/Xiaomi: Autostart = ON කරන්න', style: TextStyle(color: Color(0xfff59e0b), fontWeight: FontWeight.bold, fontSize: 12)),
                  SizedBox(height: 6),
                  Text('4. Samsung: Never sleeping apps වලට එක් කරන්න', style: TextStyle(color: Colors.white70, fontSize: 12)),
                ],
              ),
            ),
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff0284c7)),
                icon: const Icon(Icons.settings, color: Colors.white, size: 18),
                label: const Text('Open App Settings', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                onPressed: () {
                  Navigator.pop(ctx);
                  Geolocator.openAppSettings();
                },
              ),
            ),
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(side: const BorderSide(color: Color(0xff22c55e))),
                icon: const Icon(Icons.battery_charging_full, color: Color(0xff22c55e), size: 18),
                label: const Text('Disable Battery Restrictions', style: TextStyle(color: Color(0xff22c55e), fontWeight: FontWeight.bold)),
                onPressed: () async {
                  Navigator.pop(ctx);
                  await Permission.ignoreBatteryOptimizations.request();
                  _refreshDaemonStatus();
                },
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close', style: TextStyle(color: Colors.white54)),
          ),
        ],
      ),
    );
  }


  // --- SOS Alert Trigger with Emergency Confirmation ---
  Future<void> _sendSOS() async {
    final confirmed = await showDialog<bool>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff1e293b),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: const BorderSide(color: Color(0xffef4444), width: 1.5),
        ),
        title: const Row(
          children: [
            Icon(Icons.warning_amber_rounded, color: Color(0xffef4444), size: 28),
            SizedBox(width: 8),
            Expanded(
              child: Text('Confirm Emergency SOS',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16)),
            ),
          ],
        ),
        content: const Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Are you sure you want to broadcast an emergency distress signal?',
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 13),
            ),
            SizedBox(height: 10),
            Text(
              'This will instantly alert the Gal-Oya Operations Room and fleet supervisors with your real-time GPS coordinates.',
              style: TextStyle(color: Colors.white70, fontSize: 12),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel', style: TextStyle(color: Colors.white60)),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xffef4444),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            icon: const Icon(Icons.emergency, size: 18),
            label: const Text('BROADCAST SOS', style: TextStyle(fontWeight: FontWeight.bold)),
            onPressed: () => Navigator.pop(ctx, true),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    final pos = _currentPosition;
    final lat = pos?.latitude ?? 7.2050;
    final lng = pos?.longitude ?? 81.6580;

    final sosPayload = {
      'lat': lat,
      'lng': lng,
      'battery': _batteryLevel,
      'status': 'EMERGENCY_SOS',
      't': DateTime.now().millisecondsSinceEpoch,
    };

    bool sentOnline = false;
    try {
      final res = await Api.post('sos', sosPayload);
      if (res['ok'] == true) {
        sentOnline = true;
      }
    } catch (_) {
      // Offline fallback: enqueue in local SQLite and trigger background sync worker
      await LocalDb.enqueue('sos', jsonEncode(sosPayload));
      Sync.run();
    }

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              Icon(sentOnline ? Icons.check_circle : Icons.cloud_queue, color: Colors.white, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  sentOnline
                      ? 'EMERGENCY SOS broadcasted to Operations Room!'
                      : 'SOS saved locally — broadcasting to server as soon as connected.',
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
              ),
            ],
          ),
          backgroundColor: const Color(0xffef4444),
          duration: const Duration(seconds: 4),
        ),
      );
    }
  }

  // --- Quick Status Modal ---
  void _showStatusDialog() {
    final statuses = [
      {'name': 'Working / On Field', 'icon': Icons.agriculture, 'color': Colors.green},
      {'name': 'Loading / Transport', 'icon': Icons.local_shipping, 'color': Colors.blue},
      {'name': 'Breakdown / Maintenance', 'icon': Icons.build, 'color': Colors.red},
      {'name': 'Lunch / Standby', 'icon': Icons.coffee, 'color': Colors.amber},
      {'name': 'Field Inspection', 'icon': Icons.fact_check, 'color': Colors.purple},
    ];

    showModalBottomSheet(
      context: context,
      backgroundColor: const Color(0xff0f172a),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Set Machinery / Staff Status', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            ...statuses.map((s) => ListTile(
                  leading: CircleAvatar(
                    backgroundColor: (s['color'] as Color).withOpacity(0.2),
                    child: Icon(s['icon'] as IconData, color: s['color'] as Color),
                  ),
                  title: Text(s['name'] as String, style: const TextStyle(color: Colors.white)),
                  trailing: _currentStatus == s['name'] ? const Icon(Icons.check, color: Colors.green) : null,
                  onTap: () {
                    setState(() => _currentStatus = s['name'] as String);
                    Navigator.pop(ctx);
                    LocalDb.enqueue('status_change', jsonEncode({'status': s['name']}));
                    Sync.run();
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('Status updated: ${s['name']}')),
                    );
                  },
                )),
          ],
        ),
      ),
    );
  }

  // --- Quick Chat Note Modal ---
  void _showChatDialog() {
    final ctl = TextEditingController();
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff1e293b),
        title: const Text('Send Note to Dispatcher', style: TextStyle(color: Colors.white)),
        content: TextField(
          controller: ctl,
          style: const TextStyle(color: Colors.white),
          decoration: const InputDecoration(
            hintText: 'Type field report or message...',
            hintStyle: TextStyle(color: Colors.white38),
          ),
          maxLines: 3,
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel', style: TextStyle(color: Colors.white54))),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff3b82f6)),
            onPressed: () {
              if (ctl.text.trim().isNotEmpty) {
                LocalDb.enqueue('note', jsonEncode({'message': ctl.text.trim()}));
                Sync.run();
                Navigator.pop(ctx);
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Message sent to dispatch')));
              }
            },
            child: const Text('Send', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  // --- Photo Capture with GPS & Anti-Evasion Identity Verification ---
  Future<void> _snapPhoto({bool isSelfie = false, bool isMandatory = false}) async {
    try {
      File? photo;
      try {
        photo = await Navigator.of(context).push<File>(
          MaterialPageRoute(
            builder: (_) => InAppCameraScreen(
              preferredLens: isSelfie ? CameraLensDirection.front : CameraLensDirection.back,
              isSelfie: isSelfie,
              title: isSelfie ? 'Identity Verification Selfie' : 'Field / Crop Inspection',
              subtitle: isSelfie
                  ? 'Front Camera · Center face inside oval'
                  : 'Rear Camera · Plot Observation',
            ),
          ),
        );
      } catch (camErr) {
        // Fallback to system camera if in-app camera fails to initialize
        final picker = ImagePicker();
        final xf = await picker.pickImage(
          source: ImageSource.camera,
          preferredCameraDevice: isSelfie ? CameraDevice.front : CameraDevice.rear,
          imageQuality: 75,
          maxWidth: 1600,
          maxHeight: 1600,
        );
        if (xf != null) photo = File(xf.path);
      }

      if (photo == null) {
        // Officer cancelled or backed out without taking the photo
        if (isSelfie || isMandatory) {
          final sp = await SharedPreferences.getInstance();
          await sp.setBool('pending_selfie_verification', true);

          // Log security evasion alert to SQLite offline queue
          try {
            await LocalDb.enqueue(
              'duty',
              jsonEncode({
                'status': 'on',
                'event_type': 'selfie_evaded',
                'note': 'Officer cancelled / backed out of identity verification selfie',
                'lat': _currentPosition?.latitude ?? 0.0,
                'lng': _currentPosition?.longitude ?? 0.0,
                'battery': _batteryLevel,
                't': DateTime.now().millisecondsSinceEpoch,
              }),
            );
            Sync.run();
          } catch (_) {}

          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
              content: Text('⚠️ සත්‍යාපනය මඟහරින ලදී (Selfie Cancelled)! ඔබගේ සත්‍යාපන Selfie ඡායාරූපය ලබා දෙන තුරු පසුපස කැමරාව අක්‍රිය වේ.'),
              backgroundColor: Colors.redAccent,
              duration: Duration(seconds: 4),
            ));
          }
        }
        return;
      }

      final File confirmedPhoto = photo;
      final String photoPath = confirmedPhoto.path;

      // Ensure FRESH GPS position at the moment photo is taken
      Position? pos;
      try {
        pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.high,
          timeLimit: const Duration(seconds: 4),
        );
      } catch (_) {}
      pos ??= _currentPosition;
      pos ??= await Geolocator.getLastKnownPosition();

      final lat = pos?.latitude ?? 7.2050;
      final lng = pos?.longitude ?? 81.6580;
      if (pos != null) {
        _currentPosition = pos;
      }

      // 1. Proximity query for nearest plots (sorted by boundary edge distance)
      List<Map<String, dynamic>> nearbyPlots = await LocalDb.getNearbyGeofences(lat, lng, limit: 8);
      if (nearbyPlots.isEmpty) {
        try {
          final onlinePlots = await Sync.refreshNearbyGeofences(lat, lng)
              .timeout(const Duration(milliseconds: 2000), onTimeout: () => null);
          if (onlinePlots != null && onlinePlots.isNotEmpty) {
            nearbyPlots = onlinePlots.take(8).toList();
          }
        } catch (_) {}
      }

      String autoGeofence = 'Field Area';
      String selectedGeofence = '';
      if (nearbyPlots.isNotEmpty) {
        final closest = nearbyPlots.first;
        final bool isInside = closest['is_inside'] == true;
        final edgeMeters = (closest['edge_distance'] as num?)?.round() ?? (closest['distance'] as num?)?.round() ?? 0;
        if (isInside || edgeMeters <= 10) {
          autoGeofence = 'Inside ${closest['name']}';
          selectedGeofence = closest['name']?.toString() ?? '';
        } else if (edgeMeters <= 500) {
          autoGeofence = 'Near ${closest['name']} (${edgeMeters}m)';
          selectedGeofence = closest['name']?.toString() ?? '';
        } else if (edgeMeters <= 1500) {
          autoGeofence = 'Near ${closest['name']} (${(edgeMeters / 1000).toStringAsFixed(1)}km)';
          selectedGeofence = closest['name']?.toString() ?? '';
        } else {
          autoGeofence = 'Outside Plantation Area';
          selectedGeofence = 'Office / External Area';
        }
      } else {
        autoGeofence = 'Outside Plantation Area';
        selectedGeofence = 'Office / External Area';
      }

      String selectedActivity = isSelfie ? 'Officer Verification Selfie' : 'Field Visit';
      String note = isSelfie ? 'On-site Officer Verification Selfie' : '';

      if (mounted) {
        final res = await showDialog<Map<String, String>>(
          context: context,
          builder: (ctx) {
            final ctl = TextEditingController(text: note);
            String act = selectedActivity;
            String secGf = selectedGeofence;

            return StatefulBuilder(
              builder: (context, setDlgState) {
                return AlertDialog(
                  backgroundColor: const Color(0xff1e293b),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  contentPadding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
                  titlePadding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
                  actionsPadding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
                  title: Row(
                    children: [
                      Icon(isSelfie ? Icons.face : Icons.camera_alt, color: isSelfie ? const Color(0xffa855f7) : const Color(0xff38bdf8), size: 20),
                      const SizedBox(width: 8),
                      Text(isSelfie ? 'Officer Selfie Verification' : 'Field Photo & Task Details',
                          style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold)),
                    ],
                  ),
                  content: SizedBox(
                    width: double.maxFinite,
                    child: SingleChildScrollView(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // 1. Activity / Task Category Selector
                          const Text('1. Activity / Task Type:',
                              style: TextStyle(color: Colors.white70, fontSize: 11.5, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 5),
                          Wrap(
                            spacing: 5,
                            runSpacing: 5,
                            children: [
                              if (isSelfie) 'Officer Verification Selfie',
                              'Field Visit',
                              'Fertilizer Application',
                              'Land Preparation',
                              'Harvesting',
                              'Irrigation Check',
                              'Weed Control',
                              'General Inspection',
                              '🌾 Paddy Plot Visit',
                              '🌾 Paddy Land Observation',
                              'Other'
                            ].map((item) {
                              final isSel = act == item;
                              return InkWell(
                                onTap: () => setDlgState(() => act = item),
                                borderRadius: BorderRadius.circular(6),
                                child: AnimatedContainer(
                                  duration: const Duration(milliseconds: 120),
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
                                  decoration: BoxDecoration(
                                    color: isSel ? const Color(0xff0284c7) : const Color(0xff0f172a),
                                    borderRadius: BorderRadius.circular(6),
                                    border: Border.all(
                                      color: isSel ? const Color(0xff38bdf8) : const Color(0xff334155),
                                      width: 1,
                                    ),
                                  ),
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      if (isSel) ...[
                                        const Icon(Icons.check, size: 12, color: Colors.white),
                                        const SizedBox(width: 4),
                                      ],
                                      Text(
                                        item,
                                        style: TextStyle(
                                          color: isSel ? Colors.white : const Color(0xffcbd5e1),
                                          fontSize: 10.5,
                                          fontWeight: isSel ? FontWeight.bold : FontWeight.w500,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              );
                            }).toList(),
                          ),
                          const SizedBox(height: 10),

                          // 2. Geofence Location (Plot)
                          const Text('2. Location & Plot:',
                              style: TextStyle(color: Colors.white70, fontSize: 12, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: const Color(0xff0f172a),
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: const Color(0xff334155)),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.location_on, color: Color(0xff10b981), size: 16),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text('📍 Auto: $autoGeofence',
                                      style: const TextStyle(
                                          color: Color(0xff34d399), fontSize: 11, fontWeight: FontWeight.w600)),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 8),

                          () {
                            final validNearbyPlots = nearbyPlots.where((p) {
                              final d = (p['distance'] as num?)?.toDouble() ?? 0.0;
                              return p['is_inside'] == true || d <= 2000;
                            }).toList();

                            final dropdownItems = <String>[
                              ...validNearbyPlots.map((p) => p['name'].toString()),
                              if (!validNearbyPlots.any((p) => p['name'] == 'Office / External Area'))
                                'Office / External Area',
                            ];

                            final currentVal = dropdownItems.contains(secGf)
                                ? secGf
                                : (dropdownItems.isNotEmpty ? dropdownItems.first : 'Office / External Area');

                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  validNearbyPlots.isNotEmpty
                                      ? 'Select / Confirm Plot (Nearby Only):'
                                      : 'Confirm Location Area:',
                                  style: const TextStyle(color: Colors.white60, fontSize: 11),
                                ),
                                const SizedBox(height: 4),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 10),
                                  decoration: BoxDecoration(
                                    color: const Color(0xff0f172a),
                                    borderRadius: BorderRadius.circular(8),
                                    border: Border.all(color: const Color(0xff334155)),
                                  ),
                                  child: DropdownButtonHideUnderline(
                                    child: DropdownButton<String>(
                                      value: currentVal,
                                      dropdownColor: const Color(0xff0f172a),
                                      isExpanded: true,
                                      style: const TextStyle(color: Colors.white, fontSize: 12),
                                      items: dropdownItems.map((val) {
                                        final matched = validNearbyPlots.firstWhere(
                                          (p) => p['name'] == val,
                                          orElse: () => <String, dynamic>{},
                                        );
                                        String tag = '';
                                        if (matched.isNotEmpty) {
                                          final d = (matched['distance'] as num?)?.round() ?? 0;
                                          tag = d <= 25 ? ' (Inside / 0m)' : ' (${d}m away)';
                                        }
                                        return DropdownMenuItem<String>(
                                          value: val,
                                          child: Text('$val$tag', style: const TextStyle(color: Colors.white)),
                                        );
                                      }).toList(),
                                      onChanged: (v) {
                                        if (v != null) setDlgState(() => secGf = v);
                                      },
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 8),
                              ],
                            );
                          }(),

                          // 3. Optional Remarks
                          const Text('3. Optional Remarks / Note:',
                              style: TextStyle(color: Colors.white70, fontSize: 11.5, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          TextField(
                            controller: ctl,
                            style: const TextStyle(color: Colors.white, fontSize: 12),
                            decoration: const InputDecoration(
                              hintText: 'Additional details (e.g. 50kg urea applied)...',
                              hintStyle: TextStyle(color: Colors.white38, fontSize: 11),
                              isDense: true,
                              contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 7),
                              border: OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  actions: [
                    TextButton(
                      onPressed: () => Navigator.pop(ctx, null),
                      child: const Text('Cancel', style: TextStyle(color: Colors.white54)),
                    ),
                    ElevatedButton(
                      style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff0284c7)),
                      onPressed: () {
                        Navigator.pop(ctx, {
                          'activity': act,
                          'auto_geofence': autoGeofence,
                          'selected_geofence': secGf.isNotEmpty ? secGf : autoGeofence,
                          'note': ctl.text.trim(),
                        });
                      },
                      child: const Text('Upload Photo',
                          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                    ),
                  ],
                );
              },
            );
          },
        );

        if (res == null) return; // User cancelled
        selectedActivity = res['activity'] ?? 'Field Visit';
        autoGeofence = res['auto_geofence'] ?? '';
        selectedGeofence = res['selected_geofence'] ?? '';
        note = res['note'] ?? '';
      }

      final cid = 'fp_${DateTime.now().millisecondsSinceEpoch}';

      await LocalDb.enqueue(
        'field_photo',
        jsonEncode({
          'client_id': cid,
          'lat': lat,
          'lng': lng,
          'activity_type': selectedActivity,
          'auto_geofence': autoGeofence,
          'selected_geofence': selectedGeofence,
          'note': note,
          'is_selfie': isSelfie ? 1 : 0,
          't': DateTime.now().millisecondsSinceEpoch,
        }),
        file: photoPath,
      );
      Sync.run();

      // Reset or increment identity verification counters
      try {
        final sp = await SharedPreferences.getInstance();
        if (isSelfie) {
          await sp.setBool('pending_selfie_verification', false);
          await sp.setInt('visits_since_selfie', 0);
          await sp.setDouble('last_selfie_lat', lat);
          await sp.setDouble('last_selfie_lng', lng);
          await sp.setString('last_selfie_plot', selectedGeofence.isNotEmpty ? selectedGeofence : autoGeofence);
          final now = DateTime.now();
          await sp.setInt('last_selfie_time', now.millisecondsSinceEpoch);
          await sp.setString('last_selfie_day', now.toIso8601String().substring(0, 10));
        } else {
          int v = (sp.getInt('visits_since_selfie') ?? 0) + 1;
          await sp.setInt('visits_since_selfie', v);
        }
      } catch (_) {}

      final photoItem = {
        'client_id': cid,
        'lat': lat,
        'lng': lng,
        'activity_type': selectedActivity,
        'auto_geofence': autoGeofence,
        'selected_geofence': selectedGeofence,
        'note': note,
        'file': photoPath,
        'time': DateTime.now(),
      };
      setState(() {
        _capturedPhotos.insert(0, photoItem);
      });

      if (mounted) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            backgroundColor: const Color(0xff1e293b),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            title: const Row(
              children: [
                Icon(Icons.check_circle, color: Color(0xff22c55e), size: 24),
                SizedBox(width: 8),
                Text('Photo Uploaded!', style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
              ],
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: Image.file(
                    confirmedPhoto,
                    height: 160,
                    width: double.infinity,
                    fit: BoxFit.cover,
                  ),
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: const Color(0xff0f172a),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('📍 GPS: ${lat.toStringAsFixed(5)}, ${lng.toStringAsFixed(5)}',
                          style: const TextStyle(color: Colors.white70, fontSize: 11)),
                      const SizedBox(height: 4),
                      const Text('☁️ Status: Synced to Galoya Server',
                          style: TextStyle(color: Color(0xff38bdf8), fontSize: 11, fontWeight: FontWeight.bold)),
                      if (note.isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text('📝 Note: $note', style: const TextStyle(color: Colors.white60, fontSize: 11)),
                      ],
                    ],
                  ),
                ),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () {
                  Navigator.pop(ctx);
                  _mapController.move(LatLng(lat, lng), 16.5);
                },
                child: const Text('View on Map', style: TextStyle(color: Color(0xff38bdf8))),
              ),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff16a34a)),
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Done', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              ),
            ],
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error capturing photo: $e')));
      }
    }
  }

  Future<void> _handleCameraAction() async {
    final sp = await SharedPreferences.getInstance();
    final bool pendingSelfie = sp.getBool('pending_selfie_verification') ?? false;
    final int visits = sp.getInt('visits_since_selfie') ?? 0;

    final double? lastLat = sp.getDouble('last_selfie_lat');
    final double? lastLng = sp.getDouble('last_selfie_lng');
    final int? lastTime = sp.getInt('last_selfie_time');
    final String? lastDay = sp.getString('last_selfie_day');

    if (!mounted) return;

    // 1. Mandatory Front Selfie if previously cancelled or evaded (Anti-Evasion Lockout)
    if (pendingSelfie) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('⚠️ සත්‍යාපන Selfie ඡායාරූපය ලබා දීම අනිවාර්ය වේ (Front Camera Active).'),
          backgroundColor: Color(0xff8b5cf6),
          duration: Duration(seconds: 4),
        ));
      }
      _snapPhoto(isSelfie: true, isMandatory: true);
      return;
    }

    final now = DateTime.now();
    final currentDay = now.toIso8601String().substring(0, 10);
    final curLat = _currentPosition?.latitude ?? 7.2050;
    final curLng = _currentPosition?.longitude ?? 81.6580;

    // 2. Calculate Distance from last verified selfie point (Hardware GPS Haversine)
    double distanceMeters = 999999.0;
    if (lastLat != null && lastLng != null) {
      distanceMeters = Geolocator.distanceBetween(lastLat, lastLng, curLat, curLng);
    }

    // 3. Smart Verification Rules Trigger
    bool shouldTriggerSelfie = false;
    String triggerReason = '';

    // Check A: Day's First Photo (or never verified on this device / new calendar day)
    if (lastTime == null || lastDay == null || lastDay != currentDay) {
      shouldTriggerSelfie = true;
      triggerReason = '🛡️ දවසේ ආරම්භක සත්‍යාපනය: කරුණාකර Front Selfie ඡායාරූපයක් ලබාගන්න.';
    }
    // Check B: Time Interval - 3 hours elapsed since last selfie
    else if ((now.millisecondsSinceEpoch - lastTime) >= (3 * 3600 * 1000)) {
      shouldTriggerSelfie = true;
      triggerReason = '🛡️ කාලීන සත්‍යාපනය (පැය 3 කට පසු): Front Selfie එකක් ලබාගන්න.';
    }
    // Check C: Field Photos limit - 4 field photos taken since last selfie
    else if (visits >= 4) {
      shouldTriggerSelfie = true;
      triggerReason = '🛡️ ක්ෂේත්‍ර චාරිකා සත්‍යාපනය (චාරිකා 4 කට පසු): Front Selfie එකක් ලබාගන්න.';
    }
    // Check D: Moved to distant zone (> 3km away) and took at least 2 visits
    else if (distanceMeters >= 3000.0 && visits >= 2) {
      final distKm = (distanceMeters / 1000).toStringAsFixed(1);
      shouldTriggerSelfie = true;
      triggerReason = '🛡️ නව කලාප සත්‍යාපනය (${distKm}km දුරින්): Front Selfie එකක් ලබාගන්න.';
    }

    if (shouldTriggerSelfie) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(triggerReason),
          backgroundColor: const Color(0xff8b5cf6),
          duration: const Duration(seconds: 4),
        ));
      }
      _snapPhoto(isSelfie: true, isMandatory: true);
      return;
    }

    // Default: Normal Field / Crop Photo (Rear Camera)
    _snapPhoto(isSelfie: false, isMandatory: false);
  }

  Future<void> _showManualCameraModeSheet() async {
    final sp = await SharedPreferences.getInstance();
    final bool pendingSelfie = sp.getBool('pending_selfie_verification') ?? false;

    if (!mounted) return;

    showModalBottomSheet(
      context: context,
      backgroundColor: const Color(0xff0f172a),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(6),
                      decoration: BoxDecoration(
                        color: const Color(0xff0284c7).withOpacity(0.2),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Icon(Icons.camera, color: Color(0xff38bdf8), size: 20),
                    ),
                    const SizedBox(width: 10),
                    const Text(
                      'Camera & Verification Mode',
                      style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                // 1. Field Photo Option
                InkWell(
                  onTap: () {
                    Navigator.pop(ctx);
                    if (pendingSelfie) {
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                        content: Text('⚠️ සත්‍යාපන Selfie එක අනිවාර්යයි! කරුණාකර පළමුව Selfie එක සම්පූර්ණ කරන්න.'),
                        backgroundColor: Colors.redAccent,
                        duration: Duration(seconds: 4),
                      ));
                      _snapPhoto(isSelfie: true, isMandatory: true);
                    } else {
                      _snapPhoto(isSelfie: false, isMandatory: false);
                    }
                  },
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(
                      color: pendingSelfie ? const Color(0xff1e293b).withOpacity(0.5) : const Color(0xff1e293b),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: pendingSelfie ? Colors.red.withOpacity(0.4) : const Color(0xff334155)),
                    ),
                    child: Row(
                      children: [
                        Icon(
                          pendingSelfie ? Icons.lock : Icons.camera_alt,
                          color: pendingSelfie ? Colors.redAccent : const Color(0xff38bdf8),
                          size: 24,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '🌾 ක්ෂේත්‍ර ඡායාරූපය (Field / Crop Photo)',
                                style: TextStyle(
                                  color: pendingSelfie ? const Color(0xff94a3b8) : Colors.white,
                                  fontSize: 13.5,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                pendingSelfie
                                    ? '🔒 අක්‍රියයි - පළමුව Selfie සත්‍යාපනය සම්පූර්ණ කරන්න'
                                    : 'පසුපස කැමරාව (Rear Camera) මඟින් වගා හෝ කාර්ය ඡායාරූප',
                                style: TextStyle(
                                  color: pendingSelfie ? Colors.redAccent : const Color(0xff94a3b8),
                                  fontSize: 11,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                // 2. Officer Verification Selfie Option
                InkWell(
                  onTap: () {
                    Navigator.pop(ctx);
                    _snapPhoto(isSelfie: true, isMandatory: true);
                  },
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(
                      color: const Color(0xff1e293b),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xffa855f7)),
                    ),
                    child: const Row(
                      children: [
                        Icon(Icons.face_retouching_natural, color: Color(0xffc084fc), size: 24),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '🤳 නිලධාරී සත්‍යාපන Selfie (Officer Verification)',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 13.5,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                'ඉදිරිපස කැමරාව (Front Camera) සහ AI Liveness Detection',
                                style: TextStyle(color: Color(0xff94a3b8), fontSize: 11),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 8),
              ],
            ),
          ),
        );
      },
    );
  }

  double? _safeDouble(dynamic val) {
    if (val == null) return null;
    if (val is num) return val.toDouble();
    if (val is String) return double.tryParse(val.trim());
    return null;
  }

  void _showGalleryModal() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xff0f172a),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => _FieldPhotosGallerySheet(
        capturedPhotos: _capturedPhotos,
        onPhotoTap: (item) {
          _openFullScreenPhotoViewer(context, item);
        },
      ),
    );
  }

  void _openFullScreenPhotoViewer(BuildContext context, Map<String, dynamic> item) {
    try {
      final fullUrl = item['full_url']?.toString() ?? (item['file_path'] != null ? 'https://track.galoya.lk/uploads/${item['file_path']}' : '');
      final localPath = item['file'] as String?;
      final lat = _safeDouble(item['lat']);
      final lng = _safeDouble(item['lng']);
      final activity = item['activity_type']?.toString().isNotEmpty == true ? item['activity_type'].toString() : 'Field Photo';
      final sector = item['geofence_name']?.toString() ?? item['selected_geofence']?.toString() ?? item['auto_geofence']?.toString() ?? 'Gal-Oya Plantation';
      final timeStr = item['taken_at']?.toString() ?? (item['time'] is DateTime ? (item['time'] as DateTime).toIso8601String().substring(0, 19).replaceAll('T', ' ') : '');
      final note = item['note']?.toString();

      Navigator.of(context, rootNavigator: true).push(
        MaterialPageRoute(
          fullscreenDialog: true,
          builder: (ctx) {
            return Scaffold(
              backgroundColor: const Color(0xff0b0f19),
              body: SafeArea(
                child: Stack(
                  children: [
                    // 1. Interactive Zoomable Photo (Pinch to Zoom & Pan)
                    Center(
                      child: InteractiveViewer(
                        minScale: 0.8,
                        maxScale: 5.0,
                        child: localPath != null && File(localPath).existsSync()
                            ? Image.file(
                                File(localPath),
                                fit: BoxFit.contain,
                              )
                            : (fullUrl.toString().isNotEmpty
                                ? Image.network(
                                    fullUrl.toString(),
                                    fit: BoxFit.contain,
                                    loadingBuilder: (_, child, prog) {
                                      if (prog == null) return child;
                                      final total = prog.expectedTotalBytes;
                                      final loaded = prog.cumulativeBytesLoaded;
                                      return Center(
                                        child: Column(
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            const CircularProgressIndicator(color: Color(0xff10b981)),
                                            const SizedBox(height: 12),
                                            Text(
                                              total != null ? '${(loaded / total * 100).toInt()}%' : 'Loading photo...',
                                              style: const TextStyle(color: Colors.white70, fontSize: 13),
                                            ),
                                          ],
                                        ),
                                      );
                                    },
                                    errorBuilder: (_, __, ___) => const Column(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Icon(Icons.broken_image, color: Colors.white38, size: 64),
                                        SizedBox(height: 10),
                                        Text('Could not load photo', style: TextStyle(color: Colors.white54)),
                                      ],
                                    ),
                                  )
                                : const Center(child: Text('No photo source', style: TextStyle(color: Colors.white54)))),
                      ),
                    ),

                    // 2. Top Bar (Back button, Activity badge, Close button)
                    Positioned(
                      top: 10,
                      left: 12,
                      right: 12,
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Container(
                            decoration: BoxDecoration(
                              color: Colors.black54,
                              shape: BoxShape.circle,
                              border: Border.all(color: Colors.white24),
                            ),
                            child: IconButton(
                              icon: const Icon(Icons.arrow_back, color: Colors.white),
                              onPressed: () => Navigator.pop(ctx),
                              tooltip: 'Close',
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                            decoration: BoxDecoration(
                              color: const Color(0xff064e3b).withOpacity(0.9),
                              borderRadius: BorderRadius.circular(20),
                              border: Border.all(color: const Color(0xff10b981), width: 1.2),
                              boxShadow: const [BoxShadow(color: Colors.black45, blurRadius: 10)],
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Icon(Icons.verified, color: Color(0xff34d399), size: 16),
                                const SizedBox(width: 6),
                                Text(
                                  activity,
                                  style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                                ),
                              ],
                            ),
                          ),
                          Container(
                            decoration: BoxDecoration(
                              color: Colors.black54,
                              shape: BoxShape.circle,
                              border: Border.all(color: Colors.white24),
                            ),
                            child: IconButton(
                              icon: const Icon(Icons.close, color: Colors.white),
                              onPressed: () => Navigator.pop(ctx),
                              tooltip: 'Close',
                            ),
                          ),
                        ],
                      ),
                    ),

                    // 3. Bottom Glass Overlay Card with Geofence & GPS Info
                    Positioned(
                      bottom: 16,
                      left: 16,
                      right: 16,
                      child: Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: const Color(0xff0f172a).withOpacity(0.92),
                          borderRadius: BorderRadius.circular(18),
                          border: Border.all(color: const Color(0xff334155), width: 1.2),
                          boxShadow: const [
                            BoxShadow(color: Colors.black54, blurRadius: 16, offset: Offset(0, 6)),
                          ],
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.location_on, color: Color(0xff10b981), size: 18),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    sector,
                                    style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.bold),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                                if (timeStr.isNotEmpty)
                                  Text(
                                    timeStr.length > 16 ? timeStr.substring(0, 16) : timeStr,
                                    style: const TextStyle(color: Color(0xff94a3b8), fontSize: 11),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 6),
                            if (lat != null && lng != null)
                              Row(
                                children: [
                                  const Icon(Icons.my_location, color: Color(0xff38bdf8), size: 14),
                                  const SizedBox(width: 6),
                                  Text(
                                    'GPS: ${lat.toStringAsFixed(6)}, ${lng.toStringAsFixed(6)}',
                                    style: const TextStyle(color: Color(0xff38bdf8), fontSize: 12, fontFamily: 'monospace'),
                                  ),
                                ],
                              ),
                            if (note != null && note.trim().isNotEmpty) ...[
                              const SizedBox(height: 6),
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Icon(Icons.note_alt_outlined, color: Colors.amber, size: 14),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Text(
                                      note,
                                      style: const TextStyle(color: Colors.white70, fontSize: 12),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                            const SizedBox(height: 10),
                            Row(
                              children: [
                                if (lat != null && lng != null)
                                  Expanded(
                                    child: ElevatedButton.icon(
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: const Color(0xff0284c7),
                                        foregroundColor: Colors.white,
                                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                        padding: const EdgeInsets.symmetric(vertical: 9),
                                      ),
                                      icon: const Icon(Icons.map, size: 16),
                                      label: const Text('Show on Map', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                                      onPressed: () {
                                        Navigator.pop(ctx);
                                        Navigator.maybePop(context);
                                        _mapController.move(LatLng(lat, lng), 17.0);
                                      },
                                    ),
                                  ),
                                if (lat != null && lng != null) const SizedBox(width: 8),
                                Expanded(
                                  child: OutlinedButton(
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: Colors.white70,
                                      side: const BorderSide(color: Color(0xff475569)),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                      padding: const EdgeInsets.symmetric(vertical: 9),
                                    ),
                                    child: const Text('Close', style: TextStyle(fontSize: 12)),
                                    onPressed: () => Navigator.pop(ctx),
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Cannot view photo: $e')));
      }
    }
  }

  void _viewPhotoDetail(Map<String, dynamic> item) {
    final fullUrl = item['full_url'] ?? (item['file_path'] != null ? 'https://track.galoya.lk/uploads/${item['file_path']}' : '');
    final localPath = item['file'] as String?;
    final lat = _safeDouble(item['lat']);
    final lng = _safeDouble(item['lng']);

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff1e293b),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.photo, color: Color(0xff38bdf8), size: 22),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                item['activity_type']?.toString().isNotEmpty == true ? item['activity_type'].toString() : 'Field Photo',
                style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            GestureDetector(
              onTap: () {
                Navigator.pop(ctx);
                _openFullScreenPhotoViewer(context, item);
              },
              child: Stack(
                alignment: Alignment.bottomRight,
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: localPath != null && File(localPath).existsSync()
                        ? Image.file(File(localPath), height: 200, width: double.infinity, fit: BoxFit.cover)
                        : (fullUrl.toString().isNotEmpty
                            ? Image.network(
                                fullUrl.toString(),
                                height: 200,
                                width: double.infinity,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => Container(
                                  height: 140,
                                  color: Colors.white12,
                                  child: const Center(child: Icon(Icons.broken_image, color: Colors.white54, size: 40)),
                                ),
                              )
                            : const SizedBox(height: 50, child: Center(child: Text('Image not found', style: TextStyle(color: Colors.white54))))),
                  ),
                  Container(
                    margin: const EdgeInsets.all(8),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(color: Colors.black87, borderRadius: BorderRadius.circular(6)),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.zoom_in, color: Colors.white, size: 14),
                        SizedBox(width: 4),
                        Text('Tap to View Full Screen', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: const Color(0xff0f172a),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('📍 Sector: ${item['geofence_name'] ?? item['selected_geofence'] ?? item['auto_geofence'] ?? 'N/A'}',
                      style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 4),
                  if (lat != null && lng != null)
                    Text('🌐 GPS: ${lat.toStringAsFixed(5)}, ${lng.toStringAsFixed(5)}',
                        style: const TextStyle(color: Colors.white70, fontSize: 11)),
                  if (item['note']?.toString().isNotEmpty == true) ...[
                    const SizedBox(height: 4),
                    Text('📝 Note: ${item['note']}', style: const TextStyle(color: Colors.white70, fontSize: 11)),
                  ],
                ],
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              _openFullScreenPhotoViewer(context, item);
            },
            child: const Text('🔍 Full Screen', style: TextStyle(color: Color(0xff10b981), fontWeight: FontWeight.bold)),
          ),
          if (lat != null && lng != null)
            TextButton(
              onPressed: () {
                Navigator.pop(ctx);
                _mapController.move(LatLng(lat, lng), 16.5);
              },
              child: const Text('View on Map', style: TextStyle(color: Color(0xff38bdf8))),
            ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff2563eb)),
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  // --- Tasks Bottom Sheet ---
  void _showTasksModal() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => _AssignedTasksModalSheet(
        tasks: _tasks,
        onRefresh: _loadTasks,
        onOpenTask: (task) {
          Navigator.push(context, MaterialPageRoute(builder: (_) => TaskDetailScreen(task: task))).then((_) => _loadTasks());
        },
        onGoogleNavigate: (lat, lng, name) {
          _openGoogleMapsNavigation(lat, lng, name);
        },
        onSelectOnMap: (task) {
          _selectPlotForNavigation(task);
        },
      ),
    );
  }

  String _formatDistanceMeters(double meters) {
    if (meters < 1000) {
      return '${meters.round()} m';
    }
    return '${(meters / 1000).toStringAsFixed(1)} km';
  }

  Future<void> _openGoogleMapsNavigation(double lat, double lng, String label) async {
    final googleNavUri = Uri.parse('google.navigation:q=$lat,$lng&mode=d');
    final webMapsUri = Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng');
    try {
      if (await canLaunchUrl(googleNavUri)) {
        await launchUrl(googleNavUri, mode: LaunchMode.externalApplication);
      } else {
        await launchUrl(webMapsUri, mode: LaunchMode.externalApplication);
      }
    } catch (_) {
      try {
        await launchUrl(webMapsUri, mode: LaunchMode.externalApplication);
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Could not open navigation: $e')),
          );
        }
      }
    }
  }

  void _selectPlotForNavigation(Map<String, dynamic> plot) async {
    setState(() {
      _targetPlot = plot;
      _targetPolygonPoints = [];
      _nearbyIdentifiedPolygons = [];
      _nearbyIdentifiedMarkers = [];
      _nearbyIdentifiedPlots = [];
    });

    final gLat = (plot['lat'] is num)
        ? (plot['lat'] as num).toDouble()
        : double.tryParse(plot['lat']?.toString() ?? '') ?? 0.0;
    final gLng = (plot['lng'] is num)
        ? (plot['lng'] as num).toDouble()
        : double.tryParse(plot['lng']?.toString() ?? '') ?? 0.0;

    if (gLat != 0.0 && gLng != 0.0) {
      _mapController.move(LatLng(gLat, gLng), 16.5);
    }

    final plotLabel = (plot['code'] != null && plot['code'].toString().isNotEmpty && plot['code'] != plot['name'])
        ? '${plot['name']} (${plot['code']})'
        : '${plot['name'] ?? 'Plot'}';

    // 1. Check if polygon coordinates already exist in the plot map (from online sync or cache)
    final pts = <LatLng>[];
    if (plot['coordinates'] is List) {
      for (final c in (plot['coordinates'] as List)) {
        if (c is List && c.length >= 2) {
          final lat = (c[0] is num) ? (c[0] as num).toDouble() : double.tryParse(c[0]?.toString() ?? '') ?? 0.0;
          final lng = (c[1] is num) ? (c[1] as num).toDouble() : double.tryParse(c[1]?.toString() ?? '') ?? 0.0;
          if (lat != 0.0 && lng != 0.0) pts.add(LatLng(lat, lng));
        }
      }
    }

    // 2. Fallback: Query full polygon coordinates from server on-demand
    if (pts.isEmpty) {
      try {
        final name = (plot['name'] ?? plot['code'] ?? '').toString();
        if (name.isNotEmpty) {
          final res = await Api.get('plot_polygon', params: {'name': name});
          if (res['ok'] == true && res['coordinates'] is List) {
            final rawList = res['coordinates'] as List;
            for (final c in rawList) {
              if (c is List && c.length >= 2) {
                final lat = (c[0] is num) ? (c[0] as num).toDouble() : double.tryParse(c[0]?.toString() ?? '') ?? 0.0;
                final lng = (c[1] is num) ? (c[1] as num).toDouble() : double.tryParse(c[1]?.toString() ?? '') ?? 0.0;
                if (lat != 0.0 && lng != 0.0) pts.add(LatLng(lat, lng));
              }
            }
          }
        }
      } catch (_) {}
    }

    double centerLat = gLat;
    double centerLng = gLng;
    if (pts.isNotEmpty) {
      double sumLat = 0.0;
      double sumLng = 0.0;
      for (final p in pts) {
        sumLat += p.latitude;
        sumLng += p.longitude;
      }
      centerLat = sumLat / pts.length;
      centerLng = sumLng / pts.length;
      plot['lat'] = centerLat;
      plot['lng'] = centerLng;
    }

    if (pts.isNotEmpty && mounted) {
      setState(() {
        _targetPolygonPoints = pts;
      });
      final bounds = LatLngBounds.fromPoints(pts);
      _mapController.fitCamera(CameraFit.bounds(bounds: bounds, padding: const EdgeInsets.all(50)));
    }

    if (mounted) {
      ScaffoldMessenger.of(context).hideCurrentSnackBar();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: const Color(0xff15803d),
          behavior: SnackBarBehavior.floating,
          margin: const EdgeInsets.fromLTRB(14, 0, 14, 120),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          duration: const Duration(seconds: 10),
          dismissDirection: DismissDirection.horizontal,
          content: Row(
            children: [
              const Icon(Icons.location_on, color: Color(0xff4ade80), size: 18),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  'Plot: $plotLabel',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              TextButton(
                style: TextButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                  backgroundColor: const Color(0x33ffffff),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () {
                  ScaffoldMessenger.of(context).hideCurrentSnackBar();
                  setState(() {
                    _targetPlot = null;
                    _targetPolygonPoints = [];
                    _nearbyIdentifiedPolygons = [];
                    _nearbyIdentifiedMarkers = [];
                    _nearbyIdentifiedPlots = [];
                  });
                },
                child: const Text('Clear', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(width: 4),
              TextButton(
                style: TextButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                  backgroundColor: const Color(0xff059669),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
                onPressed: () => _openGoogleMapsNavigation(centerLat != 0.0 ? centerLat : gLat, centerLng != 0.0 ? centerLng : gLng, plotLabel),
                child: const Text('Google Maps', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(width: 4),
              IconButton(
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(),
                icon: const Icon(Icons.close, color: Colors.white70, size: 20),
                tooltip: 'Dismiss Bar',
                onPressed: () {
                  ScaffoldMessenger.of(context).hideCurrentSnackBar();
                },
              ),
            ],
          ),
        ),
      );
    }
  }

  void _showCdnModalSheet({Map<String, dynamic>? prefillPlot}) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => _CaneCdnModalSheet(
        currentPosition: _currentPosition,
        prefillPlot: prefillPlot,
        userCode: _code,
        userName: _name,
      ),
    );
  }

  void _showPlotNavigatorModal() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => _PlotNavigatorModalSheet(
        currentPosition: _currentPosition,
        onSelectPlot: (plot) {
          Navigator.pop(ctx);
          _selectPlotForNavigation(plot);
        },
        onGoogleNavigate: (lat, lng, name) {
          Navigator.pop(ctx);
          _openGoogleMapsNavigation(lat, lng, name);
        },
        onIssueCdn: (plot) {
          Navigator.pop(ctx);
          _showCdnModalSheet(prefillPlot: plot);
        },
        formatDistance: _formatDistanceMeters,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final currentLatLng = _currentPosition != null
        ? LatLng(_currentPosition!.latitude, _currentPosition!.longitude)
        : _defaultCenter;

    return Scaffold(
      backgroundColor: const Color(0xff0f172a),
      body: SafeArea(
        child: Stack(
          children: [
            // 1. FULL INTERACTIVE LEAFLET / FLUTTER MAP
            Positioned.fill(
              child: FlutterMap(
                mapController: _mapController,
                options: MapOptions(
                  initialCenter: currentLatLng,
                  initialZoom: 15.5,
                  minZoom: 5.0,
                  maxZoom: 19.0,
                  onPositionChanged: (camera, hasGesture) {
                    if (mounted && camera.rotation != _mapRotation) {
                      setState(() => _mapRotation = camera.rotation);
                    }
                  },
                ),
                children: [
                  TileLayer(
                    urlTemplate: _isSatellite
                        ? 'https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}'
                        : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    subdomains: const ['0', '1', '2', '3'],
                    userAgentPackageName: 'lk.galoya.stafftrack',
                    maxZoom: 20,
                  ),
                  if (_targetPolygonPoints.isNotEmpty || _nearbyIdentifiedPolygons.isNotEmpty)
                    PolygonLayer(
                      polygons: [
                        ..._nearbyIdentifiedPolygons,
                        if (_targetPolygonPoints.isNotEmpty && _nearbyIdentifiedPolygons.isEmpty)
                          Polygon(
                            points: _targetPolygonPoints,
                            color: const Color(0x3322c55e),
                            borderColor: const Color(0xff16a34a),
                            borderStrokeWidth: 3.0,
                            isFilled: true,
                          ),
                      ],
                    ),
                  if (_targetPlot != null && _targetPolygonPoints.isEmpty && _nearbyIdentifiedPolygons.isEmpty)
                    PolylineLayer(
                      polylines: [
                        Polyline(
                          points: [
                            currentLatLng,
                            LatLng(
                              (_targetPlot!['lat'] as num).toDouble(),
                              (_targetPlot!['lng'] as num).toDouble(),
                            ),
                          ],
                          strokeWidth: 4.5,
                          color: const Color(0xfff59e0b),
                        ),
                      ],
                    ),
                  MarkerLayer(
                    markers: [
                      ..._nearbyIdentifiedMarkers,
                      if (_isBlinkingLocation)
                        Marker(
                          point: currentLatLng,
                          width: 100,
                          height: 100,
                          child: AnimatedBuilder(
                            animation: _locationBlinkAnim,
                            builder: (ctx, _) {
                              final v = _locationBlinkAnim.value;
                              return Center(
                                child: Container(
                                  width: 40 + (v * 50),
                                  height: 40 + (v * 50),
                                  decoration: BoxDecoration(
                                    shape: BoxShape.circle,
                                    color: const Color(0xffec4899).withOpacity((1.0 - v) * 0.45),
                                    border: Border.all(
                                      color: const Color(0xffec4899).withOpacity(1.0 - v),
                                      width: 2.5,
                                    ),
                                  ),
                                ),
                              );
                            },
                          ),
                        ),
                      // CENTER PLOT NAME BADGE (Always visible in the middle of target plot / polygon!)
                      if (_targetPlot != null && _nearbyIdentifiedMarkers.isEmpty)
                        Marker(
                          point: _targetPolygonPoints.isNotEmpty
                              ? LatLng(
                                  _targetPolygonPoints.map((e) => e.latitude).reduce((a, b) => a + b) / _targetPolygonPoints.length,
                                  _targetPolygonPoints.map((e) => e.longitude).reduce((a, b) => a + b) / _targetPolygonPoints.length,
                                )
                              : LatLng(
                                  (_targetPlot!['lat'] is num)
                                      ? (_targetPlot!['lat'] as num).toDouble()
                                      : double.tryParse(_targetPlot!['lat']?.toString() ?? '') ?? 0.0,
                                  (_targetPlot!['lng'] is num)
                                      ? (_targetPlot!['lng'] as num).toDouble()
                                      : double.tryParse(_targetPlot!['lng']?.toString() ?? '') ?? 0.0,
                                ),
                          width: 140,
                          height: 38,
                          child: Center(
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                              decoration: BoxDecoration(
                                color: const Color(0xee0f172a),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: const Color(0xff22c55e), width: 1.8),
                                boxShadow: const [
                                  BoxShadow(color: Colors.black87, blurRadius: 6, offset: Offset(0, 2)),
                                ],
                              ),
                              child: Text(
                                _targetPlot!['name'] ?? '',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: Color(0xff4ade80), fontWeight: FontWeight.bold, fontSize: 12),
                              ),
                            ),
                          ),
                        ),
                      Marker(
                        point: currentLatLng,
                        width: 70,
                        height: 70,
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            // Google Maps Direction Cone / Beam if heading available
                            if (_currentPosition != null && _currentPosition!.heading >= 0)
                              Transform.rotate(
                                angle: (_currentPosition!.heading - _mapRotation) * (pi / 180.0),
                                child: CustomPaint(
                                  size: const Size(64, 64),
                                  painter: _GoogleDirectionBeamPainter(),
                                ),
                              ),
                            Container(
                              width: 44,
                              height: 44,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: _isBlinkingLocation
                                    ? const Color(0xffec4899).withOpacity(0.35)
                                    : Colors.blue.withOpacity(0.25),
                              ),
                              child: Center(
                                child: Container(
                                  width: 18,
                                  height: 18,
                                  decoration: BoxDecoration(
                                    shape: BoxShape.circle,
                                    color: _isBlinkingLocation ? const Color(0xffec4899) : const Color(0xff2563eb),
                                    border: Border.all(color: Colors.white, width: 2.8),
                                    boxShadow: const [
                                      BoxShadow(color: Colors.black45, blurRadius: 6, spreadRadius: 1.5),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      ..._capturedPhotos.map((p) {
                        return Marker(
                          point: LatLng(p['lat'] as double, p['lng'] as double),
                          width: 38,
                          height: 38,
                          child: GestureDetector(
                            onTap: () => _viewPhotoDetail(p),
                            child: Container(
                              decoration: BoxDecoration(
                                color: const Color(0xff0284c7),
                                shape: BoxShape.circle,
                                border: Border.all(color: Colors.white, width: 2),
                                boxShadow: const [
                                  BoxShadow(color: Colors.black45, blurRadius: 4, offset: Offset(0, 2)),
                                ],
                              ),
                              child: const Center(
                                child: Icon(Icons.camera_alt, color: Colors.white, size: 18),
                              ),
                            ),
                          ),
                        );
                      }),
                    ],
                  ),
                ],
              ),
            ),

            // 2. WIATAG TOP HEADER BAR
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.white,
                  boxShadow: [
                    BoxShadow(color: Colors.black.withOpacity(0.08), blurRadius: 10, offset: const Offset(0, 3)),
                  ],
                ),
                child: Row(
                  children: [
                    // Gal-Oya Official Branding
                    GestureDetector(
                      onTap: () => AppUpdater.checkForUpdates(context),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.asset(
                              'assets/logo.png',
                              width: 32,
                              height: 32,
                              fit: BoxFit.contain,
                              errorBuilder: (_, __, ___) => const Icon(Icons.location_on, color: Color(0xff15803d), size: 28),
                            ),
                          ),
                          const SizedBox(width: 8),
                          const Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text('GalOya', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w900, color: Color(0xff15803d), letterSpacing: -0.3)),
                                  Text('Track', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w900, color: Color(0xff0f172a), letterSpacing: -0.3)),
                                  Text('360', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w900, color: Color(0xffeab308), letterSpacing: -0.3)),
                                ],
                              ),
                              Text('People • Field • Fleet', style: TextStyle(fontSize: 8.5, fontWeight: FontWeight.w700, color: Color(0xff64748b))),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const Spacer(),
                    // Action Buttons (Plot, Tasks, Settings, Exit)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Find & Navigate to Plot Icon
                        IconButton(
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                          icon: const Icon(Icons.explore_outlined, color: Color(0xff0284c7), size: 22),
                          tooltip: 'Plot Navigator & Directions',
                          onPressed: _showPlotNavigatorModal,
                        ),
                        const SizedBox(width: 2),
                        // Cane Delivery Note (CDN) & Weighbridge Tracker
                        IconButton(
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                          icon: const Icon(Icons.receipt_long, color: Color(0xfff59e0b), size: 22),
                          tooltip: 'Cane Delivery Note (CDN)',
                          onPressed: () => _showCdnModalSheet(),
                        ),
                        const SizedBox(width: 2),
                        // Assigned Tasks Icon (Counts only pending/uncompleted tasks)
                        Builder(
                          builder: (context) {
                            final pendingCount = _tasks.where((t) => t['status'] != 'done' && t['status'] != 'cancelled').length;
                            return IconButton(
                              padding: EdgeInsets.zero,
                              constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                              icon: Stack(
                                clipBehavior: Clip.none,
                                children: [
                                  const Icon(Icons.assignment_outlined, color: Color(0xff334155), size: 22),
                                  if (pendingCount > 0)
                                    Positioned(
                                      right: -4,
                                      top: -3,
                                      child: Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                                        decoration: BoxDecoration(color: const Color(0xff2563eb), borderRadius: BorderRadius.circular(10)),
                                        child: Text('$pendingCount', style: const TextStyle(color: Colors.white, fontSize: 8.5, fontWeight: FontWeight.bold)),
                                      ),
                                    ),
                                ],
                              ),
                              tooltip: 'Assigned Tasks',
                              onPressed: _showTasksModal,
                            );
                          },
                        ),
                        const SizedBox(width: 2),
                        // Settings Icon (Supervisor PIN Protected)
                        IconButton(
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                          icon: const Icon(Icons.settings_outlined, color: Color(0xff334155), size: 22),
                          tooltip: 'Supervisor Settings',
                          onPressed: () async {
                            final authorized = await _requestAdminPassword('access telematics settings');
                            if (authorized && mounted) {
                              Navigator.push(context, MaterialPageRoute(builder: (_) => const SettingsScreen()));
                            }
                          },
                        ),
                        const SizedBox(width: 4),
                        // Logout / Exit Button (Supervisor PIN Protected - Styled for high visibility)
                        Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: const Color(0xfffef2f2),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: const Color(0xfffca5a5), width: 1.0),
                            boxShadow: const [
                              BoxShadow(color: Color(0x1aef4444), blurRadius: 4, offset: Offset(0, 1)),
                            ],
                          ),
                          child: IconButton(
                            padding: EdgeInsets.zero,
                            constraints: const BoxConstraints(),
                            icon: const Icon(Icons.logout_rounded, color: Color(0xffdc2626), size: 19),
                            tooltip: 'Exit & Sign Out',
                            onPressed: () async {
                              final authorized = await _requestAdminPassword('stop tracking and sign out from this device');
                              if (authorized) {
                                await Tracker.stop();
                                await AuthVault.clear();
                                final sp = await SharedPreferences.getInstance();
                                await sp.remove('token');
                                if (mounted) {
                                  Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => const LoginScreen()));
                                }
                              }
                            },
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            // 2.5. FLOATING TARGET PLOT NAVIGATION HUD
            if (_targetPlot != null)
              Positioned(
                top: 66,
                left: 16,
                right: 16,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: const Color(0xff16a34a), width: 1.5),
                    boxShadow: const [
                      BoxShadow(color: Colors.black54, blurRadius: 10, offset: Offset(0, 4)),
                    ],
                  ),
                  child: Builder(
                    builder: (ctx) {
                      final hudLat = _targetPolygonPoints.isNotEmpty
                          ? _targetPolygonPoints.map((e) => e.latitude).reduce((a, b) => a + b) / _targetPolygonPoints.length
                          : ((_targetPlot!['lat'] is num)
                              ? (_targetPlot!['lat'] as num).toDouble()
                              : double.tryParse(_targetPlot!['lat']?.toString() ?? '') ?? 0.0);
                      final hudLng = _targetPolygonPoints.isNotEmpty
                          ? _targetPolygonPoints.map((e) => e.longitude).reduce((a, b) => a + b) / _targetPolygonPoints.length
                          : ((_targetPlot!['lng'] is num)
                              ? (_targetPlot!['lng'] as num).toDouble()
                              : double.tryParse(_targetPlot!['lng']?.toString() ?? '') ?? 0.0);

                      return Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: const Color(0xff16a34a).withOpacity(0.2),
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(Icons.grass_rounded, color: Color(0xff22c55e), size: 20),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  _targetPlot!['name'] ?? 'Target Plot',
                                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                Text(
                                  (_targetPlot!['is_inside'] == true)
                                      ? '📍 Current Location Inside Plot'
                                      : (_currentPosition != null && hudLat != 0.0 && hudLng != 0.0
                                          ? 'Distance: ${_formatDistanceMeters(
                                              Geolocator.distanceBetween(
                                                _currentPosition!.latitude,
                                                _currentPosition!.longitude,
                                                hudLat,
                                                hudLng,
                                              ),
                                            )}'
                                          : 'Target plot active'),
                                  style: TextStyle(
                                    color: (_targetPlot!['is_inside'] == true)
                                        ? const Color(0xff4ade80)
                                        : const Color(0xff38bdf8),
                                    fontSize: 11,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.navigation, color: Colors.greenAccent),
                            tooltip: 'Google Maps Turn-by-Turn',
                            onPressed: () {
                              _openGoogleMapsNavigation(hudLat, hudLng, _targetPlot!['name'] ?? 'Plot');
                            },
                          ),
                          IconButton(
                            icon: const Icon(Icons.close, color: Colors.white60),
                            tooltip: 'Clear Polygon & Details',
                            onPressed: () {
                              ScaffoldMessenger.of(context).hideCurrentSnackBar();
                              setState(() {
                                _targetPlot = null;
                                _targetPolygonPoints = [];
                                _nearbyIdentifiedPolygons = [];
                                _nearbyIdentifiedMarkers = [];
                                _nearbyIdentifiedPlots = [];
                              });
                            },
                          ),
                        ],
                      );
                    },
                  ),
                ),
              ),

            // 3. COMPACT FLOATING EMERGENCY SOS BUTTON
            Positioned(
              top: _targetPlot != null ? 144 : 64,
              left: 14,
              child: Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: _sendSOS,
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xff1e293b).withOpacity(0.92),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: const Color(0xffef4444), width: 1.2),
                      boxShadow: const [
                        BoxShadow(color: Color(0x33ef4444), blurRadius: 8, spreadRadius: 1, offset: Offset(0, 2)),
                      ],
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 8,
                          height: 8,
                          decoration: const BoxDecoration(
                            color: Color(0xffef4444),
                            shape: BoxShape.circle,
                            boxShadow: [
                              BoxShadow(color: Color(0xffef4444), blurRadius: 4, spreadRadius: 1),
                            ],
                          ),
                        ),
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: const Color(0xffef4444),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Text('SOS', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 10)),
                        ),
                        const SizedBox(width: 6),
                        const Text(
                          'Emergency',
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.white),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),

            // 4. FLOATING MAP CONTROLS (Right side: Layers, Center, Compass, Nearby Plots)
            Positioned(
              top: _targetPlot != null ? 144 : 64,
              right: 14,
              child: Column(
                children: [
                  // Layer Switcher (Satellite / OSM)
                  _buildCircleMapBtn(
                    icon: _isSatellite ? Icons.layers : Icons.satellite_alt,
                    tooltip: 'Switch Layer',
                    onTap: () => setState(() => _isSatellite = !_isSatellite),
                  ),
                  const SizedBox(height: 10),
                  // Recenter
                  _buildCircleMapBtn(
                    icon: Icons.navigation_rounded,
                    tooltip: 'Recenter',
                    onTap: _recenterMap,
                  ),
                  const SizedBox(height: 10),
                  // Compass (Google Maps Style: Dynamic Needle + North Reset + Heading Follow)
                  _buildCompassMapBtn(),
                  const SizedBox(height: 10),
                  // Locate Current Plot & Nearby Polygons (Pink button from user request)
                  _buildCircleMapBtn(
                    icon: Icons.radar,
                    iconColor: const Color(0xffec4899),
                    tooltip: 'Identify Current & Nearby Plots',
                    onTap: _locateCurrentPlotAndShowNearby,
                  ),
                ],
              ),
            ),

            // 5. WIATAG 6 CIRCULAR ACTION BUTTONS
            Positioned(
              bottom: 175,
              left: 12,
              right: 12,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                children: [
                  _buildWiaActionBtn(
                    color: const Color(0xff8b5cf6), // Purple
                    icon: Icons.check,
                    onTap: _showStatusDialog,
                  ),
                  _buildWiaActionBtn(
                    color: const Color(0xff3b82f6), // Blue
                    icon: Icons.chat_bubble,
                    onTap: _showChatDialog,
                  ),
                  _buildWiaActionBtn(
                    color: const Color(0xfff97316), // Orange
                    icon: Icons.image,
                    onTap: _showGalleryModal,
                  ),
                  _buildWiaActionBtn(
                    color: const Color(0xff059669), // Emerald
                    icon: Icons.explore,
                    onTap: _showPlotNavigatorModal,
                  ),
                  _buildWiaActionBtn(
                    color: const Color(0xfff59e0b), // Golden CDN
                    icon: Icons.receipt_long,
                    onTap: () => _showCdnModalSheet(),
                  ),
                  _buildWiaActionBtn(
                    color: const Color(0xff0284c7), // Cyan Camera
                    icon: Icons.camera_alt,
                    onTap: _handleCameraAction,
                    onLongPress: _showManualCameraModeSheet,
                  ),
                  _buildWiaActionBtn(
                    color: const Color(0xff10b981), // Emerald Battery
                    icon: Icons.battery_charging_full,
                    onTap: () {
                      showDialog(
                        context: context,
                        builder: (ctx) => AlertDialog(
                          backgroundColor: const Color(0xff1e293b),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                          title: Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.all(6),
                                decoration: BoxDecoration(color: const Color(0xff10b981).withOpacity(0.2), shape: BoxShape.circle),
                                child: const Icon(Icons.info_outline, color: Color(0xff10b981), size: 20),
                              ),
                              const SizedBox(width: 10),
                              const Text('System Diagnostics', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16)),
                            ],
                          ),
                          content: Column(
                            mainAxisSize: MainAxisSize.min,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '🔋 Battery Level: $_batteryLevel%\n📍 GPS Accuracy: ${_accuracy.toStringAsFixed(1)} m\n⏱ Interval: ${_interval}s\n☁️ Pending Sync: $_pendingPts points\n\n📱 App: GalOya Track360\n🏷 Version: v$kAppVersion (Build $kAppBuildNumber)\n👨‍💻 $kCreatedBy\n🏢 $kOrganization',
                                style: const TextStyle(color: Colors.white70, fontSize: 13, height: 1.45),
                              ),
                              const SizedBox(height: 14),
                              SizedBox(
                                width: double.infinity,
                                child: ElevatedButton.icon(
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xff8b5cf6),
                                    foregroundColor: Colors.white,
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                    padding: const EdgeInsets.symmetric(vertical: 10),
                                  ),
                                  icon: const Icon(Icons.face, size: 16),
                                  label: const Text('Enroll / Update Master Face ID', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12.5)),
                                  onPressed: () {
                                    Navigator.pop(ctx);
                                    Navigator.of(context).push(
                                      MaterialPageRoute(builder: (_) => const FaceEnrollmentScreen()),
                                    );
                                  },
                                ),
                              ),
                              const SizedBox(height: 8),
                              SizedBox(
                                width: double.infinity,
                                child: ElevatedButton.icon(
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xff0284c7),
                                    foregroundColor: Colors.white,
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                    padding: const EdgeInsets.symmetric(vertical: 10),
                                  ),
                                  icon: const Icon(Icons.system_update, size: 16),
                                  label: const Text('Check for Updates', style: TextStyle(fontWeight: FontWeight.bold)),
                                  onPressed: () {
                                    Navigator.pop(ctx);
                                    AppUpdater.checkForUpdates(context);
                                  },
                                ),
                              ),
                            ],
                          ),
                          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Close', style: TextStyle(color: Colors.white54)))],
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),

            // 6. BOTTOM TELEMATICS PANEL
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: Container(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                  boxShadow: [
                    BoxShadow(color: Colors.black26, blurRadius: 15, offset: Offset(0, -4)),
                  ],
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // Drag Handle
                    Container(
                      width: 36,
                      height: 4,
                      decoration: BoxDecoration(color: const Color(0xffcbd5e1), borderRadius: BorderRadius.circular(2)),
                    ),
                    const SizedBox(height: 10),

                    // 24/7 Background Daemon Warning Card (Redmi / Samsung)
                    if (!_isAlwaysLocation || !_isBatteryIgnored) ...[
                      GestureDetector(
                        onTap: _showBackgroundDaemonHelpDialog,
                        child: Container(
                          margin: const EdgeInsets.only(bottom: 10),
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          decoration: BoxDecoration(
                            color: const Color(0xfffef2f2),
                            border: Border.all(color: const Color(0xffef4444)),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Row(
                            children: [
                              Icon(Icons.warning_amber_rounded, color: Color(0xffdc2626), size: 20),
                              SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  'Redmi / Samsung: App close කරත් 24/7 දිවීමට Settings සකසන්න (Tap Here)',
                                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xff991b1b)),
                                ),
                              ),
                              Icon(Icons.chevron_right, color: Color(0xffdc2626), size: 18),
                            ],
                          ),
                        ),
                      ),
                    ],

                    // Tracking Toggle Row
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Text(
                              'Tracking',
                              style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: Color(0xff0f172a)),
                            ),
                            if (_pendingPts > 0) ...[
                              const SizedBox(width: 8),
                              GestureDetector(
                                onTap: () async {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(content: Text('Syncing offline data to server...'), duration: Duration(seconds: 2)),
                                  );
                                  await Sync.run();
                                  await _refreshStatus();
                                },
                                child: Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(
                                    color: const Color(0xfffef3c7),
                                    border: Border.all(color: const Color(0xfff59e0b)),
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      const Icon(Icons.cloud_queue, size: 13, color: Color(0xffb45309)),
                                      const SizedBox(width: 4),
                                      Text(
                                        '$_pendingPts offline (Sync)',
                                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xffb45309)),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ),
                        Switch(
                          value: _tracking,
                          activeColor: const Color(0xff22c55e),
                          onChanged: _toggleTracking,
                        ),
                      ],
                    ),

                    const Divider(height: 16),

                    // Unit info & real-time stats
                    Builder(
                      builder: (context) {
                        final isStaff = _unitType == 'staff' || _code.isEmpty || _code == '49-1665';
                        final iconEmoji = isStaff ? '👤' : '🚜';
                        final displayName = isStaff
                            ? (_name.toLowerCase().contains('officer')
                                ? _name
                                : '$_name · ${_designation.isNotEmpty ? _designation : 'Field Officer'}')
                            : (_code.isNotEmpty ? '$_code · $_name' : _name);

                        return Row(
                          children: [
                            Text(iconEmoji, style: const TextStyle(fontSize: 20)),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    displayName,
                                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Color(0xff0f172a)),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  Text(
                                    '📍 Accuracy ${_accuracy.toStringAsFixed(0)} m · Speed ${(_speed * 3.6).toStringAsFixed(1)} km/h',
                                    style: const TextStyle(fontSize: 11, color: Color(0xff64748b)),
                                  ),
                                ],
                              ),
                            ),
                            // Live Status pill
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                              decoration: BoxDecoration(
                                color: _tracking ? const Color(0xffdcfce7) : const Color(0xfff1f5f9),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Container(
                                    width: 8,
                                    height: 8,
                                    decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      color: _tracking ? const Color(0xff22c55e) : const Color(0xff94a3b8),
                                    ),
                                  ),
                                  const SizedBox(width: 5),
                                  Text(
                                    _tracking ? 'Live ${_interval}s' : 'Off Duty',
                                    style: TextStyle(
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold,
                                      color: _tracking ? const Color(0xff15803d) : const Color(0xff64748b),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        );
                      },
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildCircleMapBtn({required IconData icon, Color? iconColor, required String tooltip, required VoidCallback onTap}) {
    return Container(
      width: 42,
      height: 42,
      decoration: const BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        boxShadow: [
          BoxShadow(color: Colors.black26, blurRadius: 8, offset: Offset(0, 3)),
        ],
      ),
      child: IconButton(
        icon: Icon(icon, size: 20, color: iconColor ?? const Color(0xff1e293b)),
        tooltip: tooltip,
        onPressed: onTap,
      ),
    );
  }

  Widget _buildCompassMapBtn() {
    return Container(
      width: 42,
      height: 42,
      decoration: BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        border: _isHeadingFollowMode
            ? Border.all(color: const Color(0xff2563eb), width: 2.2)
            : null,
        boxShadow: const [
          BoxShadow(color: Colors.black26, blurRadius: 8, offset: Offset(0, 3)),
        ],
      ),
      child: IconButton(
        tooltip: _isHeadingFollowMode
            ? 'Compass: Following Direction (Tap for North-Up)'
            : 'Compass (Tap to align North / follow direction)',
        padding: EdgeInsets.zero,
        onPressed: _onCompassTap,
        icon: Transform.rotate(
          angle: (-_mapRotation * (pi / 180.0)),
          child: SizedBox(
            width: 24,
            height: 24,
            child: Stack(
              alignment: Alignment.center,
              children: [
                Positioned(
                  top: 1,
                  child: CustomPaint(
                    size: const Size(10, 11),
                    painter: _CompassNeedlePainter(isNorth: true),
                  ),
                ),
                Positioned(
                  bottom: 1,
                  child: CustomPaint(
                    size: const Size(10, 11),
                    painter: _CompassNeedlePainter(isNorth: false),
                  ),
                ),
                Container(
                  width: 4,
                  height: 4,
                  decoration: const BoxDecoration(
                    color: Color(0xff0f172a),
                    shape: BoxShape.circle,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildWiaActionBtn({
    required Color color,
    required IconData icon,
    required VoidCallback onTap,
    VoidCallback? onLongPress,
  }) {
    return GestureDetector(
      onTap: onTap,
      onLongPress: onLongPress,
      child: Container(
        width: 46,
        height: 46,
        decoration: BoxDecoration(
          color: color,
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(color: color.withOpacity(0.4), blurRadius: 10, offset: const Offset(0, 4)),
          ],
        ),
        child: Icon(icon, color: Colors.white, size: 22),
      ),
    );
  }
}

/// Detailed Bottom Sheet showing Plot, Registered Farmer, Appointed Nominee, Permissions & Standing Crops
class _PlotDetailsSheet extends StatefulWidget {
  final Map<String, dynamic> plot;
  final VoidCallback onNavigate;
  final VoidCallback onShowMap;
  final VoidCallback onIssueCdn;

  const _PlotDetailsSheet({
    required this.plot,
    required this.onNavigate,
    required this.onShowMap,
    required this.onIssueCdn,
  });

  @override
  State<_PlotDetailsSheet> createState() => _PlotDetailsSheetState();
}

class _PlotDetailsSheetState extends State<_PlotDetailsSheet> {
  late Map<String, dynamic> _p;
  List<Map<String, dynamic>> _crops = [];
  bool _loadingCrops = true;

  @override
  void initState() {
    super.initState();
    _p = Map<String, dynamic>.from(widget.plot);
    _fetchPlotDetailsAndCrops();
  }

  String _cleanPhone(String? raw) {
    if (raw == null) return '';
    var p = raw.trim().replaceAll(RegExp(r'\s+'), '');
    if (p.isNotEmpty && !p.startsWith('0') && p.length == 9) {
      return '0$p';
    }
    return p;
  }

  Future<void> _fetchPlotDetailsAndCrops() async {
    final code = (_p['plot_manual_code'] ?? _p['name'] ?? _p['code'] ?? _p['plot_no'] ?? '').toString().trim();
    if (code.isEmpty) {
      if (mounted) setState(() => _loadingCrops = false);
      return;
    }

    // 1. Instant local SQLite lookup
    try {
      final localMaster = await LocalDb.getMasterPlotByCode(code);
      if (localMaster != null && mounted) {
        setState(() {
          _p.addAll(localMaster);
        });
      }
      final localCrops = await LocalDb.getActiveCrops(code);
      if (localCrops.isNotEmpty && mounted) {
        setState(() {
          _crops = localCrops;
          _loadingCrops = false;
        });
      }
    } catch (_) {}

    // 2. Cloud lookup (returns both active crops AND complete plot/farmer/nominee profile)
    try {
      final res = await Api.get('get_plot_crops', params: {
        'plot_no': code,
        'key': 'galoya2026',
      }).timeout(const Duration(milliseconds: 3000));

      if (res['ok'] == true && mounted) {
        if (res['plot'] is Map) {
          final serverPlot = (res['plot'] as Map).cast<String, dynamic>();
          setState(() {
            _p.addAll(serverPlot);
          });
          await LocalDb.saveMasterPlots([serverPlot]);
        }
        if (res['crops'] is List) {
          final serverCrops = (res['crops'] as List).cast<Map<String, dynamic>>();
          setState(() {
            _crops = serverCrops;
            _loadingCrops = false;
          });
          await LocalDb.saveCrops(serverCrops);
        } else {
          setState(() => _loadingCrops = false);
        }
      } else {
        if (mounted) setState(() => _loadingCrops = false);
      }
    } catch (_) {
      if (mounted) setState(() => _loadingCrops = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    final code = (p['plot_manual_code'] ?? p['name'] ?? p['code'] ?? 'Unknown Plot').toString();
    final sector = (p['sector_no'] ?? '').toString();
    final landArea = p['land_area'] != null ? '${p['land_area']} Ha' : '';
    final farmerName = (p['farmer_name'] ?? '').toString();
    final farmerPhone = _cleanPhone(p['farmer_phone']?.toString());
    final farmerNic = (p['farmer_nic'] ?? '').toString();
    final farmerNo = (p['farmer_no'] ?? '').toString();

    final nomineeName = (p['nominee_name'] ?? '').toString();
    final nomineePhone = _cleanPhone(p['nominee_phone']?.toString());
    final nomineeNic = (p['nominee_nic'] ?? '').toString();
    final bankCode = (p['bank_code'] ?? '').toString();
    final bankAcc = (p['bank_accno'] ?? '').toString();
    final branchCode = (p['branch_code'] ?? '').toString();

    final payPerm = (p['payment_permission'] == 1 || p['payment_permission'] == true);
    final debitPerm = (p['debit_permission'] == 1 || p['debit_permission'] == true);
    final isAgronomy = p['block_id'] != null;

    return Container(
      height: MediaQuery.of(context).size.height * 0.85,
      decoration: const BoxDecoration(
        color: Color(0xff0f172a),
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: Color(0xff334155), width: 1.5)),
      ),
      child: Column(
        children: [
          Container(
            margin: const EdgeInsets.only(top: 10, bottom: 8),
            width: 44,
            height: 4,
            decoration: BoxDecoration(color: const Color(0xff475569), borderRadius: BorderRadius.circular(2)),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: const Color(0xff059669).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.terrain, color: Color(0xff34d399), size: 22),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(code, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                      Row(
                        children: [
                          if (sector.isNotEmpty) ...[
                            Text('Sector: $sector', style: const TextStyle(color: Color(0xff94a3b8), fontSize: 12)),
                            const SizedBox(width: 8),
                          ],
                          if (landArea.isNotEmpty) ...[
                            Text('• $landArea', style: const TextStyle(color: Color(0xff34d399), fontSize: 12, fontWeight: FontWeight.w600)),
                            const SizedBox(width: 8),
                          ],
                          Text(isAgronomy ? '• Agronomy' : '• Plantation', style: const TextStyle(color: Color(0xff38bdf8), fontSize: 12)),
                        ],
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white70),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          const Divider(height: 1, color: Color(0xff334155)),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // 1. REGISTERED FARMER SECTION
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xff334155)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.person, color: Color(0xff38bdf8), size: 18),
                          const SizedBox(width: 8),
                          const Text('REGISTERED FARMER (ගොවියා)', style: TextStyle(color: Color(0xff38bdf8), fontWeight: FontWeight.bold, fontSize: 12)),
                          const Spacer(),
                          if (farmerNo.isNotEmpty)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                              decoration: BoxDecoration(color: const Color(0xff334155), borderRadius: BorderRadius.circular(6)),
                              child: Text(farmerNo, style: const TextStyle(color: Colors.white70, fontSize: 10)),
                            ),
                        ],
                      ),
                      const SizedBox(height: 10),
                      Text(
                        farmerName.isNotEmpty ? farmerName : 'No Farmer Name recorded',
                        style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold),
                      ),
                      if (farmerNic.isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text('NIC: $farmerNic', style: const TextStyle(color: Color(0xff94a3b8), fontSize: 12)),
                      ],
                      const SizedBox(height: 10),
                      if (farmerPhone.isNotEmpty)
                        SizedBox(
                          width: double.infinity,
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xff059669),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 8),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                            icon: const Icon(Icons.phone, size: 16),
                            label: Text('CALL FARMER ($farmerPhone)', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                            onPressed: () => launchUrl(Uri.parse('tel:$farmerPhone')),
                          ),
                        )
                      else
                        const Text('No contact number available', style: TextStyle(color: Colors.white38, fontSize: 12)),
                    ],
                  ),
                ),
                const SizedBox(height: 14),

                // 2. APPOINTED NOMINEE SECTION
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: nomineeName.isNotEmpty ? const Color(0xff8b5cf6).withOpacity(0.5) : const Color(0xff334155)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.people_alt, color: Color(0xffa78bfa), size: 18),
                          const SizedBox(width: 8),
                          const Text('APPOINTED NOMINEE (නොමිනී)', style: TextStyle(color: Color(0xffa78bfa), fontWeight: FontWeight.bold, fontSize: 12)),
                        ],
                      ),
                      const SizedBox(height: 10),
                      if (nomineeName.isNotEmpty) ...[
                        Text(nomineeName, style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold)),
                        if (nomineeNic.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text('NIC: $nomineeNic', style: const TextStyle(color: Color(0xff94a3b8), fontSize: 12)),
                        ],
                        if (bankAcc.isNotEmpty || bankCode.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text('Bank: $bankCode $branchCode (Acc: $bankAcc)', style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 12)),
                        ],
                        const SizedBox(height: 10),
                        if (nomineePhone.isNotEmpty)
                          SizedBox(
                            width: double.infinity,
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xff7c3aed),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(vertical: 8),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              ),
                              icon: const Icon(Icons.phone, size: 16),
                              label: Text('CALL NOMINEE ($nomineePhone)', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                              onPressed: () => launchUrl(Uri.parse('tel:$nomineePhone')),
                            ),
                          )
                        else
                          const Text('No nominee contact number recorded', style: TextStyle(color: Colors.white38, fontSize: 12)),
                      ] else ...[
                        const Text('No Nominee Appointed for this plot (තවමත් නොමිනී පවරා නැත)', style: TextStyle(color: Colors.white54, fontSize: 13, fontStyle: FontStyle.italic)),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 14),

                // 3. PERMISSIONS BADGES
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xff334155)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('PERMISSIONS / බලයලත් ක්‍රියාමාර්ග', style: TextStyle(color: Color(0xff94a3b8), fontWeight: FontWeight.bold, fontSize: 11)),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 10),
                              decoration: BoxDecoration(
                                color: payPerm ? const Color(0xff065f46).withOpacity(0.5) : const Color(0xff991b1b).withOpacity(0.4),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: payPerm ? const Color(0xff10b981) : const Color(0xffef4444)),
                              ),
                              child: Row(
                                children: [
                                  Icon(payPerm ? Icons.check_circle : Icons.cancel, color: payPerm ? const Color(0xff34d399) : const Color(0xfff87171), size: 16),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text('Payment Sign', style: TextStyle(color: Colors.white70, fontSize: 10)),
                                        Text(payPerm ? 'ALLOWED' : 'DENIED', style: TextStyle(color: payPerm ? const Color(0xff34d399) : const Color(0xfff87171), fontWeight: FontWeight.bold, fontSize: 11)),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Container(
                              padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 10),
                              decoration: BoxDecoration(
                                color: debitPerm ? const Color(0xff065f46).withOpacity(0.5) : const Color(0xff991b1b).withOpacity(0.4),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(color: debitPerm ? const Color(0xff10b981) : const Color(0xffef4444)),
                              ),
                              child: Row(
                                children: [
                                  Icon(debitPerm ? Icons.check_circle : Icons.cancel, color: debitPerm ? const Color(0xff34d399) : const Color(0xfff87171), size: 16),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text('Debit Sign', style: TextStyle(color: Colors.white70, fontSize: 10)),
                                        Text(debitPerm ? 'ALLOWED' : 'DENIED', style: TextStyle(color: debitPerm ? const Color(0xff34d399) : const Color(0xfff87171), fontWeight: FontWeight.bold, fontSize: 11)),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),

                // 4. STANDING ACTIVE CROPS (Status = 1)
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xff334155)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.eco, color: Color(0xff10b981), size: 18),
                          const SizedBox(width: 8),
                          const Text('ACTIVE STANDING CROPS (සක්‍රීය බෝග)', style: TextStyle(color: Color(0xff10b981), fontWeight: FontWeight.bold, fontSize: 12)),
                          const Spacer(),
                          if (_loadingCrops)
                            const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xff10b981))),
                        ],
                      ),
                      const SizedBox(height: 10),
                      if (!_loadingCrops && _crops.isEmpty)
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(color: const Color(0xff7f1d1d).withOpacity(0.4), borderRadius: BorderRadius.circular(8), border: Border.all(color: const Color(0xffef4444))),
                          child: const Row(
                            children: [
                              Icon(Icons.warning, color: Color(0xfff87171), size: 18),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  'මෙම ඉඩමේ දැනට අස්වනු නෙළීමට වලංගු බෝගයක් වාර්තා වී නොමැත. බෝගයක් නැති ඉඩම් සඳහා CDN ලිවීම තහනම් කර ඇත.',
                                  style: TextStyle(color: Color(0xfffca5a5), fontSize: 11),
                                ),
                              ),
                            ],
                          ),
                        )
                      else if (_crops.isNotEmpty)
                        Column(
                          children: _crops.map((c) {
                            final cycle = (c['crop_cycle'] ?? '').toString();
                            final ext = c['crop_extent'] != null ? '${c['crop_extent']} Ha' : '';
                            final varName = (c['variety'] ?? '').toString();
                            final age = (c['age_month'] ?? '').toString();
                            return Container(
                              margin: const EdgeInsets.only(bottom: 8),
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                              decoration: BoxDecoration(
                                color: const Color(0xff0f172a),
                                borderRadius: BorderRadius.circular(10),
                                border: Border.all(color: const Color(0xff059669)),
                              ),
                              child: Row(
                                children: [
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                    decoration: BoxDecoration(color: const Color(0xff059669), borderRadius: BorderRadius.circular(6)),
                                    child: Text(cycle, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12)),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('Variety: ${varName.isNotEmpty ? varName : "Standard"} • $ext', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12)),
                                        if (age.isNotEmpty)
                                          Text('Age: $age Months', style: const TextStyle(color: Color(0xff94a3b8), fontSize: 10)),
                                      ],
                                    ),
                                  ),
                                  const Icon(Icons.check_circle, color: Color(0xff34d399), size: 18),
                                ],
                              ),
                            );
                          }).toList(),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // 5. ACTION BUTTONS
                Row(
                  children: [
                    Expanded(
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff0284c7),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        icon: const Icon(Icons.map, size: 18),
                        label: const Text('Show Map', style: TextStyle(fontWeight: FontWeight.bold)),
                        onPressed: () {
                          Navigator.pop(context);
                          widget.onShowMap();
                        },
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff059669),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        icon: const Icon(Icons.navigation, size: 18),
                        label: const Text('Navigate', style: TextStyle(fontWeight: FontWeight.bold)),
                        onPressed: () {
                          Navigator.pop(context);
                          widget.onNavigate();
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xfff59e0b),
                      foregroundColor: Colors.black,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    icon: const Icon(Icons.receipt_long, size: 18),
                    label: const Text('ISSUE CANE DELIVERY NOTE (CDN)', style: TextStyle(fontWeight: FontWeight.bold)),
                    onPressed: () {
                      Navigator.pop(context);
                      widget.onIssueCdn();
                    },
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}


/// Modal bottom sheet for searching plantation plots and launching turn-by-turn navigation
class _PlotNavigatorModalSheet extends StatefulWidget {
  final Position? currentPosition;
  final ValueChanged<Map<String, dynamic>> onSelectPlot;
  final void Function(double lat, double lng, String name) onGoogleNavigate;
  final String Function(double meters) formatDistance;
  final ValueChanged<Map<String, dynamic>>? onIssueCdn;

  const _PlotNavigatorModalSheet({
    required this.currentPosition,
    required this.onSelectPlot,
    required this.onGoogleNavigate,
    required this.formatDistance,
    this.onIssueCdn,
  });

  @override
  State<_PlotNavigatorModalSheet> createState() => _PlotNavigatorModalSheetState();
}

class _PlotNavigatorModalSheetState extends State<_PlotNavigatorModalSheet> {
  final TextEditingController _searchCtrl = TextEditingController();
  List<Map<String, dynamic>> _results = [];
  bool _loading = false;
  String _modeLabel = 'Nearest Plots Around You';

  @override
  void initState() {
    super.initState();
    _loadInitialPlots();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadInitialPlots() async {
    setState(() => _loading = true);
    try {
      if (widget.currentPosition != null) {
        final lat = widget.currentPosition!.latitude;
        final lng = widget.currentPosition!.longitude;
        // 1. Immediately query fast local SQLite cache (< 5ms)
        List<Map<String, dynamic>> list = await LocalDb.getNearbyGeofences(lat, lng, limit: 15);
        final enriched = <Map<String, dynamic>>[];
        for (final item in list) {
          final m = Map<String, dynamic>.from(item);
          final code = (m['name'] ?? m['code'] ?? '').toString().trim();
          final master = await LocalDb.getMasterPlotByCode(code);
          if (master != null) {
            m['farmer_name'] = master['farmer_name'];
            m['farmer_phone'] = master['farmer_phone'];
            m['farmer_nic'] = master['farmer_nic'];
            m['farmer_no'] = master['farmer_no'];
            m['sector_no'] = master['sector_no'];
            m['land_area'] = master['land_area'];
            m['plot_no'] = master['plot_no'];
            m['nominee_name'] = master['nominee_name'];
            m['nominee_phone'] = master['nominee_phone'];
            m['nominee_nic'] = master['nominee_nic'];
            m['bank_code'] = master['bank_code'];
            m['branch_code'] = master['branch_code'];
            m['bank_accno'] = master['bank_accno'];
            m['payment_permission'] = master['payment_permission'];
            m['debit_permission'] = master['debit_permission'];
          }
          enriched.add(m);
        }
        if (mounted && enriched.isNotEmpty) {
          final firstDist = ((enriched.first['distance'] as num?)?.round() ?? 0);
          setState(() {
            _results = enriched;
            _modeLabel = firstDist > 2500
                ? 'Plantation Plots (Nearest: ${(firstDist / 1000).toStringAsFixed(1)}km)'
                : 'Nearest Plots Around You (${enriched.length})';
            _loading = false;
          });
        }

        // 2. Background check online if local list was empty
        if (list.isEmpty) {
          try {
            final online = await Sync.refreshNearbyGeofences(lat, lng)
                .timeout(const Duration(milliseconds: 2000), onTimeout: () => null);
            if (online != null && online.isNotEmpty && mounted) {
              final firstDist = ((online.first['distance'] as num?)?.round() ?? 0);
              setState(() {
                _results = online.take(15).toList();
                _modeLabel = firstDist > 2500
                    ? 'Plantation Plots (Nearest: ${(firstDist / 1000).toStringAsFixed(1)}km)'
                    : 'Nearest Plots Around You (${_results.length})';
                _loading = false;
              });
            }
          } catch (_) {}
        }
      } else {
        final list = await LocalDb.searchGeofences('', limit: 15);
        if (mounted) {
          setState(() {
            _results = list;
            _modeLabel = 'Plantation Plots (${list.length})';
            _loading = false;
          });
        }
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _performSearch(String query) async {
    final q = query.trim();
    if (q.isEmpty) {
      _loadInitialPlots();
      return;
    }
    setState(() => _loading = true);
    try {
      final gfList = await LocalDb.searchGeofences(
        q,
        userLat: widget.currentPosition?.latitude,
        userLng: widget.currentPosition?.longitude,
        limit: 25,
      );

      final masterList = await LocalDb.searchMasterPlots(q, limit: 30);

      final combined = <Map<String, dynamic>>[];
      final addedCodes = <String>{};

      for (final g in gfList) {
        final name = (g['name'] ?? '').toString().trim();
        final upper = name.toUpperCase();
        addedCodes.add(upper);
        final item = Map<String, dynamic>.from(g);
        final masterMatch = masterList.firstWhere(
          (m) => (m['plot_manual_code'] ?? '').toString().trim().toUpperCase() == upper,
          orElse: () => {},
        );
        if (masterMatch.isNotEmpty) {
          item['farmer_name'] = masterMatch['farmer_name'];
          item['farmer_phone'] = masterMatch['farmer_phone'];
          item['farmer_nic'] = masterMatch['farmer_nic'];
          item['farmer_no'] = masterMatch['farmer_no'];
          item['nominee_name'] = masterMatch['nominee_name'];
          item['nominee_phone'] = masterMatch['nominee_phone'];
          item['nominee_nic'] = masterMatch['nominee_nic'];
          item['bank_code'] = masterMatch['bank_code'];
          item['branch_code'] = masterMatch['branch_code'];
          item['bank_accno'] = masterMatch['bank_accno'];
          item['payment_permission'] = masterMatch['payment_permission'];
          item['debit_permission'] = masterMatch['debit_permission'];
          item['land_area'] = masterMatch['land_area'];
          item['sector_no'] = masterMatch['sector_no'];
          item['plot_no'] = masterMatch['plot_no'];
        }
        item['has_geofence'] = true;
        item['is_master_only'] = false;
        combined.add(item);
      }

      for (final m in masterList) {
        final code = (m['plot_manual_code'] ?? '').toString().trim();
        final upper = code.toUpperCase();
        if (!addedCodes.contains(upper)) {
          addedCodes.add(upper);
          combined.add({
            'id': 0,
            'name': code,
            'code': code,
            'lat': 0.0,
            'lng': 0.0,
            'distance': 0.0,
            'has_geofence': false,
            'is_master_only': true,
            'plot_no': m['plot_no'],
            'farmer_name': m['farmer_name'],
            'farmer_phone': m['farmer_phone'],
            'farmer_nic': m['farmer_nic'],
            'farmer_no': m['farmer_no'],
            'nominee_name': m['nominee_name'],
            'nominee_phone': m['nominee_phone'],
            'nominee_nic': m['nominee_nic'],
            'bank_code': m['bank_code'],
            'branch_code': m['branch_code'],
            'bank_accno': m['bank_accno'],
            'payment_permission': m['payment_permission'],
            'debit_permission': m['debit_permission'],
            'land_area': m['land_area'],
            'sector_no': m['sector_no'],
          });
        }
      }

      try {
        final res = await Api.get('search_plots_master', params: {'query': q, 'key': 'galoya2026'}).timeout(const Duration(milliseconds: 2500));
        if (res['ok'] == true && res['plots'] is List) {
          final serverPlots = res['plots'] as List;
          if (serverPlots.isNotEmpty) {
            await LocalDb.saveMasterPlots(serverPlots);
          }
          for (final p in serverPlots) {
            if (p is Map) {
              final code = (p['plot_manual_code'] ?? p['plot_no'] ?? '').toString().trim();
              final upper = code.toUpperCase();
              if (!addedCodes.contains(upper)) {
                addedCodes.add(upper);
                final hasGf = p['has_geofence'] == 1;
                combined.add({
                  'id': p['geofence_id'] ?? 0,
                  'name': code,
                  'code': code,
                  'lat': (p['gf_lat'] is num) ? (p['gf_lat'] as num).toDouble() : 0.0,
                  'lng': (p['gf_lng'] is num) ? (p['gf_lng'] as num).toDouble() : 0.0,
                  'distance': 0.0,
                  'has_geofence': hasGf,
                  'is_master_only': !hasGf,
                  'plot_no': p['plot_no'],
                  'farmer_name': p['farmer_name'],
                  'farmer_phone': p['farmer_phone'],
                  'farmer_nic': p['farmer_nic'],
                  'farmer_no': p['farmer_no'],
                  'nominee_name': p['nominee_name'],
                  'nominee_phone': p['nominee_phone'],
                  'nominee_nic': p['nominee_nic'],
                  'bank_code': p['bank_code'],
                  'branch_code': p['branch_code'],
                  'bank_accno': p['bank_accno'],
                  'payment_permission': p['payment_permission'],
                  'debit_permission': p['debit_permission'],
                  'land_area': p['land_area'],
                  'sector_no': p['sector_no'],
                });
              }
            }
          }
        }
      } catch (_) {}

      if (mounted) {
        setState(() {
          _results = combined;
          _modeLabel = 'Search Results (${combined.length})';
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;

    return Container(
      height: MediaQuery.of(context).size.height * 0.82,
      padding: EdgeInsets.only(bottom: bottomInset),
      decoration: const BoxDecoration(
        color: Color(0xff0f172a),
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: Color(0xff334155), width: 1.5)),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            margin: const EdgeInsets.only(top: 10, bottom: 8),
            width: 44,
            height: 4,
            decoration: BoxDecoration(color: const Color(0xff475569), borderRadius: BorderRadius.circular(2)),
          ),
          // Title Bar
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 6),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(7),
                  decoration: BoxDecoration(
                    color: const Color(0xff0284c7).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.explore, color: Color(0xff38bdf8), size: 20),
                ),
                const SizedBox(width: 10),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Plot Navigator & Directions',
                        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      Text(
                        'Find geofence, master record or navigate',
                        style: TextStyle(color: Color(0xff94a3b8), fontSize: 11),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white60, size: 20),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          // Search Input
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: TextField(
              controller: _searchCtrl,
              autofocus: false,
              style: const TextStyle(color: Colors.white, fontSize: 14),
              onChanged: (val) => _performSearch(val),
              decoration: InputDecoration(
                hintText: 'Search plot by name, code or farmer (e.g. V/07/16)...',
                hintStyle: const TextStyle(color: Colors.white38, fontSize: 13),
                prefixIcon: const Icon(Icons.search, color: Color(0xff38bdf8), size: 20),
                suffixIcon: _searchCtrl.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear, color: Colors.white54, size: 18),
                        onPressed: () {
                          _searchCtrl.clear();
                          _loadInitialPlots();
                        },
                      )
                    : null,
                filled: true,
                fillColor: const Color(0xff1e293b),
                contentPadding: const EdgeInsets.symmetric(vertical: 12),
                enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
                focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff0284c7), width: 1.5)),
              ),
            ),
          ),
          // Subheader
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 4),
            child: Row(
              children: [
                Text(_modeLabel, style: const TextStyle(color: Color(0xff94a3b8), fontSize: 12, fontWeight: FontWeight.w600)),
                const Spacer(),
                if (_loading)
                  const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xff38bdf8))),
              ],
            ),
          ),
          const SizedBox(height: 4),
          // Results list
          Expanded(
            child: _results.isEmpty && !_loading
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(32),
                      child: Text(
                        'No matching plots found.\nSearch by plot code or farmer name.',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: Colors.white38, fontSize: 13),
                      ),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    itemCount: _results.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (ctx, i) {
                      final p = _results[i];
                      final name = p['name'] ?? 'Unnamed Plot';
                      final dist = (p['distance'] as num?)?.toDouble() ?? 0.0;
                      final lat = (p['lat'] as num?)?.toDouble() ?? 0.0;
                      final lng = (p['lng'] as num?)?.toDouble() ?? 0.0;
                      final isMasterOnly = p['is_master_only'] == true;
                      final farmerName = (p['farmer_name'] ?? '').toString();
                      final farmerPhone = (p['farmer_phone'] ?? '').toString();
                      final landArea = (p['land_area'] is num) ? (p['land_area'] as num).toDouble() : 0.0;
                      final sector = (p['sector_no'] ?? '').toString();

                      return Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          color: const Color(0xff1e293b),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: isMasterOnly ? const Color(0xfff59e0b).withOpacity(0.5) : const Color(0xff334155)),
                        ),
                        child: Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: isMasterOnly
                                    ? const Color(0xfff59e0b).withOpacity(0.18)
                                    : const Color(0xff059669).withOpacity(0.18),
                                shape: BoxShape.circle,
                              ),
                              child: Icon(
                                isMasterOnly ? Icons.assignment_outlined : Icons.terrain,
                                color: isMasterOnly ? const Color(0xfffbbf24) : const Color(0xff34d399),
                                size: 20,
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    name,
                                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  const SizedBox(height: 3),
                                  if (farmerName.isNotEmpty || landArea > 0)
                                    Text(
                                      '${farmerName.isNotEmpty ? "👨🌾 $farmerName" : ""}${landArea > 0 ? " • 📐 ${landArea.toStringAsFixed(2)} Ha" : ""}',
                                      style: const TextStyle(color: Color(0xffe2e8f0), fontSize: 11),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  const SizedBox(height: 2),
                                  Row(
                                    children: [
                                      if (sector.isNotEmpty) ...[
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                                          decoration: BoxDecoration(
                                            color: const Color(0xff334155),
                                            borderRadius: BorderRadius.circular(6),
                                          ),
                                          child: Text('Sec $sector', style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 10, fontWeight: FontWeight.w600)),
                                        ),
                                        const SizedBox(width: 6),
                                      ],
                                      if (dist > 0)
                                        Text(
                                          '🚗 ${widget.formatDistance(dist)}',
                                          style: const TextStyle(color: Color(0xff38bdf8), fontSize: 11, fontWeight: FontWeight.w700),
                                        ),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                            // Action 0: Plot, Farmer & Nominee Profile Sheet
                            IconButton(
                              padding: EdgeInsets.zero,
                              constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                              icon: const Icon(Icons.info_outline, color: Color(0xff38bdf8), size: 20),
                              tooltip: 'Plot & Nominee Profile',
                              onPressed: () {
                                showModalBottomSheet(
                                  context: context,
                                  isScrollControlled: true,
                                  backgroundColor: Colors.transparent,
                                  builder: (_) => _PlotDetailsSheet(
                                    plot: p,
                                    onNavigate: () => widget.onGoogleNavigate(lat, lng, name),
                                    onShowMap: () => widget.onSelectPlot(p),
                                    onIssueCdn: () => widget.onIssueCdn?.call(p),
                                  ),
                                );
                              },
                            ),
                            if (!isMasterOnly) ...[
                              const SizedBox(width: 2),
                              // Action 1: In-App Map
                              IconButton(
                                padding: EdgeInsets.zero,
                                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                                icon: const Icon(Icons.map_outlined, color: Color(0xff38bdf8), size: 19),
                                tooltip: 'Show on Map',
                                onPressed: () => widget.onSelectPlot(p),
                              ),
                              const SizedBox(width: 4),
                              // Action 2: Google Maps Turn-by-Turn
                              ElevatedButton.icon(
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: const Color(0xff059669),
                                  foregroundColor: Colors.white,
                                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 5),
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                  minimumSize: Size.zero,
                                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                ),
                                icon: const Icon(Icons.navigation, size: 12),
                                label: const Text('Go', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                                onPressed: () => widget.onGoogleNavigate(lat, lng, name),
                              ),
                            ],
                            if (isMasterOnly && farmerPhone.isNotEmpty) ...[
                              IconButton(
                                padding: EdgeInsets.zero,
                                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                                icon: const Icon(Icons.call, color: Color(0xff38bdf8), size: 19),
                                tooltip: 'Call Farmer',
                                onPressed: () => launchUrl(Uri.parse('tel:$farmerPhone')),
                              ),
                            ],

                          ],
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

// Cane Delivery Note (CDN) Modal & Tracker extracted to cane_cdn_modal.dart
typedef _CaneCdnModalSheet = CaneCdnModalSheet;

class _FieldPhotosGallerySheet extends StatefulWidget {
  final List<Map<String, dynamic>> capturedPhotos;
  final Function(Map<String, dynamic>) onPhotoTap;

  const _FieldPhotosGallerySheet({
    required this.capturedPhotos,
    required this.onPhotoTap,
  });

  @override
  State<_FieldPhotosGallerySheet> createState() => _FieldPhotosGallerySheetState();
}

class _FieldPhotosGallerySheetState extends State<_FieldPhotosGallerySheet> {
  late DateTime _galleryDate;
  bool _loading = true;
  List<Map<String, dynamic>> _photos = [];

  @override
  void initState() {
    super.initState();
    _galleryDate = DateTime.now();
    _fetchPhotos(_galleryDate);
  }

  Future<void> _fetchPhotos(DateTime date) async {
    setState(() => _loading = true);
    final dStr = "${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}";
    try {
      final res = await Api.get('my_field_photos', params: {'date': dStr});
      if (res['ok'] == true && res['photos'] is List) {
        if (mounted) {
          setState(() {
            _photos = (res['photos'] as List).cast<Map<String, dynamic>>();
            _galleryDate = date;
            _loading = false;
          });
          return;
        }
      }
    } catch (_) {}
    if (mounted) {
      setState(() {
        _galleryDate = date;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final isToday = _galleryDate.year == now.year &&
        _galleryDate.month == now.month &&
        _galleryDate.day == now.day;

    final localTodayPhotos = widget.capturedPhotos.where((p) {
      final t = p['time'] as DateTime?;
      if (t == null) return false;
      return t.year == _galleryDate.year && t.month == _galleryDate.month && t.day == _galleryDate.day;
    }).toList();

    return Container(
      padding: const EdgeInsets.all(16),
      height: MediaQuery.of(context).size.height * 0.75,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('Field Photos Gallery',
                  style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
              IconButton(
                icon: const Icon(Icons.close, color: Colors.white54),
                onPressed: () => Navigator.pop(context),
              ),
            ],
          ),
          const SizedBox(height: 8),

          // Date Filter Selector Bar
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: const Color(0xff1e293b),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xff334155)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                IconButton(
                  icon: const Icon(Icons.chevron_left, color: Colors.white70, size: 22),
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(),
                  onPressed: () {
                    final prev = _galleryDate.subtract(const Duration(days: 1));
                    _fetchPhotos(prev);
                  },
                ),
                InkWell(
                  onTap: () async {
                    final picked = await showDatePicker(
                      context: context,
                      initialDate: _galleryDate,
                      firstDate: DateTime(2025),
                      lastDate: DateTime.now(),
                    );
                    if (picked != null) {
                      _fetchPhotos(picked);
                    }
                  },
                  child: Row(
                    children: [
                      const Icon(Icons.calendar_month, color: Color(0xff38bdf8), size: 18),
                      const SizedBox(width: 8),
                      Text(
                        isToday
                            ? 'Today, ${_galleryDate.day}/${_galleryDate.month}/${_galleryDate.year}'
                            : '${_galleryDate.day}/${_galleryDate.month}/${_galleryDate.year}',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      const SizedBox(width: 4),
                      const Icon(Icons.arrow_drop_down, color: Colors.white54),
                    ],
                  ),
                ),
                IconButton(
                  icon: Icon(Icons.chevron_right, color: isToday ? Colors.white24 : Colors.white70, size: 22),
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(),
                  onPressed: isToday
                      ? null
                      : () {
                          final next = _galleryDate.add(const Duration(days: 1));
                          _fetchPhotos(next);
                        },
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),

          if (_loading)
            const Expanded(
              child: Center(
                child: CircularProgressIndicator(color: Color(0xff38bdf8)),
              ),
            )
          else if (_photos.isEmpty && localTodayPhotos.isEmpty)
            Expanded(
              child: Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.photo_library_outlined, size: 48, color: Colors.white24),
                    const SizedBox(height: 12),
                    const Text('No photos for this date', style: TextStyle(color: Colors.white54)),
                    const SizedBox(height: 4),
                    const Text('Snap field photos or choose another date above',
                        style: TextStyle(color: Colors.white38, fontSize: 12)),
                  ],
                ),
              ),
            )
          else
            Expanded(
              child: ListView(
                children: [
                  // Server synced photos
                  ..._photos.map((item) {
                    final fullUrl = item['full_url'] ?? (item['file_path'] != null ? 'https://track.galoya.lk/uploads/${item['file_path']}' : '');
                    return Card(
                      color: const Color(0xff1e293b),
                      margin: const EdgeInsets.only(bottom: 10),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      child: ListTile(
                        leading: GestureDetector(
                          onTap: () => widget.onPhotoTap(item),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.network(
                              fullUrl,
                              width: 50,
                              height: 50,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => Container(
                                width: 50,
                                height: 50,
                                color: Colors.white12,
                                child: const Icon(Icons.image, color: Colors.white54),
                              ),
                            ),
                          ),
                        ),
                        title: Text(
                          item['activity_type']?.toString().isNotEmpty == true
                              ? item['activity_type'].toString()
                              : (item['note']?.toString().isNotEmpty == true ? item['note'].toString() : 'Field Photo'),
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                        subtitle: Text(
                          '📍 ${item['geofence_name'] ?? 'Field Plot'}${item['note']?.toString().isNotEmpty == true ? ' • ${item['note']}' : ''}\n🕒 ${item['taken_at'] ?? ''}',
                          style: const TextStyle(color: Colors.white60, fontSize: 11),
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.cloud_done, color: Color(0xff38bdf8), size: 18),
                            const SizedBox(width: 4),
                            IconButton(
                              icon: const Icon(Icons.fullscreen, color: Color(0xff38bdf8), size: 24),
                              tooltip: 'Enlarge Photo',
                              padding: EdgeInsets.zero,
                              constraints: const BoxConstraints(),
                              onPressed: () => widget.onPhotoTap(item),
                            ),
                          ],
                        ),
                        onTap: () => widget.onPhotoTap(item),
                      ),
                    );
                  }),
                  // Local pending/recent photos
                  ...localTodayPhotos.map((item) {
                    final f = File(item['file'] as String);
                    final dt = item['time'] as DateTime;
                    return Card(
                      color: const Color(0xff1e293b),
                      margin: const EdgeInsets.only(bottom: 10),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      child: ListTile(
                        leading: GestureDetector(
                          onTap: () => widget.onPhotoTap(item),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: f.existsSync()
                                ? Image.file(f, width: 50, height: 50, fit: BoxFit.cover)
                                : const Icon(Icons.image, color: Colors.white54),
                          ),
                        ),
                        title: Text(
                          item['activity_type']?.toString().isNotEmpty == true
                              ? item['activity_type'].toString()
                              : (item['note']?.toString().isNotEmpty == true ? item['note'].toString() : 'Field Photo'),
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                        subtitle: Text(
                          '📍 ${item['selected_geofence'] ?? item['auto_geofence'] ?? 'Field Plot'}${item['note']?.toString().isNotEmpty == true ? ' • ${item['note']}' : ''}\n🕒 ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}',
                          style: const TextStyle(color: Colors.white60, fontSize: 11),
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.check_circle, color: Color(0xff22c55e), size: 18),
                            const SizedBox(width: 4),
                            IconButton(
                              icon: const Icon(Icons.fullscreen, color: Color(0xff22c55e), size: 24),
                              tooltip: 'Enlarge Photo',
                              padding: EdgeInsets.zero,
                              constraints: const BoxConstraints(),
                              onPressed: () => widget.onPhotoTap(item),
                            ),
                          ],
                        ),
                        onTap: () => widget.onPhotoTap(item),
                      ),
                    );
                  }),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Modal bottom sheet for displaying assigned tasks with Pending vs Completed History separation
class _AssignedTasksModalSheet extends StatefulWidget {
  final List<Map<String, dynamic>> tasks;
  final VoidCallback onRefresh;
  final ValueChanged<Map<String, dynamic>> onOpenTask;
  final void Function(double lat, double lng, String name) onGoogleNavigate;
  final ValueChanged<Map<String, dynamic>> onSelectOnMap;

  const _AssignedTasksModalSheet({
    required this.tasks,
    required this.onRefresh,
    required this.onOpenTask,
    required this.onGoogleNavigate,
    required this.onSelectOnMap,
  });

  @override
  State<_AssignedTasksModalSheet> createState() => _AssignedTasksModalSheetState();
}

class _AssignedTasksModalSheetState extends State<_AssignedTasksModalSheet> {
  int _tabIndex = 0; // 0 = Pending/Active, 1 = Completed History
  DateTime _historyDate = DateTime.now();
  bool _showAllHistory = false;

  @override
  Widget build(BuildContext context) {
    final pendingTasks = widget.tasks
        .where((t) => t['status'] != 'done' && t['status'] != 'cancelled')
        .toList();

    final allCompleted = widget.tasks
        .where((t) => t['status'] == 'done' || t['status'] == 'cancelled')
        .toList();

    final completedTasks = _showAllHistory
        ? allCompleted
        : allCompleted.where((t) {
            final compStr = t['completed_at'] ?? t['created_at'] ?? t['due_date'];
            if (compStr == null) return true;
            try {
              final dt = DateTime.parse(compStr.toString().replaceAll(' ', 'T'));
              return dt.year == _historyDate.year &&
                  dt.month == _historyDate.month &&
                  dt.day == _historyDate.day;
            } catch (_) {
              return true;
            }
          }).toList();

    final now = DateTime.now();
    final isToday = _historyDate.year == now.year &&
        _historyDate.month == now.month &&
        _historyDate.day == now.day;

    return Container(
      height: MediaQuery.of(context).size.height * 0.85,
      decoration: const BoxDecoration(
        color: Color(0xff0f172a),
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: Color(0xff334155), width: 1.5)),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            margin: const EdgeInsets.only(top: 10, bottom: 8),
            width: 44,
            height: 4,
            decoration: BoxDecoration(color: const Color(0xff475569), borderRadius: BorderRadius.circular(2)),
          ),

          // Header
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 6),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(7),
                  decoration: BoxDecoration(
                    color: const Color(0xff2563eb).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.assignment, color: Color(0xff38bdf8), size: 20),
                ),
                const SizedBox(width: 10),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'My Assigned Tasks',
                        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      Text(
                        'Track pending work & review completed history',
                        style: TextStyle(color: Color(0xff94a3b8), fontSize: 11),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.refresh, color: Color(0xff38bdf8), size: 20),
                  tooltip: 'Refresh Tasks',
                  onPressed: widget.onRefresh,
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white60, size: 20),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),

          // Segmented Tabs: [ Pending & Active (N) ]  [ Completed History (N) ]
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Container(
              padding: const EdgeInsets.all(3),
              decoration: BoxDecoration(
                color: const Color(0xff1e293b),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xff334155)),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: GestureDetector(
                      onTap: () => setState(() => _tabIndex = 0),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        decoration: BoxDecoration(
                          color: _tabIndex == 0 ? const Color(0xff2563eb) : Colors.transparent,
                          borderRadius: BorderRadius.circular(9),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.pending_actions, size: 16, color: Colors.white),
                            const SizedBox(width: 6),
                            Text(
                              'Pending & Active (${pendingTasks.length})',
                              style: TextStyle(
                                color: Colors.white,
                                fontWeight: _tabIndex == 0 ? FontWeight.bold : FontWeight.w500,
                                fontSize: 12,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  Expanded(
                    child: GestureDetector(
                      onTap: () => setState(() => _tabIndex = 1),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        decoration: BoxDecoration(
                          color: _tabIndex == 1 ? const Color(0xff059669) : Colors.transparent,
                          borderRadius: BorderRadius.circular(9),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.history, size: 16, color: Colors.white),
                            const SizedBox(width: 6),
                            Text(
                              'Completed (${allCompleted.length})',
                              style: TextStyle(
                                color: Colors.white,
                                fontWeight: _tabIndex == 1 ? FontWeight.bold : FontWeight.w500,
                                fontSize: 12,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // TAB 0: PENDING & ACTIVE
          if (_tabIndex == 0)
            Expanded(
              child: pendingTasks.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(32),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Container(
                              padding: const EdgeInsets.all(16),
                              decoration: BoxDecoration(
                                color: const Color(0xff10b981).withOpacity(0.15),
                                shape: BoxShape.circle,
                              ),
                              child: const Icon(Icons.task_alt_rounded, color: Color(0xff10b981), size: 48),
                            ),
                            const SizedBox(height: 14),
                            const Text(
                              'No Pending Tasks Today! 🎉',
                              style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 6),
                            const Text(
                              'All assigned tasks are completed.\nSwitch to "Completed" to review past finished tasks.',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: Color(0xff94a3b8), fontSize: 12),
                            ),
                          ],
                        ),
                      ),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                      itemCount: pendingTasks.length,
                      itemBuilder: (ctx, i) {
                        final t = pendingTasks[i];
                        final status = (t['status'] ?? 'pending').toString();
                        final isProgress = status == 'in_progress';
                        final lat = (t['lat'] is num) ? (t['lat'] as num).toDouble() : null;
                        final lng = (t['lng'] is num) ? (t['lng'] as num).toDouble() : null;
                        final gfName = t['geofence_name']?.toString() ?? '';
                        final location = t['address']?.toString() ?? t['location_name']?.toString() ?? 'Galoya Plantation';

                        return Card(
                          color: const Color(0xff1e293b),
                          margin: const EdgeInsets.only(bottom: 12),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                            side: BorderSide(
                              color: isProgress ? const Color(0xff3b82f6) : const Color(0xff334155),
                              width: isProgress ? 1.5 : 1.0,
                            ),
                          ),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(16),
                            onTap: () {
                              Navigator.pop(context);
                              widget.onOpenTask(t);
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(14),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      CircleAvatar(
                                        backgroundColor: isProgress ? const Color(0xff2563eb) : const Color(0xfff59e0b).withOpacity(0.2),
                                        child: Icon(
                                          isProgress ? Icons.run_circle_outlined : Icons.assignment,
                                          color: isProgress ? Colors.white : const Color(0xfff59e0b),
                                        ),
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              t['title'] ?? 'Field Task',
                                              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
                                            ),
                                            const SizedBox(height: 3),
                                            if (gfName.isNotEmpty) ...[
                                              Row(
                                                children: [
                                                  Container(
                                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                                                    decoration: BoxDecoration(
                                                      color: const Color(0xff065f46),
                                                      borderRadius: BorderRadius.circular(6),
                                                    ),
                                                    child: Text(
                                                      '🌾 $gfName',
                                                      style: const TextStyle(color: Color(0xff6ee7b7), fontSize: 10.5, fontWeight: FontWeight.w700),
                                                    ),
                                                  ),
                                                ],
                                              ),
                                              const SizedBox(height: 3),
                                            ],
                                            Text(
                                              '📍 $location',
                                              style: const TextStyle(color: Color(0xff94a3b8), fontSize: 11.5),
                                            ),
                                            if (t['due_date'] != null)
                                              Text(
                                                '📅 Due: ${t['due_date']}',
                                                style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 11),
                                              ),
                                          ],
                                        ),
                                      ),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                                        decoration: BoxDecoration(
                                          color: isProgress ? const Color(0xff2563eb).withOpacity(0.2) : const Color(0xfff59e0b).withOpacity(0.2),
                                          borderRadius: BorderRadius.circular(10),
                                        ),
                                        child: Text(
                                          isProgress ? 'In Progress' : 'Pending',
                                          style: TextStyle(
                                            color: isProgress ? const Color(0xff60a5fa) : const Color(0xfffbbf24),
                                            fontSize: 11,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                  // Quick Action: Google Maps Turn-by-Turn Navigation & Show on Map
                                  if (lat != null && lng != null) ...[
                                    const SizedBox(height: 10),
                                    const Divider(color: Color(0xff334155), height: 1),
                                    const SizedBox(height: 8),
                                    Row(
                                      children: [
                                        Expanded(
                                          child: ElevatedButton.icon(
                                            style: ElevatedButton.styleFrom(
                                              backgroundColor: const Color(0xff059669),
                                              foregroundColor: Colors.white,
                                              padding: const EdgeInsets.symmetric(vertical: 8),
                                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                              elevation: 0,
                                            ),
                                            icon: const Icon(Icons.navigation, size: 15),
                                            label: const Text('Google Maps Directions ↗', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold)),
                                            onPressed: () => widget.onGoogleNavigate(lat, lng, t['title'] ?? 'Task Location'),
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        OutlinedButton.icon(
                                          style: OutlinedButton.styleFrom(
                                            foregroundColor: const Color(0xff38bdf8),
                                            side: const BorderSide(color: Color(0xff0284c7)),
                                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                          ),
                                          icon: const Icon(Icons.map_outlined, size: 15),
                                          label: const Text('On Map', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold)),
                                          onPressed: () {
                                            Navigator.pop(context);
                                            widget.onSelectOnMap(t);
                                          },
                                        ),
                                      ],
                                    ),
                                  ],
                                ],
                              ),
                            ),
                          ),
                        );
                      },
                    ),
            ),

          // TAB 1: COMPLETED HISTORY
          if (_tabIndex == 1) ...[
            // Date Filter Selector Bar (Matching Field Photos Gallery!)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                decoration: BoxDecoration(
                  color: const Color(0xff1e293b),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xff334155)),
                ),
                child: Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.chevron_left, color: Color(0xff38bdf8), size: 22),
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                      onPressed: () {
                        setState(() {
                          _showAllHistory = false;
                          _historyDate = _historyDate.subtract(const Duration(days: 1));
                        });
                      },
                    ),
                    Expanded(
                      child: InkWell(
                        onTap: () async {
                          final picked = await showDatePicker(
                            context: context,
                            initialDate: _historyDate,
                            firstDate: DateTime(2025),
                            lastDate: DateTime.now().add(const Duration(days: 1)),
                          );
                          if (picked != null) {
                            setState(() {
                              _showAllHistory = false;
                              _historyDate = picked;
                            });
                          }
                        },
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.calendar_today, color: Color(0xff38bdf8), size: 14),
                            const SizedBox(width: 6),
                            Text(
                              _showAllHistory
                                  ? 'All Completed Tasks'
                                  : (isToday ? 'Today, ${_historyDate.day}/${_historyDate.month}/${_historyDate.year}' : '${_historyDate.day}/${_historyDate.month}/${_historyDate.year}'),
                              style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                            ),
                          ],
                        ),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.chevron_right, color: Color(0xff38bdf8), size: 22),
                      padding: EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                      onPressed: isToday
                          ? null
                          : () {
                              setState(() {
                                _showAllHistory = false;
                                _historyDate = _historyDate.add(const Duration(days: 1));
                              });
                            },
                    ),
                    const SizedBox(width: 6),
                    TextButton(
                      style: TextButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        backgroundColor: _showAllHistory ? const Color(0xff059669) : const Color(0xff334155),
                        minimumSize: const Size(40, 26),
                      ),
                      onPressed: () => setState(() => _showAllHistory = !_showAllHistory),
                      child: Text(
                        _showAllHistory ? 'By Date' : 'View All',
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 6),
            Expanded(
              child: completedTasks.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(32),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.history, color: Colors.white38, size: 44),
                            const SizedBox(height: 12),
                            Text(
                              _showAllHistory ? 'No completed tasks found in history.' : 'No completed tasks on this date.',
                              style: const TextStyle(color: Colors.white54, fontSize: 14),
                            ),
                            if (!_showAllHistory) ...[
                              const SizedBox(height: 10),
                              ElevatedButton(
                                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff334155)),
                                onPressed: () => setState(() => _showAllHistory = true),
                                child: const Text('View All History', style: TextStyle(color: Colors.white, fontSize: 12)),
                              ),
                            ],
                          ],
                        ),
                      ),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                      itemCount: completedTasks.length,
                      itemBuilder: (ctx, i) {
                        final t = completedTasks[i];
                        final isCancelled = t['status'] == 'cancelled';
                        final lat = (t['lat'] is num) ? (t['lat'] as num).toDouble() : null;
                        final lng = (t['lng'] is num) ? (t['lng'] as num).toDouble() : null;

                        return Card(
                          color: const Color(0xff1e293b),
                          margin: const EdgeInsets.only(bottom: 10),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(14),
                            onTap: () {
                              Navigator.pop(context);
                              widget.onOpenTask(t);
                            },
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    backgroundColor: isCancelled ? const Color(0xffef4444).withOpacity(0.2) : const Color(0xff10b981).withOpacity(0.2),
                                    child: Icon(
                                      isCancelled ? Icons.cancel_outlined : Icons.check_circle_outline,
                                      color: isCancelled ? const Color(0xffef4444) : const Color(0xff10b981),
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          t['title'] ?? 'Task',
                                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                                        ),
                                        const SizedBox(height: 2),
                                        if (t['geofence_name'] != null)
                                          Text(
                                            '🌾 ${t['geofence_name']}',
                                            style: const TextStyle(color: Color(0xff34d399), fontSize: 11, fontWeight: FontWeight.w600),
                                          ),
                                        Text(
                                          t['address'] ?? t['location_name'] ?? 'Galoya Plantation',
                                          style: const TextStyle(color: Colors.white60, fontSize: 11),
                                        ),
                                        if (t['completed_at'] != null)
                                          Text(
                                            '✓ Completed: ${t['completed_at']}',
                                            style: const TextStyle(color: Color(0xff94a3b8), fontSize: 10.5),
                                          ),
                                      ],
                                    ),
                                  ),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                        decoration: BoxDecoration(
                                          color: isCancelled ? const Color(0xffef4444).withOpacity(0.2) : const Color(0xff10b981).withOpacity(0.2),
                                          borderRadius: BorderRadius.circular(8),
                                        ),
                                        child: Text(
                                          isCancelled ? 'Cancelled' : 'Done ✓',
                                          style: TextStyle(
                                            color: isCancelled ? const Color(0xfff87171) : const Color(0xff34d399),
                                            fontSize: 11,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ),
                                      if (lat != null && lng != null) ...[
                                        const SizedBox(height: 6),
                                        IconButton(
                                          padding: EdgeInsets.zero,
                                          constraints: const BoxConstraints(),
                                          icon: const Icon(Icons.directions, color: Color(0xff38bdf8), size: 22),
                                          tooltip: 'Google Maps Directions',
                                          onPressed: () => widget.onGoogleNavigate(lat, lng, t['title'] ?? 'Task Location'),
                                        ),
                                      ],
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          ),
                        );
                      },
                    ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Dynamic rotating needle for the compass widget
class _CompassNeedlePainter extends CustomPainter {
  final bool isNorth;
  const _CompassNeedlePainter({required this.isNorth});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = isNorth ? const Color(0xffef4444) : const Color(0xff64748b)
      ..style = PaintingStyle.fill;

    final path = Path();
    if (isNorth) {
      path.moveTo(size.width / 2, 0);
      path.lineTo(size.width, size.height);
      path.lineTo(0, size.height);
    } else {
      path.moveTo(0, 0);
      path.lineTo(size.width, 0);
      path.lineTo(size.width / 2, size.height);
    }
    path.close();
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Google Maps style glowing blue directional beam cone pointing in device heading
class _GoogleDirectionBeamPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final rect = Rect.fromCircle(center: center, radius: size.width / 2);

    final gradient = RadialGradient(
      colors: [
        const Color(0xff3b82f6).withOpacity(0.4),
        const Color(0xff38bdf8).withOpacity(0.15),
        const Color(0xff38bdf8).withOpacity(0.0),
      ],
      stops: const [0.2, 0.6, 1.0],
    );

    final paint = Paint()
      ..shader = gradient.createShader(rect)
      ..style = PaintingStyle.fill;

    const sweepAngle = pi / 3;
    const startAngle = -pi / 2 - sweepAngle / 2;

    final path = Path();
    path.moveTo(center.dx, center.dy);
    path.arcTo(rect, startAngle, sweepAngle, false);
    path.close();

    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

