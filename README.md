# fpp-HomeAssistant

Integrates [Falcon Player (FPP)](https://github.com/FalconChristmas/fpp) with
[Home Assistant](https://www.home-assistant.io/) using Home Assistant's MQTT Auto Discovery.

Once FPP's MQTT connection is configured and this plugin is enabled, FPP's entities show up in Home
Assistant automatically — no manual YAML entity definitions needed.

## Features

- **Pixel Overlay Models as RGB lights** — each FPP Overlay Model is discovered as an RGB Light
  device, so it can be switched and coloured from Home Assistant.
- **GPIO inputs as binary sensors** — FPP GPIO inputs are discovered as HA binary sensors.
- **GPIO outputs as switches** — FPP GPIO outputs are discovered as HA switches.
- **WLED overlay effects as HA light effects** — create an FPP Command for each effect you want
  exposed and give it the name that should appear in the Home Assistant light UI.

## Important limitation

All of these integrations are **one-way**. If an Overlay Model is changed from within FPP directly,
that change is *not* reflected back in Home Assistant.

## Installation

1. Configure FPP's MQTT settings so FPP can reach the same broker Home Assistant uses.
2. Install this plugin from **Content Setup → Plugins** in the FPP web UI.
3. Restart FPPD.

## Configuration

**Content Setup → Home Assistant** in the FPP web UI. Choose which Overlay Models and GPIO pins to
publish, and map any WLED effect commands you want available as HA light effects.

## License

GPLv2 — see [LICENSE](LICENSE).
