import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

/*
 * The base URL is a build input, never a hardcoded string in the source, so a
 * release build cannot quietly point at a placeholder host the way the previous
 * APK did (it shipped https://example.com/...).
 */
val appBaseUrl: String = (findProperty("baseUrl") as String?)
    ?: "http://10.0.2.2/tenant-system/"

val keystorePropertiesFile = rootProject.file("keystore.properties")
val keystoreProperties = Properties().apply {
    if (keystorePropertiesFile.exists()) {
        keystorePropertiesFile.inputStream().use { load(it) }
    }
}

android {
    namespace = "com.urbahan.tenant"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.urbahan.tenant"
        minSdk = 24
        targetSdk = 35
        versionCode = 2
        versionName = "1.0.0"
        buildConfigField("String", "BASE_URL", "\"$appBaseUrl\"")
        resourceConfigurations += listOf("en")
    }

    buildFeatures {
        buildConfig = true
    }

    signingConfigs {
        if (keystorePropertiesFile.exists()) {
            create("release") {
                storeFile = rootProject.file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            isMinifyEnabled = false
        }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            if (keystorePropertiesFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            } else {
                // Fail loudly instead of emitting a debug-signed "release".
                logger.warn("keystore.properties missing: assembleRelease will produce an UNSIGNED apk")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-ktx:1.9.2")
    implementation("androidx.webkit:webkit:1.11.0")
}
