<?php
// ============================================================================
// controllers/AuthController.php
// ----------------------------------------------------------------------------
// CONTROLADOR encargado del inicio y cierre de sesión (login/logout).
// Aquí se concentra TODA la validación de errores del formulario de login:
// campos vacíos, usuario inexistente, contraseña incorrecta. La Vista solo
// muestra los mensajes que este Controlador le entrega; el Modelo solo
// consulta la base de datos. Cada capa mantiene su única responsabilidad.
// ============================================================================

require_once __DIR__ . "/../config/Database.php";
require_once __DIR__ . "/../config/Auth.php";
require_once __DIR__ . "/../config/Correo.php";
require_once __DIR__ . "/../models/UsuarioModel.php";
require_once __DIR__ . "/../models/AprendizModel.php";

class AuthController
{
    // Guarda la instancia del modelo de usuarios.
    private $modelo;

    // Modelo de aprendices: se necesita aquí porque el registro público de
    // un APRENDIZ no solo crea una fila en "usuarios", también crea (o
    // vincula) su propia fila en "aprendices" (nombre + ficha).
    private $modeloAprendiz;

    // Constructor: arma la conexión y crea el modelo de usuarios.
    public function __construct()
    {
        $db = new Database();                    // Conexión a la base de datos
        $conexionPDO = $db->getConexion();        // Objeto PDO ya conectado
        $this->modelo = new UsuarioModel($conexionPDO); // Modelo listo para consultar usuarios
        $this->modeloAprendiz = new AprendizModel($conexionPDO);
    }

    // ------------------------------------------------------------------
    // Acción: mostrar el formulario de login.
    // ------------------------------------------------------------------
    public function mostrarLogin()
    {
        // Si el usuario ya tiene sesión iniciada, no tiene sentido ver el
        // login otra vez: se le manda directo a su dashboard.
        if (Auth::estaAutenticado()) {
            header("Location: index.php?accion=dashboard");
            exit;
        }

        // Se recuperan mensajes de error que hayan quedado guardados en sesión
        // (por ejemplo, si Auth::requerirAutenticacion() redirigió hasta aquí).
        $errores = [];
        if (isset($_SESSION["error_login"])) {
            $errores[] = $_SESSION["error_login"]; // Se agrega el mensaje al arreglo
            unset($_SESSION["error_login"]);        // Se borra para que no aparezca de nuevo después
        }

        // Se recupera un posible mensaje de éxito (por ejemplo, tras
        // restablecer la contraseña correctamente) para mostrarlo una sola vez.
        $mensajeExito = null;
        if (isset($_SESSION["mensaje_exito"])) {
            $mensajeExito = $_SESSION["mensaje_exito"];
            unset($_SESSION["mensaje_exito"]);
        }

        // Se muestra la vista del login, con $errores y $mensajeExito disponibles.
        require __DIR__ . "/../views/auth/login.php";
    }

    // ------------------------------------------------------------------
    // Acción: procesar el envío (POST) del formulario de login, con
    // validación de errores paso a paso.
    // ------------------------------------------------------------------
    public function login()
    {
        // Arreglo donde se van acumulando los mensajes de error encontrados.
        $errores = [];

        // Solo se procesa si la petición llegó por POST (formulario enviado).
        if ($_SERVER["REQUEST_METHOD"] === "POST") {

            // Se leen los campos enviados, quitando espacios sobrantes.
            $usuario    = trim($_POST["usuario"] ?? "");
            $contrasena = trim($_POST["contrasena"] ?? "");

            // --- VALIDACIÓN 1: campos vacíos ---------------------------------
            if ($usuario === "") {
                $errores[] = "El campo usuario es obligatorio."; // Falta el usuario
            }
            if ($contrasena === "") {
                $errores[] = "El campo contraseña es obligatorio."; // Falta la contraseña
            }

            // Solo se sigue validando contra la base de datos si no hay
            // errores de campos vacíos (evita una consulta innecesaria).
            if (empty($errores)) {

                // --- VALIDACIÓN 2: el usuario debe existir -------------------
                $datosUsuario = $this->modelo->obtenerPorUsuario($usuario);

                if (!$datosUsuario) {
                    // No se dice "el usuario no existe" por seguridad (para no
                    // revelar a un atacante si el usuario es válido o no);
                    // se usa un mensaje genérico igual que en el caso de clave incorrecta.
                    $errores[] = "Usuario o contraseña incorrectos.";
                } else {
                    // --- VALIDACIÓN 3: la contraseña debe coincidir ----------
                    // password_verify compara el texto ingresado contra el HASH
                    // guardado en la base de datos (nunca se comparan contraseñas
                    // en texto plano).
                    if (!password_verify($contrasena, $datosUsuario["contrasena"])) {
                        $errores[] = "Usuario o contraseña incorrectos.";
                    }
                }
            }

            // --- Si NO hubo ningún error, se inicia sesión -------------------
            if (empty($errores)) {
                Auth::iniciarSesionUsuario($datosUsuario); // Guarda id, nombre, usuario y rol en sesión
                header("Location: index.php?accion=dashboard"); // Redirige al dashboard según su rol
                exit;
            }
        }

        // Si llegaron errores (o no fue POST), se vuelve a mostrar el login
        // con los mensajes de error para que el usuario corrija los datos.
        require __DIR__ . "/../views/auth/login.php";
    }

