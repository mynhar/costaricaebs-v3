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
$nombre   = isset($_POST['name'])    ? strip_tags(trim($_POST['name']))                              : '';
$empresa  = isset($_POST['company']) ? strip_tags(trim($_POST['company']))                           : '';
$email    = isset($_POST['email'])   ? filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL)      : '';
$telefono = isset($_POST['phone'])   ? strip_tags(trim($_POST['phone']))                             : '';
$servicio = isset($_POST['service']) ? strip_tags(trim($_POST['service']))                           : '';
$mensaje  = isset($_POST['message']) ? strip_tags(trim($_POST['message']))                           : '';

// ─────────────────────────────────────────────────────────────
// FUNCIONES ANTI-BOT
// ─────────────────────────────────────────────────────────────

/**
 * Detecta si un texto parece generado por un bot:
 * cadenas aleatorias sin vocales, consonantes consecutivas excesivas, etc.
 */
function esTextoAleatorio($texto) {
    if (empty($texto)) return false;

    $largo   = strlen($texto);
    $vocales = preg_match_all('/[aeiouáéíóúAEIOUÁÉÍÓÚ]/', $texto);
    $letras  = preg_match_all('/[a-zA-ZáéíóúÁÉÍÓÚ]/', $texto);

    // Necesitamos varias señales simultáneas para declarar bot,
    // ya que apellidos reales pueden tener pocas vocales (ej: hmbonilla, lynch)
    $puntos_sospecha = 0;

    // Señal 1 (fuerte): proporción de vocales extremadamente baja (< 15%)
    if ($letras > 6 && ($vocales / $letras) < 0.15) {
        $puntos_sospecha += 2;
    }

    // Señal 2 (fuerte): 5+ consonantes seguidas sin ninguna vocal
    if (preg_match('/[^aeiouáéíóú\d\s\-\_\.]{5,}/i', $texto)) {
        $puntos_sospecha += 2;
    }

    // Señal 3: entropía muy alta (casi cada carácter es único)
    if ($largo >= 10) {
        $unicos = count(array_unique(str_split(strtolower($texto))));
        if (($unicos / $largo) > 0.88) {
            $puntos_sospecha += 1;
        }
    }

    // Señal 4: texto largo sin espacios (nombres/empresas reales suelen tenerlos)
    if ($largo > 15 && strpos($texto, ' ') === false) {
        $puntos_sospecha += 1;
    }

    // Solo es bot si acumula suficientes señales
    return $puntos_sospecha >= 3;
}

/**
 * Detecta si un email tiene patrones de bot:
 * muchos puntos, números intercalados, alias con caracteres raros.
 */
function esEmailBot($email) {
    $partes = explode('@', $email);
    $local  = $partes[0]; // parte antes del @
    $dominio = isset($partes[1]) ? strtolower($partes[1]) : '';

    // Dominios de confianza: relajar reglas (puntos y números son normales)
    $dominios_confianza = ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com',
                           'icloud.com', 'live.com', 'protonmail.com', 'me.com'];
    $es_dominio_confianza = in_array($dominio, $dominios_confianza);

    // Puntos excesivos: solo bloquear si NO es dominio conocido
    // (osoxeha.d.iy4.7 tiene 3 puntos Y números Y texto aleatorio)
    if (!$es_dominio_confianza && substr_count($local, '.') >= 3) {
        return true;
    }

    // Mezcla de letras y números con puntos intercalados (patrón bot específico)
    // ej: iy4.7 — número solo entre puntos
    if (preg_match('/\.\d+\./', $local) || preg_match('/\.\d+$/', $local)) {
        return true;
    }

    // El alias en sí parece aleatorio (aplica a todos los dominios)
    // Pero solo si el alias es largo; alias cortos pueden ser legítimos
    if (strlen($local) >= 8 && esTextoAleatorio($local)) {
        return true;
    }

    return false;
}

/**
 * Detecta si el teléfono parece falso:
 * todos dígitos iguales, secuencias como 1234567890, demasiado largo.
 */
function esTelefonoFalso($telefono) {
    $solo_digitos = preg_replace('/\D/', '', $telefono);

    // Más de 15 dígitos es irreal
    if (strlen($solo_digitos) > 15) return true;

    // Todos los dígitos iguales (ej: 1111111111)
    if (preg_match('/^(\d)\1{7,}$/', $solo_digitos)) return true;

    // Secuencia ascendente o descendente (ej: 1234567890)
    $es_secuencia = true;
    for ($i = 1; $i < strlen($solo_digitos); $i++) {
        if (abs((int)$solo_digitos[$i] - (int)$solo_digitos[$i-1]) !== 1) {
            $es_secuencia = false;
            break;
        }
    }
    if ($es_secuencia && strlen($solo_digitos) >= 8) return true;

    return false;
}

// ─────────────────────────────────────────────────────────────
// HONEYPOT: campo oculto que los bots llenan pero humanos no
// Agrega <input type="text" name="website" style="display:none"> en tu HTML
// ─────────────────────────────────────────────────────────────
if (!empty($_POST['website'])) {
    // Bot detectado — finge éxito para no revelar la trampa
    header("Location: " . $url_redireccion);
    exit;
}

