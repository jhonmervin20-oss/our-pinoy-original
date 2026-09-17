<?php
/**
 * customer/includes/reservation_email.php
 *
 * The confirmation e-ticket a guest receives by email once their reservation
 * payment clears.
 *
 * Why this exists as its own file: the on-screen e-ticket in
 * reservation_confirm.php is only reachable while the guest still has that tab
 * open. Close it and the QR code is gone -- there was no copy anywhere. The
 * email is the durable one, and the QR has to be readable from the phone at the
 * door.
 *
 * The QR is generated server-side here, not reused from the page. The page
 * draws it into a <canvas> with JavaScript, which no mail client will run, so
 * a PNG has to be encoded on the server and embedded in the message body.
 * Both encode the same value -- the bare reservation number -- so a code
 * scanned off the phone screen and one scanned out of the email are identical.
 */

require_once __DIR__ . '/../../config/mailer.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;

/**
 * PNG bytes for a reservation's QR code, or null if the encoder is unavailable.
 *
 * Error correction is deliberately HIGH: this code gets scanned off a phone
 * screen at a restaurant door, through fingerprints and glare, and the payload
 * is short enough that the redundancy costs nothing worth having.
 */
function reservationQrPng(string $reservationNumber, int $size = 220): ?string
{
    try {
        $qr = QrCode::create($reservationNumber)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
            ->setSize($size)
            ->setMargin(10)
            ->setRoundBlockSizeMode(RoundBlockSizeMode::Margin);

        return (new PngWriter())->write($qr)->getString();
    } catch (Throwable $e) {
        // GD missing, or the encoder failed. The email is still worth sending
        // without the image -- the reservation number is printed beside it in
        // text for exactly this case.
        error_log('reservationQrPng() failed: ' . $e->getMessage());
        return null;
    }
}

