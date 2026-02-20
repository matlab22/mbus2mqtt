# mbus2mqtt - LoxBerry Plugin

Reads data from M-Bus meters (water, gas, heat, electricity) via a USB M-Bus master adapter and publishes the values as JSON to an MQTT broker. From there the data can be forwarded to a Loxone Miniserver using the LoxBerry MQTT plugin.

## What it does

- Scans the M-Bus for connected slave devices (meters) at configurable baud rates (300 / 2400 / 9600)
- Caches found device addresses so subsequent runs skip the scan
- Reads meter data, converts the XML response to JSON and publishes it to `<topic>/<meter-address>`
- Runs automatically on a configurable interval (1 / 3 / 5 / 10 / 15 / 30 / 60 minutes) via LoxBerry cron
- Settings (device, MQTT broker, baud rates, interval) are managed through the LoxBerry web UI
- "Clear address cache" button forces a fresh bus scan on the next run

## Hardware requirements

- M-Bus meter(s): e.g. water meter, gas meter, heat meter with M-Bus interface
- USB M-Bus master adapter (e.g. from AliExpress ~25 €) **or** TTL M-Bus master (~18 €)
- LoxBerry running LoxBerry OS 3.0+

## Dependencies (installed automatically)

- `libmbus` (compiled from source: https://github.com/rscada/libmbus)
- `mosquitto-clients` (for `mosquitto_pub`)
- `python3` (for XML → JSON conversion, no extra packages needed)
- `jq`, `git`, `libtool`, `autoconf` (build tools)

## Installation

Install via the LoxBerry Plugin Manager using the URL:

```
https://github.com/matlab22/mbus2mqtt/archive/master.zip
```

During installation `libmbus` is automatically cloned and compiled. This may take a few minutes.

## Configuration

After installation open the plugin settings page in the LoxBerry web UI:

1. Select the serial device your USB M-Bus master is on (e.g. `/dev/ttyUSB0`)
2. Check the baud rate(s) your meters use (2400 is most common)
3. Enter your MQTT broker address and credentials
4. Set the base MQTT topic (data will appear at `<topic>/<meter-address>`)
5. Choose the poll interval
6. Save — the cron job is activated immediately

To add a new meter later: click **Clear Address Cache** in the UI. The next poll will rescan the bus.

## Credits

Based on the work by [themole](https://the78mole.de/taking-your-m-bus-online-with-mqtt/) and the [libmbus](https://github.com/rscada/libmbus) library.

Originally documented at: https://wiki.loxberry.de/modifikationen_hacks/mbus2mqtt