// ─────────────────────────────────────────────────────────────
// RATE LIMITING simple por IP (requiere carpeta writable /tmp)
// ─────────────────────────────────────────────────────────────
$ip          = $_SERVER['REMOTE_ADDR'];
$ip_hash     = md5($ip); // no guardar IPs en texto plano
$rate_file   = sys_get_temp_dir() . "/form_rl_" . $ip_hash . ".txt";
$max_envios  = 3;   // máximo de envíos
$ventana_seg = 600; // en 10 minutos

if (file_exists($rate_file)) {
    $data = json_decode(file_get_contents($rate_file), true);
    // Resetear si venció la ventana de tiempo
    if (time() - $data['inicio'] > $ventana_seg) {
        $data = ['inicio' => time(), 'count' => 0];
    }
    if ($data['count'] >= $max_envios) {
        mostrarError("Demasiados intentos. Por favor espera unos minutos antes de volver a enviar.", "");
    }
    $data['count']++;
} else {
    $data = ['inicio' => time(), 'count' => 1];
}
file_put_contents($rate_file, json_encode($data));

// ─────────────────────────────────────────────────────────────
// VALIDACIONES ESTÁNDAR
// ─────────────────────────────────────────────────────────────
if (empty($nombre))   mostrarError("El campo NOMBRE está vacío", $nombre);
if (empty($empresa))  mostrarError("El campo EMPRESA está vacío", $empresa);
if (empty($email))    mostrarError("El campo EMAIL está vacío", $email);
if (empty($servicio)) mostrarError("El campo SERVICIO está vacío", $servicio);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mostrarError("El EMAIL tiene un formato inválido", $email);
}

// ─────────────────────────────────────────────────────────────
// VALIDACIONES ANTI-BOT
// ─────────────────────────────────────────────────────────────
if (esTextoAleatorio($nombre)) {
    mostrarError("El campo NOMBRE no parece válido. Por favor ingrese su nombre real.", $nombre);
}

if (esTextoAleatorio($empresa)) {
    mostrarError("El campo EMPRESA no parece válido. Por favor ingrese el nombre real de su empresa.", $empresa);
}

if (esEmailBot($email)) {
    mostrarError("El EMAIL ingresado no parece válido. Por favor use su correo real.", $email);
}

if (!empty($telefono) && esTelefonoFalso($telefono)) {
    mostrarError("El TELÉFONO ingresado no parece válido.", $telefono);
}

if (!empty($mensaje) && esTextoAleatorio($mensaje)) {
    mostrarError("El MENSAJE no parece válido. Por favor describa brevemente su consulta.", $mensaje);
}

// Largo mínimo razonable en campos de texto
if (strlen($nombre) < 3) {
    mostrarError("El NOMBRE es demasiado corto.", $nombre);
}
if (strlen($empresa) < 2) {
    mostrarError("El nombre de EMPRESA es demasiado corto.", $empresa);
}

// ─────────────────────────────────────────────────────────────
// FUNCIÓN PARA MOSTRAR ERRORES
// ─────────────────────────────────────────────────────────────
function mostrarError($mensaje, $valor) {
    global $url_redireccion;
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Error en el formulario</title>
    </head>
    <body>
        <script>
            alert("ERROR: ' . addslashes($mensaje) . '\n\nValor recibido: [' . addslashes($valor) . ']");
            window.location.href = "' . $url_redireccion . '";
        </script>
    </body>
    </html>';
    exit;
}

// ─────────────────────────────────────────────────────────────
// CONSTRUIR Y ENVIAR EMAIL
// ─────────────────────────────────────────────────────────────
$cuerpo_mensaje  = "===== NUEVO MENSAJE DE CONTACTO =====\n\n";
$cuerpo_mensaje .= "NOMBRE: "             . $nombre   . "\n";
$cuerpo_mensaje .= "EMPRESA: "            . $empresa  . "\n";
$cuerpo_mensaje .= "EMAIL: "              . $email    . "\n";
$cuerpo_mensaje .= "TELÉFONO: "           . ($telefono ?: 'No proporcionado') . "\n";
$cuerpo_mensaje .= "SERVICIO DE INTERÉS: ". $servicio . "\n\n";
$cuerpo_mensaje .= "MENSAJE:\n"           . $mensaje  . "\n\n";
$cuerpo_mensaje .= "===================================\n";
$cuerpo_mensaje .= "Enviado desde: "      . $ip       . "\n";
$cuerpo_mensaje .= "Fecha: "              . date('Y-m-d H:i:s') . "\n";

$headers  = "From: "        . $email . "\r\n";
$headers .= "Reply-To: "   . $email . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$enviado = mail($destinatario, $asunto, $cuerpo_mensaje, $headers);

if ($enviado) {
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Mensaje enviado</title>
    </head>
    <body>
        <script>
            alert("✓ ¡Mensaje enviado correctamente!\n\nGracias por contactarnos.");
            window.location.href = "' . $url_redireccion . '";
        </script>
    </body>
    </html>';
} else {
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Error al enviar</title>
    </head>
    <body>
        <script>
            alert("ERROR: No se pudo enviar el mensaje.\n\nPor favor, intenta nuevamente o contacta directamente a contacto@costaricaebs.com");
            window.location.href = "' . $url_redireccion . '";
        </script>
    </body>
    </html>';
}
exit;
?>