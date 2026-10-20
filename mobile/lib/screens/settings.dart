import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';
import '../data_usage.dart';
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
  Map<String, dynamic> _dataStats = {};

  @override
  void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    final sp = await SharedPreferences.getInstance();
    await sp.reload();
    final pts = await LocalDb.countPoints(), q = await LocalDb.countQueue();
    final stats = await DataUsage.getStats();
    if (mounted) {
      setState(() {
        _server = sp.getInt('server_interval') ?? kDefaultIntervalSec;
        _allow = sp.getBool('allow_change') ?? true;
        _mine = sp.getInt('user_interval');
        _pts = pts; _q = q;
        _dataStats = stats;
      });
    }
  }

  Future<void> _set(int? v) async {
    final sp = await SharedPreferences.getInstance();
    if (v == null) { await sp.remove('user_interval'); } else { await sp.setInt('user_interval', v); }
    setState(() => _mine = v);
  }

  Future<void> _confirmResetData() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Reset Data Counter?'),
        content: const Text('This will reset your local data counter to 0 B for new monitoring. Are you sure?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.warn, foregroundColor: Colors.white),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Reset'),
          ),
        ],
      ),
    );
    if (ok == true) {
      await DataUsage.resetStats();
      await _load();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Data counter reset successfully.')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final effective = (_allow && _mine != null) ? _mine! : _server;
    final todayStr = _dataStats['today_formatted']?.toString() ?? '0 B';
    final monthStr = _dataStats['month_formatted']?.toString() ?? '0 B';
    final totalStr = _dataStats['total_formatted']?.toString() ?? '0 B';
    final sentStr = _dataStats['sent_formatted']?.toString() ?? '0 B';
    final recvStr = _dataStats['received_formatted']?.toString() ?? '0 B';

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
        // MOBILE DATA USAGE SECTION
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Row(
              children: [
                Icon(Icons.data_usage_rounded, color: Color(0xff0284c7), size: 22),
                SizedBox(width: 8),
                Text('Mobile Data Usage', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
              ],
            ),
            TextButton.icon(
              style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
              onPressed: _confirmResetData,
              icon: const Icon(Icons.restart_alt, size: 16, color: Color(0xff64748b)),
              label: const Text('Reset', style: TextStyle(color: Color(0xff64748b), fontSize: 12)),
            ),
          ],
        ),
        const SizedBox(height: 4),
        const Text('Cellular data consumed by GPS tracking & telemetry', style: TextStyle(color: AppColors.mut, fontSize: 12)),
        const SizedBox(height: 10),
        Card(
          elevation: 1,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                        decoration: BoxDecoration(color: const Color(0xfff0fdf4), borderRadius: BorderRadius.circular(10), border: Border.all(color: const Color(0xffbbf7d0))),
                        child: Column(
                          children: [
                            const Text('TODAY', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xff166534))),
                            const SizedBox(height: 4),
                            Text(todayStr, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: Color(0xff15803d))),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                        decoration: BoxDecoration(color: const Color(0xfff0f9ff), borderRadius: BorderRadius.circular(10), border: Border.all(color: const Color(0xffbae6fd))),
                        child: Column(
                          children: [
                            const Text('THIS MONTH', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xff075985))),
                            const SizedBox(height: 4),
                            Text(monthStr, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: Color(0xff0284c7))),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                        decoration: BoxDecoration(color: const Color(0xfff8fafc), borderRadius: BorderRadius.circular(10), border: Border.all(color: const Color(0xffe2e8f0))),
                        child: Column(
                          children: [
                            const Text('ALL-TIME', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xff475569))),
                            const SizedBox(height: 4),
                            Text(totalStr, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: Color(0xff1e293b))),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  decoration: BoxDecoration(color: const Color(0xfff8fafc), borderRadius: BorderRadius.circular(8)),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.arrow_upward, size: 14, color: Color(0xff059669)),
                          const SizedBox(width: 4),
                          Text('Sent: $sentStr', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xff334155))),
                        ],
                      ),
                      Container(width: 1, height: 14, color: const Color(0xffcbd5e1)),
                      Row(
                        children: [
                          const Icon(Icons.arrow_downward, size: 14, color: Color(0xff0284c7)),
                          const SizedBox(width: 4),
                          Text('Received: $recvStr', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Color(0xff334155))),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 8),
                const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.eco_rounded, size: 14, color: Color(0xff16a34a)),
                    SizedBox(width: 5),
                    Text('Ultra-low data mode • Est. ~1-2 MB / day', style: TextStyle(fontSize: 11, color: Color(0xff16a34a), fontWeight: FontWeight.w600)),
                  ],
                ),
              ],
            ),
          ),
        ),

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