/** Everything the e-ticket needs, in one read. */
function getReservationEmailData(PDO $db, int $reservationId): ?array
{
    $stmt = $db->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests,
                r.status AS reservation_status,
                ts.slot_label, ts.start_time, ts.end_time,
                rp.payment_purpose, rp.deposit_percentage, rp.amount_due, rp.amount_paid,
                rp.payment_status, rp.paymongo_reference_number,
                u.user_id, u.first_name, u.last_name, u.email
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         JOIN users u ON u.user_id = r.customer_id
         LEFT JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.reservation_id = ?
         ORDER BY rp.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** The restaurant's own contact details, for the footer. */
function reservationEmailBranding(PDO $db): array
{
    $defaults = [
        'restaurant_name'    => 'OPO! Our Pinoy Original',
        'restaurant_address' => '',
        'restaurant_phone'   => '',
        'restaurant_email'   => '',
    ];

    try {
        $rows = $db->query(
            "SELECT setting_key, setting_value FROM system_settings
             WHERE setting_key IN ('restaurant_name','restaurant_address','restaurant_phone','restaurant_email')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return $defaults;
    }

    foreach ($defaults as $k => $v) {
        if (!empty($rows[$k])) {
            $defaults[$k] = $rows[$k];
        }
    }

    return $defaults;
}

/**
 * Renders the HTML e-ticket.
 *
 * Written as nested tables with inline styles on purpose. Mail clients are not
 * browsers: Outlook renders through Word, Gmail strips <style> blocks in some
 * contexts, and flexbox/grid are unsupported across most of the field. Tables
 * with inline attributes are the layout that survives all of them.
 */
function renderReservationEmailHtml(array $r, array $brand, array $advanceItems, bool $hasQr): string
{
    $gold = '#9c7734';
    $ink  = '#241f1a';
    $soft = '#6f6355';
    $line = '#e7e0d5';

    // Escaped up front rather than inside the template. A heredoc can only
    // interpolate variables, not function calls, so escaping has to happen
    // before the markup rather than within it.
    $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $brandName  = $esc($brand['restaurant_name']);
    $firstName  = $esc($r['first_name']);
    $resNo      = $esc($r['reservation_number']);
    $fullName   = trim($r['first_name'] . ' ' . $r['last_name']);
    $guests     = (int)$r['number_of_guests'];
    $paid       = (float)$r['amount_paid'];
    $due        = (float)$r['amount_due'];
    $isFee      = ($r['payment_purpose'] ?? '') === 'reservation_fee';
    $payLabel   = $isFee ? 'Reservation fee' : 'Deposit (' . (float)$r['deposit_percentage'] . '%)';

    // Detail rows, as a two-column table so the labels stay aligned even in
    // clients that collapse whitespace.
    $detailRows = [
        'Guest name'      => $fullName,
        'Reservation no.' => $r['reservation_number'],
        'Date'            => date('l, F j, Y', strtotime($r['reservation_date'])),
        'Time'            => date('g:i A', strtotime($r['start_time'])) . ' - ' . date('g:i A', strtotime($r['end_time'])),
        'Party size'      => $guests . ' ' . ($guests === 1 ? 'guest' : 'guests'),
    ];

    $detailHtml = '';
    foreach ($detailRows as $label => $value) {
        $detailHtml .= '<tr>'
            . '<td style="padding:9px 0;border-bottom:1px solid ' . $line . ';color:' . $soft . ';font-size:13px;">' . $esc($label) . '</td>'
            . '<td style="padding:9px 0;border-bottom:1px solid ' . $line . ';color:' . $ink . ';font-size:14px;font-weight:600;text-align:right;">' . $esc($value) . '</td>'
            . '</tr>';
    }

    $advanceHtml = '';
    if ($advanceItems) {
        $advanceHtml .= '<tr><td colspan="2" style="padding:18px 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . $gold . ';">Advance order</td></tr>';
        foreach ($advanceItems as $it) {
            $advanceHtml .= '<tr>'
                . '<td style="padding:6px 0;color:' . $soft . ';font-size:13px;">' . $esc($it['item_name']) . ' &times; ' . (int)$it['quantity'] . '</td>'
                . '<td style="padding:6px 0;color:' . $ink . ';font-size:13px;text-align:right;">&#8369;' . number_format((float)$it['subtotal'], 2) . '</td>'
                . '</tr>';
        }
    }

    $payHtml = '<tr><td colspan="2" style="padding:18px 0 6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:' . $gold . ';">Payment</td></tr>'
        . '<tr><td style="padding:6px 0;color:' . $soft . ';font-size:13px;">' . $esc($payLabel) . '</td>'
        . '<td style="padding:6px 0;color:' . $ink . ';font-size:13px;text-align:right;font-weight:600;">&#8369;' . number_format($paid, 2) . ' paid</td></tr>';

    if (!$isFee && $due - $paid > 0.005) {
        $payHtml .= '<tr><td style="padding:6px 0;color:' . $soft . ';font-size:13px;">Balance on arrival</td>'
            . '<td style="padding:6px 0;color:' . $ink . ';font-size:13px;text-align:right;">&#8369;' . number_format($due - $paid, 2) . '</td></tr>';
    }
    if (!empty($r['paymongo_reference_number'])) {
        $payHtml .= '<tr><td style="padding:6px 0;color:' . $soft . ';font-size:13px;">Reference</td>'
            . '<td style="padding:6px 0;color:' . $soft . ';font-size:12px;text-align:right;word-break:break-all;">' . $esc($r['paymongo_reference_number']) . '</td></tr>';
    }

    // If the encoder failed, the block still has to carry the number in a form
    // the door can act on -- an email that silently lost its ticket is worse
    // than one that never had a picture of it.
    $qrBlock = $hasQr
        ? '<img src="cid:ticketqr" width="200" height="200" alt="QR code for reservation ' . $resNo . '" style="display:block;margin:0 auto;border:0;">'
        : '<div style="font-size:26px;font-weight:700;letter-spacing:.08em;color:' . $ink . ';padding:26px 0;">' . $resNo . '</div>';

    $footerBits = array_filter([
        $brand['restaurant_address'],
        $brand['restaurant_phone'],
        $brand['restaurant_email'],
    ]);
    $footer = $footerBits ? implode(' &nbsp;&middot;&nbsp; ', array_map($esc, $footerBits)) : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f1ea;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;padding:24px 12px;">
<tr><td align="center">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">

  <tr><td style="background:{$gold};padding:22px 24px;text-align:center;">
    <div style="color:#ffffff;font-size:19px;font-weight:700;letter-spacing:.02em;">{$brandName}</div>
    <div style="color:#f3e8d5;font-size:12px;margin-top:3px;letter-spacing:.06em;text-transform:uppercase;">Reservation confirmed</div>
  </td></tr>

  <tr><td style="padding:26px 24px 6px;text-align:center;">
    <div style="font-size:21px;font-weight:700;color:{$ink};">Your table is booked</div>
    <div style="font-size:14px;color:{$soft};margin-top:6px;line-height:1.5;">Thank you, {$firstName}. Show the code below when you arrive.</div>
  </td></tr>

  <tr><td style="padding:18px 24px 6px;text-align:center;">
    {$qrBlock}
    <div style="font-size:12px;color:{$soft};margin-top:10px;">Reservation no.</div>
    <div style="font-size:16px;font-weight:700;color:{$ink};letter-spacing:.04em;">{$resNo}</div>
  </td></tr>

  <tr><td style="padding:14px 24px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      {$detailHtml}
      {$advanceHtml}
      {$payHtml}
    </table>
  </td></tr>

  <tr><td style="padding:20px 24px 4px;">
    <div style="background:#faf7f1;border-left:3px solid {$gold};border-radius:6px;padding:12px 14px;font-size:12.5px;color:{$soft};line-height:1.55;">
      Please arrive on time. If no one from your party checks in within the grace period after your slot begins, the reservation is automatically marked a no-show and the table is released.
    </div>
  </td></tr>

  <tr><td style="padding:20px 24px 26px;text-align:center;border-top:1px solid {$line};">
    <div style="font-size:11.5px;color:#a39a8c;line-height:1.6;">{$footer}</div>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/** Plain-text fallback, written rather than stripped out of the HTML. */
function renderReservationEmailText(array $r, array $brand, array $advanceItems): string
{
    $lines   = [];
    $lines[] = strtoupper($brand['restaurant_name']) . ' - RESERVATION CONFIRMED';
    $lines[] = '';
    $lines[] = 'Thank you, ' . $r['first_name'] . '. Your table is booked.';
    $lines[] = '';
    $lines[] = 'Reservation no. : ' . $r['reservation_number'];
    $lines[] = 'Date            : ' . date('l, F j, Y', strtotime($r['reservation_date']));
    $lines[] = 'Time            : ' . date('g:i A', strtotime($r['start_time'])) . ' - ' . date('g:i A', strtotime($r['end_time']));
    $lines[] = 'Party size      : ' . (int)$r['number_of_guests'];

    if ($advanceItems) {
        $lines[] = '';
        $lines[] = 'ADVANCE ORDER';
        foreach ($advanceItems as $it) {
            $lines[] = '  ' . $it['item_name'] . ' x' . (int)$it['quantity']
                . '  PHP ' . number_format((float)$it['subtotal'], 2);
        }
    }

    $isFee   = ($r['payment_purpose'] ?? '') === 'reservation_fee';
    $lines[] = '';
    $lines[] = 'PAYMENT';
    $lines[] = '  ' . ($isFee ? 'Reservation fee' : 'Deposit (' . (float)$r['deposit_percentage'] . '%)')
        . ': PHP ' . number_format((float)$r['amount_paid'], 2) . ' paid';
    $balance = (float)$r['amount_due'] - (float)$r['amount_paid'];
    if (!$isFee && $balance > 0.005) {
        $lines[] = '  Balance on arrival: PHP ' . number_format($balance, 2);
    }

    $lines[] = '';
    $lines[] = 'Show your reservation number at the door. If no one checks in within';
    $lines[] = 'the grace period after your slot begins, the table is released.';
    $lines[] = '';
    $lines[] = trim(implode('  |  ', array_filter([
        $brand['restaurant_address'], $brand['restaurant_phone'], $brand['restaurant_email'],
    ])));

    return implode("\n", $lines);
}

/**
 * Renders the no-show notice email -- a plain notice card, not the full
 * e-ticket layout (no QR, no advance-order breakdown: the table is already
 * gone, so there's nothing left to check in with). States what happened and,
 * if money was paid, that it was forfeited -- same directness as the in-app
 * notification's own copy (see notifyReservationNoShow()'s docblock).
 */
function renderReservationNoShowEmailHtml(array $r, array $brand): string
{
    $gold = '#9c7734';
    $ink  = '#241f1a';
    $soft = '#6f6355';
    $line = '#e7e0d5';
    $danger = '#b3413a';

    $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $brandName = $esc($brand['restaurant_name']);
    $firstName = $esc($r['first_name']);
    $resNo     = $esc($r['reservation_number']);
    $paid      = (float)$r['amount_paid'];

    $detailRows = [
        'Reservation no.' => $r['reservation_number'],
        'Date'            => date('l, F j, Y', strtotime($r['reservation_date'])),
        'Time'            => date('g:i A', strtotime($r['start_time'])) . ' - ' . date('g:i A', strtotime($r['end_time'])),
    ];
    $detailHtml = '';
    foreach ($detailRows as $label => $value) {
        $detailHtml .= '<tr>'
            . '<td style="padding:9px 0;border-bottom:1px solid ' . $line . ';color:' . $soft . ';font-size:13px;">' . $esc($label) . '</td>'
            . '<td style="padding:9px 0;border-bottom:1px solid ' . $line . ';color:' . $ink . ';font-size:14px;font-weight:600;text-align:right;">' . $esc($value) . '</td>'
            . '</tr>';
    }
    if ($paid > 0.005) {
        $detailHtml .= '<tr>'
            . '<td style="padding:9px 0;color:' . $soft . ';font-size:13px;">Amount forfeited</td>'
            . '<td style="padding:9px 0;color:' . $danger . ';font-size:14px;font-weight:700;text-align:right;">&#8369;' . number_format($paid, 2) . '</td>'
            . '</tr>';
    }

    $footerBits = array_filter([
        $brand['restaurant_address'],
        $brand['restaurant_phone'],
        $brand['restaurant_email'],
    ]);
    $footer = $footerBits ? implode(' &nbsp;&middot;&nbsp; ', array_map($esc, $footerBits)) : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f1ea;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;padding:24px 12px;">
<tr><td align="center">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">

  <tr><td style="background:{$ink};padding:22px 24px;text-align:center;">
    <div style="color:#ffffff;font-size:19px;font-weight:700;letter-spacing:.02em;">{$brandName}</div>
    <div style="color:#d9cdb8;font-size:12px;margin-top:3px;letter-spacing:.06em;text-transform:uppercase;">Reservation marked as no-show</div>
  </td></tr>

  <tr><td style="padding:26px 24px 6px;text-align:center;">
    <div style="font-size:21px;font-weight:700;color:{$ink};">We didn't see you at your table</div>
    <div style="font-size:14px;color:{$soft};margin-top:6px;line-height:1.5;">Hi {$firstName}, your reservation below was released because no one from your party checked in within the grace period.</div>
  </td></tr>

  <tr><td style="padding:18px 24px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      {$detailHtml}
    </table>
  </td></tr>

  <tr><td style="padding:20px 24px 4px;">
    <div style="background:#faf7f1;border-left:3px solid {$gold};border-radius:6px;padding:12px 14px;font-size:12.5px;color:{$soft};line-height:1.55;">
      If you believe this was marked in error, please contact us and we'll be glad to help.
    </div>
  </td></tr>

  <tr><td style="padding:20px 24px 26px;text-align:center;border-top:1px solid {$line};">
    <div style="font-size:11.5px;color:#a39a8c;line-height:1.6;">{$footer}</div>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/** Plain-text fallback for the no-show notice, mirroring the HTML version. */
function renderReservationNoShowEmailText(array $r, array $brand): string
{
    $lines   = [];
    $lines[] = strtoupper($brand['restaurant_name']) . ' - RESERVATION MARKED AS NO-SHOW';
    $lines[] = '';
    $lines[] = 'Hi ' . $r['first_name'] . '. Your reservation was released because no one';
    $lines[] = 'from your party checked in within the grace period.';
    $lines[] = '';
    $lines[] = 'Reservation no. : ' . $r['reservation_number'];
    $lines[] = 'Date            : ' . date('l, F j, Y', strtotime($r['reservation_date']));
    $lines[] = 'Time            : ' . date('g:i A', strtotime($r['start_time'])) . ' - ' . date('g:i A', strtotime($r['end_time']));

    $paid = (float)$r['amount_paid'];
    if ($paid > 0.005) {
        $lines[] = 'Amount forfeited: PHP ' . number_format($paid, 2);
    }

    $lines[] = '';
    $lines[] = "If you believe this was marked in error, please contact us and we'll help.";
    $lines[] = '';
    $lines[] = trim(implode('  |  ', array_filter([
        $brand['restaurant_address'], $brand['restaurant_phone'], $brand['restaurant_email'],
    ])));

    return implode("\n", $lines);
}

/**
 * Sends the no-show notice. Returns true if SMTP accepted the message.
 *
 * Best-effort by contract, same as sendReservationConfirmationEmail(): the
 * status change has already been committed by the caller (either the staff
 * action in reservation/reservation_action.php or the automatic sweep in
 * sweepNoShowReservations()), so a mail failure must never undo or block
 * that. Everything here is wrapped, and a failure is logged rather than
 * thrown.
 */
function sendReservationNoShowEmail(PDO $db, int $reservationId): bool
{
    try {
        $r = getReservationEmailData($db, $reservationId);
        if (!$r || empty($r['email'])) {
            error_log("sendReservationNoShowEmail(): no reservation/email for #{$reservationId}");
            return false;
        }

        $brand = reservationEmailBranding($db);
        $html  = renderReservationNoShowEmailHtml($r, $brand);
        $text  = renderReservationNoShowEmailText($r, $brand);

        return Mailer::sendRich(
            $r['email'],
            trim($r['first_name'] . ' ' . $r['last_name']),
            'Reservation marked as no-show - ' . $r['reservation_number'],
            $html,
            [],
            $text
        );
    } catch (Throwable $e) {
        error_log('sendReservationNoShowEmail() failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sends the confirmation e-ticket. Returns true if SMTP accepted the message.
 *
 * Best-effort by contract: the caller has already taken the guest's money and
 * confirmed the booking, so a mail failure must never surface as a failed
 * reservation. Everything here is wrapped, and a failure is logged rather
 * than thrown.
 */
function sendReservationConfirmationEmail(PDO $db, int $reservationId): bool
{
    try {
        $r = getReservationEmailData($db, $reservationId);
        if (!$r || empty($r['email'])) {
            error_log("sendReservationConfirmationEmail(): no reservation/email for #{$reservationId}");
            return false;
        }

        $brand = reservationEmailBranding($db);

        $advanceItems = [];
        try {
            if (function_exists('getReservationAdvanceOrderItems')) {
                $advanceItems = getReservationAdvanceOrderItems($db, $reservationId);
            }
        } catch (Throwable $e) {
            // A missing advance-order read should not cost the guest the ticket.
        }

        $png    = reservationQrPng($r['reservation_number']);
        $html   = renderReservationEmailHtml($r, $brand, $advanceItems, $png !== null);
        $text   = renderReservationEmailText($r, $brand, $advanceItems);
        $inline = $png !== null
            ? ['ticketqr' => ['content' => $png, 'name' => $r['reservation_number'] . '.png', 'mime' => 'image/png']]
            : [];

        return Mailer::sendRich(
            $r['email'],
            trim($r['first_name'] . ' ' . $r['last_name']),
            'Reservation confirmed - ' . $r['reservation_number'] . ' on ' . date('M j, Y', strtotime($r['reservation_date'])),
            $html,
            $inline,
            $text
        );
    } catch (Throwable $e) {
        error_log('sendReservationConfirmationEmail() failed: ' . $e->getMessage());
        return false;
    }
}
