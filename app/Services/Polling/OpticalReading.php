<?php

namespace App\Services\Polling;

/**
 * One port's optical (SFP / fibre) power, in dBm. Identified by whatever the source gives us:
 * an SNMP optical table is indexed by ifIndex (and may carry the port name), the RouterOS API
 * only names the port. RecordOpticalPower matches it to our interface row by name first (when
 * given), then ifIndex. Either power can be null (eg a module that reports Rx but not Tx).
 */
class OpticalReading
{
    public function __construct(
        public readonly ?int $ifIndex,
        public readonly ?string $name,
        public readonly ?float $rxDbm,
        public readonly ?float $txDbm,
    ) {}

    /**
     * A usable dBm value, or null. Real optics sit roughly -40..+10 dBm; anything far outside
     * that is a vendor sentinel for "no reading" (eg -inf rendered as a huge negative), not a
     * light level worth alerting on.
     */
    public static function dbm(mixed $value, int $divisor = 1): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            // RouterOS 6 can render "-5.123dBm" - take the leading number.
            if (preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) !== 1) {
                return null;
            }
            $value = $m[0];
        }

        $dbm = (float) $value / max(1, $divisor);

        return $dbm >= -60.0 && $dbm <= 30.0 ? round($dbm, 2) : null;
    }
}
