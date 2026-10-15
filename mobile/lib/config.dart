/// Change this to your server URL (folder where api.php is uploaded).
const String kServerBase = 'https://track.galoya.lk';

String get kApiUrl => '$kServerBase/api.php';
String photoUrl(String path) => '$kServerBase/uploads/$path';

/// Default GPS interval in seconds (1 minute). Server setting overrides this.
const int kDefaultIntervalSec = 60;
