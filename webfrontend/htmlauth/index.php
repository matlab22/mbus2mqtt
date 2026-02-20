<?php

# M-Bus to MQTT - LoxBerry Plugin
# Settings web interface

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

##########################################################################
# Variables
##########################################################################

$version   = LBSystem::pluginversion();
$cfgfile   = $lbpconfigdir . "/mbus2mqtt.cfg";
$addrdir   = $lbpconfigdir;
$logfile   = $lbplogdir . "/mbus2mqtt.log";
$template  = $lbptemplatedir . "/settings.html";

##########################################################################
# Read / Init Config
##########################################################################

$cfg_defaults = [
    'DEVICE'     => '/dev/ttyUSB0',
    'MQTT_HOST'  => 'localhost',
    'MQTT_PORT'  => '1883',
    'MQTT_USER'  => 'loxberry',
    'MQTT_PASS'  => '',
    'MQTT_TOPIC' => 'mbusmeters',
    'BAUD_300'   => '0',
    'BAUD_2400'  => '1',
    'BAUD_9600'  => '0',
    'INTERVAL'   => '5',
];

if ( file_exists($cfgfile) ) {
    $raw = parse_ini_file($cfgfile, true);
    $cfg = isset($raw['MAIN']) ? $raw['MAIN'] : [];
    // Fill any missing keys with defaults
    foreach ($cfg_defaults as $k => $v) {
        if ( !isset($cfg[$k]) ) $cfg[$k] = $v;
    }
} else {
    $cfg = $cfg_defaults;
    write_cfg($cfgfile, $cfg);
}

##########################################################################
# Handle POST (save settings)
##########################################################################

$message      = "";
$message_type = "ok";

$allowed_log_lines = [40, 100, 200];
$log_lines = (int)($_GET['loglines'] ?? 40);
if ( !in_array($log_lines, $allowed_log_lines, true) ) {
    $log_lines = 40;
}

$allowed_refresh = [0, 5, 10, 30];
$refresh_seconds = (int)($_GET['refresh'] ?? 0);
if ( !in_array($refresh_seconds, $allowed_refresh, true) ) {
    $refresh_seconds = 0;
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $action = $_POST['action'] ?? '';

    if ( $action === 'save' ) {
        $old_interval = $cfg['INTERVAL'];

        $cfg['DEVICE']     = $_POST['DEVICE']     ?? '/dev/ttyUSB0';
        $cfg['MQTT_HOST']  = $_POST['MQTT_HOST']  ?? 'localhost';
        $cfg['MQTT_PORT']  = $_POST['MQTT_PORT']  ?? '1883';
        $cfg['MQTT_USER']  = $_POST['MQTT_USER']  ?? '';
        $cfg['MQTT_PASS']  = $_POST['MQTT_PASS']  ?? '';
        $cfg['MQTT_TOPIC'] = $_POST['MQTT_TOPIC'] ?? 'mbusmeters';
        $cfg['BAUD_300']   = !empty($_POST['BAUD_300'])  ? '1' : '0';
        $cfg['BAUD_2400']  = !empty($_POST['BAUD_2400']) ? '1' : '0';
        $cfg['BAUD_9600']  = !empty($_POST['BAUD_9600']) ? '1' : '0';
        $cfg['INTERVAL']   = $_POST['INTERVAL']   ?? '5';

        if ( write_cfg($cfgfile, $cfg) ) {
            $message = "Settings saved.";
            if ( $old_interval !== $cfg['INTERVAL'] ) {
                update_cron_symlink($old_interval, $cfg['INTERVAL']);
            }
        } else {
            $message      = "Error saving config.";
            $message_type = "error";
        }

    } elseif ( $action === 'rescan' ) {
        foreach (['300', '2400', '9600'] as $baud) {
            $f = "$addrdir/addresses_$baud.txt";
            if ( file_exists($f) ) unlink($f);
        }
        $message = "Address cache cleared. Next run will rescan the M-Bus.";
    } elseif ( $action === 'clearlog' ) {
        if ( file_put_contents($logfile, "") !== false ) {
            $message = "Log cleared.";
        } else {
            $message = "Could not clear log.";
            $message_type = "error";
        }
    }
}

