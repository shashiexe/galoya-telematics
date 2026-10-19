import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';
import '../db.dart';
import '../sync.dart';
import '../theme.dart';
import '../updater.dart';

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
        Card(
          color: const Color(0xff1e293b),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(4),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Image.asset('assets/logo.png', width: 36, height: 36, fit: BoxFit.contain),
                      ),
                    ),
                    const SizedBox(width: 12),
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('GalOya Track360', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15)),
                          Text('People • Field • Fleet', style: TextStyle(color: Color(0xff22c55e), fontSize: 11, fontWeight: FontWeight.w700)),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(color: const Color(0xff064e3b), borderRadius: BorderRadius.circular(8), border: Border.all(color: const Color(0xff10b981), width: 0.8)),
                      child: const Text('v$kAppVersion', style: TextStyle(color: Color(0xff34d399), fontSize: 11, fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
                const Divider(color: Color(0xff334155), height: 24),
                const Text('Created by GOPL IT', style: TextStyle(color: Color(0xffa7f3d0), fontSize: 12, fontWeight: FontWeight.bold)),
                const SizedBox(height: 2),
                const Text('Gal-Oya Plantations (Pvt) Limited', style: TextStyle(color: Color(0xff64748b), fontSize: 11)),
                const SizedBox(height: 14),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xff0284c7),
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      padding: const EdgeInsets.symmetric(vertical: 11),
                    ),
                    icon: const Icon(Icons.system_update, size: 18),
                    label: const Text('Check for Updates', style: TextStyle(fontWeight: FontWeight.bold)),
                    onPressed: () => AppUpdater.checkForUpdates(context),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 20),
        Center(child: Text('Server: $kServerBase', style: const TextStyle(color: AppColors.mut, fontSize: 12))),
      ]),
    );
  }
}
