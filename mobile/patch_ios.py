import os
import re

print("[Patch-iOS] Starting iOS configuration patch for Galoya Telematics...")

# 1. Patch ios/Runner/Info.plist
plist_path = "ios/Runner/Info.plist"
if os.path.exists(plist_path):
    with open(plist_path, "r", encoding="utf-8") as f:
        content = f.read()

    # App Display Name
    if "<key>CFBundleDisplayName</key>" not in content:
        content = content.replace(
            "<key>CFBundleName</key>",
            "<key>CFBundleDisplayName</key>\n\t<string>Galoya Telematics</string>\n\t<key>CFBundleName</key>"
        )

    # Permission descriptions & Background modes to inject
    permissions_xml = """
	<!-- Background GPS Tracking & Telematics -->
	<key>UIBackgroundModes</key>
	<array>
		<string>location</string>
		<string>fetch</string>
		<string>remote-notification</string>
	</array>
	<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
	<string>Galoya Telematics tracks vehicle and staff location continuously in background for fleet management.</string>
	<key>NSLocationWhenInUseUsageDescription</key>
	<string>Galoya Telematics requires your location to display machinery position on live monitoring map.</string>
	<key>NSLocationAlwaysUsageDescription</key>
	<string>Galoya Telematics requires 24/7 background location tracking for fleet safety and operation.</string>
	<key>NSCameraUsageDescription</key>
	<string>Galoya Telematics needs camera access to capture field work before and after photos.</string>
	<key>NSPhotoLibraryUsageDescription</key>
	<string>Galoya Telematics needs photo library access to upload field inspection duty images.</string>
	<key>ITSAppUsesNonExemptEncryption</key>
	<false/>
"""

    if "UIBackgroundModes" not in content:
        # Inject right before the closing </dict>
        last_dict = content.rfind("</dict>")
        if last_dict != -1:
            content = content[:last_dict] + permissions_xml + content[last_dict:]
            print("[Patch-iOS] Injected background modes and permissions into Info.plist")

    with open(plist_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("[Patch-iOS] Updated ios/Runner/Info.plist successfully")

# 2. Patch ios/Podfile
podfile_path = "ios/Podfile"
if os.path.exists(podfile_path):
    with open(podfile_path, "r", encoding="utf-8") as f:
        pod_content = f.read()

    # Set platform :ios, '13.0'
    if "# platform :ios" in pod_content:
        pod_content = pod_content.replace("# platform :ios, '12.0'", "platform :ios, '13.0'")
        pod_content = pod_content.replace("# platform :ios, '11.0'", "platform :ios, '13.0'")
    elif "platform :ios" not in pod_content:
        pod_content = "platform :ios, '13.0'\n" + pod_content

    # Inject permission_handler build macros in post_install
    macro_setup = """
    installer.pods_project.targets.each do |target|
      flutter_additional_ios_build_settings(target)
      target.build_configurations.each do |config|
        config.build_settings['GCC_PREPROCESSOR_DEFINITIONS'] ||= [
          '$(inherited)',
          'PERMISSION_LOCATION=1',
          'PERMISSION_CAMERA=1',
          'PERMISSION_NOTIFICATIONS=1',
        ]
      end
    end
"""
    if "PERMISSION_LOCATION" not in pod_content and "post_install do |installer|" in pod_content:
        pod_content = re.sub(
            r"post_install do \|installer\|.*?end\s*\n\s*end",
            "post_install do |installer|\n" + macro_setup + "end",
            pod_content,
            flags=re.DOTALL
        )
        print("[Patch-iOS] Injected permission_handler macros into Podfile post_install")

    with open(podfile_path, "w", encoding="utf-8") as f:
        f.write(pod_content)
    print("[Patch-iOS] Updated ios/Podfile successfully")

print("[Patch-iOS] iOS Patching completed successfully.")
