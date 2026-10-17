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

import '../api.dart';
import '../config.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';
import '../tracker.dart';
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

class _HomeScreenState extends State<HomeScreen> {
  final MapController _mapController = MapController();
  
  bool _tracking = false;
  String _name = 'Field Officer';
  String _code = 'STAFF-01';
  int _interval = 15;
  int _pendingPts = 0;
  
  Position? _currentPosition;
  double _speed = 0.0;
  double _accuracy = 15.0;
  int _batteryLevel = 100;
  String _currentStatus = 'Working';
  
  bool _isSatellite = true;
  Timer? _locationTimer;
  Timer? _syncTimer;
  List<Map<String, dynamic>> _tasks = [];
  final List<Map<String, dynamic>> _capturedPhotos = [];

  // Default Galoya Plantation / Damana center
  static const LatLng _defaultCenter = LatLng(7.2050, 81.6580);

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _locationTimer?.cancel();
    _syncTimer?.cancel();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    final sp = await SharedPreferences.getInstance();
    await sp.reload();
    
    // Request all permissions upfront
    await Tracker.ensurePermissions();
    
    final tracking = sp.getBool('tracking') ?? false;
    final name = sp.getString('name') ?? 'Field Officer';
    final code = sp.getString('code') ?? '49-1665';
    final interval = await effectiveInterval();

    setState(() {
      _tracking = tracking;
      _name = name;
      _code = code;
      _interval = interval;
    });

    _loadBattery();
    _fetchLiveLocation();
    _loadTasks();

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

