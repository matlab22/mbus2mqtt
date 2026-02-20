<?php

# M-Bus to MQTT - LoxBerry Plugin
# Settings web interface

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";

##########################################################################
# Variables
##########################################################################

$version   = LBSystem::pluginversion();
$cfgfile   = $lbpconfigdir . "/pluginconfig.cfg";
$addrdir   = $lbpconfigdir;
$logfile   = $lbplogdir . "/mbus2mqtt.log";
$template  = $lbptemplatedir . "/settings.html";

##########################################################################
# Get MQTT connection details from LoxBerry system
##########################################################################

$mqtt_creds = mqtt_connectiondetails();

##########################################################################
# Read / Init Config
##########################################################################

$cfg_defaults = [
    'DEVICE'     => '/dev/ttyUSB0',
    'MQTT_HOST'  => $mqtt_creds->brokerhost ?? 'localhost',
    'MQTT_PORT'  => $mqtt_creds->brokerport ?? '1883',
    'MQTT_USER'  => $mqtt_creds->brokeruser ?? '',
    'MQTT_PASS'  => $mqtt_creds->brokerpass ?? '',
    'MQTT_TOPIC' => 'mbusmeters',
    'BAUD_300'   => '0',
    'BAUD_2400'  => '1',
    'BAUD_9600'  => '0',
    'INTERVAL'   => '5',
];

