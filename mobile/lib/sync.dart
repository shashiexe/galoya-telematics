import 'dart:convert';
import 'dart:io';

import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'config.dart';
import 'data_usage.dart';
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
      try {
        await refreshMasterVehiclesAndTrailers();
      } catch (_) {}
      try {
        await refreshMasterPlotsAndCrops();
      } catch (_) {}
    } finally {
      _running = false;
    }
  }

  static Future<void> refreshMasterPlotsAndCrops({bool force = false}) async {
    try {
      final sp = await SharedPreferences.getInstance();
      final lastSync = sp.getInt('last_erp_master_sync') ?? 0;
      final lastBuildSynced = sp.getInt('last_erp_master_build_synced') ?? 0;
      final count = await LocalDb.countMasterPlots();
      final cropsCount = await LocalDb.countCrops();
      final now = DateTime.now().millisecondsSinceEpoch;
      const currentBuild = 44;

      if (!force && lastBuildSynced >= currentBuild && count >= 14000 && cropsCount >= 4000 && (now - lastSync < 24 * 3600 * 1000)) {
        return;
      }

      final res = await Api.get(
        'get_all_master_plots_and_crops',
        params: {'key': 'galoya2026'},
        timeout: const Duration(seconds: 90),
      );
      if (res['ok'] == true) {
        final plots = (res['plots'] is List) ? (res['plots'] as List) : [];
        final crops = (res['crops'] is List) ? (res['crops'] as List) : [];
        if (plots.isNotEmpty || crops.isNotEmpty) {
          await LocalDb.saveBulkMasterPlotsAndCrops(plots, crops);
          await sp.setInt('last_erp_master_sync', now);
          await sp.setInt('last_erp_master_build_synced', currentBuild);
        }
      }
    } catch (_) {}
  }

  static Future<void> refreshMasterVehiclesAndTrailers() async {
    try {
      final vRes = await Api.get('get_active_vehicles');
      if (vRes['ok'] == true && vRes['vehicles'] is List) {
        await LocalDb.saveVehicles(vRes['vehicles'] as List);
      }
    } catch (_) {}

    try {
      final tRes = await Api.get('get_trailers');
      if (tRes['ok'] == true && tRes['trailers'] is List) {
        await LocalDb.saveTrailers(tRes['trailers'] as List);
      }
    } catch (_) {}
  }

  /// Sync lightweight geofences for offline proximity calculation (< 50KB)
  static Future<void> refreshGeofences({bool force = false}) async {
    try {
      final sp = await SharedPreferences.getInstance();
      final lastSync = sp.getInt('last_gf_sync') ?? 0;
      final count = await LocalDb.countGeofences();
      final now = DateTime.now().millisecondsSinceEpoch;

      // If full dataset (>= 4500 plots) already cached, don't re-download within 12 hours
      if (!force && count >= 4500 && (now - lastSync < 12 * 3600 * 1000)) {
        return; // fresh enough
      }

      // If missing full dataset, ensure local pre-seeded asset is applied first
      if (count < 4500) {
        await LocalDb.ensureGeofencesSeeded();
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
      final res = await Api.get('geofences_nearby', params: {
        'lat': lat.toString(),
        'lng': lng.toString(),
        'limit': '25',
      });
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
      final stats = await DataUsage.getStats();
      final totalKb = ((stats['total_bytes'] as int) / 1024).round();
      await Api.post('locations', {
        'points': rows.map((r) => {
              'lat': r['lat'], 'lng': r['lng'], 'accuracy': r['accuracy'],
              'speed': r['speed'], 'battery': r['battery'], 't': r['t'],
            }).toList(),
        'data_traffic_kb': totalKb,
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
        } else if (q['kind'] == 'cdn_issue') {
          final filesToUpload = <String, File>{};
          final cdnPhotoPath = payload['cdn_photo']?.toString() ?? q['file']?.toString() ?? '';
          if (cdnPhotoPath.isNotEmpty) {
            final f = File(cdnPhotoPath);
            if (await f.exists()) filesToUpload['cdn_photo'] = f;
          }
          final tractorPhotoPath = payload['tractor_photo']?.toString() ?? '';
          if (tractorPhotoPath.isNotEmpty) {
            final f = File(tractorPhotoPath);
            if (await f.exists()) filesToUpload['tractor_photo'] = f;
          }
          final trailerPhotoPath = payload['trailer_photo']?.toString() ?? '';
          if (trailerPhotoPath.isNotEmpty) {
            final f = File(trailerPhotoPath);
            if (await f.exists()) filesToUpload['trailer_photo'] = f;
          }
          final loadPhotoPath = payload['load_photo']?.toString() ?? '';
          if (loadPhotoPath.isNotEmpty) {
            final f = File(loadPhotoPath);
            if (await f.exists()) filesToUpload['load_photo'] = f;
          }

          if (filesToUpload.isNotEmpty) {
            final res = await Api.uploadFiles(
              'cdn_issue',
              payload.map((k, v) => MapEntry(k, v?.toString() ?? '')),
              filesToUpload,
            );
            final cdnNo = (payload['cdn'] ?? res['cdn'] ?? '').toString().trim();
            if (cdnNo.isNotEmpty && res is Map) {
              await LocalDb.updateCdnPhotos(
                cdn: cdnNo,
                cdnPhoto: res['cdn_photo']?.toString(),
                tractorPhoto: res['tractor_photo']?.toString(),
                trailerPhoto: res['trailer_photo']?.toString(),
                loadPhoto: res['load_photo']?.toString(),
              );
            }
            for (final f in filesToUpload.values) {
              await f.delete().catchError((_) => f);
            }
          } else {
            final res = await Api.post('cdn_issue', payload);
            final cdnNo = (payload['cdn'] ?? res['cdn'] ?? '').toString().trim();
            if (cdnNo.isNotEmpty && res is Map) {
              await LocalDb.updateCdnPhotos(
                cdn: cdnNo,
                cdnPhoto: res['cdn_photo']?.toString(),
                tractorPhoto: res['tractor_photo']?.toString(),
                trailerPhoto: res['trailer_photo']?.toString(),
                loadPhoto: res['load_photo']?.toString(),
              );
            }
          }
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
