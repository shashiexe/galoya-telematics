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
      await _points();
      await _queue();
    } catch (_) {
      // offline – will retry on the next tick / connectivity change
    } finally {
      _running = false;
    }
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
