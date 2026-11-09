import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import 'home.dart';
import 'in_app_camera.dart';

class FaceEnrollmentScreen extends StatefulWidget {
  final bool isMandatory;
  const FaceEnrollmentScreen({super.key, this.isMandatory = false});

  @override
  State<FaceEnrollmentScreen> createState() => _FaceEnrollmentScreenState();
}

class _FaceEnrollmentScreenState extends State<FaceEnrollmentScreen>
    with SingleTickerProviderStateMixin {
  File? _capturedFace;
  bool _isEnrolling = false;
  int _currentStep = 1; // 1: Align, 2: Action Check (Blink/Smile), 3: Review & Submit
  String? _statusText;
  String _officerName = 'Field Officer';

  late AnimationController _pulseCtrl;
  late Animation<double> _pulseAnim;

  @override
  void initState() {
    super.initState();
    _pulseCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat(reverse: true);
    _pulseAnim = Tween<double>(begin: 0.96, end: 1.04).animate(
      CurvedAnimation(parent: _pulseCtrl, curve: Curves.easeInOut),
    );

    _loadOfficerInfo();
  }

  Future<void> _loadOfficerInfo() async {
    final sp = await SharedPreferences.getInstance();
    setState(() {
      _officerName = sp.getString('name') ?? 'Field Officer';
    });
  }

  @override
  void dispose() {
    _pulseCtrl.dispose();
    super.dispose();
  }

  Future<void> _startLiveCapture() async {
    try {
      final captured = await Navigator.of(context).push<File>(
        MaterialPageRoute(
          builder: (_) => const InAppCameraScreen(
            preferredLens: CameraLensDirection.front,
            isSelfie: true,
            isEnrollment: true,
            title: 'Live Face ID Enrollment',
            subtitle: 'Front Camera · Align inside oval & blink/smile',
          ),
        ),
      );

      if (captured == null) {
        setState(() {
          _currentStep = 1;
          _statusText = 'ඡායාරූපය ලබාගැනීම අවලංගු විය. කරුණාකර නැවත උත්සාහ කරන්න.';
        });
        return;
      }

      if (await captured.exists() && await captured.length() > 5000) {
        setState(() {
          _capturedFace = captured;
          _currentStep = 3;
          _statusText = '✅ Live Face Confirmed! Ready to save.';
        });
      } else {
        setState(() {
          _currentStep = 1;
          _statusText = 'ඡායාරූපය පැහැදිලි නැත. හොඳින් ආලෝකය ඇති තැනකදී නැවත ගන්න.';
        });
      }
    } catch (e) {
      setState(() {
        _currentStep = 1;
        _statusText = 'කැමරා දෝෂයකි: $e';
      });
    }
  }

  Future<void> _submitMasterFace() async {
    if (_capturedFace == null) return;
    setState(() {
      _isEnrolling = true;
      _statusText = '☁️ Master Face ID සේවාදායකයේ (Server) ලියාපදිංචි වෙමින් පවතී...';
    });

    try {
      final res = await Api.upload('enroll_face', {}, _capturedFace!);

      if (res['ok'] == true) {
        final sp = await SharedPreferences.getInstance();
        await sp.setBool('face_enrolled', true);
        if (res['profile_photo'] != null) {
          await sp.setString('profile_photo', res['profile_photo'].toString());
        }

        if (mounted) {
          showDialog(
            context: context,
            barrierDismissible: false,
            builder: (ctx) => AlertDialog(
              backgroundColor: const Color(0xff1e293b),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
              title: const Row(
                children: [
                  Icon(Icons.verified, color: Color(0xff22c55e), size: 28),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text('Master Face Active!',
                        style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
              content: const Text(
                'ඔබගේ සත්‍ය මුහුණ (Live Master Face ID) පද්ධතිය තුළ සාර්ථකව සක්‍රිය කරන ලදී.\n\nඉදිරි ක්ෂේත්‍ර චාරිකා වලදී ගන්නා සියලුම සත්‍යාපන selfies මෙම ඡායාරූපය සමඟ සසඳනු ලැබේ.',
                style: TextStyle(color: Color(0xffcbd5e1), fontSize: 13, height: 1.45),
              ),
              actions: [
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xff16a34a),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 10),
                  ),
                  onPressed: () {
                    Navigator.pop(ctx);
                    Navigator.of(context).pushReplacement(
                      MaterialPageRoute(builder: (_) => const HomeScreen()),
                    );
                  },
                  child: const Text('Proceed to App (ඇතුල් වන්න)',
                      style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                ),
              ],
            ),
          );
        }
      } else {
        throw Exception(res['error'] ?? 'Server error occurred');
      }
    } catch (e) {
      setState(() {
        _isEnrolling = false;
        _statusText = 'Upload Error: $e';
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Registration Failed: $e'), backgroundColor: Colors.redAccent),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0b1120),
      appBar: AppBar(
        backgroundColor: const Color(0xff0f172a),
        elevation: 0,
        centerTitle: true,
        title: const Text(
          'Official Face ID Enrollment',
          style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
          child: Column(
            children: [
              // 1. Officer Header Card
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: const Color(0xff1e293b),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xff334155)),
                ),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: const Color(0xff0284c7).withOpacity(0.18),
                        shape: BoxShape.circle,
                      ),
                      child: const Icon(Icons.person, color: Color(0xff38bdf8), size: 22),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _officerName,
                            style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 2),
                          const Text(
                            'One-time Master Face Registration',
                            style: TextStyle(color: Color(0xff94a3b8), fontSize: 11.5),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: const Color(0xfff59e0b).withOpacity(0.18),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: const Text(
                        'Pending ID',
                        style: TextStyle(color: Color(0xfffbbf24), fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 28),

              // 2. Animated Oval Liveness Face Frame
              ScaleTransition(
                scale: _pulseAnim,
                child: Container(
                  width: 230,
                  height: 280,
                  decoration: BoxDecoration(
                    color: const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(140),
                    border: Border.all(
                      color: _capturedFace != null ? const Color(0xff22c55e) : const Color(0xff0284c7),
                      width: 3.5,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: (_capturedFace != null ? const Color(0xff22c55e) : const Color(0xff0284c7)).withOpacity(0.25),
                        blurRadius: 22,
                        spreadRadius: 2,
                      ),
                    ],
                  ),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(140),
                    child: _capturedFace != null
                        ? Image.file(_capturedFace!, fit: BoxFit.cover)
                        : Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(
                                Icons.face_retouching_natural,
                                size: 84,
                                color: const Color(0xff38bdf8).withOpacity(0.8),
                              ),
                              const SizedBox(height: 12),
                              const Text(
                                'Front Camera',
                                style: TextStyle(color: Color(0xff94a3b8), fontSize: 13, fontWeight: FontWeight.w600),
                              ),
                              const SizedBox(height: 4),
                              const Text(
                                'Center face inside oval',
                                style: TextStyle(color: Colors.white54, fontSize: 11),
                              ),
                            ],
                          ),
                  ),
                ),
              ),

              const SizedBox(height: 24),

              // 3. Status Guidance Banner
              if (_statusText != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  margin: const EdgeInsets.only(bottom: 20),
                  decoration: BoxDecoration(
                    color: _capturedFace != null
                        ? const Color(0xff065f46).withOpacity(0.3)
                        : const Color(0xff1e293b),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                      color: _capturedFace != null ? const Color(0xff10b981) : const Color(0xff334155),
                    ),
                  ),
                  child: Text(
                    _statusText!,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: _capturedFace != null ? const Color(0xff34d399) : const Color(0xffcbd5e1),
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),

              // 4. Instructions Card
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xff0f172a),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: const Color(0xff334155)),
                ),
                child: const Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(Icons.shield, color: Color(0xff38bdf8), size: 18),
                        SizedBox(width: 8),
                        Text(
                          'ලෝක සම්මත Liveness Verification පියවර:',
                          style: TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    SizedBox(height: 10),
                    Text(
                      '• මුහුණ Oval Frame එකට කෙලින් තබා ගන්න (Look Straight)\n• ඇස් දෙක පියා අරින්න (Blink eyes naturally)\n• ස්වභාවිකව සිනාසෙන්න (Smile slightly)\n• පැහැදිලි ආලෝකය ඇති ස්ථානයක සිටින්න',
                      style: TextStyle(color: Color(0xff94a3b8), fontSize: 12, height: 1.5),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 28),

              // 5. Action Buttons
              if (_capturedFace == null)
                SizedBox(
                  width: double.infinity,
                  height: 50,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xff0284c7),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      elevation: 4,
                    ),
                    icon: const Icon(Icons.camera_front, color: Colors.white, size: 22),
                    label: const Text(
                      'Start Live Face Capture',
                      style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold),
                    ),
                    onPressed: _isEnrolling ? null : _startLiveCapture,
                  ),
                )
              else
                Row(
                  children: [
                    Expanded(
                      flex: 1,
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          side: const BorderSide(color: Color(0xff64748b)),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                        icon: const Icon(Icons.refresh, color: Colors.white70),
                        label: const Text('Retake', style: TextStyle(color: Colors.white70, fontWeight: FontWeight.bold)),
                        onPressed: _isEnrolling ? null : _startLiveCapture,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      flex: 2,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff16a34a),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          elevation: 4,
                        ),
                        icon: _isEnrolling
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                              )
                            : const Icon(Icons.check_circle, color: Colors.white),
                        label: Text(
                          _isEnrolling ? 'Enrolling...' : 'Confirm & Save Face ID',
                          style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.bold),
                        ),
                        onPressed: _isEnrolling ? null : _submitMasterFace,
                      ),
                    ),
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }
}

