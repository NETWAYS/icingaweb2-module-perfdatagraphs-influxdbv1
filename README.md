**Note:** This is an early release that is still in development and prone to change

# Icinga Web Performance Data Graphs InfluxDBv1 Backend

A InfluxDBv1 backend for the Icinga Web Performance Data Graphs Module.

This module requires the frontend module:

- https://github.com/NETWAYS/icingaweb2-module-perfdatagraphs

## Installation Requirements

* PHP version ≥ 8.0
* IcingaDB or IDO Database

## Known Issues

### Time range buttons do not adjust when no data is available

When a time range is selected for which there is no data yet
(e.g. a newly created service) the x-axis does not adjust.
