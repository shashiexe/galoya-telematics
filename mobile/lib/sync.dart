import 'dart:convert';
import 'dart:io';

import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'config.dart';
import 'db.dart';

/// Effective GPS interval (seconds): staff's own choice if allowed, else server value.
Future<int> effectiveInterval() async {
  final sp = await SharedPreferences.getInstance();
  await sp.reload();
  final server = sp.getInt('server_interval') ?? kDefaultIntervalSec;
  final allow = sp.getBool('allow_change') ?? true;
  final mine = sp.getInt('user_interval');
  return (allow && mine != null) ? mine : server;
}

/// Uploads everything stored offline. Safe to call any time; only one run at once.
class Sync {
  static bool _running = false;

  static Future<void> run() async {
    if (_running) return;
    if (await Api.token() == null) return;
    _running = true;
    try {
      try {
        await _points();
      } catch (_) {}
      try {
        await _queue();
      } catch (_) {}
      try {
        await refreshGeofences();
      } catch (_) {}
    } finally {
      _running = false;
    }
  }

  /// Sync lightweight geofences for offline proximity calculation (< 50KB)
  static Future<void> refreshGeofences({bool force = false}) async {
    try {
      final sp = await SharedPreferences.getInstance();
      final lastSync = sp.getInt('last_gf_sync') ?? 0;
      final count = await LocalDb.countGeofences();
      final now = DateTime.now().millisecondsSinceEpoch;

      if (!force && count > 0 && (now - lastSync < 6 * 3600 * 1000)) {
        return; // fresh enough
      }

      final res = await Api.get('geofences_light');
      if (res['ok'] == true && res['geofences'] is List) {
        await LocalDb.saveGeofences(res['geofences'] as List);
        await sp.setInt('last_gf_sync', now);
      }
    } catch (_) {}
  }

  /// Sync high-precision polygon coordinates for plots near current GPS location
  static Future<List<Map<String, dynamic>>?> refreshNearbyGeofences(double lat, double lng) async {
    try {
      final res = await Api.get('geofences_nearby&lat=$lat&lng=$lng&limit=25');
      if (res['ok'] == true && res['geofences'] is List) {
        final list = res['geofences'] as List;
        await LocalDb.saveGeofences(list);
        return list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
    } catch (_) {}
    return null;
  }

  static Future<void> _points() async {
    final sp = await SharedPreferences.getInstance();
    final batch = sp.getInt('batch') ?? 500;
    while (true) {
      final rows = await LocalDb.points(batch);
      if (rows.isEmpty) return;
      await Api.post('locations', {
        'points': rows.map((r) => {
              'lat': r['lat'], 'lng': r['lng'], 'accuracy': r['accuracy'],
              'speed': r['speed'], 'battery': r['battery'], 't': r['t'],
            }).toList(),
      });
      await LocalDb.deletePointsUpTo(rows.last['id'] as int);
      if (rows.length < batch) return;
    }
  }

  static Future<void> _queue() async {
    for (final q in await LocalDb.queue()) {
      final id = q['id'] as int;
      final payload = jsonDecode(q['payload'] as String) as Map<String, dynamic>;
      try {
        if (q['kind'] == 'duty') {
          await Api.post('duty', payload);
        } else if (q['kind'] == 'sos') {
          await Api.post('sos', payload);
        } else if (q['kind'] == 'status_change' || q['kind'] == 'status' || q['kind'] == 'note') {
          await Api.post('duty', {
            'status': 'on',
            'event_type': q['kind'],
            'note': payload['status'] ?? payload['message'] ?? '',
            't': payload['t'] ?? DateTime.now().millisecondsSinceEpoch,
          });
        } else if (q['kind'] == 'photo' || q['kind'] == 'field_photo') {
          final f = File(q['file'] as String);
          if (!await f.exists()) { await LocalDb.dequeue(id); continue; }
          payload['client_id'] ??= 'fp_${DateTime.now().millisecondsSinceEpoch}_$id';
          final action = (payload.containsKey('task_id') && payload['task_id'] != null && payload['task_id'] != 0)
              ? 'task_photo'
              : 'upload_field_photo';
          await Api.upload(action, payload.map((k, v) => MapEntry(k, v?.toString() ?? '')), f);
          await f.delete().catchError((_) => f);
        }
        await LocalDb.dequeue(id);
      } on ApiException catch (e) {
        // 4xx (not 401/408/429) = permanent problem (e.g. task deleted) -> drop after a few tries
        final s = e.status ?? 0;
        if (s >= 400 && s < 500 && s != 401 && s != 408 && s != 429) {
          await LocalDb.markFailed(id, e.message);
          if ((q['tries'] as int) >= 4) await LocalDb.dequeue(id);
        } else {
          rethrow;
        }
      }
    }
  }
}