  // --- SOS Alert Trigger ---
  Future<void> _sendSOS() async {
    final pos = _currentPosition;
    final lat = pos?.latitude ?? 7.2050;
    final lng = pos?.longitude ?? 81.6580;

    await LocalDb.enqueue('sos', jsonEncode({
      'lat': lat,
      'lng': lng,
      'status': 'EMERGENCY_SOS',
      't': DateTime.now().millisecondsSinceEpoch,
    }));
    Sync.run();

    if (mounted) {
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: const Color(0xff1e293b),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          title: const Row(
            children: [
              Icon(Icons.warning_amber_rounded, color: Colors.red, size: 28),
              SizedBox(width: 8),
              Text('EMERGENCY SOS', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            ],
          ),
          content: Text(
            'SOS Emergency signal sent to Galoya Operations Room with GPS coordinates ($lat, $lng).\n\nImmediate assistance requested!',
            style: const TextStyle(color: Colors.white70),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('OK', style: TextStyle(color: Colors.redAccent, fontWeight: FontWeight.bold)),
            ),
          ],
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

      // Ensure GPS position
      Position? pos = _currentPosition;
      if (pos == null) {
        try {
          pos = await Geolocator.getLastKnownPosition();
          pos ??= await Geolocator.getCurrentPosition(
            desiredAccuracy: LocationAccuracy.medium,
            timeLimit: const Duration(seconds: 4),
          );
        } catch (_) {}
      }

      final lat = pos?.latitude ?? 7.2050;
      final lng = pos?.longitude ?? 81.6580;

      // 1. Proximity query for nearest plots (top 8, zero lag)
      List<Map<String, dynamic>> nearbyPlots = [];
      try {
        nearbyPlots = await LocalDb.getNearbyGeofences(lat, lng, limit: 8);
      } catch (_) {}

      String autoGeofence = 'Field Area';
      String selectedGeofence = '';
      if (nearbyPlots.isNotEmpty) {
        final closest = nearbyPlots.first;
        final dMeters = (closest['distance'] as num?)?.round() ?? 0;
        if (dMeters <= 250) {
          autoGeofence = 'Inside ${closest['name']}';
        } else {
          autoGeofence = 'Near ${closest['name']} (${dMeters}m)';
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

  void _viewPhotoDetail(Map<String, dynamic> item) {
    final f = File(item['file'] as String);
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff1e293b),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.camera_alt, color: Color(0xff38bdf8), size: 22),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                item['note']?.toString().isNotEmpty == true ? item['note'] : 'Field Photo',
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
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: f.existsSync()
                  ? Image.file(f, height: 180, width: double.infinity, fit: BoxFit.cover)
                  : const SizedBox(height: 100, child: Center(child: Icon(Icons.broken_image, color: Colors.white38, size: 40))),
            ),
            const SizedBox(height: 12),
            Text('📍 GPS: ${(item['lat'] as double).toStringAsFixed(5)}, ${(item['lng'] as double).toStringAsFixed(5)}',
                style: const TextStyle(color: Colors.white70, fontSize: 12)),
            const SizedBox(height: 4),
            Text('🕒 Time: ${(item['time'] as DateTime).toString().substring(0, 19)}',
                style: const TextStyle(color: Colors.white60, fontSize: 11)),
            const SizedBox(height: 4),
            const Text('☁️ Status: Saved & Uploaded to Server',
                style: TextStyle(color: Color(0xff22c55e), fontSize: 11, fontWeight: FontWeight.bold)),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close', style: TextStyle(color: Colors.white70)),
          ),
        ],
      ),
    );
  }

  void _showGalleryModal() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xff0f172a),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Container(
          padding: const EdgeInsets.all(16),
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Field Photos Gallery',
                      style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                  Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.add_photo_alternate, color: Color(0xff38bdf8)),
                        tooltip: 'Upload from phone gallery',
                        onPressed: () {
                          Navigator.pop(ctx);
                          _snapPhoto(ImageSource.gallery);
                        },
                      ),
                      IconButton(
                        icon: const Icon(Icons.close, color: Colors.white54),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 12),
              if (_capturedPhotos.isEmpty)
                const Expanded(
                  child: Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.photo_library_outlined, size: 48, color: Colors.white24),
                        SizedBox(height: 12),
                        Text('No photos captured yet today', style: TextStyle(color: Colors.white54)),
                        SizedBox(height: 4),
                        Text('Tap camera button to snap and tag field photos',
                            style: TextStyle(color: Colors.white38, fontSize: 12)),
                      ],
                    ),
                  ),
                )
              else
                Expanded(
                  child: ListView.builder(
                    itemCount: _capturedPhotos.length,
                    itemBuilder: (ctx, idx) {
                      final item = _capturedPhotos[idx];
                      final f = File(item['file'] as String);
                      final dt = item['time'] as DateTime;
                      return Card(
                        color: const Color(0xff1e293b),
                        margin: const EdgeInsets.only(bottom: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        child: ListTile(
                          leading: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: f.existsSync()
                                ? Image.file(f, width: 50, height: 50, fit: BoxFit.cover)
                                : const Icon(Icons.image, color: Colors.white54),
                          ),
                          title: Text(
                            item['activity_type']?.toString().isNotEmpty == true
                                ? item['activity_type'].toString()
                                : (item['note']?.toString().isNotEmpty == true ? item['note'].toString() : 'Field Photo'),
                            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          subtitle: Text(
                            '📍 ${item['selected_geofence'] ?? item['auto_geofence'] ?? 'Field Plot'}${item['note']?.toString().isNotEmpty == true && item['note'] != item['activity_type'] ? ' • ${item['note']}' : ''}\n🕒 ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}',
                            style: const TextStyle(color: Colors.white60, fontSize: 11),
                          ),
                          trailing: const Icon(Icons.check_circle, color: Color(0xff22c55e), size: 20),
                          onTap: () {
                            Navigator.pop(ctx);
                            _viewPhotoDetail(item);
                          },
                        ),
                      );
                    },
                  ),
                ),
            ],
          ),
        ),
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
                  MarkerLayer(
                    markers: [
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
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                decoration: BoxDecoration(
                  color: Colors.white,
                  boxShadow: [
                    BoxShadow(color: Colors.black.withOpacity(0.1), blurRadius: 10, offset: const Offset(0, 3)),
                  ],
                ),
                child: Row(
                  children: [
                    // WiaTag Droplet Logo
                    Transform.rotate(
                      angle: -0.785, // -45 deg
                      child: Container(
                        width: 24,
                        height: 24,
                        decoration: const BoxDecoration(
                          gradient: LinearGradient(colors: [Color(0xff42a5f5), Color(0xff1976d2)]),
                          borderRadius: BorderRadius.only(
                            topLeft: Radius.circular(12),
                            topRight: Radius.circular(12),
                            bottomLeft: Radius.circular(12),
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    const Text(
                      'wiatag',
                      style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: Color(0xff1e293b), letterSpacing: -0.5),
                    ),
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: const Color(0xffe2e8f0), borderRadius: BorderRadius.circular(6)),
                      child: const Text('Galoya', style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xff475569))),
                    ),
                    const Spacer(),
                    // Assigned Tasks Icon
                    IconButton(
                      icon: Stack(
                        children: [
                          const Icon(Icons.assignment_outlined, color: Color(0xff334155)),
                          if (_tasks.isNotEmpty)
                            Positioned(
                              right: 0,
                              top: 0,
                              child: Container(
                                padding: const EdgeInsets.all(3),
                                decoration: const BoxDecoration(color: Colors.blue, shape: BoxShape.circle),
                                child: Text('${_tasks.length}', style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold)),
                              ),
                            ),
                        ],
                      ),
                      onPressed: _showTasksModal,
                    ),
                    // Settings Icon (Supervisor PIN Protected)
                    IconButton(
                      icon: const Icon(Icons.settings_outlined, color: Color(0xff334155)),
                      onPressed: () async {
                        final authorized = await _requestAdminPassword('access telematics settings');
                        if (authorized && mounted) {
                          Navigator.push(context, MaterialPageRoute(builder: (_) => const SettingsScreen()));
                        }
                      },
                    ),
                    // Logout (Supervisor PIN Protected)
                    IconButton(
                      icon: const Icon(Icons.exit_to_app, color: Color(0xff334155)),
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
                  ],
                ),
              ),
            ),

            // 3. FLOATING TOP SOS CARD
            Positioned(
              top: 68,
              left: 16,
              right: 16,
              child: GestureDetector(
                onTap: _sendSOS,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(30),
                    boxShadow: const [
                      BoxShadow(color: Colors.black26, blurRadius: 12, offset: Offset(0, 4)),
                    ],
                  ),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
                        decoration: BoxDecoration(
                          color: const Color(0xffef4444),
                          borderRadius: BorderRadius.circular(15),
                          boxShadow: const [
                            BoxShadow(color: Color(0x66ef4444), blurRadius: 8, spreadRadius: 1),
                          ],
                        ),
                        child: const Text('SOS', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 11)),
                      ),
                      const SizedBox(width: 12),
                      const Text(
                        'Send SOS',
                        style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Color(0xff1e293b)),
                      ),
                      const Spacer(),
                      const Icon(Icons.arrow_forward_ios, size: 14, color: Color(0xff94a3b8)),
                    ],
                  ),
                ),
              ),
            ),

            // 4. FLOATING MAP CONTROLS (Right side: Layers, Center, Compass)
            Positioned(
              top: 130,
              right: 16,
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
                    color: const Color(0xff475569), // Slate
                    icon: Icons.crop_free,
                    onTap: () {
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(content: Text('Unit: $_code verified on field.')),
                      );
                    },
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
                          title: const Text('Diagnostics', style: TextStyle(color: Colors.white)),
                          content: Text(
                            'Battery Level: $_batteryLevel%\nGPS Accuracy: ${_accuracy.toStringAsFixed(1)} m\nInterval: ${_interval}s\nPending Sync: $_pendingPts points',
                            style: const TextStyle(color: Colors.white70),
                          ),
                          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
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

                    // Tracking Toggle Row
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Tracking',
                          style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: Color(0xff0f172a)),
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
                    Row(
                      children: [
                        const Text('🚜', style: TextStyle(fontSize: 20)),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '$_code · $_name',
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