    // ------------------------------------------------------------------
    // Acción: cerrar la sesión activa y volver al login.
    // ------------------------------------------------------------------
    public function logout()
    {
        Auth::cerrarSesion();               // Borra todos los datos de sesión
        header("Location: index.php?accion=login"); // Redirige al formulario de login
        exit;
    }

    // ====================================================================
    // REGISTRO PÚBLICO (auto-registro de nuevas cuentas)
    // Cualquier visitante SIN sesión puede crear su propia cuenta, pero
    // solo con rol "aprendiz" o "coordinador". Las cuentas de "docente"
    // quedan reservadas al CRUD de usuarios que solo un coordinador ya
    // autenticado puede usar (ver UsuarioController), para que no
    // cualquiera pueda auto-asignarse ese rol.
    // ====================================================================

    // ------------------------------------------------------------------
    // Paso 1: muestra el formulario de registro (vacío la primera vez).
    // ------------------------------------------------------------------
    public function mostrarRegistro()
    {
        // Si ya hay sesión iniciada, no tiene sentido registrarse de nuevo.
        if (Auth::estaAutenticado()) {
            header("Location: index.php?accion=dashboard");
            exit;
        }

        $errores = [];

        // Se conservan los datos ya escritos si el formulario se vuelve a
        // mostrar por errores de validación (para no obligar a reescribir todo).
        $datos = [
            "nombre" => "", "usuario" => "", "correo" => "", "rol" => "aprendiz", "ficha" => "",
        ];

        require __DIR__ . "/../views/auth/registro.php";
    }

    // ------------------------------------------------------------------
    // Paso 2: procesa el envío (POST) del formulario de registro.
    // ------------------------------------------------------------------
    public function registrar()
    {
        $errores = [];

        // Se leen y limpian todos los campos del formulario.
        $datos = [
            "nombre"  => trim($_POST["nombre"] ?? ""),
            "usuario" => trim($_POST["usuario"] ?? ""),
            "correo"  => trim($_POST["correo"] ?? ""),
            "rol"     => trim($_POST["rol"] ?? ""),
            "ficha"   => trim($_POST["ficha"] ?? ""),
        ];
        $contrasena          = $_POST["contrasena"] ?? "";
        $confirmarContrasena = $_POST["confirmar_contrasena"] ?? "";

        if ($_SERVER["REQUEST_METHOD"] === "POST") {

            // --- VALIDACIÓN 1: campos obligatorios -------------------------
            if ($datos["nombre"] === "") {
                $errores[] = "El nombre es obligatorio.";
            }
            if ($datos["usuario"] === "") {
                $errores[] = "El nombre de usuario es obligatorio.";
            }
            if ($datos["correo"] === "") {
                $errores[] = "El correo es obligatorio.";
            } elseif (!filter_var($datos["correo"], FILTER_VALIDATE_EMAIL)) {
                $errores[] = "Escribe un correo electrónico válido.";
            }

            // --- VALIDACIÓN 2: rol permitido para auto-registro -------------
            // Por seguridad, el registro público NUNCA acepta rol "docente":
            // esas cuentas solo las crea un coordinador desde el CRUD interno.
            if (!in_array($datos["rol"], ["aprendiz", "coordinador"], true)) {
                $errores[] = "Rol inválido para registro público.";
            }

            // --- VALIDACIÓN 3: si es aprendiz, la ficha es obligatoria ------
            if ($datos["rol"] === "aprendiz" && $datos["ficha"] === "") {
                $errores[] = "La ficha es obligatoria para registrarte como aprendiz.";
            }

            // --- VALIDACIÓN 4: contraseña -----------------------------------
            if ($contrasena === "") {
                $errores[] = "La contraseña es obligatoria.";
            } elseif (strlen($contrasena) < 6) {
                $errores[] = "La contraseña debe tener mínimo 6 caracteres.";
            }
            if ($confirmarContrasena === "") {
                $errores[] = "Debes confirmar la contraseña.";
            } elseif ($contrasena !== $confirmarContrasena) {
                $errores[] = "Las contraseñas no coinciden.";
            }

            // --- VALIDACIÓN 5: usuario y correo no deben estar en uso -------
            if (empty($errores)) {
                if ($this->modelo->existeUsuario($datos["usuario"])) {
                    $errores[] = "Ese nombre de usuario ya está en uso.";
                }
                if ($this->modelo->existeCorreo($datos["correo"])) {
                    $errores[] = "Ese correo ya está registrado.";
                }
            }

            // --- Si no hubo ningún error, se crea la cuenta -----------------
            if (empty($errores)) {

                $hash = password_hash($contrasena, PASSWORD_DEFAULT);
                $aprendizId = null;

                // Si se registra como aprendiz, primero se crea su propia
                // fila en "aprendices" (nombre + ficha) y se guarda el id
                // devuelto para vincularla de inmediato a la cuenta nueva.
                if ($datos["rol"] === "aprendiz") {
                    $aprendizId = $this->modeloAprendiz->crear($datos["nombre"], $datos["ficha"]);
                }

                $this->modelo->crear($datos["nombre"], $datos["usuario"], $datos["correo"], $hash, $datos["rol"]);

                // Se busca la cuenta recién creada para vincular el aprendiz_id
                // (el método crear() del modelo de usuarios no soporta ese
                // campo directamente, así que se actualiza justo después).
                if ($aprendizId !== null) {
                    $usuarioCreado = $this->modelo->obtenerPorUsuario($datos["usuario"]);
                    $this->modelo->actualizar(
                        $usuarioCreado["id"],
                        $datos["nombre"],
                        $datos["usuario"],
                        $datos["correo"],
                        $datos["rol"],
                        $aprendizId
                    );
                }

                // Se deja iniciada la sesión automáticamente, para que la
                // persona no tenga que volver a loguearse justo después de
                // registrarse (mejor experiencia de usuario).
                $usuarioParaSesion = $this->modelo->obtenerPorUsuario($datos["usuario"]);
                Auth::iniciarSesionUsuario($usuarioParaSesion);

                header("Location: index.php?accion=dashboard");
                exit;
            }
        }

        // Si hubo errores (o no fue POST), se vuelve a mostrar el formulario.
        require __DIR__ . "/../views/auth/registro.php";
    }

