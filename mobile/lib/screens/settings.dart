import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});
  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  static const _options = {15: '15 seconds', 30: '30 seconds', 60: '1 minute', 120: '2 minutes', 300: '5 minutes', 600: '10 minutes'};
  int _server = kDefaultIntervalSec;
  int? _mine;
  bool _allow = true;
  int _pts = 0, _q = 0;

  @override
  void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    final sp = await SharedPreferences.getInstance();
    await sp.reload();
    final pts = await LocalDb.countPoints(), q = await LocalDb.countQueue();
    setState(() {
      _server = sp.getInt('server_interval') ?? kDefaultIntervalSec;
      _allow = sp.getBool('allow_change') ?? true;
      _mine = sp.getInt('user_interval');
      _pts = pts; _q = q;
    });
  }

  Future<void> _set(int? v) async {
    final sp = await SharedPreferences.getInstance();
    if (v == null) { await sp.remove('user_interval'); } else { await sp.setInt('user_interval', v); }
    setState(() => _mine = v);
  }

  @override
  Widget build(BuildContext context) {
    final effective = (_allow && _mine != null) ? _mine! : _server;
    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Text('Location interval', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
        const SizedBox(height: 4),
        Text('Currently sending location every ${_options[effective] ?? '$effective s'}',
            style: const TextStyle(color: AppColors.mut)),
        const SizedBox(height: 12),
        Card(child: Column(children: [
          RadioListTile<int?>(
            value: null, groupValue: _allow ? _mine : null,
            title: Text('Default (${_options[_server] ?? '$_server s'})'),
            subtitle: const Text('Set by your admin'),
            onChanged: _allow ? _set : null,
          ),
          ..._options.entries.map((e) => RadioListTile<int?>(
                value: e.key, groupValue: _allow ? _mine : null, title: Text(e.value),
                onChanged: _allow ? _set : null,
              )),
        ])),
        if (!_allow) const Padding(padding: EdgeInsets.only(top: 8),
            child: Text('Your admin has locked this setting.', style: TextStyle(color: AppColors.warn))),
        const SizedBox(height: 24),
        const Text('Offline data', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
        const SizedBox(height: 8),
        Card(child: Column(children: [
          ListTile(leading: const Icon(Icons.location_on), title: const Text('Locations waiting'), trailing: Text('$_pts')),
          ListTile(leading: const Icon(Icons.photo), title: const Text('Photos / actions waiting'), trailing: Text('$_q')),
          ListTile(leading: const Icon(Icons.sync, color: AppColors.pri), title: const Text('Upload now'),
              onTap: () async { await Sync.run(); await _load(); }),
        ])),
        const SizedBox(height: 24),
        Center(child: Text('Server: $kServerBase', style: const TextStyle(color: AppColors.mut, fontSize: 12))),
      ]),
    );
  }
}
