<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function field(array $data, string $key, int $max): string {
    $value = $data[$key] ?? '';
    if (!is_string($value)) respond(422, ['error' => 'Datos no válidos.']);
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) > $max) respond(422, ['error' => "El campo $key es demasiado largo."]);
    return $value;
}

function database(): PDO {
    $directory = getenv('LATAM_DATA_DIR') ?: dirname(__DIR__) . '/latam-data';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        respond(503, ['error' => 'El almacenamiento no está disponible.']);
    }
    $db = new PDO('sqlite:' . $directory . '/registrations.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec("CREATE TABLE IF NOT EXISTS places (id TEXT PRIMARY KEY, name TEXT NOT NULL, region TEXT NOT NULL, country TEXT NOT NULL, lat REAL NOT NULL, lon REAL NOT NULL, kind TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE TABLE IF NOT EXISTS registrations (id INTEGER PRIMARY KEY, place_id TEXT NOT NULL REFERENCES places(id), name TEXT NOT NULL, birth_date TEXT NOT NULL, religion TEXT NOT NULL, occupation TEXT NOT NULL, nickname TEXT NOT NULL, public_profile INTEGER NOT NULL, mayor_detained INTEGER NOT NULL DEFAULT 0, delete_hash TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS searches (query TEXT PRIMARY KEY, results TEXT NOT NULL, updated_at INTEGER NOT NULL)');
    $placeColumns = array_column($db->query('PRAGMA table_info(places)')->fetchAll(), 'name');
    if (!in_array('kind', $placeColumns, true)) {
        $db->exec("ALTER TABLE places ADD COLUMN kind TEXT NOT NULL DEFAULT ''");
        $db->exec('DELETE FROM searches');
    }
    $registrationColumns = array_column($db->query('PRAGMA table_info(registrations)')->fetchAll(), 'name');
    if (!in_array('mayor_detained', $registrationColumns, true)) {
        $db->exec('ALTER TABLE registrations ADD COLUMN mayor_detained INTEGER NOT NULL DEFAULT 0');
    }
    return $db;
}

function registrations(PDO $db): array {
    $rows = $db->query('SELECT p.id AS place_id, p.name AS city, p.region, p.country, p.lat, p.lon, r.name, r.birth_date, r.religion, r.occupation, r.nickname, r.public_profile, r.mayor_detained FROM registrations r JOIN places p ON p.id = r.place_id ORDER BY r.id DESC')->fetchAll();
    return array_map(static function (array $row): array {
        $public = (bool) $row['public_profile'];
        return [
            'place_id' => $row['place_id'], 'city' => $row['city'], 'region' => $row['region'],
            'country' => $row['country'], 'lat' => (float) $row['lat'], 'lon' => (float) $row['lon'],
            'nickname' => $row['nickname'], 'name' => $public ? $row['name'] : '',
            'birth_date' => $public ? $row['birth_date'] : '', 'religion' => $public ? $row['religion'] : '',
            'occupation' => $public ? $row['occupation'] : '', 'mayor_detained' => (bool) $row['mayor_detained'],
        ];
    }, $rows);
}