    // ====================================================================
    // RECUPERACIÓN DE CONTRASEÑA (3 pasos)
    // Paso 1: mostrarRecuperar()      -> formulario donde se pide el correo
    // Paso 2: enviarRecuperacion()    -> genera el token y envía el correo
    // Paso 3: mostrarRestablecer()    -> formulario de nueva contraseña (con el token de la URL)
    // Paso 4: restablecer()           -> guarda la nueva contraseña
    // ====================================================================

    // ------------------------------------------------------------------
    // Paso 1: muestra el formulario donde la persona escribe su correo.
    // ------------------------------------------------------------------
    public function mostrarRecuperar()
    {
        // Arreglo de errores (vacío la primera vez que se abre el formulario).
        $errores = [];
        require __DIR__ . "/../views/auth/recuperar.php";
    }

    // ------------------------------------------------------------------
    // Paso 2: procesa el envío del formulario de recuperación. Genera un
    // token único, lo guarda con fecha de expiración, y envía el correo
    // con el enlace de restablecimiento.
    // ------------------------------------------------------------------
    public function enviarRecuperacion()
    {
        $errores = [];

        if ($_SERVER["REQUEST_METHOD"] === "POST") {

            // Se lee y limpia el correo escrito en el formulario.
            $correo = trim($_POST["correo"] ?? "");

            // --- VALIDACIÓN 1: el campo no puede estar vacío -----------------
            if ($correo === "") {
                $errores[] = "El campo correo es obligatorio.";
            }
            // --- VALIDACIÓN 2: debe tener formato de correo válido -----------
            elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = "Escribe un correo electrónico válido.";
            }

            // Solo se continúa si el correo pasó las validaciones de formato.
            if (empty($errores)) {

                // Se busca si existe un usuario con ese correo.
                $usuario = $this->modelo->obtenerPorCorreo($correo);

                // Por seguridad, el mensaje que ve la persona es EL MISMO exista
                // o no ese correo en la base de datos: así no se revela cuáles
                // correos están registrados en el sistema (evita que alguien
                // "adivine" cuentas válidas probando correos al azar).
                if ($usuario) {
                    // Se genera un token aleatorio de 64 caracteres hexadecimales.
                    // random_bytes() es criptográficamente seguro (a diferencia de rand()).
                    $token = bin2hex(random_bytes(32));

                    // El enlace será válido por 1 hora desde este momento.
                    $expira = date("Y-m-d H:i:s", strtotime("+1 hour"));

                    // Se guarda el token y su expiración en la base de datos.
                    $this->modelo->guardarTokenRecuperacion($usuario["id"], $token, $expira);

                    // Se arma el enlace completo que la persona debe abrir.
                    $enlace = "http://" . $_SERVER["HTTP_HOST"]
                        . dirname($_SERVER["SCRIPT_NAME"])
                        . "/index.php?accion=restablecer&token=" . $token;

                    // Se envía el correo (o, si mail() no está configurado en el
                    // servidor local, al menos queda registrado en el log).
                    Correo::enviarRecuperacion($correo, $usuario["nombre"], $enlace);
                }

                // Se guarda el mensaje de éxito para mostrarlo en el login,
                // exista o no exista ese correo (mismo mensaje en ambos casos).
                $_SESSION["mensaje_exito"] =
                    "Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.";
                header("Location: index.php?accion=login");
                exit;
            }
        }

