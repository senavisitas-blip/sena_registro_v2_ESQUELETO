<?php
// ============================================================================
// controllers/AprendizController.php
// ----------------------------------------------------------------------------
// CONTROLADOR (la "C" de MVC).
// Responsabilidad única: recibir la petición del usuario (clic, formulario),
// pedirle los datos o la acción al MODELO, y luego elegir qué VISTA mostrar
// pasándole los datos ya listos. El controlador NUNCA contiene SQL ni HTML
// directamente; solo "orquesta" el flujo entre Modelo y Vista.
//
// A partir de esta versión, cada método empieza pidiéndole permiso a la
// clase Auth (Auth::requerirAutenticacion / Auth::requerirRol). Esa es la
// forma en que se aplican los ROLES: no se revisa el rol dentro del Modelo
// ni en la Vista, sino aquí, ANTES de ejecutar cualquier acción sensible.
// ============================================================================

// Se incluye la clase de conexión, el modelo y el helper de autenticación/roles.
require_once __DIR__ . "/../config/Database.php";
require_once __DIR__ . "/../config/Auth.php";
require_once __DIR__ . "/../models/AprendizModel.php";

class AprendizController
{
    // Guardará la instancia del modelo con la que hablará este controlador.
    private $modelo;

    // Constructor: arma la conexión y crea el modelo listo para usarse.
    public function __construct()
    {
        $db = new Database();                       // Se crea la conexión a la BD
        $conexionPDO = $db->getConexion();           // Se obtiene el objeto PDO ya conectado
        $this->modelo = new AprendizModel($conexionPDO); // Se inyecta el PDO en el modelo
    }

    // ------------------------------------------------------------------
    // Acción: listar todos los aprendices (pantalla principal).
    // Permiso: cualquier usuario autenticado (aprendiz, docente o coordinador)
    // puede VER el listado; por eso solo se exige sesión, sin restringir rol.
    // ------------------------------------------------------------------
    public function listar()
    {
        // Se exige que haya una sesión iniciada; si no, redirige al login.
        Auth::requerirAutenticacion();

        // Se le pide al modelo TODOS los registros.
        $aprendices = $this->modelo->obtenerTodos();

        // Se incluye la vista y se le "pasa" la variable $aprendices; dentro
        // de la vista esa variable ya estará disponible para pintarla en HTML.
        require __DIR__ . "/../views/aprendices/listar.php";
    }

    // ------------------------------------------------------------------
    // Acción: mostrar el formulario vacío para crear un nuevo aprendiz.
    // Permiso: SOLO coordinador puede registrar aprendices nuevos. El
    // docente puede VER, EDITAR e inactivar por retiro, pero no CREAR
    // (así lo pidió el proyecto: el alta inicial queda en manos del
    // coordinador, y el docente se enfoca en el seguimiento académico).
    // ------------------------------------------------------------------
    public function mostrarFormularioCrear()
    {
        // Se exige sesión Y que el rol esté entre los permitidos.
        Auth::requerirRol(["coordinador"]);

        // No necesita datos del modelo, solo debe mostrar la vista del formulario.
        require __DIR__ . "/../views/aprendices/formulario_crear.php";
    }

    // ------------------------------------------------------------------
    // Acción: procesar el envío (POST) del formulario de creación.
    // Permiso: igual que arriba, solo coordinador.
    // ------------------------------------------------------------------
    public function guardar()
    {
        // Se valida el permiso ANTES de tocar la base de datos.
        Auth::requerirRol(["coordinador"]);

        // Se valida que la petición realmente venga por POST (envío de formulario).
        if ($_SERVER["REQUEST_METHOD"] === "POST") {

            // Se leen y limpian los datos que llegaron del formulario.
            // trim() quita espacios sobrantes al inicio/fin del texto.
            $nombre = trim($_POST["nombre"] ?? "");
            $ficha  = trim($_POST["ficha"] ?? "");

            // Validación mínima en el controlador: campos no vacíos.
            if ($nombre !== "" && $ficha !== "") {
                // Se delega la inserción real al modelo.
                $this->modelo->crear($nombre, $ficha);
            }

            // Tras guardar, se redirige al listado (patrón Post/Redirect/Get
            // evita que al recargar la página se vuelva a enviar el formulario).
            header("Location: index.php?accion=listar");
            exit; // Se detiene la ejecución inmediatamente después de redirigir
        }
    }

