import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// Local offline storage: GPS points + pending actions (duty, photos).
class LocalDb {
  static Database? _db;

  static Future<Database> get db async {
    if (_db != null) return _db!;
    final dir = await getDatabasesPath();
    _db = await openDatabase(
      p.join(dir, 'stafftrack.db'),
      version: 1,
      onCreate: (d, v) async {
        await d.execute('''CREATE TABLE points(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          lat REAL, lng REAL, accuracy REAL, speed REAL, battery INTEGER, t INTEGER)''');
        await d.execute('''CREATE TABLE queue(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          kind TEXT, payload TEXT, file TEXT, created INTEGER, tries INTEGER DEFAULT 0, last_error TEXT)''');
        await d.execute('CREATE TABLE tasks_cache(id INTEGER PRIMARY KEY, json TEXT)');
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
}