if ( file_exists($cfgfile) ) {
    $raw = parse_ini_file($cfgfile, true);
    $section = isset($raw['MAIN']) ? $raw['MAIN'] : $raw;
    $pcfg = (array)$section;
    // Fill any missing keys with defaults
    foreach ($cfg_defaults as $k => $v) {
        if ( !array_key_exists($k, $pcfg) ) $pcfg[$k] = $v;
    }
} else {
    $pcfg = $cfg_defaults;
    write_cfg($cfgfile, $pcfg);
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
        $old_interval = $pcfg['INTERVAL'];

        $pcfg['DEVICE']     = $_POST['DEVICE']     ?? '/dev/ttyUSB0';
        $pcfg['MQTT_HOST']  = $_POST['MQTT_HOST']  ?? 'localhost';
        $pcfg['MQTT_PORT']  = $_POST['MQTT_PORT']  ?? '1883';
        $pcfg['MQTT_USER']  = $_POST['MQTT_USER']  ?? '';
        $pcfg['MQTT_PASS']  = $_POST['MQTT_PASS']  ?? '';
        $pcfg['MQTT_TOPIC'] = $_POST['MQTT_TOPIC'] ?? 'mbusmeters';
        $pcfg['BAUD_300']   = !empty($_POST['BAUD_300'])  ? '1' : '0';
        $pcfg['BAUD_2400']  = !empty($_POST['BAUD_2400']) ? '1' : '0';
        $pcfg['BAUD_9600']  = !empty($_POST['BAUD_9600']) ? '1' : '0';
        $pcfg['INTERVAL']   = $_POST['INTERVAL']   ?? '5';

        if ( write_cfg($cfgfile, $pcfg) ) {
            $message = "Settings saved.";
            if ( $old_interval !== $pcfg['INTERVAL'] ) {
                update_cron_symlink($old_interval, $pcfg['INTERVAL']);
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
    } elseif ( $action === 'pollnow' ) {
        $script = escapeshellarg("$lbhomedir/bin/plugins/mbus2mqtt/read_send_meters_mqtt.sh");
        $logarg = escapeshellarg($logfile);
        exec("nohup bash $script >> $logarg 2>&1 &");
        $message = "Poll triggered — check the log in a few seconds.";
        $refresh_seconds = 5;
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

$current_device = $pcfg['DEVICE'];
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

function interval_sel($pcfg, $val) {
    return ($pcfg['INTERVAL'] === $val) ? ' selected' : '';
}

##########################################################################
# Output HTML via LoxBerry header + template
##########################################################################

LBWeb::lbheader("M-Bus to MQTT V$version", "", "");

?>

<?php if ($message): ?>
<div style="padding:0.6em 1em; margin-bottom:0.8em; border-radius:4px; background:<?= $message_type === 'error' ? '#c00' : '#2a2' ?>; color:#fff; font-weight:bold;">
  <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<form method="post" action="" id="settingsform">
<input type="hidden" name="action" value="save">

<h3>M-Bus Device</h3>

<div class="ui-field-contain">
  <label for="DEVICE">Serial Device:</label>
  <select name="DEVICE" id="DEVICE" data-mini="true">
    <?php foreach ($dev_options as $opt): ?>
    <option value="<?= $opt['VALUE'] ?>"<?= $opt['SELECTED'] ?>><?= $opt['LABEL'] ?></option>
    <?php endforeach; ?>
  </select>
</div>

<fieldset data-role="controlgroup" data-type="horizontal" data-mini="true">
  <legend>Baud Rates to scan:</legend>
  <input type="checkbox" name="BAUD_300"  id="BAUD_300"  value="1" <?= $pcfg['BAUD_300']  === '1' ? 'checked' : '' ?>>
  <label for="BAUD_300">300</label>
  <input type="checkbox" name="BAUD_2400" id="BAUD_2400" value="1" <?= $pcfg['BAUD_2400'] === '1' ? 'checked' : '' ?>>
  <label for="BAUD_2400">2400</label>
  <input type="checkbox" name="BAUD_9600" id="BAUD_9600" value="1" <?= $pcfg['BAUD_9600'] === '1' ? 'checked' : '' ?>>
  <label for="BAUD_9600">9600</label>
</fieldset>

<div class="ui-field-contain">
  <label for="INTERVAL">Poll Interval:</label>
  <select name="INTERVAL" id="INTERVAL" data-mini="true">
    <option value="1"  <?= interval_sel($pcfg,'1')  ?>>Every 1 minute</option>
    <option value="3"  <?= interval_sel($pcfg,'3')  ?>>Every 3 minutes</option>
    <option value="5"  <?= interval_sel($pcfg,'5')  ?>>Every 5 minutes</option>
    <option value="10" <?= interval_sel($pcfg,'10') ?>>Every 10 minutes</option>
    <option value="15" <?= interval_sel($pcfg,'15') ?>>Every 15 minutes</option>
    <option value="30" <?= interval_sel($pcfg,'30') ?>>Every 30 minutes</option>
    <option value="60" <?= interval_sel($pcfg,'60') ?>>Every 60 minutes (hourly)</option>
  </select>
</div>

<h3>MQTT Settings</h3>

<div class="ui-field-contain">
  <label for="MQTT_HOST">Broker Host:</label>
  <input type="text" name="MQTT_HOST" id="MQTT_HOST" value="<?= htmlspecialchars($pcfg['MQTT_HOST']) ?>" data-mini="true">
</div>

<div class="ui-field-contain">
  <label for="MQTT_PORT">Broker Port:</label>
  <input type="number" name="MQTT_PORT" id="MQTT_PORT" value="<?= htmlspecialchars($pcfg['MQTT_PORT']) ?>" min="1" max="65535" data-mini="true">
</div>

<div class="ui-field-contain">
  <label for="MQTT_USER">Username:</label>
  <input type="text" name="MQTT_USER" id="MQTT_USER" value="<?= htmlspecialchars($pcfg['MQTT_USER']) ?>" autocomplete="off" data-mini="true">
</div>

<div class="ui-field-contain">
  <label for="MQTT_PASS">Password:</label>
  <input type="password" name="MQTT_PASS" id="MQTT_PASS" value="<?= htmlspecialchars($pcfg['MQTT_PASS']) ?>" autocomplete="off" data-mini="true">
</div>

<div class="ui-field-contain">
  <label for="MQTT_TOPIC">Base Topic:</label>
  <input type="text" name="MQTT_TOPIC" id="MQTT_TOPIC" value="<?= htmlspecialchars($pcfg['MQTT_TOPIC']) ?>" data-mini="true">
</div>
<p style="font-size:0.85em; color:#888; margin-top:0;">Data published to: <em>&lt;topic&gt;/&lt;meter-address&gt;</em></p>

<input type="submit" value="Save Settings" data-mini="true" data-icon="check">
</form>

<br>

<form method="post" action="">
  <input type="hidden" name="action" value="pollnow">
  <input type="submit" value="Poll Now" data-mini="true" data-icon="arrow-r" data-theme="b">
</form>

<form method="post" action="">
  <input type="hidden" name="action" value="rescan">
  <input type="submit" value="Clear Address Cache &amp; Force Rescan" data-mini="true" data-icon="refresh" data-theme="b">
</form>

<h3>Log</h3>

<form method="get" action="">
  <div class="ui-field-contain">
    <label for="loglines">Lines:</label>
    <select name="loglines" id="loglines" data-mini="true">
      <option value="40"  <?= $log_lines === 40  ? 'selected' : '' ?>>40</option>
      <option value="100" <?= $log_lines === 100 ? 'selected' : '' ?>>100</option>
      <option value="200" <?= $log_lines === 200 ? 'selected' : '' ?>>200</option>
    </select>
  </div>
  <div class="ui-field-contain">
    <label for="refresh">Auto-refresh:</label>
    <select name="refresh" id="refresh" data-mini="true">
      <option value="0"  <?= $refresh_seconds === 0  ? 'selected' : '' ?>>Off</option>
      <option value="5"  <?= $refresh_seconds === 5  ? 'selected' : '' ?>>5s</option>
      <option value="10" <?= $refresh_seconds === 10 ? 'selected' : '' ?>>10s</option>
      <option value="30" <?= $refresh_seconds === 30 ? 'selected' : '' ?>>30s</option>
    </select>
  </div>
  <input type="submit" value="Apply" data-mini="true" data-inline="true">
</form>

<form method="post" action="" style="margin:0.5em 0;">
  <input type="hidden" name="action" value="clearlog">
  <input type="submit" value="Clear Log" data-mini="true" data-inline="true" data-icon="delete" data-theme="b">
</form>

<pre style="background:#111;color:#eee;padding:0.8em;overflow:auto;max-height:400px;font-size:0.8em;border-radius:4px;"><?= $log_content ?></pre>

<?php if ( $refresh_seconds > 0 ): ?>
<script>setTimeout(function(){window.location.reload();},<?= (int)$refresh_seconds * 1000 ?>);</script>
<?php endif; ?>

<?php

LBWeb::lbfooter();

##########################################################################
# Helper: write INI-style config file
##########################################################################

function write_cfg($file, $pcfg) {
    $content = "[MAIN]\n";
    foreach ($pcfg as $k => $v) {
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
