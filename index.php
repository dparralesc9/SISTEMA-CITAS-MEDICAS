<?php
// index.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';
session_start();

// ---------- Utilidades ----------
function mayus($s) {
    return strtr(strtoupper((string)$s), ['á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ñ'=>'Ñ','ü'=>'Ü']);
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash($tipo, $msg) { $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg]; }
function volver() { header("Location: index.php"); exit; }
function csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

$ESPECIALIDADES = ['Medicina General', 'Pediatría', 'Odontología'];
$logueado = isset($_SESSION['usuario']);

// ---------- Cerrar sesión ----------
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ---------- Login (contraseña cifrada + CSRF) ----------
if (isset($_POST['login'])) {
    if (!csrf_ok()) { flash('error', 'SOLICITUD NO VÁLIDA. INTENTE DE NUEVO.'); volver(); }
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
    $stmt->execute([trim($_POST['user'])]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $ok = false;
    if ($u) {
        if (password_verify($_POST['pass'], $u['password'])) {
            $ok = true;
        } elseif ($u['password'] === $_POST['pass']) {
            // Base antigua con clave en texto plano: se actualiza a hash
            $ok = true;
            $up = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $up->execute([password_hash($_POST['pass'], PASSWORD_DEFAULT), $u['id']]);
        }
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['usuario'] = $u['usuario'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } else {
        flash('error', 'ACCESO DENEGADO. VERIFIQUE SUS CREDENCIALES.');
    }
    volver();
}

// ---------- Agendar cita (con validaciones) ----------
if (isset($_POST['agendar']) && $logueado) {
    if (!csrf_ok()) { flash('error', 'SOLICITUD NO VÁLIDA.'); volver(); }
    $pac   = trim($_POST['paciente'] ?? '');
    $esp   = $_POST['especialidad'] ?? '';
    $fecha = $_POST['fecha'] ?? '';
    $hora  = $_POST['hora'] ?? '';

    if (preg_match_all('/\p{L}/u', $pac) < 3 || !preg_match("/^[\p{L} .'-]+$/u", $pac)) {
        flash('error', 'EL NOMBRE DEBE TENER AL MENOS 3 LETRAS Y NO LLEVAR NÚMEROS.');
    } elseif (!in_array($esp, $ESPECIALIDADES, true)) {
        flash('error', 'ESPECIALIDAD NO VÁLIDA.');
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
        flash('error', 'FECHA NO VÁLIDA.');
    } elseif ($fecha < date('Y-m-d')) {
        flash('error', 'NO SE PUEDE AGENDAR UNA CITA EN UNA FECHA PASADA.');
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
        flash('error', 'HORA NO VÁLIDA.');
    } else {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM citas
                              WHERE especialidad = ? AND fecha = ? AND hora = ? AND estado <> 'Cancelada'");
        $chk->execute([$esp, $fecha, $hora]);
        if ($chk->fetchColumn() > 0) {
            flash('error', 'YA EXISTE UNA CITA DE ESA ESPECIALIDAD EN ESA FECHA Y HORA.');
        } else {
            $ins = $pdo->prepare("INSERT INTO citas (paciente_nombre, especialidad, fecha, hora) VALUES (?, ?, ?, ?)");
            $ins->execute([$pac, $esp, $fecha, $hora]);
            flash('ok', 'CITA REGISTRADA CORRECTAMENTE.');
        }
    }
    volver();
}

// ---------- Cambiar estado / eliminar ----------
if (isset($_POST['accion']) && $logueado) {
    if (!csrf_ok()) { flash('error', 'SOLICITUD NO VÁLIDA.'); volver(); }
    $id = (int)($_POST['id'] ?? 0);
    if ($_POST['accion'] === 'atender') {
        $pdo->prepare("UPDATE citas SET estado = 'Atendida' WHERE id = ?")->execute([$id]);
        flash('ok', 'CITA MARCADA COMO ATENDIDA.');
    } elseif ($_POST['accion'] === 'cancelar') {
        $pdo->prepare("UPDATE citas SET estado = 'Cancelada' WHERE id = ?")->execute([$id]);
        flash('ok', 'CITA CANCELADA.');
    } elseif ($_POST['accion'] === 'eliminar') {
        $pdo->prepare("DELETE FROM citas WHERE id = ?")->execute([$id]);
        flash('ok', 'CITA ELIMINADA.');
    }
    volver();
}

// ---------- Mensaje flash ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Datos del panel ----------
$q   = trim($_GET['q'] ?? '');
$fEs = $_GET['esp'] ?? '';
$fEt = $_GET['est'] ?? '';
$citas = [];
$stats = ['Total' => 0, 'Registrada' => 0, 'Atendida' => 0, 'Cancelada' => 0];

if ($logueado) {
    $where = []; $params = [];
    if ($q !== '')   { $where[] = "paciente_nombre LIKE ?"; $params[] = "%$q%"; }
    if ($fEs !== '') { $where[] = "especialidad = ?";       $params[] = $fEs; }
    if ($fEt !== '') { $where[] = "estado = ?";             $params[] = $fEt; }
    $sql = "SELECT * FROM citas" . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY fecha ASC, hora ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $citas = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pdo->query("SELECT estado, COUNT(*) AS n FROM citas GROUP BY estado") as $r) {
        $stats['Total'] += $r['n'];
        if (isset($stats[$r['estado']])) { $stats[$r['estado']] = $r['n']; }
    }
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
<?php if ($flash): ?>
    <p class="<?= $flash['tipo'] === 'ok' ? 'msg-ok' : 'msg-error' ?>"><?= e($flash['msg']) ?></p>
<?php endif; ?>

<?php if (!$logueado): ?>
    <div class="login-form">
        <h2>ACCESO AL SISTEMA MÉDICO</h2>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="text" name="user" placeholder="USUARIO (ADMIN)" required>
            <input type="password" name="pass" placeholder="CONTRASEÑA (1234)" required>
            <button type="submit" name="login">INGRESAR</button>
        </form>
    </div>

<?php else: ?>
    <div class="header">
        <span>BIENVENIDO: <strong><?= e($_SESSION['usuario']) ?></strong></span>
        <a href="?logout=1" class="logout">CERRAR SESIÓN</a>
    </div>

    <div class="stats">
        <div class="stat"><span><?= $stats['Total'] ?></span>TOTAL</div>
        <div class="stat"><span><?= $stats['Registrada'] ?></span>REGISTRADAS</div>
        <div class="stat"><span><?= $stats['Atendida'] ?></span>ATENDIDAS</div>
        <div class="stat"><span><?= $stats['Cancelada'] ?></span>CANCELADAS</div>
    </div>

    <div class="appointment-form">
        <h2>AGENDAR CITA MÉDICA</h2>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="text" name="paciente" placeholder="NOMBRE COMPLETO DEL PACIENTE" required>
            <select name="especialidad">
                <?php foreach ($ESPECIALIDADES as $esp): ?>
                    <option value="<?= e($esp) ?>"><?= e(mayus($esp)) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="display:flex; gap:10px;">
                <input type="date" name="fecha" min="<?= date('Y-m-d') ?>" required>
                <input type="time" name="hora" required>
            </div>
            <button type="submit" name="agendar">GUARDAR CITA</button>
        </form>
    </div>

    <h2>REPORTE DE CITAS REGISTRADAS</h2>

    <form method="GET" class="filtros">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="BUSCAR PACIENTE">
        <select name="esp">
            <option value="">TODAS LAS ESPECIALIDADES</option>
            <?php foreach ($ESPECIALIDADES as $esp): ?>
                <option value="<?= e($esp) ?>" <?= $fEs === $esp ? 'selected' : '' ?>><?= e(mayus($esp)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="est">
            <option value="">TODOS LOS ESTADOS</option>
            <?php foreach (['Registrada', 'Atendida', 'Cancelada'] as $es): ?>
                <option value="<?= $es ?>" <?= $fEt === $es ? 'selected' : '' ?>><?= mayus($es) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-sm">FILTRAR</button>
        <a href="index.php" class="btn-sm btn-gris">LIMPIAR</a>
    </form>

    <div class="tabla-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th><th>PACIENTE</th><th>ESPECIALIDAD</th>
                <th>FECHA Y HORA</th><th>ESTADO</th><th>ACCIONES</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$citas): ?>
            <tr><td colspan="6" class="vacio">NO HAY CITAS PARA MOSTRAR.</td></tr>
        <?php endif; ?>
        <?php foreach ($citas as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= e($row['paciente_nombre']) ?></td>
                <td><?= e($row['especialidad']) ?></td>
                <td><?= e($row['fecha']) ?> | <?= e($row['hora']) ?></td>
                <td><span class="badge b-<?= e(strtolower($row['estado'])) ?>"><?= e(mayus($row['estado'])) ?></span></td>
                <td class="acciones">
                    <?php if ($row['estado'] === 'Registrada'): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button class="btn-sm" name="accion" value="atender">ATENDER</button>
                            <button class="btn-sm btn-gris" name="accion" value="cancelar">CANCELAR</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('¿ELIMINAR ESTA CITA?');">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button class="btn-sm btn-rojo" name="accion" value="eliminar">ELIMINAR</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>

</body>
</html>