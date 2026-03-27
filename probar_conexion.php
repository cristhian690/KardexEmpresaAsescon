<?php
// Incluimos tu archivo de conexión
include 'conexion.php';

// Verificamos si la variable $conexion existe y no tiene errores
if ($conexion->connect_error) {
    echo "❌ Error fatal: No se pudo conectar a la base de datos.<br>";
    echo "Detalle del error: " . $conexion->connect_error;
} else {
    echo "✅ ¡Excelente! PHP se conectó correctamente a 'dbasescon'.<br>";
    
    // Prueba extra: Ver si la tabla 'kardex' existe
    $resultado = $conexion->query("SHOW TABLES LIKE 'kardex'");
    if ($resultado->num_rows > 0) {
        echo "📂 La tabla 'kardex' también fue encontrada con éxito.";
    } else {
        echo "⚠️ Conectado, pero la tabla 'kardex' NO existe todavía.";
    }
}
?>