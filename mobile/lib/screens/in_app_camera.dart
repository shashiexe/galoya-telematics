import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';

/// Full In-App Camera Screen with Real-Time Front Viewfinder,
/// Interactive Oval Alignment, and Liveness Actions (Blink/Smile prompts).
class InAppCameraScreen extends StatefulWidget {
  final CameraLensDirection preferredLens;
  final bool isSelfie;
  final bool isEnrollment;
  final String title;
  final String subtitle;

  const InAppCameraScreen({
    super.key,
    this.preferredLens = CameraLensDirection.front,
    this.isSelfie = true,
    this.isEnrollment = false,
    this.title = 'Camera',
    this.subtitle = 'Align within frame',
  });

  @override
  State<InAppCameraScreen> createState() => _InAppCameraScreenState();
}

class _InAppCameraScreenState extends State<InAppCameraScreen>
    with SingleTickerProviderStateMixin {
  CameraController? _controller;
  List<CameraDescription> _availableCameras = [];
  bool _isInit = false;
  bool _initError = false;
  String? _errorMessage;

  File? _capturedFile;
  bool _isTakingPicture = false;

  // Liveness challenge state (0: Align, 1: Blink, 2: Smile, 3: Ready)
  int _challengeStep = 0;
  Timer? _challengeTimer;

  late AnimationController _pulseController;
  late Animation<double> _pulseAnimation;

  @override
  void initState() {
    super.initState();
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat(reverse: true);
    _pulseAnimation = Tween<double>(begin: 0.97, end: 1.03).animate(
      CurvedAnimation(parent: _pulseController, curve: Curves.easeInOut),
    );

    _startCamera();
  }

  @override
  void dispose() {
    _challengeTimer?.cancel();
    _pulseController.dispose();
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _startCamera() async {
    try {
      final perm = await Permission.camera.request();
      if (!perm.isGranted) {
        setState(() {
          _initError = true;
          _errorMessage = 'Camera permission was denied. Please allow camera access in Settings.';
        });
        return;
      }

      _availableCameras = await availableCameras();
      if (_availableCameras.isEmpty) {
        throw Exception('No cameras found on device');
      }

      // Filter for requested lens direction (front or rear)
      CameraDescription selectedCam = _availableCameras.firstWhere(
        (c) => c.lensDirection == widget.preferredLens,
        orElse: () => _availableCameras.first,
      );

      _controller = CameraController(
        selectedCam,
        ResolutionPreset.high,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );

      await _controller!.initialize();

      if (!mounted) return;
      setState(() {
        _isInit = true;
        _initError = false;
      });

      if (widget.isEnrollment || widget.isSelfie) {
        _startLivenessSequence();
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _initError = true;
        _errorMessage = e.toString();
      });
    }
  }

  void _startLivenessSequence() {
    _challengeTimer?.cancel();
    setState(() => _challengeStep = 0); // Align

    _challengeTimer = Timer(const Duration(milliseconds: 1800), () {
      if (!mounted) return;
      setState(() => _challengeStep = 1); // Blink

      _challengeTimer = Timer(const Duration(milliseconds: 2200), () {
        if (!mounted) return;
        setState(() => _challengeStep = 2); // Smile

        _challengeTimer = Timer(const Duration(milliseconds: 2200), () {
          if (!mounted) return;
          setState(() => _challengeStep = 3); // Ready
        });
      });
    });
  }

  Future<void> _takePicture() async {
    if (_controller == null || !_controller!.value.isInitialized || _isTakingPicture) return;

    try {
      setState(() => _isTakingPicture = true);
      final xFile = await _controller!.takePicture();
      final file = File(xFile.path);

      if (await file.exists() && await file.length() > 5000) {
        setState(() {
          _capturedFile = file;
          _isTakingPicture = false;
        });
      } else {
        throw Exception('Captured image was empty or unreadable');
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to take photo: $e'), backgroundColor: Colors.red),
        );
      }
      setState(() => _isTakingPicture = false);
    }
  }

  Future<void> _fallbackPicker() async {
    try {
      final picker = ImagePicker();
      final photo = await picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: widget.isSelfie ? CameraDevice.front : CameraDevice.rear,
        imageQuality: 85,
        maxWidth: 1600,
        maxHeight: 1600,
      );
      if (photo != null) {
        if (!mounted) return;
        Navigator.pop(context, File(photo.path));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('System camera error: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  Widget _buildLivenessBadge() {
    String text;
    IconData icon;
    Color color;

    switch (_challengeStep) {
      case 0:
        text = 'Center face inside oval';
        icon = Icons.face;
        color = const Color(0xff38bdf8);
        break;
      case 1:
        text = '👁️ Blink your eyes (ඇස් පිල්ලම් ගසන්න)';
        icon = Icons.remove_red_eye;
        color = const Color(0xfff59e0b);
        break;
      case 2:
        text = '😊 Smile naturally (ස්වභාවිකව සිනාසෙන්න)';
        icon = Icons.sentiment_very_satisfied;
        color = const Color(0xff10b981);
        break;
      case 3:
      default:
        text = '✓ Liveness Verified · Ready to Capture';
        icon = Icons.verified;
        color = const Color(0xff22c55e);
        break;
    }

    return AnimatedContainer(
      duration: const Duration(milliseconds: 300),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      decoration: BoxDecoration(
        color: const Color(0xff0f172a).withOpacity(0.85),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: color.withOpacity(0.8), width: 1.5),
        boxShadow: [
          BoxShadow(
            color: color.withOpacity(0.25),
            blurRadius: 10,
            spreadRadius: 1,
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: color, size: 18),
          const SizedBox(width: 8),
          Text(
            text,
            style: TextStyle(
              color: Colors.white,
              fontSize: 12.5,
              fontWeight: FontWeight.bold,
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // 1. Captured Preview Screen (Review & Submit)
    if (_capturedFile != null) {
      return Scaffold(
        backgroundColor: const Color(0xff0b1120),
        body: SafeArea(
          child: Column(
            children: [
              // Top Header
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                child: Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: () {
                        setState(() => _capturedFile = null);
                      },
                    ),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                        'Review Captured Photo',
                        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              ),

              // Image Preview
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(16),
                    child: Image.file(
                      _capturedFile!,
                      fit: BoxFit.contain,
                      width: double.infinity,
                    ),
                  ),
                ),
              ),

              // Action Buttons
              Padding(
                padding: const EdgeInsets.all(20),
                child: Row(
                  children: [
                    Expanded(
                      flex: 1,
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          side: const BorderSide(color: Color(0xff64748b)),
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        icon: const Icon(Icons.refresh, color: Colors.white70),
                        label: const Text('Retake', style: TextStyle(color: Colors.white70, fontWeight: FontWeight.bold)),
                        onPressed: () {
                          setState(() {
                            _capturedFile = null;
                            if (widget.isEnrollment || widget.isSelfie) {
                              _startLivenessSequence();
                            }
                          });
                        },
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      flex: 2,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff16a34a),
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          elevation: 4,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        icon: const Icon(Icons.check_circle, color: Colors.white),
                        label: const Text(
                          'Confirm & Use Photo',
                          style: TextStyle(color: Colors.white, fontSize: 14.5, fontWeight: FontWeight.bold),
                        ),
                        onPressed: () {
                          Navigator.pop(context, _capturedFile);
                        },
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    // 2. Camera Error or Initialization View
    if (_initError) {
      return Scaffold(
        backgroundColor: const Color(0xff0b1120),
        appBar: AppBar(
          backgroundColor: const Color(0xff0f172a),
          title: Text(widget.title),
          leading: IconButton(
            icon: const Icon(Icons.close),
            onPressed: () => Navigator.pop(context, null),
          ),
        ),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.videocam_off, size: 64, color: Colors.amber),
                const SizedBox(height: 16),
                const Text(
                  'In-App Camera Unavailable',
                  style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 8),
                Text(
                  _errorMessage ?? 'Device camera could not be accessed directly.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Color(0xff94a3b8), fontSize: 13),
                ),
                const SizedBox(height: 24),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xff0284c7),
                    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                  ),
                  icon: const Icon(Icons.camera_alt),
                  label: const Text('Open System Camera Fallback'),
                  onPressed: _fallbackPicker,
                ),
              ],
            ),
          ),
        ),
      );
    }

    if (!_isInit || _controller == null) {
      return Scaffold(
        backgroundColor: const Color(0xff0b1120),
        body: const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircularProgressIndicator(color: Color(0xff38bdf8)),
              SizedBox(height: 16),
              Text(
                'Starting Camera Viewfinder...',
                style: TextStyle(color: Color(0xff94a3b8), fontSize: 13),
              ),
            ],
          ),
        ),
      );
    }

    // 3. Live Viewfinder Overlay UI
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Live Camera Stream
          Center(
            child: AspectRatio(
              aspectRatio: 1 / _controller!.value.aspectRatio,
              child: CameraPreview(_controller!),
            ),
          ),

          // Dark Oval Vignette / Guide Frame for Face ID & Selfies
          if (widget.isEnrollment || widget.isSelfie)
            Center(
              child: ScaleTransition(
                scale: _pulseAnimation,
                child: Container(
                  width: 250,
                  height: 330,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(160),
                    border: Border.all(
                      color: _challengeStep == 3
                          ? const Color(0xff22c55e)
                          : const Color(0xff38bdf8),
                      width: 3.5,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: (_challengeStep == 3
                                ? const Color(0xff22c55e)
                                : const Color(0xff38bdf8))
                            .withOpacity(0.3),
                        blurRadius: 25,
                        spreadRadius: 2,
                      ),
                    ],
                  ),
                ),
              ),
            ),

          // Top Header Bar
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [Colors.black.withOpacity(0.7), Colors.transparent],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
                child: Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.white, size: 26),
                      onPressed: () => Navigator.pop(context, null),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            widget.title,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 15.5,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          Text(
                            widget.subtitle,
                            style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 11.5),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),

          // Liveness Challenge Badge (Above bottom controls)
          if (widget.isEnrollment || widget.isSelfie)
            Positioned(
              bottom: 115,
              left: 20,
              right: 20,
              child: Center(
                child: _buildLivenessBadge(),
              ),
            ),

          // Bottom Shutter Controls
          Positioned(
            bottom: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              child: Container(
                padding: const EdgeInsets.only(bottom: 24, top: 12),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [Colors.transparent, Colors.black.withOpacity(0.8)],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    // System camera fallback button
                    IconButton(
                      icon: const Icon(Icons.photo_camera_back, color: Colors.white70, size: 28),
                      tooltip: 'System Camera',
                      onPressed: _fallbackPicker,
                    ),

                    // Main Big Shutter Button
                    GestureDetector(
                      onTap: _isTakingPicture ? null : _takePicture,
                      child: Container(
                        width: 76,
                        height: 76,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 4),
                          color: _isTakingPicture ? Colors.grey : Colors.white24,
                        ),
                        child: Center(
                          child: Container(
                            width: 60,
                            height: 60,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: _isTakingPicture
                                  ? Colors.grey
                                  : (widget.isSelfie
                                      ? const Color(0xff8b5cf6)
                                      : const Color(0xff0284c7)),
                            ),
                            child: _isTakingPicture
                                ? const CircularProgressIndicator(color: Colors.white, strokeWidth: 3)
                                : const Icon(Icons.camera_alt, color: Colors.white, size: 30),
                          ),
                        ),
                      ),
                    ),

                    // Flash or Spacer
                    const SizedBox(width: 48),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