##########################################################################
# Build available /dev/tty* serial devices list
##########################################################################

$serial_devices = array_merge(
    glob('/dev/ttyUSB*') ?: [],
    glob('/dev/ttyAMA*') ?: [],
    glob('/dev/ttyS*')   ?: []
);
sort($serial_devices);

$current_device = $cfg['DEVICE'];
$dev_options = [];
foreach ($serial_devices as $d) {
    $dev_options[] = [
        'VALUE'    => htmlspecialchars($d),
        'LABEL'    => htmlspecialchars($d),
        'SELECTED' => ($d === $current_device) ? ' selected' : '',
    ];
}
// If configured device not in list, prepend it
$found = array_filter($dev_options, fn($o) => $o['VALUE'] === htmlspecialchars($current_device));
if ( empty($found) ) {
    array_unshift($dev_options, [
        'VALUE'    => htmlspecialchars($current_device),
        'LABEL'    => htmlspecialchars($current_device) . " (configured)",
        'SELECTED' => ' selected',
    ]);
}

##########################################################################
# Read last N lines of log
##########################################################################

$log_content = "(no log yet)";
if ( file_exists($logfile) ) {
    $lines = [];
    exec('tail -' . (int)$log_lines . ' ' . escapeshellarg($logfile) . ' 2>/dev/null', $lines);
    $log_content = htmlspecialchars(implode("\n", $lines));
}

##########################################################################
# Interval selected helper
##########################################################################

function interval_sel($cfg, $val) {
    return ($cfg['INTERVAL'] === $val) ? ' selected' : '';
}

##########################################################################
# Output HTML via LoxBerry header + template
##########################################################################

LBWeb::lbheader("M-Bus to MQTT V$version", "", "");

?>
<form method="post" action="">
<input type="hidden" name="action" value="save">

<div class="lbform">
  <h3>Serial Device</h3>
  <label>M-Bus Device:</label>
  <select name="DEVICE">
    <?php foreach ($dev_options as $opt): ?>
    <option value="<?= $opt['VALUE'] ?>"<?= $opt['SELECTED'] ?>><?= $opt['LABEL'] ?></option>
    <?php endforeach; ?>
  </select>

  <h3>MQTT Settings</h3>
  <label>MQTT Host:</label>
  <input type="text" name="MQTT_HOST" value="<?= htmlspecialchars($cfg['MQTT_HOST']) ?>">

  <label>MQTT Port:</label>
  <input type="text" name="MQTT_PORT" value="<?= htmlspecialchars($cfg['MQTT_PORT']) ?>">

  <label>MQTT User:</label>
  <input type="text" name="MQTT_USER" value="<?= htmlspecialchars($cfg['MQTT_USER']) ?>">

  <label>MQTT Password:</label>
  <input type="password" name="MQTT_PASS" value="<?= htmlspecialchars($cfg['MQTT_PASS']) ?>">

  <label>MQTT Topic:</label>
  <input type="text" name="MQTT_TOPIC" value="<?= htmlspecialchars($cfg['MQTT_TOPIC']) ?>">

  <h3>Baud Rates to scan</h3>
  <label><input type="checkbox" name="BAUD_300"  <?= $cfg['BAUD_300']  === '1' ? 'checked' : '' ?>> 300 baud</label>
  <label><input type="checkbox" name="BAUD_2400" <?= $cfg['BAUD_2400'] === '1' ? 'checked' : '' ?>> 2400 baud</label>
  <label><input type="checkbox" name="BAUD_9600" <?= $cfg['BAUD_9600'] === '1' ? 'checked' : '' ?>> 9600 baud</label>

  <h3>Poll Interval</h3>
  <label>Interval (minutes):</label>
  <select name="INTERVAL">
    <option value="1" <?= interval_sel($cfg,'1') ?>>1 min</option>
    <option value="3" <?= interval_sel($cfg,'3') ?>>3 min</option>
    <option value="5" <?= interval_sel($cfg,'5') ?>>5 min</option>
    <option value="10"<?= interval_sel($cfg,'10') ?>>10 min</option>
    <option value="15"<?= interval_sel($cfg,'15') ?>>15 min</option>
    <option value="30"<?= interval_sel($cfg,'30') ?>>30 min</option>
    <option value="60"<?= interval_sel($cfg,'60') ?>>60 min (hourly)</option>
  </select>

  <?php if ($message): ?>
  <div class="lbmessage <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <button type="submit">Save Settings</button>
