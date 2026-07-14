<?php

namespace App\Services\Import\LibreNms;

/**
 * A source of LibreNMS data for import. Two implementations: MySQL (reads the LibreNMS
 * database directly - gives credentials + maps) and API (REST, devices only). Both return
 * the same normalised shapes so ImportFromLibreNms doesn't care which was used.
 */
interface LibreNmsSource
{
    /**
     * Discovered devices, normalised.
     *
     * @return list<array{
     *   hostname:string, ip:?string, snmp_community:?string, snmp_version:?string,
     *   os:?string, hardware:?string, serial:?string, sysname:?string, disabled:bool
     * }>
     */
    public function devices(): array;

    /**
     * Custom maps with device placements (MySQL only; API returns []).
     *
     * @return list<array{name:string, nodes:list<array{ip:?string, hostname:?string, x:float, y:float, label:?string}>}>
     */
    public function maps(): array;
}
