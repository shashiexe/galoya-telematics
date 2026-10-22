/// Change this to your server URL (folder where api.php is uploaded).
const String kServerBase = 'https://track.galoya.lk';
const String kServerBaseHttp = 'http://track.galoya.lk';

String get kApiUrl => '$kServerBase/api.php';
String get kApiUrlHttp => '$kServerBaseHttp/api.php';
String photoUrl(String path) => '$kServerBase/uploads/$path';

/// Default GPS interval in seconds (15 seconds for smooth bicycle/vehicle tracking).
const int kDefaultIntervalSec = 15;

/// App Branding & OTA Updates
const String kAppName = 'GalOya Track360';
const String kAppTagline = 'People • Field • Fleet';
const String kAppVersion = '1.3.7';
const int kAppBuildNumber = 22;
const String kCreatedBy = 'Created by GOPL IT';
const String kOrganization = 'Gal-Oya Plantations (Pvt) Limited';
const String kApkDownloadUrl = 'https://track.galoya.lk/Galoya-Telematics.apk';