        // Si hubo errores de validación, se vuelve a mostrar el formulario.
        require __DIR__ . "/../views/auth/recuperar.php";
    }

    // ------------------------------------------------------------------
    // Paso 3: se llega aquí al hacer clic en el enlace del correo. Valida
    // que el token exista y no haya expirado antes de mostrar el formulario
    // de nueva contraseña.
    // ------------------------------------------------------------------
    public function mostrarRestablecer()
    {
        // El token viaja en la URL: index.php?accion=restablecer&token=xxxx
        $token = $_GET["token"] ?? "";

        // Se busca un usuario cuyo token coincida Y que no esté vencido.
        $usuario = $this->modelo->obtenerPorTokenValido($token);

        if (!$usuario) {
            // Token inexistente, ya usado, o vencido: se avisa en el login
            // en vez de mostrar un formulario que de todas formas fallaría.
            $_SESSION["error_login"] = "El enlace de recuperación no es válido o ya expiró. Solicita uno nuevo.";
            header("Location: index.php?accion=login");
            exit;
        }

        // El formulario necesita el token (para reenviarlo oculto) y el
        // arreglo de errores (vacío la primera vez que se muestra).
        $errores = [];
        require __DIR__ . "/../views/auth/restablecer.php";
    }

    // ------------------------------------------------------------------
    // Paso 4: procesa el envío del formulario de nueva contraseña.
    // ------------------------------------------------------------------
    public function restablecer()
    {
        $errores = [];
        $token = $_POST["token"] ?? $_GET["token"] ?? "";

        // Se vuelve a validar el token aquí también (nunca confiar solo en
        // que "ya se validó antes"): pudo expirar justo entre que se mostró
        // el formulario y que la persona lo envió.
        $usuario = $this->modelo->obtenerPorTokenValido($token);

        if (!$usuario) {
            $_SESSION["error_login"] = "El enlace de recuperación no es válido o ya expiró. Solicita uno nuevo.";
            header("Location: index.php?accion=login");
            exit;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {

            $contrasena          = $_POST["contrasena"] ?? "";
            $confirmarContrasena = $_POST["confirmar_contrasena"] ?? "";

            // --- VALIDACIÓN 1: campo no vacío ---------------------------------
            if ($contrasena === "") {
                $errores[] = "La nueva contraseña es obligatoria.";
            }
            // --- VALIDACIÓN 2: longitud mínima --------------------------------
            elseif (strlen($contrasena) < 6) {
                $errores[] = "La contraseña debe tener mínimo 6 caracteres.";
            }

            // --- VALIDACIÓN 3: las dos contraseñas deben coincidir ------------
            if ($confirmarContrasena === "") {
                $errores[] = "Debes confirmar la nueva contraseña.";
            } elseif ($contrasena !== $confirmarContrasena) {
                $errores[] = "Las contraseñas no coinciden.";
            }

            // Si no hubo ningún error, se guarda la nueva contraseña.
            if (empty($errores)) {
                // password_hash() encripta la contraseña; NUNCA se guarda en texto plano.
                $hash = password_hash($contrasena, PASSWORD_DEFAULT);

                // Se actualiza la contraseña y se borra el token (de un solo uso).
                $this->modelo->actualizarContrasenaYLimpiarToken($usuario["id"], $hash);

                $_SESSION["mensaje_exito"] = "Tu contraseña fue actualizada. Ya puedes iniciar sesión.";
                header("Location: index.php?accion=login");
                exit;
            }
        }

        // Si hubo errores, se vuelve a mostrar el formulario con el token.
        require __DIR__ . "/../views/auth/restablecer.php";
    }
}
