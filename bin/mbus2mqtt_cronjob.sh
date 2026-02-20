#!/bin/bash

# M-Bus to MQTT - Cron wrapper
# This script is called by the LoxBerry cron daemon.
# The symlink is placed in /opt/loxberry/system/cron/cron.05min/
# by postinstall.sh. To change the interval, update postinstall.sh
# or move the symlink to a different cron.XXmin folder via the plugin UI.

LBHOME=${LBHOMEDIR:-/opt/loxberry}
SCRIPT="$LBHOME/bin/plugins/mbus2mqtt/read_send_meters_mqtt.sh"
LOGFILE="$LBHOME/log/plugins/mbus2mqtt/mbus2mqtt.log"

# Rotate log if larger than 1 MB
if [ -f "$LOGFILE" ] && [ "$(stat -c%s "$LOGFILE" 2>/dev/null)" -gt 1048576 ]; then
    mv "$LOGFILE" "${LOGFILE}.old"
fi

bash "$SCRIPT" >> "$LOGFILE" 2>&1
