<?php
// index.php
require_once 'db_config.php';
session_start();

$msg = "";

// Lógica de Login
if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND password = ?");
    $stmt->execute([$_POST['user'], $_POST['pass']]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['usuario'] = $user['usuario'];
    } else {
        $msg = "<p class='msg-error'>ACCESO DENEGADO. VERIFIQUE SUS CREDENCIALES.</p>";
    }
}

// Cerrar Sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Lógica de Registro de Cita
if (isset($_POST['agendar'])) {
    $sql = "INSERT INTO citas (paciente_nombre, especialidad, fecha, hora) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$_POST['paciente'], $_POST['especialidad'], $_POST['fecha'], $_POST['hora']]);
    $msg = "<p class='msg-ok'>CITA REGISTRADA CORRECTAMENTE.</p>";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MEDICITAS - SISTEMA DE CITAS MÉDICAS</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="container">
    <?php if (!isset($_SESSION['usuario'])): ?>
        <div class="login-form">
            <h2>ACCESO AL SISTEMA MÉDICO</h2>
            <?= $msg ?>
            <form method="POST">
                <input type="text" name="user" placeholder="USUARIO (ADMIN)" required>
                <input type="password" name="pass" placeholder="CONTRASEÑA (1234)" required>
                <button type="submit" name="login">INGRESAR</button>
            </form>
        </div>

    <?php else: ?>
        <div class="header">
            <span>BIENVENIDO: <strong><?= htmlspecialchars($_SESSION['usuario']) ?></strong></span>
            <a href="?logout=1" class="logout">CERRAR SESIÓN</a>
        </div>

        <div class="appointment-form">
            <h2>AGENDAR CITA MÉDICA</h2>
            <?= $msg ?>
            <form method="POST">
                <input type="text" name="paciente" placeholder="NOMBRE COMPLETO DEL PACIENTE" required>
                <select name="especialidad">
                    <option value="Medicina General">MEDICINA GENERAL</option>
                    <option value="Pediatría">PEDIATRÍA</option>
                    <option value="Odontología">ODONTOLOGÍA</option>
                </select>
                <div style="display:flex; gap:10px;">
                    <input type="date" name="fecha" required>
                    <input type="time" name="hora" required>
                </div>
                <button type="submit" name="agendar">GUARDAR CITA</button>
            </form>
        </div>

        <h2>REPORTE DE CITAS REGISTRADAS</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>PACIENTE</th>
                    <th>ESPECIALIDAD</th>
                    <th>FECHA Y HORA</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT * FROM citas ORDER BY id DESC");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['paciente_nombre']) ?></td>
                        <td><?= htmlspecialchars($row['especialidad']) ?></td>
                        <td><?= htmlspecialchars($row['fecha']) ?> | <?= htmlspecialchars($row['hora']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>