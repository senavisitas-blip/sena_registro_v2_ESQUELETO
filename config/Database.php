<?php
// ============================================================================
// config/Database.php
// ----------------------------------------------------------------------------
// Esta clase pertenece a la capa de configuración/infraestructura del patrón
// MVC. No es "Modelo" de negocio (no representa una tabla como "aprendices"),
// pero es usada POR los modelos para obtener la conexión a la base de datos.
// Centralizar la conexión aquí evita repetir el mismo código PDO en cada
// archivo, que era lo que pasaba en la versión original del proyecto.
// ============================================================================

class Database
{
    // Datos de conexión al servidor MySQL. Se declaran como propiedades
    // privadas porque solo esta clase necesita conocerlos (encapsulamiento).
    private $host = "localhost";      // Dirección del servidor de base de datos
    private $puerto = "3306";         // Puerto donde escucha MySQL/MariaDB
    private $bd = "sena_v3"; // Nombre de la base de datos a utilizar
    private $usuario = "root";        // Usuario con el que se autentica PDO
    private $clave = "";              // Contraseña del usuario (vacía en entorno local)

    // Propiedad que guardará el objeto PDO ya conectado, para no crear
    // una nueva conexión cada vez que se llame a getConexion().
    private $conexion = null;

    // Constructor: se ejecuta automáticamente al crear "new Database()".
    public function __construct()
    {
        // Se llama al método que realmente abre la conexión.
        $this->conectar();
    }

    // Método privado: solo esta clase puede invocarlo directamente.
    private function conectar()
    {
        // El bloque try/catch captura errores de conexión sin romper
        // la ejecución de todo el programa con un error fatal.
        try {
            // Se arma el DSN (Data Source Name) con host, puerto y base de datos.
            $dsn = "mysql:host={$this->host};port={$this->puerto};dbname={$this->bd}";

            // Se crea el objeto PDO, que es el que ejecuta consultas SQL.
            $this->conexion = new PDO($dsn, $this->usuario, $this->clave);

            // Se configura PDO para que lance excepciones ante errores SQL
            // en vez de fallar en silencio; esto facilita depurar errores.
            $this->conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $error) {
            // Si algo falla (credenciales, servidor apagado, etc.) se detiene
            // la ejecución mostrando un mensaje claro en lugar de un error críptico.
            die("Error de conexión a la base de datos: " . $error->getMessage());
        }
    }

    // Método público que los Modelos usarán para obtener la conexión PDO
    // ya lista para ejecutar consultas.
    public function getConexion()
    {
        // Se retorna el objeto PDO guardado en la propiedad $conexion.
        return $this->conexion;
    }
}