try {
    $db = database();
    $route = $_GET['route'] ?? 'registrations';
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'registrations') {
        respond(200, ['registrations' => registrations($db)]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $route === 'places') {
        $query = field($_GET, 'q', 100);
        if (mb_strlen($query) < 2) respond(422, ['error' => 'Escribe al menos dos letras.']);
        $key = mb_strtolower($query);
        $stmt = $db->prepare('SELECT results FROM searches WHERE query = ? AND updated_at > ?');
        $stmt->execute([$key, time() - 30 * 86400]);
        $cached = $stmt->fetchColumn();
        if ($cached !== false) respond(200, ['places' => json_decode($cached, true)]);

        // Serialize requests across PHP workers to respect Nominatim's public limit.
        $lockPath = (getenv('LATAM_DATA_DIR') ?: dirname(__DIR__) . '/latam-data') . '/geocode.lock';
        $lock = fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) respond(503, ['error' => 'La búsqueda no está disponible.']);
        $last = (float) stream_get_contents($lock);
        $wait = 1.1 - (microtime(true) - $last);
        if ($wait > 0) usleep((int) ($wait * 1000000));
        rewind($lock);
        ftruncate($lock, 0);
        fwrite($lock, (string) microtime(true));
        fflush($lock);
        flock($lock, LOCK_UN);
        fclose($lock);

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 8,
            'countrycodes' => 'ar,bo,br,cl,co,cr,cu,do,ec,sv,gt,hn,mx,ni,pa,py,pe,pr,uy,ve,bz,ht,jm,gy,sr,tt',
            'accept-language' => 'es',
        ]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Latam community map (https://github.com/danieluxury88/latam)',
            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $response = curl_exec($curl);
        $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($response === false || $http !== 200) respond(503, ['error' => 'No se pudo consultar el buscador de lugares.']);
        $results = json_decode($response, true);
        if (!is_array($results)) respond(503, ['error' => 'Respuesta de lugares no válida.']);
        $places = [];
        foreach ($results as $item) {
            if (!in_array($item['addresstype'] ?? '', ['city', 'town', 'village', 'municipality', 'state', 'region', 'province', 'county'], true)) continue;
            $address = $item['address'] ?? [];
            $lat = (float) ($item['lat'] ?? 0);
            $lon = (float) ($item['lon'] ?? 0);
            if ($lat < -56 || $lat > 33 || $lon < -118 || $lon > -34) continue;
            $id = ($item['osm_type'] ?? '') . ':' . ($item['osm_id'] ?? '');
            if (!preg_match('/^(node|way|relation):\d+$/', $id)) continue;
            $name = mb_substr((string) ($item['name'] ?? explode(',', (string) ($item['display_name'] ?? ''))[0]), 0, 100);
            $region = mb_substr((string) ($address['state'] ?? $address['region'] ?? ''), 0, 100);
            $country = mb_substr((string) ($address['country'] ?? ''), 0, 100);
            $place = ['id' => $id, 'name' => $name, 'region' => $region, 'country' => $country, 'lat' => $lat, 'lon' => $lon, 'kind' => (string) $item['addresstype']];
            $stmt = $db->prepare('INSERT INTO places (id, name, region, country, lat, lon, kind) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET name = excluded.name, region = excluded.region, country = excluded.country, lat = excluded.lat, lon = excluded.lon, kind = excluded.kind');
            $stmt->execute(array_values($place));
            $places[] = $place;
        }
        $stmt = $db->prepare('INSERT OR REPLACE INTO searches (query, results, updated_at) VALUES (?, ?, ?)');
        $stmt->execute([$key, json_encode($places, JSON_UNESCAPED_UNICODE), time()]);
        respond(200, ['places' => $places]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'registrations') {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 4096) respond(413, ['error' => 'El formulario es demasiado grande.']);
        $data = json_decode($raw, true);
        if (!is_array($data)) respond(400, ['error' => 'Solicitud no válida.']);
        $place = field($data, 'place_id', 40);
        $stmt = $db->prepare('SELECT kind FROM places WHERE id = ?');
        $stmt->execute([$place]);
        $placeRow = $stmt->fetch();
        if (!$placeRow) respond(422, ['error' => 'Busca y selecciona una ciudad o provincia.']);
        $mayorDetained = ($data['mayor_detained'] ?? false) === true;
        if ($mayorDetained && !in_array($placeRow['kind'], ['city', 'town', 'village', 'municipality'], true)) {
            respond(422, ['error' => 'El aviso del alcalde solo se puede marcar para ciudades o municipios.']);
        }
        $public = ($data['public_profile'] ?? false) === true;
        $name = $public ? field($data, 'name', 100) : '';
        $birth = $public ? field($data, 'birth_date', 10) : '';
        $religion = $public ? field($data, 'religion', 80) : '';
        if (!in_array($religion, ['', 'Cristiano', 'Musulmán', 'Budista', 'Judío', 'Otro'], true)) {
            respond(422, ['error' => 'Selecciona una religión de la lista.']);
        }
        $occupation = $public ? field($data, 'occupation', 80) : '';
        $nickname = field($data, 'nickname', 120);
        if ($birth !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) || $birth > date('Y-m-d') || $birth < '1900-01-01' || !checkdate((int) substr($birth, 5, 2), (int) substr($birth, 8, 2), (int) substr($birth, 0, 4)))) {
            respond(422, ['error' => 'La fecha de nacimiento no es válida.']);
        }
        $code = bin2hex(random_bytes(16));
        $stmt = $db->prepare('INSERT INTO registrations (place_id, name, birth_date, religion, occupation, nickname, public_profile, mayor_detained, delete_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$place, $name, $birth, $religion, $occupation, $nickname, (int) $public, (int) $mayorDetained, hash('sha256', $code)]);
        respond(201, ['delete_code' => $code, 'registrations' => registrations($db)]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $route === 'delete') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) respond(400, ['error' => 'Solicitud no válida.']);
        $code = field($data, 'delete_code', 32);
        if (!preg_match('/^[a-f0-9]{32}$/', $code)) respond(422, ['error' => 'Código no válido.']);
        $stmt = $db->prepare('DELETE FROM registrations WHERE delete_hash = ?');
        $stmt->execute([hash('sha256', $code)]);
        if (!$stmt->rowCount()) respond(404, ['error' => 'No se encontró el registro.']);
        respond(200, ['registrations' => registrations($db)]);
    }
    respond(404, ['error' => 'Ruta no encontrada.']);
} catch (Throwable $error) {
    error_log('Latam API: ' . $error->getMessage());
    respond(503, ['error' => 'El servicio no está disponible ahora.']);
}
