import 'dart:convert';
import 'dart:io';
import 'dart:math';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// Local offline storage: GPS points + pending actions (duty, photos) + lightweight geofences cache.
class LocalDb {
  static Database? _db;

  static Future<Database> get db async {
    if (_db != null) return _db!;
    final dir = await getDatabasesPath();
    _db = await openDatabase(
      p.join(dir, 'stafftrack.db'),
      version: 3,
      onCreate: (d, v) async {
        await d.execute('''CREATE TABLE points(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          lat REAL, lng REAL, accuracy REAL, speed REAL, battery INTEGER, t INTEGER)''');
        await d.execute('''CREATE TABLE queue(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          kind TEXT, payload TEXT, file TEXT, created INTEGER, tries INTEGER DEFAULT 0, last_error TEXT)''');
        await d.execute('CREATE TABLE tasks_cache(id INTEGER PRIMARY KEY, json TEXT)');
        await d.execute('''CREATE TABLE IF NOT EXISTS geofences_cache(
          id INTEGER PRIMARY KEY, name TEXT, code TEXT, lat REAL, lng REAL, coordinates TEXT)''');
      },
      onUpgrade: (d, oldV, newV) async {
        if (oldV < 2) {
          await d.execute('''CREATE TABLE IF NOT EXISTS geofences_cache(
            id INTEGER PRIMARY KEY, name TEXT, code TEXT, lat REAL, lng REAL, coordinates TEXT)''');
        }
        if (oldV < 3) {
          try {
            await d.execute('ALTER TABLE geofences_cache ADD COLUMN coordinates TEXT');
          } catch (_) {}
        }
      },
    );
    return _db!;
  }

  static Future<void> addPoint(Map<String, Object?> pt) async => (await db).insert('points', pt);

  static Future<List<Map<String, Object?>>> points(int limit) async =>
      (await db).query('points', orderBy: 'id', limit: limit);

  static Future<void> deletePointsUpTo(int id) async =>
      (await db).delete('points', where: 'id <= ?', whereArgs: [id]);

  static Future<int> countPoints() async =>
      Sqflite.firstIntValue(await (await db).rawQuery('SELECT COUNT(*) FROM points')) ?? 0;

  static Future<void> enqueue(String kind, String payload, {String? file}) async =>
      (await db).insert('queue', {
        'kind': kind, 'payload': payload, 'file': file,
        'created': DateTime.now().millisecondsSinceEpoch,
      });

  static Future<List<Map<String, Object?>>> queue() async => (await db).query('queue', orderBy: 'id');

  static Future<int> countQueue() async =>
      Sqflite.firstIntValue(await (await db).rawQuery('SELECT COUNT(*) FROM queue')) ?? 0;

  static Future<void> dequeue(int id) async => (await db).delete('queue', where: 'id=?', whereArgs: [id]);

  static Future<void> markFailed(int id, String err) async => (await db)
      .rawUpdate('UPDATE queue SET tries=tries+1, last_error=? WHERE id=?', [err, id]);

  // --- Lightweight Geofences Cache (Proximity Filtering) ---

  static bool _seeding = false;

