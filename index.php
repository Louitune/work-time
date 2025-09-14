<?php
session_start();

$passwort = "yourpassword"; // <--- change here your password

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['logged_in'])) {
    if (isset($_POST['passwort'])) {
        if ($_POST['passwort'] === $passwort) {
            $_SESSION['logged_in'] = true;
        } else {
            $error = "❌ Falsches Passwort!";
        }
    }

    if (!isset($_SESSION['logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login</title>
        <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #007bff, #00c6ff);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 350px;
            text-align: center;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: #333;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #007bff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            color: white;
            cursor: pointer;
        }
        button:hover {
            background: #0056b3;
        }
        .error {
            color: red;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        </style>
        </head>
        <body>
            <div class="login-box">
                <h1>Anmelden</h1>
                <form method="post">
                    <input type="password" name="passwort" placeholder="Passwort eingeben" required>
                    <button type="submit">Login</button>
                    <?php if (isset($error)): ?>
                        <div class="error"><?= $error ?></div>
                    <?php endif; ?>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$db = new SQLite3("worktime.db");
$db->exec("CREATE TABLE IF NOT EXISTS zeiten (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    datum TEXT,
    start TEXT,
    pause_start TEXT,
    pause_ende TEXT,
    ende TEXT
)");

function berechneStunden($row) {
    if (empty($row['start']) || empty($row['ende'])) return "";
    $start = strtotime($row['datum']." ".$row['start']);
    $ende = strtotime($row['datum']." ".$row['ende']);
    $dauer = $ende - $start;

    if (!empty($row['pause_start']) && !empty($row['pause_ende'])) {
        $pauseStart = strtotime($row['datum']." ".$row['pause_start']);
        $pauseEnde = strtotime($row['datum']." ".$row['pause_ende']);
        $dauer -= max(0, $pauseEnde - $pauseStart);
    }

    $stunden = floor($dauer / 3600);
    $minuten = floor(($dauer % 3600) / 60);
    return sprintf("%02d:%02d", $stunden, $minuten);
}

function runde15($minutenGesamt) {
    return round($minutenGesamt / 15) * 15;
}

// Aktionen
if (isset($_POST['action'])) {
    $datum = date("Y-m-d");
    if ($_POST['action'] == "start") {
        $stmt = $db->prepare("INSERT INTO zeiten (datum, start) VALUES (:datum, :start)");
        $stmt->bindValue(":datum", $datum, SQLITE3_TEXT);
        $stmt->bindValue(":start", date("H:i:s"), SQLITE3_TEXT);
        $stmt->execute();
    }
    if ($_POST['action'] == "pause") {
        $db->exec("UPDATE zeiten SET pause_start='" . date("H:i:s") . "' WHERE id=(SELECT MAX(id) FROM zeiten)");
    }
    if ($_POST['action'] == "weiter") {
        $db->exec("UPDATE zeiten SET pause_ende='" . date("H:i:s") . "' WHERE id=(SELECT MAX(id) FROM zeiten)");
    }
    if ($_POST['action'] == "ende") {
        $db->exec("UPDATE zeiten SET ende='" . date("H:i:s") . "' WHERE id=(SELECT MAX(id) FROM zeiten)");
    }
    if ($_POST['action'] == "manuell") {
        $stmt = $db->prepare("INSERT INTO zeiten (datum, start, pause_start, pause_ende, ende) 
            VALUES (:datum,:start,:pause_start,:pause_ende,:ende)");
        $stmt->bindValue(":datum", $_POST['datum'], SQLITE3_TEXT);
        $stmt->bindValue(":start", $_POST['start'], SQLITE3_TEXT);
        $stmt->bindValue(":pause_start", $_POST['pause_start'], SQLITE3_TEXT);
        $stmt->bindValue(":pause_ende", $_POST['pause_ende'], SQLITE3_TEXT);
        $stmt->bindValue(":ende", $_POST['ende'], SQLITE3_TEXT);
        $stmt->execute();
    }
    if ($_POST['action'] == "export") {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=arbeitszeiten.csv");

        $result = $db->query("SELECT * FROM zeiten ORDER BY datum, id");
        echo "Datum,Arbeitsbeginn,Pausenbeginn,Pausenende,Arbeitsende,Gearbeitet (exakt),Gearbeitet (gerundet)\n";

        $sumHeute = 0;
        $sumMonat = 0;
        $heute = date("Y-m-d");
        $monat = date("Y-m");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stundenExakt = berechneStunden($row);
            $minuten = 0;
            $stundenGerundet = "";
            if ($stundenExakt) {
                list($h,$m) = explode(":",$stundenExakt);
                $minuten = $h*60 + $m;
                $minutenGerundet = runde15($minuten);
                $stundenGerundet = sprintf("%02d:%02d", floor($minutenGerundet/60), $minutenGerundet%60);
            }

            if ($row['datum'] == $heute) $sumHeute += $minuten;
            if (substr($row['datum'],0,7) == $monat) $sumMonat += $minuten;

            echo "{$row['datum']},{$row['start']},{$row['pause_start']},{$row['pause_ende']},{$row['ende']},$stundenExakt,$stundenGerundet\n";
        }

        $sumHeuteGerundet = runde15($sumHeute);
        $sumMonatGerundet = runde15($sumMonat);
        echo "Summe heute,,,,,," . sprintf("%02d:%02d", floor($sumHeuteGerundet/60), $sumHeuteGerundet%60) . "\n";
        echo "Summe Monat,,,,,," . sprintf("%02d:%02d", floor($sumMonatGerundet/60), $sumMonatGerundet%60) . "\n";

        exit;
    }
}

$heute = date("Y-m-d");
$monat = date("Y-m");

$result = $db->query("SELECT * FROM zeiten WHERE datum='$heute'");
$stundenHeute = 0;
$startTag = null;
$endeTag = null;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['start'] && (!$startTag || $row['start'] < $startTag)) $startTag = $row['start'];
    if ($row['ende'] && (!$endeTag || $row['ende'] > $endeTag)) $endeTag = $row['ende'];

    $dauer = berechneStunden($row);
    if ($dauer) {
        list($h,$m) = explode(":",$dauer);
        $stundenHeute += $h*60 + $m;
    }
}
$stundenHeuteGerundet = runde15($stundenHeute);
$stundenHeuteText = sprintf("%02d:%02d", floor($stundenHeute/60), $stundenHeute%60);
$stundenHeuteRundText = sprintf("%02d:%02d", floor($stundenHeuteGerundet/60), $stundenHeuteGerundet%60);

$result = $db->query("SELECT * FROM zeiten WHERE substr(datum,1,7)='$monat'");
$stundenMonat = 0;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $dauer = berechneStunden($row);
    if ($dauer) {
        list($h,$m) = explode(":",$dauer);
        $stundenMonat += $h*60 + $m;
    }
}
$stundenMonatGerundet = runde15($stundenMonat);
$stundenMonatText = sprintf("%02d:%02d", floor($stundenMonat/60), $stundenMonat%60);
$stundenMonatRundText = sprintf("%02d:%02d", floor($stundenMonatGerundet/60), $stundenMonatGerundet%60);

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Arbeitszeiterfassung</title>
<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
h1 { color: #333; font-size: 1.5rem; margin-bottom: 15px; }

form { margin-bottom: 20px; }
button { 
  margin: 5px; 
  padding: 12px 18px; 
  border: none; 
  border-radius: 8px;
  background: #007bff; 
  color: white; 
  font-size: 1rem; 
  cursor: pointer;
}
button:hover { background: #0056b3; }

form:first-of-type {
  display: flex;
  flex-wrap: nowrap;
  overflow-x: auto;
}
form:first-of-type button {
  flex: 0 0 auto;
}

table { 
  border-collapse: collapse; 
  width: 100%; 
  margin-top: 20px; 
  background: white; 
  border-radius: 10px; 
  overflow: hidden;
}
th, td { 
  border: 1px solid #ddd; 
  padding: 10px; 
  text-align: center; 
  font-size: 0.9rem; 
}
th { background: #f0f0f0; }

@media (max-width: 700px) {
  table, thead, tbody, th, td, tr { display: block; }
  thead { display: none; }
  tr { 
    margin-bottom: 15px; 
    background: white; 
    border: 1px solid #ddd; 
    border-radius: 8px; 
    padding: 10px;
  }
  td { 
    border: none; 
    display: flex; 
    justify-content: space-between; 
    padding: 8px 5px; 
  }
  td::before {
    content: attr(data-label);
    font-weight: bold;
    color: #333;
  }
}

.summary {
  margin: 20px 0;
  padding: 15px;
  background: #e9f5ff;
  border-left: 5px solid #007bff;
  border-radius: 8px;
  font-size: 0.95rem;
}
</style>
</head>
<body>
<h1>Arbeitszeiterfassung</h1>

<a href="?logout=1"><button style="background:#dc3545">Abmelden</button></a>

<form method="post">
  <button name="action" value="start">Arbeitsbeginn</button>
  <button name="action" value="pause">Pause</button>
  <button name="action" value="weiter">Weiter</button>
  <button name="action" value="ende">Ende</button>
  <button name="action" value="export">Export</button>
</form>

<h2>Manuelle Eingabe</h2>
<form method="post">
  <input type="hidden" name="action" value="manuell">
  Datum: <input type="date" name="datum" required><br><br>
  Start: <input type="time" name="start" required><br><br>
  Pause-Start: <input type="time" name="pause_start"><br>
  Pause-Ende: <input type="time" name="pause_ende"><br><br>
  Ende: <input type="time" name="ende" required>
  <button type="submit">Speichern</button>
</form>

<div class="summary">
  <strong>Heute gearbeitet:</strong> <?= $stundenHeuteText ?> h (gerundet: <?= $stundenHeuteRundText ?>) <br>
  <?php if ($startTag && $endeTag): ?>
    <strong>Tageszeitraum:</strong> <?= $startTag ?> – <?= $endeTag ?><br>
  <?php endif; ?>
  <strong>Diesen Monat:</strong> <?= $stundenMonatText ?> h (gerundet: <?= $stundenMonatRundText ?>)
</div>

<h2>Bisherige Einträge</h2>
<table>
<?php
$result = $db->query("SELECT * FROM zeiten ORDER BY id DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $stunden = berechneStunden($row);
    echo "<tr>
            <td data-label='Datum'>{$row['datum']}</td>
            <td data-label='Start'>{$row['start']}</td>
            <td data-label='Pause-Start'>{$row['pause_start']}</td>
            <td data-label='Pause-Ende'>{$row['pause_ende']}</td>
            <td data-label='Ende'>{$row['ende']}</td>
            <td data-label='Gearbeitet'>$stunden</td>
          </tr>";
}
?>
</table>
</body>
</html>

<script>
function aktuelleZeit() {
    const now = new Date();
    let h = now.getHours().toString().padStart(2,'0');
    let m = now.getMinutes().toString().padStart(2,'0');
    let s = now.getSeconds().toString().padStart(2,'0');
    return `${h}:${m}:${s}`;
}

document.addEventListener("DOMContentLoaded", () => {
    const buttons = document.querySelectorAll("form button[name='action']");
    buttons.forEach(btn => {
        btn.addEventListener("click", () => {
            let zeit = aktuelleZeit();
            if(btn.value == "start" || btn.value == "pause" || btn.value == "weiter" || btn.value == "ende"){
                alert(`Zeit für "${btn.textContent}": ${zeit}`);
            }
        });
    });
});
</script>
</html>
