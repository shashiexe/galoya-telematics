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

# 1.25 Copy custom app_icon.png to all mipmap directories
app_icon_source = "app_icon.png"
if os.path.exists(app_icon_source):
    mipmap_dirs = [
        "android/app/src/main/res/mipmap-mdpi",
        "android/app/src/main/res/mipmap-hdpi",
        "android/app/src/main/res/mipmap-xhdpi",
        "android/app/src/main/res/mipmap-xxhdpi",
        "android/app/src/main/res/mipmap-xxxhdpi",
    ]
    for mdir in mipmap_dirs:
        os.makedirs(mdir, exist_ok=True)
        shutil.copyfile(app_icon_source, os.path.join(mdir, "ic_launcher.png"))
    anydpi = "android/app/src/main/res/mipmap-anydpi-v26"
    if os.path.exists(anydpi):
        shutil.rmtree(anydpi, ignore_errors=True)
        print("[Patch] Removed default mipmap-anydpi-v26 to ensure custom ic_launcher is loaded!")
    print("[Patch] Copied custom app_icon.png to all mipmap directories!")

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

    # Ensure multiDexEnabled
    if "multiDexEnabled" not in content and "defaultConfig {" in content:
        content = content.replace(
            "defaultConfig {",
            "defaultConfig {\n        multiDexEnabled true"
        )
        print("[Patch] Added multiDexEnabled true to defaultConfig")

    # Disable lint vital checks
    if "lintOptions {" not in content and "android {" in content:
        content = content.replace(
            "android {",
            "android {\n    lintOptions {\n        checkReleaseBuilds false\n        abortOnError false\n    }"
        )
        print("[Patch] Added lintOptions checkReleaseBuilds false to Groovy")

    # Ensure minifyEnabled false and shrinkResources false in release buildType
    if "buildTypes {" in content:
        content = content.replace(
            "release {",
            "release {\n            minifyEnabled false\n            shrinkResources false\n            proguardFiles getDefaultProguardFile('proguard-android-optimize.txt'), 'proguard-rules.pro'"
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
            "defaultConfig {\n        multiDexEnabled = true"
        )
        print("[Patch] Added multiDexEnabled = true to kts defaultConfig")

    if "lint {" not in content and "android {" in content:
        content = content.replace(
            "android {",
            "android {\n    lint {\n        checkReleaseBuilds = false\n        abortOnError = false\n    }"
        )
        print("[Patch] Added lint checkReleaseBuilds = false to kts")

    # Disable minification and add ProGuard in release build
    if "release {" in content and "isMinifyEnabled" not in content:
        content = content.replace(
            'signingConfig = signingConfigs.getByName("debug")',
            'signingConfig = signingConfigs.getByName("debug")\n            isMinifyEnabled = false\n            isShrinkResources = false\n            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")'
        )
        print("[Patch] Disabled isMinifyEnabled and isShrinkResources in kts release build")

    with open(kts_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("[Patch] Updated android/app/build.gradle.kts successfully")

# 4. Patch Gradle wrapper (Prevent GitHub 504 timeout by routing via fast Tencent mirror)
wrapper_path = "android/gradle/wrapper/gradle-wrapper.properties"
if os.path.exists(wrapper_path):
    with open(wrapper_path, "r", encoding="utf-8") as f:
        wrapper_content = f.read()
    import re
    m = re.search(r'(gradle-[\d\.]+(-all|-bin)\.zip)', wrapper_content)
    dist_file = m.group(1) if m else "gradle-8.10.2-all.zip"
    wrapper_content = re.sub(
        r'distributionUrl=.*',
        f'distributionUrl=https\\\\://mirrors.cloud.tencent.com/gradle/{dist_file}',
        wrapper_content
    )
    with open(wrapper_path, "w", encoding="utf-8") as f:
        f.write(wrapper_content)
    print(f"[Patch] Pinned Gradle wrapper to Tencent mirror: {dist_file}")

# 5. Route MavenCentral via Cloudflare repo1.maven.org to prevent Apache Maven -> GitHub 504 timeout
for root, _, files in os.walk("android"):
    for file in files:
        if file.endswith((".gradle", ".kts")):
            fpath = os.path.join(root, file)
            with open(fpath, "r", encoding="utf-8", errors="ignore") as f:
                c = f.read()
            if "mavenCentral()" in c:
                if file.endswith(".kts"):
                    new_c = c.replace("mavenCentral()", 'maven { url = uri("https://repo1.maven.org/maven2/") }')
                else:
                    new_c = c.replace("mavenCentral()", "maven { url 'https://repo1.maven.org/maven2/' }")
                with open(fpath, "w", encoding="utf-8") as f:
                    f.write(new_c)
                print(f"[Patch] Replaced mavenCentral() with repo1.maven.org in {fpath}")

# 6. Disable all lintVitalAnalyzeRelease and lint tasks across all subprojects
root_gradle = "android/build.gradle"
if os.path.exists(root_gradle):
    with open(root_gradle, "a", encoding="utf-8") as f:
        f.write("\nsubprojects {\n    afterEvaluate {\n        tasks.matching { it.name.contains('lint') }.configureEach {\n            enabled = false\n        }\n    }\n}\n")
    print("[Patch] Disabled all lint tasks across subprojects in android/build.gradle")

root_kts = "android/build.gradle.kts"
if os.path.exists(root_kts):
    with open(root_kts, "a", encoding="utf-8") as f:
        f.write('\nsubprojects {\n    afterEvaluate {\n        tasks.matching { it.name.contains("lint") }.configureEach {\n            enabled = false\n        }\n    }\n}\n')
    print("[Patch] Disabled all lint tasks across subprojects in android/build.gradle.kts")

print("[Patch] Patching completed.")
