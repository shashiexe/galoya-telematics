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
                "dependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'"
            );
        } else {
            content += "\ndependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n}\n";
        }
        console.log('[Patch] Added coreLibraryDesugaring dependency');
    }

    if (!content.includes('multiDexEnabled') && content.includes('defaultConfig {')) {
        content = content.replace(
            'defaultConfig {',
            'defaultConfig {\n        multiDexEnabled true'
        );
        console.log('[Patch] Added multiDexEnabled');
    }

    fs.writeFileSync(gradlePath, content, 'utf8');
    console.log('[Patch] build.gradle patched');
}

// 3. Patch build.gradle.kts (Kotlin DSL)
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

    fs.writeFileSync(ktsPath, content, 'utf8');
    console.log('[Patch] build.gradle.kts patched');
}

console.log('[Patch] All patches complete!');
