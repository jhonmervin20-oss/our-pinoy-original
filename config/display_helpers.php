<?php
/**
 * config/display_helpers.php
 *
 * Shared formatters for values that are optional in the database, and so
 * render empty more often than not.
 *
 * The problem this solves: `users.phone` is nullable and, in practice, almost
 * always blank -- every staff account and 11 of 12 customers have none. Each
 * panel had invented its own way of showing that absence:
 *
 *   - an em dash on the five profile pages and cashier/reservations.php
 *   - "Not provided" on the two reservation views
 *   - and on admin/users.php a literal "&mdash;" on screen, because the HTML
 *     entity was passed THROUGH htmlspecialchars(), which escaped its
 *     ampersand into "&amp;mdash;". That same line also used `?? '…'`, so it
 *     only caught a NULL phone and printed nothing at all for the empty
 *     string.
 *
 * One absent value, four different things on screen. This file is where that
 * decision now lives, once.
 */

/**
 * A phone number for display, or "N/A" when there isn't one.
 *
 * Treats NULL, an empty string and whitespace-only as equally absent -- the
 * distinction is meaningless to a reader, and the old `?? ` checks caught only
 * the first of the three.
 *
 * Returns a PLAIN string: callers still escape at the point of output
 * (htmlspecialchars(phoneOrNa($x))), so there is no second, invisible escaping
 * rule to remember -- and no way to reintroduce the double-escaped-entity bug
 * this replaced.
 */
function phoneOrNa(?string $phone): string
{
    $phone = trim((string)$phone);
    return $phone !== '' ? $phone : 'N/A';
}
