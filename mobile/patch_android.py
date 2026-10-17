import os
import shutil

print("[Patch] Starting Android configuration patch...")

# 1. Copy AndroidManifest.xml template
manifest_template = "AndroidManifest.xml.template"
manifest_target = "android/app/src/main/AndroidManifest.xml"
if os.path.exists(manifest_template) and os.path.exists("android/app/src/main"):
    shutil.copyfile(manifest_template, manifest_target)
    print(f"[Patch] Copied {manifest_template} to {manifest_target}")

# 1.1 Copy network_security_config.xml
net_sec_template = "network_security_config.xml.template"
net_sec_target_dir = "android/app/src/main/res/xml"
net_sec_target = os.path.join(net_sec_target_dir, "network_security_config.xml")
if os.path.exists(net_sec_template):
    os.makedirs(net_sec_target_dir, exist_ok=True)
    shutil.copyfile(net_sec_template, net_sec_target)
    print(f"[Patch] Copied {net_sec_template} to {net_sec_target}")

# 1.2 Copy proguard-rules.pro
proguard_template = "proguard-rules.pro.template"
proguard_target = "android/app/proguard-rules.pro"
if os.path.exists(proguard_template):
    shutil.copyfile(proguard_template, proguard_target)
    print(f"[Patch] Copied {proguard_template} to {proguard_target}")

# 1.3 Configure gradle.properties (Disable R8 FullMode)
gradle_props = "android/gradle.properties"
if os.path.exists("gradle.properties.template") and not os.path.exists(gradle_props):
    shutil.copyfile("gradle.properties.template", gradle_props)
    print("[Patch] Copied gradle.properties.template to android/gradle.properties")
elif os.path.exists(gradle_props):
    with open(gradle_props, "r", encoding="utf-8") as f:
        props = f.read()
    if "android.enableR8.fullMode" not in props:
        props += "\nandroid.enableR8.fullMode=false\nandroid.useAndroidX=true\nandroid.enableJetifier=true\n"
        with open(gradle_props, "w", encoding="utf-8") as f:
            f.write(props)
        print("[Patch] Set android.enableR8.fullMode=false in gradle.properties")

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
                "dependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n    implementation 'androidx.multidex:multidex:2.0.1'"
            )
            print("[Patch] Added desugar_jdk_libs and multidex to Groovy dependencies")
        else:
            content += "\ndependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n    implementation 'androidx.multidex:multidex:2.0.1'\n}\n"
            print("[Patch] Appended Groovy dependencies block with desugar_jdk_libs and multidex")

    # Ensure multiDexEnabled and abiFilters
    if "multiDexEnabled" not in content and "defaultConfig {" in content:
        content = content.replace(
            "defaultConfig {",
            "defaultConfig {\n        multiDexEnabled true\n        ndk {\n            abiFilters 'armeabi-v7a', 'arm64-v8a', 'x86_64'\n        }"
        )
        print("[Patch] Added multiDexEnabled true and abiFilters to defaultConfig")

    # Ensure minifyEnabled false and shrinkResources false in release buildType
    if "buildTypes {" in content:
        content = content.replace(
            "release {",
            "release {\n            minifyEnabled false\n            shrinkResources false\n            proguardFiles getDefaultProguardFile('proguard-android.txt'), 'proguard-rules.pro'"
        )

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

    # Disable minification and add ProGuard in release build
    if "release {" in content and "isMinifyEnabled" not in content:
        content = content.replace(
            'signingConfig = signingConfigs.getByName("debug")',
            'signingConfig = signingConfigs.getByName("debug")\n            isMinifyEnabled = false\n            isShrinkResources = false\n            proguardFiles(getDefaultProguardFile("proguard-android.txt"), "proguard-rules.pro")'
        )
        print("[Patch] Disabled isMinifyEnabled and isShrinkResources in kts release build")

    with open(kts_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("[Patch] Updated android/app/build.gradle.kts successfully")

print("[Patch] Patching completed.")
