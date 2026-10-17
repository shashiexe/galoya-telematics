import os
import shutil

print("[Patch] Starting Android configuration patch...")

# 1. Copy AndroidManifest.xml template
manifest_template = "AndroidManifest.xml.template"
manifest_target = "android/app/src/main/AndroidManifest.xml"
if os.path.exists(manifest_template) and os.path.exists("android/app/src/main"):
    shutil.copyfile(manifest_template, manifest_target)
    print(f"[Patch] Copied {manifest_template} to {manifest_target}")

# 2. Patch build.gradle (Groovy)
gradle_path = "android/app/build.gradle"
if os.path.exists(gradle_path):
    with open(gradle_path, "r", encoding="utf-8") as f:
        content = f.read()

    # Enable coreLibraryDesugaring in compileOptions
    if "coreLibraryDesugaringEnabled" not in content:
        content = content.replace(
            "compileOptions {",
            "compileOptions {\n        coreLibraryDesugaringEnabled true"
        )
        print("[Patch] Added coreLibraryDesugaringEnabled to compileOptions")

    # Add coreLibraryDesugaring dependency
    if "coreLibraryDesugaring" not in content:
        if "dependencies {" in content:
            content = content.replace(
                "dependencies {",
                "dependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'"
            )
            print("[Patch] Added desugar_jdk_libs to existing dependencies block")
        else:
            content += "\ndependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n}\n"
            print("[Patch] Appended dependencies block with desugar_jdk_libs")

    # Ensure multiDexEnabled true
    if "multiDexEnabled" not in content and "defaultConfig {" in content:
        content = content.replace(
            "defaultConfig {",
            "defaultConfig {\n        multiDexEnabled true"
        )
        print("[Patch] Added multiDexEnabled true to defaultConfig")

    with open(gradle_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("[Patch] Updated android/app/build.gradle successfully")

# 3. Patch build.gradle.kts (Kotlin DSL) if used
kts_path = "android/app/build.gradle.kts"
if os.path.exists(kts_path):
    with open(kts_path, "r", encoding="utf-8") as f:
        content = f.read()

    if "isCoreLibraryDesugaringEnabled" not in content:
        content = content.replace(
            "compileOptions {",
            "compileOptions {\n        isCoreLibraryDesugaringEnabled = true"
        )
        print("[Patch] Added isCoreLibraryDesugaringEnabled to compileOptions")

    if "coreLibraryDesugaring" not in content:
        if "dependencies {" in content:
            content = content.replace(
                "dependencies {",
                'dependencies {\n    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")\n    implementation("androidx.multidex:multidex:2.0.1")'
            )
            print("[Patch] Added desugar_jdk_libs and multidex to kts dependencies")
        else:
            content += '\ndependencies {\n    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")\n    implementation("androidx.multidex:multidex:2.0.1")\n}\n'
            print("[Patch] Appended kts dependencies block with desugar_jdk_libs and multidex")

    if "multiDexEnabled" not in content and "defaultConfig {" in content:
        content = content.replace(
            "defaultConfig {",
            'defaultConfig {\n        multiDexEnabled = true\n        ndk {\n            abiFilters.addAll(listOf("armeabi-v7a", "arm64-v8a", "x86_64"))\n        }'
        )
        print("[Patch] Added multiDexEnabled = true and abiFilters to kts defaultConfig")

    with open(kts_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("[Patch] Updated android/app/build.gradle.kts successfully")

print("[Patch] Patching completed.")
