import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:uuid/uuid.dart';

import '../config.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';
import 'home.dart';

class TaskDetailScreen extends StatefulWidget {
  final Map<String, dynamic> task;
  const TaskDetailScreen({super.key, required this.task});
  @override
  State<TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends State<TaskDetailScreen> {
  late Map<String, dynamic> t = Map.of(widget.task);
  final _note = TextEditingController();
  bool _busy = false;

  /// Take photo with camera only (no gallery = no old photos), store in app dir, queue for upload.
  Future<void> _capture(String type) async {
    final shot = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 70, maxWidth: 1600);
    if (shot == null) return;
    setState(() => _busy = true);
    try {
      Position? pos;
      try { pos = await Geolocator.getCurrentPosition(timeLimit: const Duration(seconds: 10)); } catch (_) {}
      final dir = Directory(p.join((await getApplicationDocumentsDirectory()).path, 'pending_photos'));
      await dir.create(recursive: true);
      final cid = const Uuid().v4();
      final saved = await File(shot.path).copy(p.join(dir.path, '$cid.jpg'));
      await LocalDb.enqueue('photo', jsonEncode({
        'task_id': t['id'], 'type': type, 'client_id': cid,
        'lat': pos?.latitude, 'lng': pos?.longitude, 't': DateTime.now().millisecondsSinceEpoch,
        if (type == 'after') 'note': _note.text,
      }), file: saved.path);

      final changes = type == 'before'
          ? {'status': 'in_progress', 'local_before': saved.path}
          : {'status': 'done', 'local_after': saved.path};
      await TaskStore.patch(t['id'], changes);
      setState(() => t.addAll(changes));
      Sync.run();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(
            type == 'before' ? 'Task started ✓ (photo will upload automatically)' : 'Task completed ✓')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _photo(String label, String? local, String? remote) {
    Widget img;
    if (local != null && File(local).existsSync()) {
      img = Image.file(File(local), fit: BoxFit.cover);
    } else if (remote != null) {
      img = Image.network(photoUrl(remote), fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => const Icon(Icons.broken_image, color: AppColors.mut));
    } else {
      img = const Center(child: Icon(Icons.photo_camera_outlined, color: AppColors.mut, size: 36));
    }
    return Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(label, style: const TextStyle(color: AppColors.mut)),
      const SizedBox(height: 6),
      AspectRatio(aspectRatio: 3 / 4, child: ClipRRect(borderRadius: BorderRadius.circular(14),
          child: Container(color: AppColors.panel, child: img))),
    ]));
  }

  @override
  Widget build(BuildContext context) {
    final status = t['status'] as String;
    return Scaffold(
      appBar: AppBar(title: Text('Task #${t['id']}')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          Expanded(child: Text(t['title'] ?? '', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700))),
          statusChip(status),
        ]),
        if ((t['description'] ?? '').toString().isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(t['description'], style: const TextStyle(color: AppColors.mut, fontSize: 15)),
        ],
        if ((t['address'] ?? '').toString().isNotEmpty || t['lat'] != null) ...[
          const SizedBox(height: 12),
          Card(child: ListTile(
            leading: const Icon(Icons.place, color: AppColors.pri),
            title: Text(t['address'] ?? 'Task location'),
            trailing: t['lat'] != null ? const Icon(Icons.directions) : null,
            onTap: t['lat'] == null ? null : () => launchUrl(
                Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${t['lat']},${t['lng']}'),
                mode: LaunchMode.externalApplication),
          )),
        ],
        const SizedBox(height: 20),
        Row(children: [
          _photo('Before', t['local_before'], t['before_photo']),
          const SizedBox(width: 12),
          _photo('After', t['local_after'], t['after_photo']),
        ]),
        const SizedBox(height: 24),
        if (status == 'pending')
          GradButton(label: 'Start – take BEFORE photo', icon: Icons.camera_alt, busy: _busy, onTap: () => _capture('before')),
        if (status == 'in_progress') ...[
          TextField(controller: _note, maxLines: 3, decoration: const InputDecoration(labelText: 'Note (optional)')),
          const SizedBox(height: 14),
          GradButton(
            label: 'Finish – take AFTER photo', icon: Icons.check_circle, busy: _busy,
            gradient: const LinearGradient(colors: [Color(0xFF1FB58A), AppColors.ok]),
            onTap: () => _capture('after'),
          ),
        ],
        if (status == 'done')
          const Center(child: Text('✓ Task completed', style: TextStyle(color: AppColors.ok, fontSize: 18))),
      ]),
    );
  }
}
