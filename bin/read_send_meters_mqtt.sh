#!/bin/bash

# M-Bus to MQTT - LoxBerry Plugin
# Reads data from M-Bus meters and publishes JSON to MQTT.

# ---------------------------------------------------------------
# Resolve plugin folder (REPLACELBPBINDIR is replaced on install)
# ---------------------------------------------------------------
LBHOME=${LBHOMEDIR:-/opt/loxberry}
CFGFILE="$LBHOME/config/plugins/mbus2mqtt/mbus2mqtt.cfg"

# ---------------------------------------------------------------
# Parse INI config (bash-native, no external parser needed)
# Handles [MAIN] section: strips section headers and comments.
# ---------------------------------------------------------------
get_cfg() {
    sed -n '/^\[MAIN\]/,/^\[/p' "$CFGFILE" 2>/dev/null \
        | grep -i "^$1\s*=" | head -1 | cut -d'=' -f2- | tr -d '\r\n' | sed 's/^[[:space:]]*//' 
}

DEVICE=$(get_cfg DEVICE)
MQTT_HOST=$(get_cfg MQTT_HOST)
MQTT_PORT=$(get_cfg MQTT_PORT)
MQTT_USER=$(get_cfg MQTT_USER)
MQTT_PASS=$(get_cfg MQTT_PASS)
MQTT_TOPIC=$(get_cfg MQTT_TOPIC)
BAUD_2400=$(get_cfg BAUD_2400)
BAUD_9600=$(get_cfg BAUD_9600)
BAUD_300=$(get_cfg BAUD_300)

# Defaults
DEVICE=${DEVICE:-/dev/ttyUSB0}
MQTT_HOST=${MQTT_HOST:-localhost}
MQTT_PORT=${MQTT_PORT:-1883}
MQTT_TOPIC=${MQTT_TOPIC:-mbusmeters}

ADDRDIR="$LBHOME/config/plugins/mbus2mqtt"
LOGDIR="$LBHOME/log/plugins/mbus2mqtt"
mkdir -p "$ADDRDIR" "$LOGDIR"

# ---------------------------------------------------------------
# Python3 helper: XML (mbus output) → JSON
# No external packages required.
# ---------------------------------------------------------------
xml_to_json() {
python3 - "$1" <<'PYEOF'
import sys, xml.etree.ElementTree as ET, json

def elem_to_dict(el):
    d = dict(el.attrib)
    children = list(el)
    if children:
        child_dict = {}
        for ch in children:
            key = ch.tag
            val = elem_to_dict(ch)
            if key in child_dict:
                if not isinstance(child_dict[key], list):
                    child_dict[key] = [child_dict[key]]
                child_dict[key].append(val)
            else:
                child_dict[key] = val
        d.update(child_dict)
    else:
        text = (el.text or "").strip()
        if text:
            if d:
                d["_text"] = text
            else:
                return text
    return d

try:
    xml_input = sys.stdin.read() if sys.argv[1] == "-" else open(sys.argv[1]).read()
    root = ET.fromstring(xml_input)
    print(json.dumps({root.tag: elem_to_dict(root)}, indent=2))
except Exception as e:
    print(json.dumps({"error": str(e)}))
PYEOF
}

# ---------------------------------------------------------------
# MQTT publish helper
# ---------------------------------------------------------------
mqtt_pub() {
    local topic=$1
    local message=$2
    local args=(-h "$MQTT_HOST" -p "$MQTT_PORT" -t "$topic" -m "$message")
    [ -n "$MQTT_USER" ] && args+=(-u "$MQTT_USER")
    [ -n "$MQTT_PASS" ] && args+=(-P "$MQTT_PASS")
    /usr/bin/mosquitto_pub "${args[@]}"
}

# ---------------------------------------------------------------
# Read meters at a given baud rate
# ---------------------------------------------------------------
read_mbus_data() {
    local baud=$1
    local addrfile="$ADDRDIR/addresses_${baud}.txt"

    echo ""
    echo "$(date) - Baud: $baud"

    # Auto-scan if address file missing or empty
    if [ ! -s "$addrfile" ]; then
        echo "Scanning for M-Bus devices at $baud baud on $DEVICE ..."
        mbus-serial-scan-secondary -b "$baud" "$DEVICE" \
            | grep -oE '[0-9A-Fa-f]{16}' > "$addrfile"
        local count
        count=$(wc -l < "$addrfile" 2>/dev/null)
        echo "Found $count device(s)."
    fi

    [ ! -s "$addrfile" ] && echo "No devices found for $baud baud." && return

    while IFS= read -r addr; do
        [ -z "$addr" ] && continue
        echo -n "  Reading $addr ... "

        # Get raw XML from meter
        local xml
        xml=$(mbus-serial-request-data-multi-reply -b "$baud" "$DEVICE" "$addr" 2>/dev/null)
        if [ -z "$xml" ]; then
            echo "no data."
            continue
        fi

        # Convert XML to JSON
        local json
        json=$(echo "$xml" | xml_to_json -)

        # Publish full JSON
        mqtt_pub "$MQTT_TOPIC/$addr" "$json"

        local bytes
        bytes=$(echo "$json" | wc -c)
        echo "$bytes bytes sent."

        # Also publish a summary topic
        local id manufacturer medium
        id=$(echo "$json"        | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('MBusData',{}).get('SlaveInformation',{}).get('Id',''))" 2>/dev/null)
        manufacturer=$(echo "$json" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('MBusData',{}).get('SlaveInformation',{}).get('Manufacturer',''))" 2>/dev/null)
        medium=$(echo "$json"    | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('MBusData',{}).get('SlaveInformation',{}).get('Medium',''))" 2>/dev/null)
        echo "    id=$id  manufacturer=$manufacturer  medium=$medium"

    done < "$addrfile"
}

# ---------------------------------------------------------------
# Main
# ---------------------------------------------------------------
echo "===== M-Bus to MQTT ===== $(date)"

if ! command -v mbus-serial-scan-secondary > /dev/null 2>&1; then
    echo "ERROR: mbus-serial-scan-secondary not found. Is libmbus installed?"
    exit 1
fi

if ! command -v mosquitto_pub > /dev/null 2>&1; then
    echo "ERROR: mosquitto_pub not found. Install mosquitto-clients."
    exit 1
fi

[ "$BAUD_300"  = "1" ] && read_mbus_data 300
[ "$BAUD_2400" = "1" ] && read_mbus_data 2400
[ "$BAUD_9600" = "1" ] && read_mbus_data 9600

echo "===== Done ====="
