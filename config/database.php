<?php
// Conexión PDO a MySQL — Sistema Casa Bosque
// Lee credenciales desde .env y retorna una instancia PDO reutilizable

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        // Leer configuración desde .env
        $envFile = dirname(__DIR__, 2) . '/casabosque-private/.env';
        $env = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pos = strpos($line, '=');
                if ($pos === false) continue;
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $env[$key] = $value;
            }
        } else {
            error_log('CRITICAL: .env file not found at ' . $envFile);
            throw new RuntimeException('Error de configuración del servidor.');
        }

        // El nombre de la base y el usuario NO tienen valor por defecto: se
        // declaran o no se conecta. Antes caían a 'casa_hotel' y a root con
        // contraseña vacía, así que un .env incompleto podía acabar conectado
        // a una base distinta de la que se cree, o entrando como root si el
        // servidor lo permitiera. Es el mismo criterio que cifrado.php aplica
        // a las claves de cifrado: falla cerrado y lo escribe en el log.
        foreach (['DB_NAME', 'DB_USER'] as $obligatoria) {
            if (trim((string) ($env[$obligatoria] ?? '')) === '') {
                error_log('CRITICAL: ' . $obligatoria . ' ausente o vacía en ' . $envFile);
                throw new RuntimeException('Error de configuración del servidor.');
            }
        }

        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $db   = $env['DB_NAME'];
        $user = $env['DB_USER'];
        // DB_PASS sí puede ir vacía: una base local de pruebas sin contraseña
        // es legítima, y quien la declara vacía lo está diciendo a propósito.
        $pass = $env['DB_PASS'] ?? '';
        $charset = $env['DB_CHARSET'] ?? 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            // Zona horaria de Colombia para que now()/curdate() de MySQL
            // coincidan con la hora real (el servidor suele estar en UTC).
            $pdo->exec("set time_zone = '-05:00'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Error de conexión con la base de datos.');
        }
    }

    return $pdo;
}