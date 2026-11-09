import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';

/// Full In-App Camera Screen with Real-Time Front Viewfinder,
/// Interactive Oval Alignment, and Automated ML Kit Face Liveness Detection:
/// 1. Look Straight (Head alignment)
/// 2. Blink Eyes (left/right eye probability drop & rise)
/// 3. Smile Slightly (smiling probability detection)
/// 4. Auto-Captures immediately upon confirmation.
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
  CameraDescription? _selectedCamera;
  bool _isInit = false;
  bool _initError = false;
  String? _errorMessage;

  File? _capturedFile;
  bool _isTakingPicture = false;

  // ML Kit Face Detector
  FaceDetector? _faceDetector;
  bool _isStreaming = false;
  bool _isProcessingFrame = false;

  // Real-Time Liveness State Machine
  // Step 1: Look straight
  bool _stepLookStraight = false;
  // Step 2: Blink
  bool _eyesClosedSeen = false;
  bool _stepBlink = false;
  // Step 3: Smile
  bool _stepSmile = false;
  // Step 4: All verified
  bool _allVerified = false;

  String _livenessStatus = 'මුහුණ Oval එක ඇතුළට කෙලින් තබන්න';
  String _livenessSubStatus = 'Look Straight at Camera';
  Color _ovalBorderColor = const Color(0xff38bdf8); // Sky blue initially

  // Manual fallback override timer (in case low light or thick glasses)
  bool _showManualOverride = false;
  Timer? _manualOverrideTimer;

  late AnimationController _pulseController;
  late Animation<double> _pulseAnimation;

  static const Map<DeviceOrientation, int> _orientations = {
    DeviceOrientation.portraitUp: 0,
    DeviceOrientation.landscapeLeft: 90,
    DeviceOrientation.portraitDown: 180,
    DeviceOrientation.landscapeRight: 270,
  };

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

    if (widget.isSelfie) {
      _initFaceDetector();
      // Show manual fallback shutter button after 12 seconds if liveness takes too long
      _manualOverrideTimer = Timer(const Duration(seconds: 12), () {
        if (mounted) setState(() => _showManualOverride = true);
      });
    }

    _startCamera();
  }

  void _initFaceDetector() {
    _faceDetector = FaceDetector(
      options: FaceDetectorOptions(
        enableClassification: true,
        enableTracking: true,
        performanceMode: FaceDetectorMode.fast,
        minFaceSize: 0.25,
      ),
    );
  }

  @override
  void dispose() {
    _manualOverrideTimer?.cancel();
    _pulseController.dispose();
    _stopImageStreamSafe();
    _faceDetector?.close();
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _stopImageStreamSafe() async {
    if (_controller != null && _isStreaming) {
      try {
        _isStreaming = false;
        await _controller!.stopImageStream();
      } catch (_) {}
    }
  }

  // Hard-lock lens direction: strictly front lens for selfie, strictly rear lens for field photo
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

      // Hard-lock: Selfie must use front lens; Field photos must use rear lens.
      final targetLens = widget.isSelfie ? CameraLensDirection.front : CameraLensDirection.back;
      _selectedCamera = _availableCameras.firstWhere(
        (c) => c.lensDirection == targetLens,
        orElse: () => _availableCameras.firstWhere(
          (c) => widget.isSelfie
              ? c.lensDirection == CameraLensDirection.front
              : c.lensDirection == CameraLensDirection.back,
          orElse: () => _availableCameras.first,
        ),
      );

      _controller = CameraController(
        _selectedCamera!,
        ResolutionPreset.high,
        enableAudio: false,
        imageFormatGroup: Platform.isAndroid ? ImageFormatGroup.nv21 : ImageFormatGroup.bgra8888,
      );

      await _controller!.initialize();

      if (!mounted) return;
      setState(() {
        _isInit = true;
        _initError = false;
      });

      // Start live face detection stream for Selfie / Face ID
      if (widget.isSelfie && _faceDetector != null) {
        await _startImageStream();
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _initError = true;
        _errorMessage = e.toString();
      });
    }
  }

  Future<void> _startImageStream() async {
    if (_controller == null || !_controller!.value.isInitialized || _isStreaming) return;
    _isStreaming = true;
    _controller!.startImageStream((CameraImage image) {
      if (!_isStreaming || _isProcessingFrame || _allVerified || _isTakingPicture) return;
      _processCameraFrame(image);
    });
  }

  Future<void> _processCameraFrame(CameraImage image) async {
    _isProcessingFrame = true;
    try {
      final inputImage = _inputImageFromCameraImage(image, _selectedCamera!);
      if (inputImage == null) {
        _isProcessingFrame = false;
        return;
      }

      final faces = await _faceDetector!.processImage(inputImage);
      if (!mounted || _allVerified || _isTakingPicture) {
        _isProcessingFrame = false;
        return;
      }

      _handleLivenessDetection(faces);
    } catch (_) {
      // Ignore transient frame parsing errors
    } finally {
      _isProcessingFrame = false;
    }
  }

  InputImage? _inputImageFromCameraImage(CameraImage image, CameraDescription camera) {
    final sensorOrientation = camera.sensorOrientation;
    InputImageRotation? rotation;
    if (Platform.isAndroid) {
      var rotationCompensation = _orientations[_controller?.value.deviceOrientation ?? DeviceOrientation.portraitUp];
      if (rotationCompensation == null) return null;
      if (camera.lensDirection == CameraLensDirection.front) {
        rotationCompensation = (sensorOrientation + rotationCompensation) % 360;
      } else {
        rotationCompensation = (sensorOrientation - rotationCompensation + 360) % 360;
      }
      rotation = InputImageRotationValue.fromRawValue(rotationCompensation);
    } else if (Platform.isIOS) {
      rotation = InputImageRotationValue.fromRawValue(sensorOrientation);
    }
    rotation ??= InputImageRotation.rotation270deg;

    InputImageFormat? format = InputImageFormatValue.fromRawValue(image.format.raw);
    if (format == null) {
      format = Platform.isAndroid ? InputImageFormat.nv21 : InputImageFormat.bgra8888;
    }

    final WriteBuffer allBytes = WriteBuffer();
    for (final Plane plane in image.planes) {
      allBytes.putUint8List(plane.bytes);
    }
    final bytes = allBytes.done().buffer.asUint8List();

    return InputImage.fromBytes(
      bytes: bytes,
      metadata: InputImageMetadata(
        size: Size(image.width.toDouble(), image.height.toDouble()),
        rotation: rotation,
        format: format,
        bytesPerRow: image.planes[0].bytesPerRow,
      ),
    );
  }

  void _handleLivenessDetection(List<Face> faces) {
    if (faces.isEmpty) {
      setState(() {
        _livenessStatus = 'මුහුණ Oval එක ඇතුළට ගන්න';
        _livenessSubStatus = 'Center Face Inside Oval';
        _ovalBorderColor = const Color(0xfff87171); // Reddish
      });
      return;
    }

    if (faces.length > 1) {
      setState(() {
        _livenessStatus = 'එක් අයෙකුගේ මුහුණක් පමණක් තබන්න';
        _livenessSubStatus = 'Only 1 Face Allowed';
        _ovalBorderColor = const Color(0xfff87171);
      });
      return;
    }

    final face = faces.first;
    final rotY = face.headEulerAngleY ?? 0.0;
    final rotX = face.headEulerAngleX ?? 0.0;
    final leftEye = face.leftEyeOpenProbability ?? 0.9;
    final rightEye = face.rightEyeOpenProbability ?? 0.9;
    final smile = face.smilingProbability ?? 0.0;

    // 1. Step 1: Look Straight Check
    if (!_stepLookStraight) {
      if (rotY.abs() < 14 && rotX.abs() < 14) {
        setState(() {
          _stepLookStraight = true;
          _livenessStatus = '✓ මුහුණ තහවුරුයි! දැන් ඇස් පියා අරින්න';
          _livenessSubStatus = 'Step 2: Blink Eyes Naturally';
          _ovalBorderColor = const Color(0xfffacc15); // Yellow
        });
      } else {
        setState(() {
          _livenessStatus = 'කැමරාව කෙලින් බලන්න (Look Straight)';
          _livenessSubStatus = 'Keep head straight';
          _ovalBorderColor = const Color(0xff38bdf8); // Sky blue
        });
      }
      return;
    }

    // 2. Step 2: Eye Blink Check (Closed then Open)
    if (!_stepBlink) {
      if (leftEye < 0.30 && rightEye < 0.30) {
        _eyesClosedSeen = true;
      }
      if (_eyesClosedSeen && (leftEye > 0.65 && rightEye > 0.65)) {
        setState(() {
          _stepBlink = true;
          _livenessStatus = '✓ ඇස් පියවීම තහවුරුයි! දැන් මදක් සිනාසෙන්න';
          _livenessSubStatus = 'Step 3: Smile Slightly';
          _ovalBorderColor = const Color(0xffc084fc); // Purple
        });
      } else {
        setState(() {
          _livenessStatus = 'ඇස් දෙක පියා අරින්න (Blink eyes)';
          _livenessSubStatus = _eyesClosedSeen ? 'Now open eyes' : 'Close and open eyes naturally';
          _ovalBorderColor = const Color(0xfffacc15);
        });
      }
      return;
    }

    // 3. Step 3: Smile Check
    if (!_stepSmile) {
      if (smile > 0.50) {
        setState(() {
          _stepSmile = true;
          _allVerified = true;
          _livenessStatus = '🎉 Liveness තහවුරුයි! ඡායාරූපය ගන්නවා...';
          _livenessSubStatus = '✓ Verified! Capturing...';
          _ovalBorderColor = const Color(0xff22c55e); // Green
        });
        // Auto capture after brief delay
        Future.delayed(const Duration(milliseconds: 350), () {
          _triggerAutoCapture();
        });
      } else {
        setState(() {
          _livenessStatus = 'ස්වභාවිකව මදක් සිනාසෙන්න (Smile)';
          _livenessSubStatus = 'Smile gently at camera';
          _ovalBorderColor = const Color(0xffc084fc);
        });
      }
      return;
    }
  }

  Future<void> _triggerAutoCapture() async {
    if (_isTakingPicture || !mounted) return;
    await _stopImageStreamSafe();
    await _takePicture();
  }

  Future<void> _takePicture() async {
    if (_controller == null || !_controller!.value.isInitialized || _isTakingPicture) return;

    try {
      setState(() => _isTakingPicture = true);
      await _stopImageStreamSafe();

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
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xff0f172a).withOpacity(0.92),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: _ovalBorderColor.withOpacity(0.9), width: 1.8),
        boxShadow: [
          BoxShadow(
            color: _ovalBorderColor.withOpacity(0.35),
            blurRadius: 14,
            spreadRadius: 2,
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                _allVerified
                    ? Icons.check_circle
                    : (_stepSmile
                        ? Icons.sentiment_very_satisfied
                        : (_stepBlink
                            ? Icons.visibility
                            : (_stepLookStraight ? Icons.face : Icons.center_focus_strong))),
                color: _ovalBorderColor,
                size: 20,
              ),
              const SizedBox(width: 8),
              Flexible(
                child: Text(
                  _livenessStatus,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 3),
          Text(
            _livenessSubStatus,
            style: TextStyle(
              color: _ovalBorderColor.withOpacity(0.9),
              fontSize: 11,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 6),
          // Interactive 3-Step Progress Indicators
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              _buildStepIndicator('1. Face Straight', _stepLookStraight),
              const SizedBox(width: 8),
              _buildStepIndicator('2. Blink Eyes', _stepBlink),
              const SizedBox(width: 8),
              _buildStepIndicator('3. Smile', _stepSmile),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildStepIndicator(String label, bool isDone) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: isDone ? const Color(0xff22c55e).withOpacity(0.2) : Colors.white10,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: isDone ? const Color(0xff22c55e) : Colors.white24,
          width: 1,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            isDone ? Icons.check_circle : Icons.radio_button_unchecked,
            color: isDone ? const Color(0xff22c55e) : Colors.white38,
            size: 11,
          ),
          const SizedBox(width: 4),
          Text(
            label,
            style: TextStyle(
              color: isDone ? const Color(0xff4ade80) : Colors.white54,
              fontSize: 10,
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
                        setState(() {
                          _capturedFile = null;
                          _stepLookStraight = false;
                          _eyesClosedSeen = false;
                          _stepBlink = false;
                          _stepSmile = false;
                          _allVerified = false;
                          if (widget.isSelfie) _startImageStream();
                        });
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
                            _stepLookStraight = false;
                            _eyesClosedSeen = false;
                            _stepBlink = false;
                            _stepSmile = false;
                            _allVerified = false;
                            if (widget.isSelfie) _startImageStream();
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

          // Dynamic Color-Changing Oval Frame for Face ID & Selfies
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
                      color: _ovalBorderColor,
                      width: 3.5,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: _ovalBorderColor.withOpacity(0.35),
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
                            widget.isSelfie
                                ? 'Real-Time AI Face Liveness Detection'
                                : widget.subtitle,
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
              bottom: 125,
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
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // Manual Override Hint (Shows after 12s if low light/glasses)
                    if (widget.isSelfie && _showManualOverride && !_allVerified)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: TextButton.icon(
                          style: TextButton.styleFrom(
                            backgroundColor: Colors.white12,
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                          ),
                          icon: const Icon(Icons.touch_app, color: Colors.amber, size: 16),
                          label: const Text(
                            'Take Photo Manually (Manual Override)',
                            style: TextStyle(color: Colors.amber, fontSize: 12, fontWeight: FontWeight.bold),
                          ),
                          onPressed: _isTakingPicture ? null : _takePicture,
                        ),
                      ),

                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                      children: [
                        // Left spacer
                        const SizedBox(width: 48),

                        // Shutter Button: For Rear camera (direct capture), or when Selfie is verified/override
                        GestureDetector(
                          onTap: _isTakingPicture
                              ? null
                              : (!widget.isSelfie || _showManualOverride || _allVerified
                                  ? _takePicture
                                  : null),
                          child: Container(
                            width: 76,
                            height: 76,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              border: Border.all(
                                color: (widget.isSelfie && !_allVerified && !_showManualOverride)
                                    ? Colors.white24
                                    : Colors.white,
                                width: 4,
                              ),
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
                                      : (!widget.isSelfie
                                          ? const Color(0xff0284c7) // Rear camera: Blue
                                          : (_allVerified
                                              ? const Color(0xff22c55e) // Verified: Green
                                              : (_showManualOverride
                                                  ? const Color(0xff8b5cf6)
                                                  : Colors.white12))),
                                ),
                                child: _isTakingPicture
                                    ? const CircularProgressIndicator(color: Colors.white, strokeWidth: 3)
                                    : Icon(
                                        _allVerified
                                            ? Icons.check
                                            : (widget.isSelfie && !_showManualOverride
                                                ? Icons.auto_awesome
                                                : Icons.camera_alt),
                                        color: Colors.white,
                                        size: 28,
                                      ),
                              ),
                            ),
                          ),
                        ),

                        // Right spacer
                        const SizedBox(width: 48),
                      ],
                    ),
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
