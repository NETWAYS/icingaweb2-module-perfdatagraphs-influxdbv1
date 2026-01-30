# Installation

## Packages

NETWAYS provides this module via [https://packages.netways.de](https://packages.netways.de/).

To install this module, follow the setup instructions for the **extras** repository.

**RHEL or compatible:**

`dnf install icingaweb2-module-perfdatagraphs-influxdbv1`

**Ubuntu/Debian:**

`apt install icingaweb2-module-perfdatagraphs-influxdbv1`

## From source

1. Clone a Icinga Web Performance Data Graphs Backend repository into `/usr/share/icingaweb2/modules/perfdatagraphsinfluxdbv1/`

2. Enable the module using the `Configuration → Modules` menu or the `icingacli`

3. Configure the Influx URL, database and authentication using the `Configuration → Modules` menu

# Configuration

`config.ini` - section `influx`

| Option    | Description    | Default value                             |
|-----------|----------------|-------------------------------------------|
| api_url             | The URL for InfluxDB including the scheme | `http://localhost:8086` |
| api_database        | the database for the performance data     |  |
| api_username        | The basic auth username for the database  |  |
| api_password        | The basic auth password for the database  |  |
| api_timeout         | HTTP timeout for the API in seconds. Should be higher than 0  | `10` (seconds) |
| api_max_data_points | The maximum numbers of datapoints each series returns. If there are more datapoints the module will use the GROUP BY function to downsample to this number. You can disable aggregation by setting this to 0. | `10000` |
| api_tls_insecure    | Skip the TLS verification  | `false` (unchecked) |
| writer_host_name_template_tag    | The configured tag name for the 'host name' in Icinga 2 InfluxWriter  | `hostname` |
| writer_service_name_template_tag | The configured tag name for the 'service name' in Icinga 2 InfluxWriter  | `service` |

`api_max_data_points` is used for downsampling data. The value is used to calculate window sizes for the `GROUP BY` function.
We use `GROUP BY` and the `last` selector, which means, for each window the last data point is used.
This means, while there is less data in total, each data point will still point to a real check command execution.

