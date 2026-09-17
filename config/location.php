<?php
/**
 * config/location.php
 *
 * One definition of where the restaurant is, for every map link and embed.
 *
 * Google resolves a pin from the listed BUSINESS NAME. Querying the bare
 * street address drops a generic address marker with no place card, no photos
 * and no reviews -- and on a road with several units it can land next door.
 * So every map query leads with the name as Google lists it, and carries the
 * configured address behind it so the pin still follows a real move.
 *
 * The name here is NOT system_settings.restaurant_name. That is the brand as it
 * appears on receipts and the site ("OPO!"), which finds nothing on Google. This
 * is the listing name, which is a different string for a different purpose.
 * Keeping them apart is deliberate: renaming the brand on a receipt must not
 * silently break the directions link.
 *
 * This file exists because the two callers had drifted -- index.php pinned
 * correctly while the customer dashboard still queried the address alone.
 */

/** The business exactly as Google lists it. */
const MAPS_PLACE_NAME = 'OPO Filipino Comfort Food';

/**
 * "OPO Filipino Comfort Food, 19 San Juan Rd, Calamba, ..." -- name first.
 */
function mapsPlaceQuery(string $address = ''): string
{
    $address = trim($address);
    return $address !== ''
        ? MAPS_PLACE_NAME . ', ' . $address
        : MAPS_PLACE_NAME;
}

/** A "get directions" link for a customer to tap. */
function mapsDirectionsUrl(string $address = ''): string
{
    return 'https://www.google.com/maps/search/?api=1&query='
        . rawurlencode(mapsPlaceQuery($address));
}

/** The src for an embedded map iframe. */
function mapsEmbedUrl(string $address = '', int $zoom = 17): string
{
    return 'https://maps.google.com/maps?q=' . rawurlencode(mapsPlaceQuery($address))
        . '&t=&z=' . $zoom . '&ie=UTF8&iwloc=B&output=embed';
}
