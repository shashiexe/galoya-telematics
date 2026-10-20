import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:geolocator/geolocator.dart';
import 'package:battery_plus/battery_plus.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:permission_handler/permission_handler.dart';

import '../api.dart';
import '../config.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';
import '../tracker.dart';
import '../updater.dart';
import '../vault.dart';
import 'login.dart';
import 'settings.dart';
import 'task_detail.dart';

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

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
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

  // Default Galoya Plantation / Damana center
  static const LatLng _defaultCenter = LatLng(7.2050, 81.6580);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _bootstrap();
    WidgetsBinding.instance.addPostFrameCallback((_) => AppUpdater.checkOnLaunch(context));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _locationTimer?.cancel();
    _syncTimer?.cancel();
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
    await sp.reload();
    
    // Request all permissions upfront including battery optimization
    await Tracker.ensurePermissions();
    await _refreshDaemonStatus();
    
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

    setState(() {
      _tracking = tracking;
      _name = name;
      _code = code;
      _unitType = unitType;
      _designation = designation;
      _interval = interval;
    });

    if (tracking) {
      await Tracker.start();
    }

    _loadBattery();
    _fetchLiveLocation();
    _loadTasks();

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
    Sync.run();

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
      }
    } catch (_) {}
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
      // User can freely pause/stop tracking without a password
      await Tracker.stop();
      setState(() => _tracking = false);

      // Record offline-durable Tracking OFF event with timestamp & battery
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
            'note': 'Tracking Turned OFF',
            'lat': pos?.latitude,
            'lng': pos?.longitude,
            'battery': bat,
            't': DateTime.now().millisecondsSinceEpoch,
          }),
        );
        Sync.run();
      } catch (_) {}

      try {
        await Api.post('set_offline', {});
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

  // --- Photo Capture with GPS ---
  Future<void> _snapPhoto(ImageSource source) async {
    try {
      final picker = ImagePicker();
      final photo = await picker.pickImage(
        source: source,
        imageQuality: 75,
        maxWidth: 1600,
        maxHeight: 1600,
      );
      if (photo == null) return;

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
      List<Map<String, dynamic>> nearbyPlots = [];
      try {
        final onlinePlots = await Sync.refreshNearbyGeofences(lat, lng)
            .timeout(const Duration(milliseconds: 1500), onTimeout: () => null);
        if (onlinePlots != null && onlinePlots.isNotEmpty) {
          nearbyPlots = onlinePlots.take(8).toList();
        } else {
          nearbyPlots = await LocalDb.getNearbyGeofences(lat, lng, limit: 8);
        }
      } catch (_) {
        nearbyPlots = await LocalDb.getNearbyGeofences(lat, lng, limit: 8);
      }

      String autoGeofence = 'Field Area';
      String selectedGeofence = '';
      if (nearbyPlots.isNotEmpty) {
        final closest = nearbyPlots.first;
        final bool isInside = closest['is_inside'] == true;
        final edgeMeters = (closest['edge_distance'] as num?)?.round() ?? (closest['distance'] as num?)?.round() ?? 0;
        if (isInside || edgeMeters <= 10) {
          autoGeofence = 'Inside ${closest['name']}';
        } else if (edgeMeters < 1000) {
          autoGeofence = 'Near ${closest['name']} (${edgeMeters}m)';
        } else {
          autoGeofence = 'Near ${closest['name']} (${(edgeMeters / 1000).toStringAsFixed(1)}km)';
        }
        selectedGeofence = closest['name']?.toString() ?? '';
      }

      String selectedActivity = 'Field Visit';
      String note = '';

      if (mounted) {
        final res = await showDialog<Map<String, String>>(
          context: context,
          builder: (ctx) {
            final ctl = TextEditingController();
            String act = selectedActivity;
            String secGf = selectedGeofence;

            return StatefulBuilder(
              builder: (context, setDlgState) {
                return AlertDialog(
                  backgroundColor: const Color(0xff1e293b),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  title: const Row(
                    children: [
                      Icon(Icons.camera_alt, color: Color(0xff38bdf8), size: 22),
                      SizedBox(width: 8),
                      Text('Field Photo & Task Details',
                          style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
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
                              style: TextStyle(color: Colors.white70, fontSize: 12, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 6),
                          Wrap(
                            spacing: 6,
                            runSpacing: 6,
                            children: [
                              'Field Visit',
                              'Fertilizer Application',
                              'Land Preparation',
                              'Harvesting',
                              'Irrigation Check',
                              'Weed Control',
                              'General Inspection',
                              'Other'
                            ].map((item) {
                              final isSel = act == item;
                              return ChoiceChip(
                                label: Text(item,
                                    style: TextStyle(
                                      color: isSel ? Colors.white : Colors.white70,
                                      fontSize: 11,
                                      fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                                    )),
                                selected: isSel,
                                selectedColor: const Color(0xff0284c7),
                                backgroundColor: const Color(0xff0f172a),
                                onSelected: (_) => setDlgState(() => act = item),
                              );
                            }).toList(),
                          ),
                          const SizedBox(height: 14),

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

                          if (nearbyPlots.isNotEmpty) ...[
                            const Text('Select / Confirm Plot (Nearby Only):',
                                style: TextStyle(color: Colors.white60, fontSize: 11)),
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
                                  value: nearbyPlots.any((p) => p['name'] == secGf)
                                      ? secGf
                                      : nearbyPlots.first['name'].toString(),
                                  dropdownColor: const Color(0xff0f172a),
                                  isExpanded: true,
                                  style: const TextStyle(color: Colors.white, fontSize: 12),
                                  items: nearbyPlots.map((p) {
                                    final d = (p['distance'] as num?)?.round() ?? 0;
                                    final tag = d <= 25 ? '(Inside / 0m)' : '(${d}m away)';
                                    return DropdownMenuItem<String>(
                                      value: p['name'].toString(),
                                      child: Text('${p['name']} $tag',
                                          style: const TextStyle(color: Colors.white)),
                                    );
                                  }).toList(),
                                  onChanged: (v) {
                                    if (v != null) setDlgState(() => secGf = v);
                                  },
                                ),
                              ),
                            ),
                            const SizedBox(height: 12),
                          ],

                          // 3. Optional Remarks
                          const Text('3. Optional Remarks / Note:',
                              style: TextStyle(color: Colors.white70, fontSize: 12, fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          TextField(
                            controller: ctl,
                            style: const TextStyle(color: Colors.white, fontSize: 13),
                            decoration: const InputDecoration(
                              hintText: 'Additional details (e.g. 50kg urea applied)...',
                              hintStyle: TextStyle(color: Colors.white38, fontSize: 12),
                              isDense: true,
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
          't': DateTime.now().millisecondsSinceEpoch,
        }),
        file: photo.path,
      );
      Sync.run();

      final photoItem = {
        'client_id': cid,
        'lat': lat,
        'lng': lng,
        'activity_type': selectedActivity,
        'auto_geofence': autoGeofence,
        'selected_geofence': selectedGeofence,
        'note': note,
        'file': photo.path,
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
                    File(photo.path),
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
      backgroundColor: const Color(0xff0f172a),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => DraggableScrollableSheet(
        initialChildSize: 0.6,
        maxChildSize: 0.9,
        minChildSize: 0.4,
        expand: false,
        builder: (_, scrollCtl) => Padding(
          padding: const EdgeInsets.all(16),
          child: ListView(
            controller: scrollCtl,
            children: [
              Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(2)))),
              const SizedBox(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('My Assigned Tasks (${_tasks.length})', style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                  IconButton(icon: const Icon(Icons.refresh, color: Colors.blue), onPressed: () => _loadTasks()),
                ],
              ),
              const SizedBox(height: 12),
              if (_tasks.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: Text('No pending tasks assigned', style: TextStyle(color: Colors.white54))),
                )
              else
                ..._tasks.map((t) => Card(
                      color: const Color(0xff1e293b),
                      margin: const EdgeInsets.only(bottom: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                      child: ListTile(
                        leading: const CircleAvatar(backgroundColor: Color(0xff3b82f6), child: Icon(Icons.assignment, color: Colors.white)),
                        title: Text(t['title'] ?? 'Task', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                        subtitle: Text(t['location_name'] ?? 'Galoya Plantation', style: const TextStyle(color: Colors.white70)),
                        trailing: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(color: Colors.blue.withOpacity(0.2), borderRadius: BorderRadius.circular(12)),
                          child: Text(t['status'] ?? 'pending', style: const TextStyle(color: Colors.blueAccent, fontSize: 12)),
                        ),
                        onTap: () {
                          Navigator.pop(ctx);
                          Navigator.push(context, MaterialPageRoute(builder: (_) => TaskDetailScreen(task: t)));
                        },
                      ),
                    )),
            ],
          ),
        ),
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
    });
    final gLat = (plot['lat'] as num?)?.toDouble() ?? 0.0;
    final gLng = (plot['lng'] as num?)?.toDouble() ?? 0.0;
    if (gLat != 0.0 && gLng != 0.0) {
      _mapController.move(LatLng(gLat, gLng), 16.5);
    }

    // Fetch full polygon coordinates from server on-demand (keeps mobile SQLite lightweight)
    try {
      final name = (plot['name'] ?? plot['code'] ?? '').toString();
      if (name.isNotEmpty) {
        final res = await Api.get('plot_polygon', params: {'name': name});
        if (res['ok'] == true && res['coordinates'] is List) {
          final rawList = res['coordinates'] as List;
          final pts = <LatLng>[];
          for (final c in rawList) {
            if (c is List && c.length >= 2) {
              final lat = (c[0] as num).toDouble();
              final lng = (c[1] as num).toDouble();
              pts.add(LatLng(lat, lng));
            }
          }
          if (pts.isNotEmpty && mounted) {
            setState(() {
              _targetPolygonPoints = pts;
            });
            final bounds = LatLngBounds.fromPoints(pts);
            _mapController.fitCamera(CameraFit.bounds(bounds: bounds, padding: const EdgeInsets.all(50)));
          }
        }
      }
    } catch (_) {}

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: const Color(0xff0284c7),
          content: Text('Target Plot: ${plot['name']} (${plot['code'] ?? ''})'),
          duration: const Duration(seconds: 4),
          action: SnackBarAction(
            label: 'Google Maps',
            textColor: Colors.white,
            onPressed: () => _openGoogleMapsNavigation(gLat, gLng, plot['name'] ?? 'Plot'),
          ),
        ),
      );
    }
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
                ),
                children: [
                  TileLayer(
                    urlTemplate: _isSatellite
                        ? 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'
                        : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    userAgentPackageName: 'lk.galoya.stafftrack',
                  ),
                  if (_targetPolygonPoints.isNotEmpty)
                    PolygonLayer(
                      polygons: [
                        Polygon(
                          points: _targetPolygonPoints,
                          color: const Color(0x3322c55e),
                          borderColor: const Color(0xff16a34a),
                          borderStrokeWidth: 3.0,
                          isFilled: true,
                        ),
                      ],
                    ),
                  if (_targetPlot != null)
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
                      if (_targetPlot != null)
                        Marker(
                          point: LatLng(
                            (_targetPlot!['lat'] as num).toDouble(),
                            (_targetPlot!['lng'] as num).toDouble(),
                          ),
                          width: 140,
                          height: 65,
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: const Color(0xfff59e0b),
                                  borderRadius: BorderRadius.circular(8),
                                  boxShadow: const [
                                    BoxShadow(color: Colors.black54, blurRadius: 4, offset: Offset(0, 2)),
                                  ],
                                ),
                                child: Text(
                                  _targetPlot!['name'] ?? 'Target Plot',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(color: Colors.black, fontWeight: FontWeight.bold, fontSize: 11),
                                ),
                              ),
                              const Icon(Icons.location_on, color: Color(0xfff59e0b), size: 34),
                            ],
                          ),
                        ),
                      Marker(
                        point: currentLatLng,
                        width: 50,
                        height: 50,
                        child: Container(
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: Colors.blue.withOpacity(0.3),
                          ),
                          child: Center(
                            child: Container(
                              width: 20,
                              height: 20,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: const Color(0xff2563eb),
                                border: Border.all(color: Colors.white, width: 3),
                                boxShadow: const [
                                  BoxShadow(color: Colors.black45, blurRadius: 6, spreadRadius: 2),
                                ],
                              ),
                            ),
                          ),
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
                        // Assigned Tasks Icon
                        IconButton(
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                          icon: Stack(
                            clipBehavior: Clip.none,
                            children: [
                              const Icon(Icons.assignment_outlined, color: Color(0xff334155), size: 22),
                              if (_tasks.isNotEmpty)
                                Positioned(
                                  right: -4,
                                  top: -3,
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                                    decoration: BoxDecoration(color: const Color(0xff2563eb), borderRadius: BorderRadius.circular(10)),
                                    child: Text('${_tasks.length}', style: const TextStyle(color: Colors.white, fontSize: 8.5, fontWeight: FontWeight.bold)),
                                  ),
                                ),
                            ],
                          ),
                          tooltip: 'Assigned Tasks',
                          onPressed: _showTasksModal,
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
                    border: Border.all(color: const Color(0xfff59e0b), width: 1.5),
                    boxShadow: const [
                      BoxShadow(color: Colors.black54, blurRadius: 10, offset: Offset(0, 4)),
                    ],
                  ),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: const Color(0xfff59e0b).withOpacity(0.2),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.near_me, color: Color(0xfff59e0b), size: 20),
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
                              _currentPosition != null
                                  ? 'Distance: ${_formatDistanceMeters(
                                      Geolocator.distanceBetween(
                                        _currentPosition!.latitude,
                                        _currentPosition!.longitude,
                                        (_targetPlot!['lat'] as num).toDouble(),
                                        (_targetPlot!['lng'] as num).toDouble(),
                                      ),
                                    )}'
                                  : 'Target plot active',
                              style: const TextStyle(color: Color(0xff38bdf8), fontSize: 11, fontWeight: FontWeight.w600),
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.navigation, color: Colors.greenAccent),
                        tooltip: 'Google Maps Turn-by-Turn',
                        onPressed: () {
                          final lat = (_targetPlot!['lat'] as num).toDouble();
                          final lng = (_targetPlot!['lng'] as num).toDouble();
                          _openGoogleMapsNavigation(lat, lng, _targetPlot!['name'] ?? 'Plot');
                        },
                      ),
                      IconButton(
                        icon: const Icon(Icons.close, color: Colors.white60),
                        tooltip: 'Cancel Navigation',
                        onPressed: () => setState(() => _targetPlot = null),
                      ),
                    ],
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

            // 4. FLOATING MAP CONTROLS (Right side: Layers, Center, Compass)
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
                  // Compass
                  _buildCircleMapBtn(
                    icon: Icons.explore,
                    iconColor: Colors.redAccent,
                    tooltip: 'Compass',
                    onTap: () => _mapController.rotate(0),
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
                    color: const Color(0xff0284c7), // Cyan Camera
                    icon: Icons.camera_alt,
                    onTap: () => _snapPhoto(ImageSource.camera),
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

  Widget _buildWiaActionBtn({required Color color, required IconData icon, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
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

/// Modal bottom sheet for searching plantation plots and launching turn-by-turn navigation
class _PlotNavigatorModalSheet extends StatefulWidget {
  final Position? currentPosition;
  final ValueChanged<Map<String, dynamic>> onSelectPlot;
  final void Function(double lat, double lng, String name) onGoogleNavigate;
  final String Function(double meters) formatDistance;

  const _PlotNavigatorModalSheet({
    required this.currentPosition,
    required this.onSelectPlot,
    required this.onGoogleNavigate,
    required this.formatDistance,
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
        List<Map<String, dynamic>> list = [];
        try {
          final online = await Sync.refreshNearbyGeofences(lat, lng)
              .timeout(const Duration(milliseconds: 1500), onTimeout: () => null);
          if (online != null && online.isNotEmpty) {
            list = online.take(15).toList();
          } else {
            list = await LocalDb.getNearbyGeofences(lat, lng, limit: 15);
          }
        } catch (_) {
          list = await LocalDb.getNearbyGeofences(lat, lng, limit: 15);
        }
        if (mounted) {
          setState(() {
            _results = list;
            _modeLabel = 'Nearest Plots Around You (${list.length})';
            _loading = false;
          });
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
      final list = await LocalDb.searchGeofences(
        q,
        userLat: widget.currentPosition?.latitude,
        userLng: widget.currentPosition?.longitude,
        limit: 25,
      );
      if (mounted) {
        setState(() {
          _results = list;
          _modeLabel = 'Search Results (${list.length})';
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
                        'Find geofence and navigate to plot',
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
                hintText: 'Search plot by name or code (e.g. 412, Sec D)...',
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
                        'No matching plots found.\nMake sure geofences are cached.',
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
                      final code = p['code'] ?? '';
                      final dist = (p['distance'] as num?)?.toDouble() ?? 0.0;
                      final lat = (p['lat'] as num?)?.toDouble() ?? 0.0;
                      final lng = (p['lng'] as num?)?.toDouble() ?? 0.0;

                      return Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          color: const Color(0xff1e293b),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: const Color(0xff334155)),
                        ),
                        child: Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: const Color(0xff059669).withOpacity(0.18),
                                shape: BoxShape.circle,
                              ),
                              child: const Icon(Icons.terrain, color: Color(0xff34d399), size: 20),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    name,
                                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  const SizedBox(height: 3),
                                  Row(
                                    children: [
                                      if (code.isNotEmpty) ...[
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                                          decoration: BoxDecoration(
                                            color: const Color(0xff334155),
                                            borderRadius: BorderRadius.circular(6),
                                          ),
                                          child: Text(code, style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 10, fontWeight: FontWeight.w600)),
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
                            // Action 1: In-App Map
                            IconButton(
                              icon: const Icon(Icons.map_outlined, color: Color(0xff38bdf8), size: 22),
                              tooltip: 'Show on Map',
                              onPressed: () => widget.onSelectPlot(p),
                            ),
                            // Action 2: Google Maps Turn-by-Turn
                            ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xff059669),
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                              ),
                              icon: const Icon(Icons.navigation, size: 14),
                              label: const Text('Go', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                              onPressed: () => widget.onGoogleNavigate(lat, lng, name),
                            ),
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

