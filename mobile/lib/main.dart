import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'screens/home.dart';
import 'screens/login.dart';
import 'sync.dart';
import 'theme.dart';
import 'tracker.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Tracker.init();
  final sp = await SharedPreferences.getInstance();
  final loggedIn = sp.getString('token') != null;
  if (loggedIn) await Tracker.resumeIfNeeded();

  // Upload offline data the moment internet comes back.
  Connectivity().onConnectivityChanged.listen((r) {
    if (!r.contains(ConnectivityResult.none)) Sync.run();
  });

  runApp(StaffTrackApp(loggedIn: loggedIn));
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
