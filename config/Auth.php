<?php
// ============================================================================
// config/Auth.php
// ----------------------------------------------------------------------------
// Esta clase centraliza todo lo relacionado con la SESIÓN del usuario que ya
// inició sesión: quién es, qué rol tiene, y si tiene permiso para hacer cierta
// acción. No es un Modelo de negocio (no representa una tabla de datos como
// "aprendices"), es infraestructura de seguridad reutilizada por los
// Controladores para proteger las rutas (patrón conocido como "guard"/"middleware").
// ============================================================================

class Auth
{
    // ------------------------------------------------------------------
    // Inicia la sesión de PHP si todavía no hay una activa. Debe llamarse
    // al principio de cada petición, antes de leer o escribir $_SESSION.
    // ------------------------------------------------------------------
    public static function iniciar()
    {
        // session_status() evita el error "session already started" si
        // este método se llama más de una vez en la misma petición.
        if (session_status() === PHP_SESSION_NONE) {
            session_start(); // Abre (o retoma) la sesión del navegador actual
        }
    }

    // ------------------------------------------------------------------
    // Guarda en la sesión los datos del usuario que acaba de iniciar sesión.
    // Se llama justo después de validar usuario y contraseña correctamente.
    // ------------------------------------------------------------------
    public static function iniciarSesionUsuario($usuario)
    {
        // Se guarda solo lo necesario en la sesión (nunca la contraseña).
        $_SESSION["usuario_id"]     = $usuario["id"];     // Identificador único
        $_SESSION["usuario_nombre"] = $usuario["nombre"]; // Nombre para mostrar en pantalla
        $_SESSION["usuario_login"]  = $usuario["usuario"];// Usuario con el que inició sesión
        $_SESSION["usuario_rol"]    = $usuario["rol"];    // Rol: aprendiz, docente o coordinador

        // Si el usuario tiene rol "aprendiz", esta columna indica cuál fila
        // de la tabla "aprendices" es su propio registro (puede ser null si
        // la cuenta todavía no fue vinculada por un coordinador).
        $_SESSION["usuario_aprendiz_id"] = $usuario["aprendiz_id"] ?? null;
    }

    // ------------------------------------------------------------------
    // Indica si hay actualmente un usuario autenticado (con sesión iniciada).
    // ------------------------------------------------------------------
    public static function estaAutenticado()
    {
        // isset() revisa si esa llave existe en el arreglo de sesión.
        return isset($_SESSION["usuario_id"]);
    }

    // ------------------------------------------------------------------
    // Devuelve el rol del usuario autenticado, o null si nadie ha iniciado sesión.
    // ------------------------------------------------------------------
    public static function obtenerRol()
    {
        // El operador ?? devuelve null automáticamente si la llave no existe.
        return $_SESSION["usuario_rol"] ?? null;
    }

    // ------------------------------------------------------------------
    // Devuelve el nombre del usuario autenticado (para mostrar "Hola, Nombre").
    // ------------------------------------------------------------------
    public static function obtenerNombre()
    {
        return $_SESSION["usuario_nombre"] ?? null;
    }

    // ------------------------------------------------------------------
    // Devuelve el id de la cuenta de "usuarios" autenticada actualmente.
    // Se usa, por ejemplo, para impedir que un coordinador elimine su
    // propia cuenta desde el CRUD de usuarios.
    // ------------------------------------------------------------------
    public static function obtenerIdUsuario()
    {
        return $_SESSION["usuario_id"] ?? null;
    }

    // ------------------------------------------------------------------
    // Devuelve el id de aprendiz vinculado a la cuenta actual (solo aplica
    // a usuarios con rol "aprendiz"; será null si no está vinculado).
    // ------------------------------------------------------------------
    public static function obtenerAprendizId()
    {
        return $_SESSION["usuario_aprendiz_id"] ?? null;
    }

    // ------------------------------------------------------------------
    // Verifica si el rol actual está dentro de una lista de roles permitidos.
    // Ejemplo de uso: Auth::tieneRol(["docente", "coordinador"])
    // ------------------------------------------------------------------
    public static function tieneRol(array $rolesPermitidos)
    {
        // in_array busca si el rol actual está dentro del arreglo permitido.
        return in_array(self::obtenerRol(), $rolesPermitidos, true);
    }

    // ------------------------------------------------------------------
    // "Guardia de ruta": si el usuario no está autenticado, lo manda al login.
    // Se debe llamar al inicio de cualquier acción que requiera sesión.
    // ------------------------------------------------------------------
    public static function requerirAutenticacion()
    {
        if (!self::estaAutenticado()) {
            // Se guarda un mensaje para mostrarlo en la pantalla de login.
            $_SESSION["error_login"] = "Debes iniciar sesión para continuar.";
            header("Location: index.php?accion=login");
            exit; // Se detiene la ejecución: nunca debe seguir cargando la página protegida
        }
    }

    // ------------------------------------------------------------------
    // "Guardia de rol": exige sesión Y que el rol esté en la lista permitida.
    // Si el usuario está autenticado pero con un rol sin permiso, se le
    // devuelve al listado con un mensaje, en vez de dejarlo pasar.
    // ------------------------------------------------------------------
    public static function requerirRol(array $rolesPermitidos)
    {
        // Primero se exige que haya sesión iniciada.
        self::requerirAutenticacion();

        // Luego se valida que el rol tenga permiso para esta acción.
        if (!self::tieneRol($rolesPermitidos)) {
            // Se guarda un mensaje de "acceso denegado" para mostrarlo al usuario.
            $_SESSION["error_permiso"] = "No tienes permiso para realizar esta acción.";
            header("Location: index.php?accion=listar");
            exit;
        }
    }

    // ------------------------------------------------------------------
    // Cierra la sesión del usuario (logout).
    // ------------------------------------------------------------------
    public static function cerrarSesion()
    {
        $_SESSION = [];        // Vacía todas las variables de sesión en memoria
        session_destroy();     // Elimina los datos de sesión guardados en el servidor
    }
}
