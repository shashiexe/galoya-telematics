import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../api.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';
import '../tracker.dart';
import 'login.dart';
import 'settings.dart';
import 'task_detail.dart';

/// Tasks are cached locally so the list works offline.
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
  bool _onDuty = false;
  bool _dutyBusy = false;
  String _name = '';
  List<Map<String, dynamic>> _tasks = [];
  int _pendingPts = 0, _pendingQ = 0;
  bool _offline = false;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _init();
    _timer = Timer.periodic(const Duration(seconds: 10), (_) => _refreshCounts());
  }

  @override
  void dispose() { _timer?.cancel(); super.dispose(); }

  Future<void> _init() async {
    final sp = await SharedPreferences.getInstance();
    setState(() {
      _name = sp.getString('name') ?? '';
      _onDuty = sp.getBool('tracking') ?? false;
    });
    _tasks = await TaskStore.cached();
    _refreshCounts();
    _reload();
  }

  Future<void> _refreshCounts() async {
    final p = await LocalDb.countPoints(), q = await LocalDb.countQueue();
    if (mounted) setState(() { _pendingPts = p; _pendingQ = q; });
  }

  Future<void> _reload() async {
    await Sync.run();
    try {
      final j = await Api.get('tasks');
      final list = (j['tasks'] as List).cast<Map<String, dynamic>>();
      // keep local optimistic statuses for photos that are still in the upload queue
      final old = {for (final t in await TaskStore.cached()) t['id']: t};
      final queuedIds = (await LocalDb.queue()).where((q) => q['kind'] == 'photo')
          .map((q) => jsonDecode(q['payload'] as String)['task_id'].toString()).toSet();
      for (final t in list) {
        if (queuedIds.contains(t['id'].toString()) && old[t['id']] != null) t['status'] = old[t['id']]!['status'];
      }
      await TaskStore.save(list);
      if (mounted) setState(() { _tasks = list; _offline = false; });
    } on ApiException catch (e) {
      if (e.status == 401) return _logout();
      if (mounted) setState(() => _offline = true);
    } catch (_) {
      if (mounted) setState(() => _offline = true);
    }
    _refreshCounts();
  }

  Future<void> _toggleDuty() async {
    setState(() => _dutyBusy = true);
    try {
      final going = !_onDuty;
      if (going && !await Tracker.ensurePermissions()) {
        _snack('Location permission is required (choose "Allow all the time")');
        return;
      }
      Position? pos;
      try { pos = await Geolocator.getCurrentPosition(timeLimit: const Duration(seconds: 10)); } catch (_) {}
      await LocalDb.enqueue('duty', jsonEncode({
        'client_id': const Uuid().v4(), 'status': going ? 'on' : 'off',
        'lat': pos?.latitude, 'lng': pos?.longitude, 't': DateTime.now().millisecondsSinceEpoch,
      }));
      if (going) {
        if (pos != null) await Tracker.recordPosition(pos);
        await Tracker.start();
      } else {
        await Tracker.stop();
      }
      setState(() => _onDuty = going);
      Sync.run().then((_) => _refreshCounts());
    } finally {
      if (mounted) setState(() => _dutyBusy = false);
    }
  }

  Future<void> _logout() async {
    if (_pendingPts + _pendingQ > 0) {
      final ok = await showDialog<bool>(context: context, builder: (c) => AlertDialog(
        title: const Text('Unsynced data'),
        content: Text('$_pendingPts locations and $_pendingQ actions are not uploaded yet. Logging out will keep them, but they upload only after you log in again.'),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
                  TextButton(onPressed: () => Navigator.pop(c, true), child: const Text('Logout'))],
      ));
      if (ok != true) return;
    }
    await Tracker.stop();
    try { await Api.post('logout', {}); } catch (_) {}
    final sp = await SharedPreferences.getInstance();
    await sp.remove('token');
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  @override
  Widget build(BuildContext context) {
    final open = _tasks.where((t) => t['status'] == 'pending' || t['status'] == 'in_progress').toList();
    final done = _tasks.where((t) => t['status'] == 'done').toList();
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Hello,', style: TextStyle(fontSize: 14, color: AppColors.mut)),
          Text(_name),
        ]),
        actions: [
          IconButton(icon: const Icon(Icons.settings), onPressed: () async {
            await Navigator.push(context, MaterialPageRoute(builder: (_) => const SettingsScreen()));
            _refreshCounts();
          }),
          IconButton(icon: const Icon(Icons.logout), onPressed: _logout),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          _dutyCard(),
          const SizedBox(height: 12),
          _syncBar(),
          const SizedBox(height: 22),
          Text('My tasks (${open.length})', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
          const SizedBox(height: 10),
          if (open.isEmpty) const Padding(padding: EdgeInsets.all(24),
              child: Center(child: Text('No open tasks 🎉', style: TextStyle(color: AppColors.mut)))),
          ...open.map(_taskTile),
          if (done.isNotEmpty) ...[
            const SizedBox(height: 18),
            const Text('Recently completed', style: TextStyle(fontSize: 16, color: AppColors.mut)),
            const SizedBox(height: 8),
            ...done.map(_taskTile),
          ],
        ]),
      ),
    );
  }

  Widget _dutyCard() => AnimatedContainer(
        duration: const Duration(milliseconds: 400),
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(20),
          gradient: _onDuty
              ? const LinearGradient(colors: [Color(0xFF1FB58A), Color(0xFF2ED3A0)])
              : const LinearGradient(colors: [AppColors.panel2, AppColors.panel]),
          border: Border.all(color: _onDuty ? Colors.transparent : AppColors.line),
          boxShadow: _onDuty ? [BoxShadow(color: AppColors.ok.withOpacity(.35), blurRadius: 24, offset: const Offset(0, 10))] : [],
        ),
        child: Row(children: [
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(_onDuty ? 'ON DUTY' : 'OFF DUTY', style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w700, letterSpacing: 1)),
            const SizedBox(height: 4),
            Text(_onDuty ? 'Your location is being tracked' : 'Tap start when you begin work',
                style: TextStyle(color: Colors.white.withOpacity(.8))),
          ])),
          GestureDetector(
            onTap: _dutyBusy ? null : _toggleDuty,
            child: Container(
              width: 72, height: 72,
              decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withOpacity(_onDuty ? .25 : .08),
                  border: Border.all(color: Colors.white.withOpacity(.5), width: 2)),
              child: _dutyBusy
                  ? const Padding(padding: EdgeInsets.all(22), child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                  : Icon(_onDuty ? Icons.stop_rounded : Icons.play_arrow_rounded, size: 40),
            ),
          ),
        ]),
      );

  Widget _syncBar() {
    final pending = _pendingPts + _pendingQ;
    final color = _offline ? AppColors.warn : (pending > 0 ? AppColors.pri : AppColors.ok);
    final text = _offline
        ? 'Offline · $pending items saved on phone'
        : pending > 0 ? 'Uploading $pending items…' : 'All data synced';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(color: color.withOpacity(.12), borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        Icon(_offline ? Icons.cloud_off : (pending > 0 ? Icons.cloud_upload : Icons.cloud_done), color: color, size: 20),
        const SizedBox(width: 10),
        Expanded(child: Text(text, style: TextStyle(color: color))),
        if (pending > 0) TextButton(onPressed: _reload, child: const Text('Sync now')),
      ]),
    );
  }

  Widget _taskTile(Map<String, dynamic> t) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Card(
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: () async {
              await Navigator.push(context, MaterialPageRoute(builder: (_) => TaskDetailScreen(task: t)));
              _tasks = await TaskStore.cached();
              setState(() {});
              _reload();
            },
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(children: [
                Container(
                  width: 44, height: 44,
                  decoration: BoxDecoration(gradient: t['status'] == 'done' ? null : AppColors.grad,
                      color: t['status'] == 'done' ? AppColors.ok.withOpacity(.2) : null, borderRadius: BorderRadius.circular(12)),
                  child: Icon(t['status'] == 'done' ? Icons.check : Icons.assignment, color: Colors.white),
                ),
                const SizedBox(width: 14),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(t['title'] ?? '', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  if ((t['address'] ?? '').toString().isNotEmpty)
                    Text(t['address'], style: const TextStyle(color: AppColors.mut), maxLines: 1, overflow: TextOverflow.ellipsis),
                  if (t['due_date'] != null) Text('Due ${t['due_date']}', style: const TextStyle(color: AppColors.mut, fontSize: 12)),
                ])),
                statusChip(t['status']),
              ]),
            ),
          ),
        ),
      );
}
