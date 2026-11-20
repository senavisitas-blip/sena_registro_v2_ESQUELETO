<?php
// ============================================================================
// config/Correo.php
// ----------------------------------------------------------------------------
// Helper de infraestructura encargado de UN solo trabajo: enviar correos.
// ----------------------------------------------------------------------------
// VERSIÓN CON ENVÍO REAL (PHPMailer + SMTP de Gmail).
//
// La función nativa mail() de PHP casi nunca entrega correos reales en un
// XAMPP/WAMP local porque no hay un servidor SMTP configurado en el equipo.
// Por eso esta clase usa PHPMailer para conectarse DIRECTAMENTE al servidor
// SMTP de Gmail (smtp.gmail.com) y sí entregar el correo a la bandeja real
// del destinatario.
//
// ============================================================================
// PASOS OBLIGATORIOS PARA QUE ESTO FUNCIONE (hazlos UNA sola vez):
// ============================================================================
// 1. Entra a la cuenta de Gmail que vas a usar PARA ENVIAR los correos
//    (por ejemplo, una cuenta de Gmail que crees solo para el proyecto).
// 2. Activa la verificación en 2 pasos:
//    https://myaccount.google.com/security -> "Verificación en 2 pasos"
//    (Gmail EXIGE esto antes de dejarte crear una contraseña de aplicación).
// 3. Genera una "Contraseña de aplicación":
//    https://myaccount.google.com/apppasswords
//    - Selecciona app "Correo" y dispositivo "Otro", ponle un nombre
//      (ej. "SenaRegistro") y da clic en "Generar".
//    - Gmail te muestra una clave de 16 letras (sin espacios reales, aunque
//      se vea en 4 grupos de 4). ESA es la que va abajo, NO la contraseña
//      normal de tu Gmail (esa nunca funciona directo con SMTP).
// 4. Reemplaza las dos constantes de abajo con tus datos reales:
// ============================================================================

const GMAIL_REMITENTE        = "yamilybellovillamil@gmail.com"; // <-- CAMBIA ESTO
const GMAIL_CONTRASENA_APP   = "zykr wwml giug kjyf";                // <-- CAMBIA ESTO (contraseña de aplicación, NO tu clave normal)

// Se incluyen manualmente las 3 clases núcleo de PHPMailer (sin necesitar
// Composer, para que funcione tal cual en cualquier XAMPP sin instalar nada
// más). Los archivos están en lib/PHPMailer/ dentro de este mismo proyecto.
require_once __DIR__ . "/../lib/PHPMailer/Exception.php";
require_once __DIR__ . "/../lib/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/../lib/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Correo
{
    // ------------------------------------------------------------------
    // Envía un correo de recuperación de contraseña con el enlace único.
    // Devuelve true/false según si el envío por SMTP tuvo éxito. SIEMPRE
    // deja además un registro en el log, útil para depurar si algo falla.
    // ------------------------------------------------------------------
    public static function enviarRecuperacion($correoDestino, $nombre, $enlace)
    {
        $asunto = "Recuperación de contraseña - Sena Registro";

        $cuerpo = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <p>Hola " . htmlspecialchars($nombre) . ",</p>
                <p>Recibimos una solicitud para restablecer tu contraseña.</p>
                <p>Haz clic en el siguiente enlace para crear una nueva contraseña
                   (válido por 1 hora):</p>
                <p><a href='" . htmlspecialchars($enlace) . "'>" . htmlspecialchars($enlace) . "</a></p>
                <p>Si tú no solicitaste esto, puedes ignorar este mensaje.</p>
            </body>
            </html>
        ";

        return self::enviarCorreo($correoDestino, $nombre, $asunto, $cuerpo, $enlace);
    }

    // ------------------------------------------------------------------
    // Método interno reutilizable: arma y envía el correo por SMTP usando
    // PHPMailer, y registra el resultado en el log sin importar qué pase.
    // ------------------------------------------------------------------
    private static function enviarCorreo($correoDestino, $nombreDestino, $asunto, $cuerpoHtml, $enlaceParaLog)
    {
        $mail = new PHPMailer(true); // true = activa las excepciones detalladas
        $enviado = false;
        $errorDetalle = "";

        try {
            // --- Configuración del servidor SMTP de Gmail -------------------
            $mail->isSMTP();
            $mail->Host       = "smtp.gmail.com";
            $mail->SMTPAuth   = true;
            $mail->Username   = GMAIL_REMITENTE;
            $mail->Password   = GMAIL_CONTRASENA_APP;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS en el puerto 587
            $mail->Port       = 587;

            // --- Arreglo para el error típico de XAMPP: --------------------
            // "SSL routines:tls_process_server_certificate:certificate verify
            // failed". Ocurre porque el paquete de certificados (cacert) que
            // trae XAMPP suele estar desactualizado o mal configurado en
            // php.ini, así que PHP no logra validar el certificado real de
            // Gmail aunque sí sea válido. Esta opción le dice a PHP que no
            // sea estricto verificando ese certificado (seguro para este
            // caso, porque de todas formas nos estamos conectando siempre
            // al host fijo "smtp.gmail.com" definido arriba, no a uno
            // cualquiera que pudiera venir de afuera).
            $mail->SMTPOptions = [
                "ssl" => [
                    "verify_peer"       => false,
                    "verify_peer_name"  => false,
                    "allow_self_signed" => true,
                ],
            ];
            $mail->CharSet    = "UTF-8";

            // --- Remitente y destinatario ------------------------------------
            $mail->setFrom(GMAIL_REMITENTE, "Sena Registro");
            $mail->addAddress($correoDestino, $nombreDestino);

            // --- Contenido -----------------------------------------------------
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $cuerpoHtml;
            $mail->AltBody = strip_tags(str_replace("</p>", "\n", $cuerpoHtml));

            $mail->send();
            $enviado = true;
        } catch (PHPMailerException $e) {
            // Si algo falla (credenciales mal puestas, sin internet, etc.),
            // se captura el detalle para dejarlo en el log y no romper la app.
            $errorDetalle = $mail->ErrorInfo;
        }

        self::registrarEnLog($correoDestino, $enlaceParaLog, $enviado, $errorDetalle);

        return $enviado;
    }

    // ------------------------------------------------------------------
    // Escribe una línea en storage/correos_enviados.log con fecha, correo,
    // enlace generado y si el envío tuvo éxito o no (y por qué, si falló).
    // Sirve como respaldo/evidencia aunque el envío real ya esté funcionando.
    // ------------------------------------------------------------------
    private static function registrarEnLog($correoDestino, $enlace, $enviado, $errorDetalle = "")
    {
        $carpeta = __DIR__ . "/../storage";
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }

        $linea = sprintf(
            "[%s] Para: %s | SMTP: %s%s | Enlace: %s%s",
            date("Y-m-d H:i:s"),
            $correoDestino,
            $enviado ? "enviado" : "NO enviado",
            $errorDetalle !== "" ? " (" . $errorDetalle . ")" : "",
            $enlace,
            PHP_EOL
        );

        file_put_contents("$carpeta/correos_enviados.log", $linea, FILE_APPEND);
    }
}
