<?php
/**
 * A script to convert database collation/charset to utf8mb4
 *
 * @copyright Copyright 2003-2021 Zen Cart Development Team
 * @license https://www.zen-cart-pro.at/license/3_0.txt GNU General Public License V3.0
 * @version $Id: utf8mb4-conversion.php 1 2021-11-07 16:00:00 webchills
 *
 * @copyright Adapted from http://stackoverflow.com/questions/105572/ and https://mathiasbynens.be/notes/mysql-utf8mb4
 *
 * NOTE!!!! NOTE!!!! NOTE!!!!
 * Sie sollten Ihren Zen Cart-Shop (und Ihre Datenbank) mindestens auf v1.5.6f deutsch aktualisieren, bevor Sie dieses Skript ausführen. 
 * (Dies liegt daran, dass die Schema-Updates in v1.5.6 Indexlängen korrigieren, die für utf8mb4 erforderlich sind).
 *
 */

// Tragen Sie hier die Zugangsdaten zu Ihrer Datenbank ein, genauso wie sie in den configure.php Ihres Zen Cart Shops hinterlegt sind:
$username = 'username';  // wie DB_SERVER_USERNAME in Ihren configure.php
$password = 'password';  // wie DB_SERVER_PASSWORD in Ihren configure.php
$db = 'database';  // wie DB_DATABASE in Ihren configure.php
$host = 'localhost';  // wie DB_SERVER in Ihren configure.php
$prefix = '';  // Falls Ihre Tabellen Namen ein Präfix verwenden wie z.B. zen_, geben Sie hier dieses Präfix an. Falls Sie kein Präfix verwenden, einfach leer lassen. // wie DB_PREFIX in Ihren configure.php


// Empfohlene Einstellung ist 'utf8mb4':
$target_charset = 'utf8mb4';


$simulate_only = false;
$skip_fields_already_using_new_collation_charset = true;
$show_sql_in_error_messages = true;



/////// DO NOT CHANGE BELOW THIS LINE ////////

$collation_fallbacks = array();
$collation_fallbacks[] = 'utf8mb4_general_ci';
$collation_fallbacks[] = 'utf8mb4_bin';
$collation_fallbacks[] = $target_charset . '_general_ci';
$collation_fallbacks[] = $target_charset . '_bin';

// Begin processing
$timer = time();
$tables = array();
$t = $i = 0;

echo "<strong>Datenbank Charset/Kollation Konvertierung</strong><br><br>\n\n";
if ($username == "username") die('<span style="color: red; font-weight: bold">Fehler: Datenbank Zugangsdaten erforderlich. Btte bearbeiten Sie diese PHP Datei wie in der Anleitung beschrieben und geben Sie Ihre Datenbank Zugangsdaten an.</span>');

$conn = mysqli_connect($host, $username, $password);
mysqli_select_db($conn, $db);

// identify target tables
$res = mysqli_query($conn, $sql = "SHOW TABLES");
printMySqlErrors($sql);
while (($row = mysqli_fetch_row($res)) != null) {
    if ($prefix == '') {
        $tables[] = $row[0];
    } else if (substr($row[0], 0, strlen($prefix)) == $prefix) {
        $tables[] = $row[0];
    }
}

// determine best supported target collation
$res = mysqli_query($conn, $sql = "SHOW COLLATION WHERE Charset = '{$target_charset}'");
printMySqlErrors($sql);
$db_collations = array();
while (($row = mysqli_fetch_row($res)) != null) {
    $db_collations[] = $row[0];
}
foreach ($collation_fallbacks as $collation) {
    if (in_array($collation, $db_collations)) {
        $target_collate = $collation;
        break;
    }
}

if (empty($target_collate)) {
    die('FEHLER: Kann keine sichere Kollation finden');
}

echo "Konvertiere Tabellen " . ($prefix == '' ? '' : 'with prefix [' . $prefix . '] ') . "in Datenbank [$db] zu $target_collate \n\n<br><br>\n\nDas kann einige Zeit dauern. Bitte warten ... <br><pre>\n\n";


