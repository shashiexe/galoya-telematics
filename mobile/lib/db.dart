import 'dart:math';
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
      version: 2,
      onCreate: (d, v) async {
        await d.execute('''CREATE TABLE points(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          lat REAL, lng REAL, accuracy REAL, speed REAL, battery INTEGER, t INTEGER)''');
        await d.execute('''CREATE TABLE queue(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          kind TEXT, payload TEXT, file TEXT, created INTEGER, tries INTEGER DEFAULT 0, last_error TEXT)''');
        await d.execute('CREATE TABLE tasks_cache(id INTEGER PRIMARY KEY, json TEXT)');
        await d.execute('''CREATE TABLE IF NOT EXISTS geofences_cache(
          id INTEGER PRIMARY KEY, name TEXT, code TEXT, lat REAL, lng REAL)''');
      },
      onUpgrade: (d, oldV, newV) async {
        if (oldV < 2) {
          await d.execute('''CREATE TABLE IF NOT EXISTS geofences_cache(
            id INTEGER PRIMARY KEY, name TEXT, code TEXT, lat REAL, lng REAL)''');
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

  /// Save lightweight geofences (only ID, name, code, lat, lng centroids)
  static Future<void> saveGeofences(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final item in list) {
      if (item is Map) {
        batch.insert(
          'geofences_cache',
          {
            'id': item['id'],
            'name': item['name'] ?? '',
            'code': item['code'] ?? '',
            'lat': (item['lat'] is num) ? (item['lat'] as num).toDouble() : 0.0,
            'lng': (item['lng'] is num) ? (item['lng'] as num).toDouble() : 0.0,
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
  static double _distanceMeters(double lat1, double lon1, double lat2, double lon2) {
    const r = 6371000.0; // Earth radius in meters
    final dLat = (lat2 - lat1) * (pi / 180.0);
    final dLon = (lon2 - lon1) * (pi / 180.0);
    final a = sin(dLat / 2) * sin(dLat / 2) +
        cos(lat1 * (pi / 180.0)) * cos(lat2 * (pi / 180.0)) * sin(dLon / 2) * sin(dLon / 2);
    final c = 2 * atan2(sqrt(a), sqrt(1 - a));
    return r * c;
  }

  /// Get nearest 5-8 plots close to the current location (sorted by distance)
  static Future<List<Map<String, dynamic>>> getNearbyGeofences(double lat, double lng, {int limit = 8}) async {
    final d = await db;
    final rows = await d.query('geofences_cache');
    if (rows.isEmpty) return [];

    final list = <Map<String, dynamic>>[];
    for (final r in rows) {
      final gLat = (r['lat'] as num?)?.toDouble() ?? 0.0;
      final gLng = (r['lng'] as num?)?.toDouble() ?? 0.0;
      if (gLat == 0.0 && gLng == 0.0) continue;

      final dist = _distanceMeters(lat, lng, gLat, gLng);
      list.add({
        'id': r['id'],
        'name': r['name'],
        'code': r['code'],
        'lat': gLat,
        'lng': gLng,
        'distance': dist,
      });
    }

    list.sort((a, b) => (a['distance'] as double).compareTo(b['distance'] as double));
    return list.take(limit).toList();
  }

  /// Search any plot by name or code
  static Future<List<Map<String, dynamic>>> searchGeofences(String query, {int limit = 10}) async {
    final d = await db;
    final q = '%${query.trim()}%';
    final rows = await d.query(
      'geofences_cache',
      where: 'name LIKE ? OR code LIKE ?',
      whereArgs: [q, q],
      limit: limit,
    );
    return rows.map((r) => Map<String, dynamic>.from(r)).toList();
  }
}