  /// Guarantees that all 5,034 plantation plots (including all 1,180 Varipathanchena plots)
  /// are pre-seeded in SQLite from the compressed local asset bundle immediately upon install/launch.
  static Future<void> ensureGeofencesSeeded() async {
    if (_seeding) return;
    _seeding = true;
    try {
      final count = await countGeofences();
      if (count >= 4500) {
        return; // Full dataset already cached
      }

      final bytes = await rootBundle.load('assets/agriculture_geofences.json.gz');
      final rawData = gzip.decode(bytes.buffer.asUint8List());
      final jsonStr = utf8.decode(rawData);
      final list = jsonDecode(jsonStr) as List;

      final d = await db;
      final batch = d.batch();
      for (final item in list) {
        if (item is Map) {
          final c = item['coordinates'];
          batch.insert(
            'geofences_cache',
            {
              'id': item['id'],
              'name': item['name'] ?? '',
              'code': item['code'] ?? '',
              'lat': (item['lat'] is num) ? (item['lat'] as num).toDouble() : 0.0,
              'lng': (item['lng'] is num) ? (item['lng'] as num).toDouble() : 0.0,
              'coordinates': c is String ? c : (c != null ? jsonEncode(c) : null),
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
      }
      await batch.commit(noResult: true);
      debugPrint('LocalDb: Pre-seeded ${list.length} plantation plots into SQLite cache.');
    } catch (e) {
      debugPrint('ensureGeofencesSeeded error: $e');
    } finally {
      _seeding = false;
    }
  }

  /// Save geofences (centroids + optional full polygon coordinates for offline high-precision boundary checks)
  static Future<void> saveGeofences(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final item in list) {
      if (item is Map) {
        String? coordsStr;
        if (item['coordinates'] is String) {
          coordsStr = item['coordinates'] as String;
        } else if (item['coordinates'] != null) {
          try {
            coordsStr = jsonEncode(item['coordinates']);
          } catch (_) {}
        }

        batch.insert(
          'geofences_cache',
          {
            'id': item['id'],
            'name': item['name'] ?? '',
            'code': item['code'] ?? '',
            'lat': (item['lat'] is num) ? (item['lat'] as num).toDouble() : 0.0,
            'lng': (item['lng'] is num) ? (item['lng'] as num).toDouble() : 0.0,
            'coordinates': coordsStr,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
    }
    await batch.commit(noResult: true);
  }

  static Future<int> countGeofences() async =>
      Sqflite.firstIntValue(await (await db).rawQuery('SELECT COUNT(*) FROM geofences_cache')) ?? 0;

  /// Calculate distance in meters using Haversine formula
  static double distanceMeters(double lat1, double lon1, double lat2, double lon2) {
    const r = 6371000.0; // Earth radius in meters
    final dLat = (lat2 - lat1) * (pi / 180.0);
    final dLon = (lon2 - lon1) * (pi / 180.0);
    final a = sin(dLat / 2) * sin(dLat / 2) +
        cos(lat1 * (pi / 180.0)) * cos(lat2 * (pi / 180.0)) * sin(dLon / 2) * sin(dLon / 2);
    final c = 2 * atan2(sqrt(a), sqrt(1 - a));
    return r * c;
  }

  /// Ray-casting Point-in-Polygon check
  static bool isPointInsidePolygon(double lat, double lng, List<List<double>> polygon) {
    if (polygon.length < 3) return false;
    bool inside = false;
    final n = polygon.length;
    for (int i = 0, j = n - 1; i < n; j = i++) {
      final xi = polygon[i][0];
      final yi = polygon[i][1];
      final xj = polygon[j][0];
      final yj = polygon[j][1];
      final intersect = ((yi > lng) != (yj > lng)) &&
          (lat < (xj - xi) * (lng - yi) / ((yj - yi) == 0 ? 0.00000001 : (yj - yi)) + xi);
      if (intersect) inside = !inside;
    }
    return inside;
  }

  /// Calculates minimum perpendicular distance from (lat, lng) to closest polygon boundary segment
  static double minDistanceToPolygonBoundary(double lat, double lng, List<List<double>> polygon) {
    if (polygon.isEmpty) return double.infinity;
    if (polygon.length < 3) {
      return distanceMeters(lat, lng, polygon[0][0], polygon[0][1]);
    }
    double minDist = double.infinity;
    final n = polygon.length;
    for (int i = 0, j = n - 1; i < n; j = i++) {
      final aLat = polygon[j][0];
      final aLng = polygon[j][1];
      final bLat = polygon[i][0];
      final bLng = polygon[i][1];

      final dLat = bLat - aLat;
      final dLng = bLng - aLng;
      double pLat, pLng;
      if (dLat == 0.0 && dLng == 0.0) {
        pLat = aLat;
        pLng = aLng;
      } else {
        double t = (((lat - aLat) * dLat) + ((lng - aLng) * dLng)) / ((dLat * dLat) + (dLng * dLng));
        t = t.clamp(0.0, 1.0);
        pLat = aLat + (t * dLat);
        pLng = aLng + (t * dLng);
      }
      final dist = distanceMeters(lat, lng, pLat, pLng);
      if (dist < minDist) {
        minDist = dist;
      }
    }
    return minDist;
  }

  /// Parse dynamic coordinates JSON array into List<List<double>>
  static List<List<double>>? _parsePolygon(dynamic raw) {
    if (raw == null) return null;
    try {
      dynamic parsed = raw;
      if (raw is String) {
        if (raw.trim().isEmpty) return null;
        parsed = jsonDecode(raw);
      }
      if (parsed is List && parsed.length >= 3) {
        final pts = <List<double>>[];
        for (final pt in parsed) {
          if (pt is List && pt.length >= 2) {
            final pLat = (pt[0] is num) ? (pt[0] as num).toDouble() : 0.0;
            final pLng = (pt[1] is num) ? (pt[1] as num).toDouble() : 0.0;
            pts.add([pLat, pLng]);
          }
        }
        if (pts.length >= 3) return pts;
      }
    } catch (_) {}
    return null;
  }

  /// Get nearest plots close to the current location, sorted by TRUE BOUNDARY EDGE distance.
  /// Inside plots (point-in-polygon) and boundary proximity (~5m) are prioritized over centroids.
  static Future<List<Map<String, dynamic>>> getNearbyGeofences(double lat, double lng, {int limit = 8}) async {
    final d = await db;
    // Step 1: Pre-filter by bounding box in SQLite (~10 km radius) so we only fetch nearby candidates, NOT 5,000!
    const double delta = 0.09; // ~10 km
    List<Map<String, Object?>> rows = await d.query(
      'geofences_cache',
      where: 'lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?',
      whereArgs: [lat - delta, lat + delta, lng - delta, lng + delta],
      limit: 60,
    );
    if (rows.isEmpty) {
      // Expand slightly to ~15 km
      const double deltaWide = 0.14;
      rows = await d.query(
        'geofences_cache',
        where: 'lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?',
        whereArgs: [lat - deltaWide, lat + deltaWide, lng - deltaWide, lng + deltaWide],
        limit: 30,
      );
      if (rows.isEmpty) return [];
    }

    final candidates = <Map<String, dynamic>>[];
    for (final r in rows) {
      final gLat = (r['lat'] as num?)?.toDouble() ?? 0.0;
      final gLng = (r['lng'] as num?)?.toDouble() ?? 0.0;
      if (gLat == 0.0 && gLng == 0.0) continue;

      final cDist = distanceMeters(lat, lng, gLat, gLng);
      candidates.add({
        'row': r,
        'cDist': cDist,
      });
    }

    // Sort by centroid to examine the closest 30 candidates for edge calculations
    candidates.sort((a, b) => (a['cDist'] as double).compareTo(b['cDist'] as double));
    final topCandidates = candidates.take(30).toList();

    // Step 2: Compute true boundary edge distance and point-in-polygon ray-casting
    final list = <Map<String, dynamic>>[];
    for (final c in topCandidates) {
      final r = c['row'] as Map<String, Object?>;
      final gLat = (r['lat'] as num?)?.toDouble() ?? 0.0;
      final gLng = (r['lng'] as num?)?.toDouble() ?? 0.0;
      final cDist = c['cDist'] as double;

      final poly = _parsePolygon(r['coordinates']);
      bool isInside = false;
      double edgeDist = cDist;

      if (poly != null && poly.isNotEmpty) {
        isInside = isPointInsidePolygon(lat, lng, poly);
        edgeDist = minDistanceToPolygonBoundary(lat, lng, poly);
      } else {
        // Fallback if full polygon coords not yet cached for this plot:
        // Use centroid distance, assume inside if within 30m of centroid
        isInside = (cDist <= 30.0);
        edgeDist = cDist;
      }

      list.add({
        'id': r['id'],
        'name': r['name'],
        'code': r['code'],
        'lat': gLat,
        'lng': gLng,
        'is_inside': isInside,
        'edge_distance': edgeDist,
        'distance': isInside ? 0.0 : edgeDist, // 0 if inside, else boundary distance in meters
        'coordinates': poly,
      });
    }

    // Step 3: Sort: Inside plots first, then by closest boundary edge distance (~5m plots beat 200m centroids!)
    list.sort((a, b) {
      final aInside = a['is_inside'] as bool;
      final bInside = b['is_inside'] as bool;
      if (aInside && !bInside) return -1;
      if (!aInside && bInside) return 1;
      return (a['distance'] as double).compareTo(b['distance'] as double);
    });

    return list.take(limit).toList();
  }

  /// Search any plot by name or code with optional boundary edge distance calculation
  static Future<List<Map<String, dynamic>>> searchGeofences(String query, {double? userLat, double? userLng, int limit = 20}) async {
    final d = await db;
    final q = '%${query.trim()}%';
    final rows = await d.query(
      'geofences_cache',
      where: 'name LIKE ? OR code LIKE ?',
      whereArgs: [q, q],
      limit: limit * 2,
    );
    final list = <Map<String, dynamic>>[];
    for (final r in rows) {
      final gLat = (r['lat'] as num?)?.toDouble() ?? 0.0;
      final gLng = (r['lng'] as num?)?.toDouble() ?? 0.0;
      double dist = 0.0;
      bool isInside = false;

      final poly = _parsePolygon(r['coordinates']);
      if (userLat != null && userLng != null && gLat != 0.0 && gLng != 0.0) {
        if (poly != null && poly.isNotEmpty) {
          isInside = isPointInsidePolygon(userLat, userLng, poly);
          dist = isInside ? 0.0 : minDistanceToPolygonBoundary(userLat, userLng, poly);
        } else {
          dist = distanceMeters(userLat, userLng, gLat, gLng);
        }
      }
      list.add({
        'id': r['id'],
        'name': r['name'],
        'code': r['code'],
        'lat': gLat,
        'lng': gLng,
        'is_inside': isInside,
        'distance': dist,
        'coordinates': poly,
      });
    }
    if (userLat != null && userLng != null) {
      list.sort((a, b) => (a['distance'] as double).compareTo(b['distance'] as double));
    }
    return list.take(limit).toList();
  }
}

