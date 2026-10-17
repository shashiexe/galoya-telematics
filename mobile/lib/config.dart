/// Change this to your server URL (folder where api.php is uploaded).
const String kServerBase = 'https://track.galoya.lk';
const String kServerBaseHttp = 'http://track.galoya.lk';

String get kApiUrl => '$kServerBase/api.php';
String get kApiUrlHttp => '$kServerBaseHttp/api.php';
String photoUrl(String path) => '$kServerBase/uploads/$path';

/// Default GPS interval in seconds (15 seconds for smooth bicycle/vehicle tracking).
const int kDefaultIntervalSec = 15;

