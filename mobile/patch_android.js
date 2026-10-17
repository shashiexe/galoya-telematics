const fs = require('fs');
const path = require('path');

console.log('[Patch] Running patch_android...');

// 1. Copy AndroidManifest.xml template
const templatePath = path.join(__dirname, 'AndroidManifest.xml.template');
const targetManifest = path.join(__dirname, 'android/app/src/main/AndroidManifest.xml');
if (fs.existsSync(templatePath) && fs.existsSync(path.dirname(targetManifest))) {
    fs.copyFileSync(templatePath, targetManifest);
    console.log('[Patch] Copied AndroidManifest.xml.template successfully');
}

// 1.1 Copy network_security_config.xml
const netSecTemplate = path.join(__dirname, 'network_security_config.xml.template');
const netSecTargetDir = path.join(__dirname, 'android/app/src/main/res/xml');
const netSecTarget = path.join(netSecTargetDir, 'network_security_config.xml');
if (fs.existsSync(netSecTemplate)) {
    if (!fs.existsSync(netSecTargetDir)) fs.mkdirSync(netSecTargetDir, { recursive: true });
    fs.copyFileSync(netSecTemplate, netSecTarget);
    console.log('[Patch] Copied network_security_config.xml.template successfully');
}

// 1.2 Copy proguard-rules.pro
const proguardTemplate = path.join(__dirname, 'proguard-rules.pro.template');
const proguardTarget = path.join(__dirname, 'android/app/proguard-rules.pro');
if (fs.existsSync(proguardTemplate)) {
    fs.copyFileSync(proguardTemplate, proguardTarget);
    console.log('[Patch] Copied proguard-rules.pro successfully');
}

// 1.3 Configure gradle.properties (Disable R8 FullMode)
const gradleProps = path.join(__dirname, 'android/gradle.properties');
const gradlePropsTemplate = path.join(__dirname, 'gradle.properties.template');
if (fs.existsSync(gradlePropsTemplate) && !fs.existsSync(gradleProps)) {
    fs.copyFileSync(gradlePropsTemplate, gradleProps);
    console.log('[Patch] Copied gradle.properties.template successfully');
} else if (fs.existsSync(gradleProps)) {
    let props = fs.readFileSync(gradleProps, 'utf8');
    if (!props.includes('android.enableR8.fullMode')) {
        props += "\nandroid.enableR8.fullMode=false\nandroid.useAndroidX=true\nandroid.enableJetifier=true\n";
        fs.writeFileSync(gradleProps, props, 'utf8');
        console.log('[Patch] Set android.enableR8.fullMode=false in gradle.properties');
    }
}

// 2. Patch build.gradle (Groovy)
const gradlePath = path.join(__dirname, 'android/app/build.gradle');
if (fs.existsSync(gradlePath)) {
    let content = fs.readFileSync(gradlePath, 'utf8');

    if (!content.includes('coreLibraryDesugaringEnabled')) {
        content = content.replace(
            'compileOptions {',
            'compileOptions {\n        coreLibraryDesugaringEnabled true'
        );
        console.log('[Patch] Enabled coreLibraryDesugaringEnabled');
    }

    if (!content.includes('coreLibraryDesugaring')) {
        if (content.includes('dependencies {')) {
            content = content.replace(
                'dependencies {',
                "dependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n    implementation 'androidx.multidex:multidex:2.0.1'"
            );
        } else {
            content += "\ndependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n    implementation 'androidx.multidex:multidex:2.0.1'\n}\n";
        }
        console.log('[Patch] Added coreLibraryDesugaring dependency');
    }

    if (!content.includes('multiDexEnabled') && content.includes('defaultConfig {')) {
        content = content.replace(
            'defaultConfig {',
            "defaultConfig {\n        multiDexEnabled true\n        ndk {\n            abiFilters 'armeabi-v7a', 'arm64-v8a', 'x86_64'\n        }"
        );
        console.log('[Patch] Added multiDexEnabled and abiFilters');
    }

    if (content.includes('buildTypes {') && content.includes('release {')) {
        content = content.replace(
            'release {',
            "release {\n            minifyEnabled false\n            shrinkResources false\n            proguardFiles getDefaultProguardFile('proguard-android.txt'), 'proguard-rules.pro'"
        );
    }

    fs.writeFileSync(gradlePath, content, 'utf8');
    console.log('[Patch] build.gradle patched');
}

// 3. Patch build.gradle.kts (Kotlin DSL) if used
const ktsPath = path.join(__dirname, 'android/app/build.gradle.kts');
if (fs.existsSync(ktsPath)) {
    let content = fs.readFileSync(ktsPath, 'utf8');

    if (!content.includes('isCoreLibraryDesugaringEnabled')) {
        content = content.replace(
            'compileOptions {',
            'compileOptions {\n        isCoreLibraryDesugaringEnabled = true'
        );
    }

    if (!content.includes('coreLibraryDesugaring')) {
        if (content.includes('dependencies {')) {
            content = content.replace(
                'dependencies {',
                'dependencies {\n    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")\n    implementation("androidx.multidex:multidex:2.0.1")'
            );
        } else {
            content += '\ndependencies {\n    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")\n    implementation("androidx.multidex:multidex:2.0.1")\n}\n';
        }
    }

    if (!content.includes('multiDexEnabled') && content.includes('defaultConfig {')) {
        content = content.replace(
            'defaultConfig {',
            'defaultConfig {\n        multiDexEnabled = true\n        ndk {\n            abiFilters.addAll(listOf("armeabi-v7a", "arm64-v8a", "x86_64"))\n        }'
        );
    }

    if (content.includes('release {') && !content.includes('isMinifyEnabled')) {
        content = content.replace(
            'signingConfig = signingConfigs.getByName("debug")',
            'signingConfig = signingConfigs.getByName("debug")\n            isMinifyEnabled = false\n            isShrinkResources = false\n            proguardFiles(getDefaultProguardFile("proguard-android.txt"), "proguard-rules.pro")'
        );
        console.log('[Patch] Disabled isMinifyEnabled and isShrinkResources in kts');
    }

    fs.writeFileSync(ktsPath, content, 'utf8');
    console.log('[Patch] build.gradle.kts patched');
}

console.log('[Patch] Completed.');
