<?php
// Configuración
$destinatario = "contacto@costaricaebs.com";
// $destinatario = "hmbonilla@gmail.com";
$asunto = "C.R. Enterprise Business Solutions. Solicita una Evaluación de Arquitectura";
$url_redireccion = "https://costaricaebs.com/";

// Validar que el formulario se envió por POST
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: " . $url_redireccion);
    exit;
}

// Obtener y limpiar datos del formulario
$nombre = isset($_POST['name']) ? strip_tags(trim($_POST['name'])) : '';
$empresa = isset($_POST['company']) ? strip_tags(trim($_POST['company'])) : '';
$email = isset($_POST['email']) ? filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL) : '';
$telefono = isset($_POST['phone']) ? strip_tags(trim($_POST['phone'])) : '';
$servicio = isset($_POST['service']) ? strip_tags(trim($_POST['service'])) : '';
$mensaje = isset($_POST['message']) ? strip_tags(trim($_POST['message'])) : '';

// Validar campos requeridos
if (empty($nombre) || empty($empresa) || empty($email) || empty($servicio)) {
    header("Location: " . $url_redireccion . "?error=campos_vacios");
    exit;
}

// Validar formato de email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: " . $url_redireccion . "?error=email_invalido");
    exit;
}

// Construir el cuerpo del mensaje
$cuerpo_mensaje = "===== NUEVO MENSAJE DE CONTACTO =====\n\n";
$cuerpo_mensaje .= "NOMBRE: " . $nombre . "\n";
$cuerpo_mensaje .= "EMPRESA: " . $empresa . "\n";
$cuerpo_mensaje .= "EMAIL: " . $email . "\n";
$cuerpo_mensaje .= "TELÉFONO: " . ($telefono ?: 'No proporcionado') . "\n";
$cuerpo_mensaje .= "SERVICIO DE INTERÉS: " . $servicio . "\n\n";
$cuerpo_mensaje .= "MENSAJE:\n" . $mensaje . "\n\n";    
$cuerpo_mensaje .= "===================================\n";
$cuerpo_mensaje .= "Enviado desde: " . $_SERVER['REMOTE_ADDR'] . "\n";
$cuerpo_mensaje .= "Fecha: " . date('Y-m-d H:i:s') . "\n";

// Configurar headers del email
$headers = "From: " . $email . "\r\n";
$headers .= "Reply-To: " . $email . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

// Enviar el email
$enviado = mail($destinatario, $asunto, $cuerpo_mensaje, $headers);

// Redirigir según el resultado
if ($enviado) {
    header("Location: " . $url_redireccion . "?mensaje=enviado");
    alert('¡Gracias! Tu mensaje ha sido enviado correctamente.');
} else {
    header("Location: " . $url_redireccion . "?error=envio_fallido");
}
exit;
?>