// process tables
foreach ($tables as $table) {
    $t++;
    echo "\nVerarbeite Tabelle [{$table}]:\n";
    ob_flush();

    // repair table first
    @set_time_limit(120);
    if (!$simulate_only) {
        mysqli_query($conn, $sql = "REPAIR TABLE `{$table}`");
        printMySqlErrors($sql);
    }

    // collect indexes to drop and rebuild
    @set_time_limit(120);
    $res = mysqli_query($conn, $sql = "SHOW INDEX FROM `{$table}`");
    printMySqlErrors($sql);
    $indices = array();
    while (($row = mysqli_fetch_array($res)) != null) {
        if ($row[2] != "PRIMARY") {
            if (sizeof($indices) == 0 || $indices[sizeof($indices) - 1]['name'] != $row[2]) {
                $indices[] = array("name" => $row[2], "unique" => (int)!($row[1] == "1"), "col" => $row[4] . ($row[7] < 1 ? '' : "($row[7])"));
                $sql = "ALTER TABLE `{$table}` DROP INDEX `{$row[2]}`";
                if ($simulate_only) {
                    echoSql($sql);
                } else {
                    mysqli_query($conn, $sql);
                    printMySqlErrors($sql);
                }
                echo "Temporär entfernt " . ($row[1] == '0' ? 'unique ' : '') . "index {$row[2]}.\n";
            } else {
                $indices[sizeof($indices) - 1]["col"] .= ', ' . $row[4] . ($row[7] < 1 ? '' : "($row[7])");
            }
        }
    }

//    $res = mysqli_query($conn, "DESCRIBE `{$table}`");
$sql = "SELECT `COLUMN_NAME` AS `Field`,
`COLUMN_TYPE`       AS `Type`,
`CHARACTER_SET_NAME` AS `Charset`,
`COLLATION_NAME`    AS `Collation`,
`IS_NULLABLE`       AS `Null`,
`COLUMN_KEY`        AS `Key`,
`COLUMN_DEFAULT`    AS `Default`,
`EXTRA`             AS `Extra`,
`PRIVILEGES`        AS `Privileges`,
`COLUMN_COMMENT`    AS `Comment`
FROM `information_schema`.`COLUMNS`
WHERE TABLE_NAME = '{$table}' 
AND TABLE_SCHEMA = '{$db}'";
    $res = mysqli_query($conn, $sql);
    printMySqlErrors($sql);
    while (($row = mysqli_fetch_assoc($res)) !== null) {
        @set_time_limit(120);
        $name = $row['Field'];
        $type = $row['Type'];
        $allownull = (strtoupper($row['Null']) === 'YES') ? 'NULL' : 'NOT NULL';
        $defaultval = ($allownull === 'NULL') ? 'DEFAULT NULL' : '';
        if (isset($row['Default']) && $row['Default'] !== null) {
            $default = $row['Default'];
            $default = str_replace(["''''", "''''''", "'NULL'"], '', $default);
            if ($default === '') $default = "''";
            $defaultval = "DEFAULT {$default}";
        }

        $current_charset = $row['Charset'];
        if ($skip_fields_already_using_new_collation_charset && $current_charset === $target_charset) {
            echo "Überspringe {$name} da sie bereits verwendet {$target_charset}.\n";
            continue;
        }

        $set = false;
        if (preg_match("/^varchar\((\d+)\)$/i", $type, $matches)) {
            $size = $matches[1];
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` VARBINARY({$size}) {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` VARCHAR({$size}) CHARACTER SET {$target_charset} {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $set = true;
            echo "Altered field `{$name}`: `{$type} {$allownull} {$defaultval}`\n";

        } elseif (preg_match("/^char\((\d+)\)$/i", $type, $matches)) {
            $size = $matches[1];
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` BINARY({$size}) {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` CHAR({$size}) CHARACTER SET {$target_charset} {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $set = true;
            echo "Altered field `{$name}`: `{$type} {$allownull} {$defaultval}`\n";

        } elseif (!strcasecmp($type, "TINYTEXT")) {
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` TINYBLOB {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` TINYTEXT CHARACTER SET {$target_charset} {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $set = true;
            echo "Altered field `{$name}`: `{$type} {$allownull} {$defaultval}`\n";

        } elseif (!strcasecmp($type, "MEDIUMTEXT")) {
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` MEDIUMBLOB {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` MEDIUMTEXT CHARACTER SET {$target_charset} {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $set = true;
            echo "Feld geändert `{$name}`: `{$type} {$allownull} {$defaultval}`\n";

        } elseif (!strcasecmp($type, "LONGTEXT")) {
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` LONGBLOB {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` LONGTEXT CHARACTER SET {$target_charset} {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $set = true;
            echo "Feld geändert `{$name}`: `{$type} {$allownull} {$defaultval}`\n";

        } elseif (!strcasecmp($type, "TEXT")) {
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` BLOB {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` TEXT CHARACTER SET {$target_charset} {$allownull} {$defaultval}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
                printMySqlErrors($sql);
            }
            $set = true;
            echo "Feld geändert `{$name}`: `{$type} {$allownull} {$defaultval}`\n";
        }
        if ($set) {
            $sql = "ALTER TABLE `{$table}` MODIFY `{$name}` COLLATE {$target_collate}";
            if ($simulate_only) {
                echoSql($sql);
            } else {
                mysqli_query($conn, $sql);
            }
        }
    }
    echo "Verarbeite Indexes...\n";
    ob_flush();
    // re-build indices..
    foreach ($indices as $index) {
        $i++;
        @set_time_limit(120);
        $sql = "CREATE " . ($index["unique"] ? "UNIQUE " : '') . "INDEX {$index["name"]} ON {$table} ({$index["col"]})";
        if ($simulate_only) {
            echoSql($sql);
        } else {
            mysqli_query($conn, $sql);
            printMySqlErrors($sql);
        }
        echo "Neu erzeugt " . ($index['unique'] == '1' ? 'unique ' : '') . "index {$index["name"]} on {$table} ({$index["col"]}).\n";
    }
    // set default collate
    @set_time_limit(120);
    $sql = "ALTER TABLE `{$table}` DEFAULT CHARACTER SET {$target_charset} COLLATE {$target_collate}";
    if ($simulate_only) {
        echoSql($sql);
    } else {
        mysqli_query($conn, $sql);
        printMySqlErrors($sql);
    }
    echo "Tabbelen Kollation aktualisiert.\n";
    ob_flush();
}
// set database charset
@set_time_limit(120);
$sql = "ALTER DATABASE {$db} DEFAULT CHARACTER SET {$target_charset} COLLATE {$target_collate}";
if ($simulate_only) {
    echoSql($sql);
} else {
    mysqli_query($conn, $sql);
    printMySqlErrors($sql);
}

mysqli_close($conn);
echo "</pre>\n";
$timer_diff = time() - $timer;
echo $t . ' Tabellen verarbeitet, ' . $i . ' Indexes verarbeitet, ' . $timer_diff . ' Sekunden Verarbeitungszeit.' . "\n\n";
echo '<br><br><br><span style="color:red;font-weight:bold">HINWEIS: Diese Datei sollte nun aus Sicherheitsgründen sofort vom Server GELÖSCHT werden!!!!!</span><br><br><br>';

function printMySqlErrors($sql)
{
    global $conn;
    global $show_sql_in_error_messages;
    if (mysqli_errno($conn)) {
        echo '<span style="color: red; font-weight: bold">MySQL Error: ' . mysqli_error($conn) . '</span>' . "\n";
        if ($show_sql_in_error_messages) {
            echoSql($sql);
        }
    }
}
function echoSql($sql)
{
    echo $sql . "\n";   
}