    // ------------------------------------------------------------------
    // Acción: mostrar el formulario de edición ya lleno con los datos actuales.
    // Permiso: SOLO docente o coordinador pueden editar.
    // ------------------------------------------------------------------
    public function mostrarFormularioEditar()
    {
        Auth::requerirRol(["docente", "coordinador"]);

        // Se obtiene el id enviado por la URL, ej: index.php?accion=editar&id=3
        $id = $_GET["id"] ?? null;

        // Si no llegó un id válido, se regresa al listado para evitar errores.
        if ($id === null) {
            header("Location: index.php?accion=listar");
            exit;
        }

        // Se pide al modelo los datos actuales de ese aprendiz.
        $aprendiz = $this->modelo->obtenerPorId($id);

        // Se muestra la vista del formulario de edición, ya con $aprendiz disponible.
        require __DIR__ . "/../views/aprendices/formulario_editar.php";
    }

    // ------------------------------------------------------------------
    // Acción: procesar el envío (POST) del formulario de edición.
    // Permiso: SOLO docente o coordinador.
    // ------------------------------------------------------------------
    public function actualizar()
    {
        Auth::requerirRol(["docente", "coordinador"]);

        // Se valida nuevamente que la petición sea POST.
        if ($_SERVER["REQUEST_METHOD"] === "POST") {

            // Se leen los datos enviados desde el formulario oculto/visible.
            $id     = $_POST["id"] ?? null;
            $nombre = trim($_POST["nombre"] ?? "");
            $ficha  = trim($_POST["ficha"] ?? "");

            // Solo se actualiza si hay un id y los campos no están vacíos.
            if ($id !== null && $nombre !== "" && $ficha !== "") {
                $this->modelo->actualizar($id, $nombre, $ficha);
            }

            // Redirección al listado tras actualizar.
            header("Location: index.php?accion=listar");
            exit;
        }
    }

    // ------------------------------------------------------------------
    // Acción: marcar a un aprendiz como "retirado" (inactivo), sin borrar
    // su registro. Permiso: docente Y coordinador, ya que es el docente
    // quien normalmente detecta y gestiona el retiro de un aprendiz.
    // ------------------------------------------------------------------
    public function inactivar()
    {
        Auth::requerirRol(["docente", "coordinador"]);

        $id = $_GET["id"] ?? null;

        if ($id !== null) {
            $this->modelo->inactivarPorRetiro($id);
        }

        header("Location: index.php?accion=listar");
        exit;
    }

    // ------------------------------------------------------------------
    // Acción: reactivar a un aprendiz previamente marcado como "retirado"
    // (por ejemplo, si se reintegra al programa). Mismo permiso que inactivar.
    // ------------------------------------------------------------------
    public function activar()
    {
        Auth::requerirRol(["docente", "coordinador"]);

        $id = $_GET["id"] ?? null;

        if ($id !== null) {
            $this->modelo->activar($id);
        }

        header("Location: index.php?accion=listar");
        exit;
    }

    // ------------------------------------------------------------------
    // Acción: eliminar un aprendiz según el id recibido por GET.
    // Permiso: SOLO coordinador puede eliminar (acción más delicada, se
    // reserva al rol de mayor jerarquía como ejemplo de granularidad de roles).
    // Nota: esto BORRA el registro por completo (y en cascada sus notas).
    // Para el caso normal de "el aprendiz se retiró", se debe usar
    // inactivar() en vez de esto, que conserva el historial.
    // ------------------------------------------------------------------
    public function eliminar()
    {
        Auth::requerirRol(["coordinador"]);

        // Se obtiene el id desde la URL, ej: index.php?accion=eliminar&id=5
        $id = $_GET["id"] ?? null;

        // Solo se elimina si efectivamente llegó un id.
        if ($id !== null) {
            $this->modelo->eliminar($id);
        }

        // Se regresa siempre al listado, haya o no haya podido eliminar.
        header("Location: index.php?accion=listar");
        exit;
    }
}
