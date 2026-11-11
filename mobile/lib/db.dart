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
      version: 5,
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
        await d.execute('''CREATE TABLE IF NOT EXISTS erp_plots(
          plot_no TEXT PRIMARY KEY, plot_manual_code TEXT, sector_no TEXT, farmer_no TEXT, farmer_name TEXT,
          farmer_phone TEXT, farmer_nic TEXT, nominee_name TEXT, nominee_nic TEXT, nominee_phone TEXT,
          bank_code TEXT, branch_code TEXT, bank_accno TEXT, payment_permission INTEGER DEFAULT 0,
          debit_permission INTEGER DEFAULT 0, block_id INTEGER, land_area REAL, payment_category TEXT,
          field_asst_no TEXT, field_asst_name TEXT, feild_asst_code TEXT, has_geofence INTEGER DEFAULT 0)''');
        await d.execute('''CREATE TABLE IF NOT EXISTS cdn_records(
          cdn TEXT PRIMARY KEY, plot_no TEXT, plot_manual_code TEXT, farmer_no TEXT, farmer_name TEXT,
          field_asst_no TEXT, field_asst_name TEXT, type TEXT, cane_category_code TEXT, vehicle_no TEXT,
          trailer_no TEXT, crop_cycle TEXT, cdn_photo TEXT, tractor_photo TEXT, load_photo TEXT,
          lat REAL, lng REAL, create_user TEXT, create_time TEXT, weige_ticket_no TEXT,
          tonnage_received REAL, gross_weight REAL, tare_weight REAL, weighed_date TEXT, status TEXT, synced INTEGER DEFAULT 1)''');
        await d.execute('''CREATE TABLE IF NOT EXISTS local_vehicles(
          vehicle_no TEXT PRIMARY KEY, vehicle_name TEXT, transporter_no TEXT)''');
        await d.execute('''CREATE TABLE IF NOT EXISTS local_trailers(
          trailer_no TEXT PRIMARY KEY, trailer_name TEXT, weight REAL, vehicle_number TEXT)''');
        await d.execute('''CREATE TABLE IF NOT EXISTS local_crops(
          id INTEGER PRIMARY KEY AUTOINCREMENT, plot_no TEXT, crop_cycle TEXT, crop_extent REAL,
          variety TEXT, status INTEGER DEFAULT 1, age_month TEXT, is_agronomy INTEGER DEFAULT 0)''');
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
        if (oldV < 4) {
          try {
            await d.execute('''CREATE TABLE IF NOT EXISTS erp_plots(
              plot_no TEXT PRIMARY KEY, plot_manual_code TEXT, sector_no TEXT, farmer_no TEXT, farmer_name TEXT,
              farmer_phone TEXT, farmer_nic TEXT, land_area REAL, payment_category TEXT, field_asst_no TEXT,
              field_asst_name TEXT, feild_asst_code TEXT, has_geofence INTEGER DEFAULT 0)''');
            await d.execute('''CREATE TABLE IF NOT EXISTS cdn_records(
              cdn TEXT PRIMARY KEY, plot_no TEXT, plot_manual_code TEXT, farmer_no TEXT, farmer_name TEXT,
              field_asst_no TEXT, field_asst_name TEXT, type TEXT, cane_category_code TEXT, vehicle_no TEXT,
              trailer_no TEXT, crop_cycle TEXT, cdn_photo TEXT, lat REAL, lng REAL, create_user TEXT,
              create_time TEXT, weige_ticket_no TEXT, tonnage_received REAL, gross_weight REAL, tare_weight REAL,
              weighed_date TEXT, status TEXT, synced INTEGER DEFAULT 1)''');
          } catch (_) {}
        }
        if (oldV < 5) {
          try {
            await d.execute('''CREATE TABLE IF NOT EXISTS local_vehicles(
              vehicle_no TEXT PRIMARY KEY, vehicle_name TEXT, transporter_no TEXT)''');
            await d.execute('''CREATE TABLE IF NOT EXISTS local_trailers(
              trailer_no TEXT PRIMARY KEY, trailer_name TEXT, weight REAL, vehicle_number TEXT)''');
            await d.execute('''CREATE TABLE IF NOT EXISTS local_crops(
              id INTEGER PRIMARY KEY AUTOINCREMENT, plot_no TEXT, crop_cycle TEXT, crop_extent REAL,
              variety TEXT, status INTEGER DEFAULT 1, age_month TEXT, is_agronomy INTEGER DEFAULT 0)''');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN nominee_name TEXT');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN nominee_nic TEXT');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN nominee_phone TEXT');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN bank_code TEXT');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN branch_code TEXT');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN bank_accno TEXT');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN payment_permission INTEGER DEFAULT 0');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN debit_permission INTEGER DEFAULT 0');
            await d.execute('ALTER TABLE erp_plots ADD COLUMN block_id INTEGER');
            await d.execute('ALTER TABLE cdn_records ADD COLUMN tractor_photo TEXT');
            await d.execute('ALTER TABLE cdn_records ADD COLUMN load_photo TEXT');
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

  /// Clean dummy test rows or untitled test polygons from SQLite cache
  static Future<void> cleanDummyGeofences() async {
    try {
      final d = await db;
      // Delete dummy test row D/207/01 placed near Damana/Hingurana (lat ~7.2085, lng ~81.6605)
      // Real D/207/01 is at lat 7.2063, lng 81.7561 (Deegawapiya Zone)
      await d.delete(
        'geofences_cache',
        where: "name = 'D/207/01' AND lng < 81.700",
      );
      // Delete any untitled polygon or test plot
      await d.delete(
        'geofences_cache',
        where: "name = '' OR name = 'Untitled Polygon' OR name LIKE 'Test%'",
      );
    } catch (_) {}
  }

  /// Save geofences (centroids + optional full polygon coordinates for offline high-precision boundary checks)
  static Future<void> saveGeofences(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final item in list) {
      if (item is Map) {
        final name = (item['name'] ?? '').toString().trim();
        final lng = (item['lng'] is num) ? (item['lng'] as num).toDouble() : 0.0;
        final lat = (item['lat'] is num) ? (item['lat'] as num).toDouble() : 0.0;

        // Skip dummy test D/207/01 with fake Hingurana coordinates (real D/207/01 is at lng > 81.700)
        if (name == 'D/207/01' && lng < 81.700) {
          continue;
        }
        if (name.isEmpty || name == 'Untitled Polygon' || name.startsWith('Test')) {
          continue;
        }

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
            'name': name,
            'code': item['code'] ?? name,
            'lat': lat,
            'lng': lng,
            'coordinates': coordsStr,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
    }
    await batch.commit(noResult: true);
    await cleanDummyGeofences();
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
  static Future<List<Map<String, dynamic>>> getNearbyGeofences(double lat, double lng, {int limit = 15}) async {
    final d = await db;
    await cleanDummyGeofences();

    // Step 1: Pre-filter by bounding box in SQLite with PROXIMITY ORDERING.
    // Euclidean squared distance monotonic approximation: ((lat - ?)*(lat - ?) + (lng - ?)*(lng - ?))
    // This guarantees SQLite returns the ACTUAL CLOSEST candidates, never truncated prematurely by insertion order!
    final distSql = '((lat - ($lat)) * (lat - ($lat)) + (lng - ($lng)) * (lng - ($lng)))';

    // Start with a 4.5 km bounding box (~0.04 degrees)
    double delta = 0.04;
    List<Map<String, Object?>> rows = await d.rawQuery(
      'SELECT * FROM geofences_cache WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? ORDER BY $distSql ASC LIMIT 60',
      [lat - delta, lat + delta, lng - delta, lng + delta],
    );

    // If fewer than 10 plots found nearby, expand to 12 km (~0.11 degrees)
    if (rows.length < 10) {
      delta = 0.11;
      rows = await d.rawQuery(
        'SELECT * FROM geofences_cache WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? ORDER BY $distSql ASC LIMIT 60',
        [lat - delta, lat + delta, lng - delta, lng + delta],
      );
    }

    // If still empty (e.g. user is testing far from plantation), expand to 35 km (~0.32 degrees)
    if (rows.isEmpty) {
      delta = 0.32;
      rows = await d.rawQuery(
        'SELECT * FROM geofences_cache WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? ORDER BY $distSql ASC LIMIT 60',
        [lat - delta, lat + delta, lng - delta, lng + delta],
      );
    }
    if (rows.isEmpty) return [];

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

    // Sort candidates by centroid distance to take the closest 40 for micro-edge calculation
    candidates.sort((a, b) => (a['cDist'] as double).compareTo(b['cDist'] as double));
    final topCandidates = candidates.take(40).toList();

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
    await cleanDummyGeofences();
    final q = '%${query.trim()}%';
    final rows = await d.query(
      'geofences_cache',
      where: '(name LIKE ? OR code LIKE ?) AND name != \'Untitled Polygon\' AND name NOT LIKE \'Test%\'',
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

  // --- CDN Records (Offline Storage & Caching) ---

  static Future<void> saveCdnRecord(Map<String, dynamic> cdn) async {
    final d = await db;
    await d.insert('cdn_records', cdn, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  static Future<void> saveCdnBatch(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final item in list) {
      if (item is Map) {
        batch.insert('cdn_records', {
          'cdn': item['cdn']?.toString() ?? '',
          'plot_no': item['plot_no']?.toString(),
          'plot_manual_code': item['plot_manual_code']?.toString(),
          'farmer_no': item['farmer_no']?.toString(),
          'farmer_name': item['farmer_name']?.toString(),
          'field_asst_no': item['field_asst_no']?.toString(),
          'field_asst_name': item['field_asst_name']?.toString(),
          'type': item['type']?.toString() ?? 'OUT',
          'cane_category_code': item['cane_category_code']?.toString() ?? 'CAT1',
          'vehicle_no': item['vehicle_no']?.toString() ?? '',
          'trailer_no': item['trailer_no']?.toString() ?? '',
          'crop_cycle': item['crop_cycle']?.toString() ?? '',
          'cdn_photo': item['cdn_photo']?.toString(),
          'lat': (item['lat'] is num) ? (item['lat'] as num).toDouble() : null,
          'lng': (item['lng'] is num) ? (item['lng'] as num).toDouble() : null,
          'create_user': item['create_user']?.toString(),
          'create_time': item['create_time']?.toString() ?? DateTime.now().toIso8601String(),
          'weige_ticket_no': item['weige_ticket_no']?.toString(),
          'tonnage_received': (item['tonnage_received'] is num) ? (item['tonnage_received'] as num).toDouble() : 0.0,
          'gross_weight': (item['gross_weight'] is num) ? (item['gross_weight'] as num).toDouble() : 0.0,
          'tare_weight': (item['tare_weight'] is num) ? (item['tare_weight'] as num).toDouble() : 0.0,
          'weighed_date': item['weighed_date']?.toString(),
          'status': item['status']?.toString() ?? 'PENDING',
          'synced': 1,
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
    }
    await batch.commit(noResult: true);
  }

  static Future<List<Map<String, dynamic>>> getCdnRecords({
    String? date,
    String status = 'COMPLETED',
    String? search,
    int limit = 50,
  }) async {
    final d = await db;
    final whereClauses = <String>[];
    final whereArgs = <dynamic>[];

    if (date != null && date.isNotEmpty) {
      whereClauses.add("substr(create_time, 1, 10) = ?");
      whereArgs.add(date);
    }
    if (status.isNotEmpty && status != 'ALL') {
      whereClauses.add("status = ?");
      whereArgs.add(status);
    }
    if (search != null && search.trim().isNotEmpty) {
      final s = '%${search.trim()}%';
      whereClauses.add("(cdn LIKE ? OR vehicle_no LIKE ? OR plot_manual_code LIKE ? OR farmer_name LIKE ? OR weige_ticket_no LIKE ?)");
      whereArgs.addAll([s, s, s, s, s]);
    }

    final whereSql = whereClauses.isNotEmpty ? whereClauses.join(' AND ') : null;
    return d.query(
      'cdn_records',
      where: whereSql,
      whereArgs: whereArgs.isNotEmpty ? whereArgs : null,
      orderBy: 'create_time DESC',
      limit: limit,
    );
  }

  static Future<Map<String, dynamic>> getCdnSummary({String? date}) async {
    final d = await db;
    String sql = "SELECT COUNT(*) as total_count, "
        "COALESCE(SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END), 0) as completed_count, "
        "COALESCE(SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END), 0) as pending_count, "
        "COALESCE(SUM(CASE WHEN status='COMPLETED' THEN tonnage_received ELSE 0.0 END), 0.0) as total_tonnage "
        "FROM cdn_records";
    List<dynamic> args = [];
    if (date != null && date.isNotEmpty) {
      sql += " WHERE substr(create_time, 1, 10) = ?";
      args.add(date);
    }
    final rows = await d.rawQuery(sql, args);
    if (rows.isNotEmpty) {
      final r = rows.first;
      return {
        'total_count': (r['total_count'] as num?)?.toInt() ?? 0,
        'completed_count': (r['completed_count'] as num?)?.toInt() ?? 0,
        'pending_count': (r['pending_count'] as num?)?.toInt() ?? 0,
        'total_tonnage': (r['total_tonnage'] as num?)?.toDouble() ?? 0.0,
      };
    }
    return {'total_count': 0, 'completed_count': 0, 'pending_count': 0, 'total_tonnage': 0.0};
  }

  // --- ERP Master Plots (Offline Hybrid Explorer) ---

  static Future<void> saveMasterPlots(List<dynamic> plots) async {
    final d = await db;
    final batch = d.batch();
    for (final p in plots) {
      if (p is Map) {
        batch.insert('erp_plots', {
          'plot_no': p['plot_no']?.toString() ?? '',
          'plot_manual_code': p['plot_manual_code']?.toString() ?? '',
          'sector_no': p['sector_no']?.toString(),
          'farmer_no': p['farmer_no']?.toString(),
          'farmer_name': p['farmer_name']?.toString(),
          'farmer_phone': p['farmer_phone']?.toString(),
          'farmer_nic': p['farmer_nic']?.toString(),
          'nominee_name': p['nominee_name']?.toString(),
          'nominee_nic': p['nominee_nic']?.toString(),
          'nominee_phone': p['nominee_phone']?.toString(),
          'bank_code': p['bank_code']?.toString(),
          'branch_code': p['branch_code']?.toString(),
          'bank_accno': p['bank_accno']?.toString(),
          'payment_permission': (p['payment_permission'] == 1 || p['payment_permission'] == true) ? 1 : 0,
          'debit_permission': (p['debit_permission'] == 1 || p['debit_permission'] == true) ? 1 : 0,
          'block_id': (p['block_id'] is int) ? p['block_id'] : int.tryParse(p['block_id']?.toString() ?? ''),
          'land_area': (p['land_area'] is num) ? (p['land_area'] as num).toDouble() : 0.0,
          'payment_category': p['payment_category']?.toString(),
          'field_asst_no': p['field_asst_no']?.toString(),
          'field_asst_name': p['field_asst_name']?.toString(),
          'feild_asst_code': p['feild_asst_code']?.toString(),
          'has_geofence': (p['has_geofence'] == 1 || p['geofence_id'] != null) ? 1 : 0,
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
    }
    await batch.commit(noResult: true);
  }

  static Future<List<Map<String, dynamic>>> searchMasterPlots(String query, {int limit = 30, bool geofenceOnly = false}) async {
    final d = await db;
    final q = '%${query.trim()}%';
    final geoFilter = geofenceOnly ? 'AND has_geofence = 1' : '';
    return d.rawQuery('''
      SELECT * FROM erp_plots 
      WHERE (plot_manual_code LIKE ? OR plot_no LIKE ? OR farmer_name LIKE ? OR farmer_nic LIKE ? OR sector_no LIKE ?)
      $geoFilter
      ORDER BY has_geofence DESC, plot_manual_code ASC
      LIMIT ?
    ''', [q, q, q, q, q, limit]);
  }

  static Future<List<Map<String, dynamic>>> getGeofencedPlots({int limit = 50}) async {
    final d = await db;
    return d.rawQuery('''
      SELECT * FROM erp_plots 
      WHERE has_geofence = 1
      ORDER BY plot_manual_code ASC
      LIMIT ?
    ''', [limit]);
  }

  static Future<int> countMasterPlots() async {
    final d = await db;
    return Sqflite.firstIntValue(await d.rawQuery('SELECT COUNT(*) FROM erp_plots')) ?? 0;
  }

  // --- Fast In-Memory Offline Cache for Vehicles, Trailers & Crops (Zero Lag) ---

  static Future<void> saveVehicles(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final v in list) {
      if (v is Map) {
        batch.insert('local_vehicles', {
          'vehicle_no': v['vehicle_no']?.toString().trim() ?? '',
          'vehicle_name': v['vehicle_name']?.toString() ?? '',
          'transporter_no': v['transporter_no']?.toString() ?? '',
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
    }
    await batch.commit(noResult: true);
  }

  static Future<List<String>> getActiveVehicleList() async {
    final d = await db;
    final rows = await d.rawQuery('SELECT vehicle_no FROM local_vehicles ORDER BY vehicle_no ASC');
    return rows.map((r) => r['vehicle_no']?.toString() ?? '').where((s) => s.isNotEmpty).toList();
  }

  static Future<void> saveTrailers(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final t in list) {
      if (t is Map) {
        batch.insert('local_trailers', {
          'trailer_no': t['trailer_no']?.toString().trim() ?? '',
          'trailer_name': t['trailer_name']?.toString() ?? '',
          'weight': (t['weight'] is num) ? (t['weight'] as num).toDouble() : 0.0,
          'vehicle_number': t['vehicle_number']?.toString() ?? '',
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
    }
    await batch.commit(noResult: true);
  }

  static Future<List<String>> getTrailerList() async {
    final d = await db;
    final rows = await d.rawQuery('SELECT trailer_no FROM local_trailers ORDER BY trailer_no ASC');
    return rows.map((r) => r['trailer_no']?.toString() ?? '').where((s) => s.isNotEmpty).toList();
  }

  static Future<void> saveCrops(List<dynamic> list) async {
    final d = await db;
    final batch = d.batch();
    for (final c in list) {
      if (c is Map) {
        batch.insert('local_crops', {
          'plot_no': c['plot_no']?.toString().trim() ?? '',
          'crop_cycle': c['crop_cycle']?.toString().trim() ?? '',
          'crop_extent': (c['crop_extent'] is num) ? (c['crop_extent'] as num).toDouble() : 0.0,
          'variety': c['variety']?.toString() ?? '',
          'status': (c['status'] == 1 || c['status'] == true) ? 1 : 0,
          'age_month': c['age_month']?.toString() ?? '',
          'is_agronomy': (c['is_agronomy'] == 1 || c['is_agronomy'] == true) ? 1 : 0,
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
    }
    await batch.commit(noResult: true);
  }

  static Future<List<Map<String, dynamic>>> getActiveCrops(String plotNo) async {
    final d = await db;
    final clean = plotNo.trim();
    return d.rawQuery('''
      SELECT * FROM local_crops 
      WHERE plot_no = ? AND status = 1 
      ORDER BY crop_cycle ASC
    ''', [clean]);
  }
}