</div>
</form>

<form method="post" action="" style="margin-top:1em;">
  <input type="hidden" name="action" value="rescan">
  <button type="submit">Clear Address Cache &amp; Rescan</button>
</form>

<h3>Log</h3>
<form method="get" action="" style="margin:1em 0; display:flex; gap:1em; align-items:center; flex-wrap:wrap;">
    <label>
        Lines:
        <select name="loglines">
            <option value="40" <?= $log_lines === 40 ? 'selected' : '' ?>>40</option>
            <option value="100" <?= $log_lines === 100 ? 'selected' : '' ?>>100</option>
            <option value="200" <?= $log_lines === 200 ? 'selected' : '' ?>>200</option>
        </select>
    </label>
    <label>
        Auto-refresh:
        <select name="refresh">
            <option value="0" <?= $refresh_seconds === 0 ? 'selected' : '' ?>>Off</option>
            <option value="5" <?= $refresh_seconds === 5 ? 'selected' : '' ?>>5s</option>
            <option value="10" <?= $refresh_seconds === 10 ? 'selected' : '' ?>>10s</option>
            <option value="30" <?= $refresh_seconds === 30 ? 'selected' : '' ?>>30s</option>
        </select>
    </label>
    <button type="submit">Apply</button>
</form>

<form method="post" action="" style="margin-bottom:1em;">
    <input type="hidden" name="action" value="clearlog">
    <button type="submit" onclick="return confirm('Clear the plugin log file?');">Clear Log</button>
</form>

<h4>Last <?= (int)$log_lines ?> lines</h4>
<pre style="background:#111;color:#eee;padding:1em;overflow:auto;max-height:400px;"><?= $log_content ?></pre>

<?php if ( $refresh_seconds > 0 ): ?>
<script>
setTimeout(function () {
    window.location.reload();
}, <?= (int)$refresh_seconds * 1000 ?>);
</script>
<?php endif; ?>

<?php

LBWeb::lbfooter();

##########################################################################
# Helper: write INI-style config file
##########################################################################

function write_cfg($file, $cfg) {
    $content = "[MAIN]\n";
    foreach ($cfg as $k => $v) {
        $content .= "$k = $v\n";
    }
    return file_put_contents($file, $content) !== false;
}

##########################################################################
# Helper: move cron symlink to new interval folder
##########################################################################

function update_cron_symlink($old, $new) {
    global $lbhomedir;

    $cron_map = [
        '1'  => 'cron.01min',
        '3'  => 'cron.03min',
        '5'  => 'cron.05min',
        '10' => 'cron.10min',
        '15' => 'cron.15min',
        '30' => 'cron.30min',
        '60' => 'cron.hourly',
    ];

    $old_dir = $cron_map[$old] ?? null;
    $new_dir = $cron_map[$new] ?? null;

    if ( !$old_dir || !$new_dir ) return;

    $script   = "$lbhomedir/bin/plugins/mbus2mqtt/mbus2mqtt_cronjob.sh";
    $old_link = "$lbhomedir/system/cron/$old_dir/99-mbus2mqtt";
    $new_link = "$lbhomedir/system/cron/$new_dir/99-mbus2mqtt";

    if ( file_exists($old_link) || is_link($old_link) ) unlink($old_link);
    symlink($script, $new_link);
}
