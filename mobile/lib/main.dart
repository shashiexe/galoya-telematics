import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'screens/home.dart';
import 'screens/login.dart';
import 'sync.dart';
import 'theme.dart';
import 'tracker.dart';
import 'vault.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Ensure SSL validation issues with local/cPanel certificates are bypassed
  try {
    HttpOverrides.global = AppHttpOverrides();
  } catch (e) {
    debugPrint('HttpOverrides error: $e');
  }

  bool loggedIn = false;
  try {
    // Auto-restore session from persistent vault if phone cache/storage was cleared
    await AuthVault.restoreIfMissing().catchError((_) => false);
    final sp = await SharedPreferences.getInstance();
    loggedIn = sp.getString('token') != null;
  } catch (e) {
    debugPrint('AuthVault/Prefs init error: $e');
  }

  // Safely initialize background tracker plugin without crashing app launch
  try {
    await Tracker.init();
  } catch (e) {
    debugPrint('Tracker init error: $e');
  }

  // Upload offline data the moment internet comes back.
  try {
    Connectivity().onConnectivityChanged.listen((r) {
      if (!r.contains(ConnectivityResult.none)) Sync.run();
    });
  } catch (e) {
    debugPrint('Connectivity listen error: $e');
  }

  runApp(StaffTrackApp(loggedIn: loggedIn));

  // Resume tracking safely AFTER UI is mounted and activity is in foreground
  if (loggedIn) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Tracker.resumeIfNeeded().catchError((e) {
        debugPrint('Safe resume error: $e');
      });
    });
  }
}

class StaffTrackApp extends StatelessWidget {
  final bool loggedIn;
  const StaffTrackApp({super.key, required this.loggedIn});
  @override
  Widget build(BuildContext context) => MaterialApp(
        title: 'StaffTrack',
        debugShowCheckedModeBanner: false,
        theme: buildTheme(),
        home: loggedIn ? const HomeScreen() : const LoginScreen(),
      );
}
