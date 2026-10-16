import 'dart:io';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import '../theme.dart';
import 'home.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;
  String? _err;

  Future<void> _login() async {
    setState(() { _busy = true; _err = null; });
    try {
      final sp = await SharedPreferences.getInstance();
      String? deviceUuid = sp.getString('app_device_uuid');
      if (deviceUuid == null) {
        deviceUuid = '8661920' + DateTime.now().millisecondsSinceEpoch.toString().substring(5);
        await sp.setString('app_device_uuid', deviceUuid);
      }

      final j = await Api.post('login', {
        'phone': _phone.text.trim(),
        'password': _pass.text,
        'device_model': 'Android Phone (${Platform.operatingSystem})',
        'unique_id': deviceUuid,
      }, auth: false);

      await sp.setString('token', j['token']);
      await sp.setString('name', j['user']['name'] ?? 'Staff');
      if (j['user']['code'] != null) await sp.setString('code', j['user']['code']);
      await sp.setString('phone', _phone.text.trim());
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const HomeScreen()));
    } catch (e) {
      setState(() => _err = e is ApiException ? e.message : 'No internet connection');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: RadialGradient(center: Alignment(.8, -1), radius: 1.2, colors: [Color(0x552A3A80), AppColors.bg]),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(28),
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: 1), duration: const Duration(milliseconds: 600), curve: Curves.easeOutCubic,
                builder: (_, v, child) => Opacity(opacity: v, child: Transform.translate(offset: Offset(0, 20 * (1 - v)), child: child)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Container(
                    width: 72, height: 72, alignment: Alignment.center,
                    decoration: BoxDecoration(gradient: AppColors.grad, borderRadius: BorderRadius.circular(22),
                        boxShadow: [BoxShadow(color: AppColors.pri.withOpacity(.5), blurRadius: 30)]),
                    child: const Icon(Icons.my_location, color: Colors.white, size: 36),
                  ),
                  const SizedBox(height: 24),
                  const Text('StaffTrack', style: TextStyle(fontSize: 32, fontWeight: FontWeight.w700)),
                  const Text('Sign in to start your duty', style: TextStyle(color: AppColors.mut, fontSize: 16)),
                  const SizedBox(height: 32),
                  TextField(controller: _phone, keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(labelText: 'Phone number', prefixIcon: Icon(Icons.phone))),
                  const SizedBox(height: 14),
                  TextField(controller: _pass, obscureText: true, onSubmitted: (_) => _login(),
                      decoration: const InputDecoration(labelText: 'Password', prefixIcon: Icon(Icons.lock))),
                  if (_err != null) Padding(padding: const EdgeInsets.only(top: 14),
                      child: Text(_err!, style: const TextStyle(color: AppColors.bad))),
                  const SizedBox(height: 24),
                  GradButton(label: 'Sign in', icon: Icons.login, busy: _busy, onTap: _login),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
