# prometheus-solaxmodbus-exporter-php
Prometheus exporter for Solax inverter data over the modbus protocol

## Requirements

`Solax Pocket Wi-Fi 3.0`

This seems to be the only dongle that supports modbus. I've also tested the Pocket LAN 3.0 and that definitely doesn't work.

![image](https://github.com/monkhaze/prometheus-solaxmodbus-exporter-php/assets/6921039/1bb14622-57d2-4695-b1c3-9fdde6bf0040)

`Solax X3-Hybrid inverter`

Other Solax inverters should work fine if you correctly define the registers in the `registers.yml` file.

![image](https://github.com/monkhaze/prometheus-solaxmodbus-exporter-php/assets/6921039/36bc2228-4eba-4207-a231-0226e3d2bb5a)

## Usage

### Build the container

```docker build . -t prometheus-solaxmodbus-exporter-php```

### Running the container

Make sure to properly set the 3 environment variables in docker-compose.yml

```docker compose up -d```

### Check if it works

```curl localhost:8065/metrics```

This URL returns data in prometheus text-based format

```curl localhost:8065/json```

This URL returns data in json format
