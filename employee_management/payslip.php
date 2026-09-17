<?php
/**
 * employee_management/payslip.php?id=
 *
 * The single unified payslip template required by the original spec: one
 * HTML/CSS structure for every employee, branching only on salary_type
 * (via renderPayslipCardHtml() in payroll_functions.php, shared with the
 * run-detail modal and the bulk-print view so all three render the exact
 * same card). Standalone print target (own <html>, no sidebar) --
 * on-screen preview and printed output are the exact same markup via
 * window.print(). ?download=1 streams the identical card through Dompdf
 * instead (same convention as purchase_orders/purchase_order_pdf.php).
 * Manager-only.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/payroll_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$payslipId = (int)($_GET['id'] ?? 0);

$pdo = Database::getInstance()->getConnection();

$details = getPayslipWithDetails($pdo, $payslipId);
if (!$details) {
    http_response_code(404);
    echo 'Payslip not found.';
    exit;
}

$identity = getRestaurantIdentity($pdo);
$employeeName = trim($details['payslip']['first_name'] . ' ' . $details['payslip']['last_name']);

$isDownload = ($_GET['download'] ?? '') === '1';

if ($isDownload) {
    require_once __DIR__ . '/../vendor/autoload.php';

    // Dompdf resolves relative <img src> against its own working directory,
    // not this file's location, so the on-screen relative path
    // ('../assets/...') would silently fail here -- pass a real filesystem
    // path instead, the one case renderPayslipCardHtml()'s $logoSrc param
    // exists for.
    $logoPath = realpath(__DIR__ . '/../assets/images/logo.jpg') ?: '';

    // payslipPdfStyles(), not payslipDocumentStyles(): the screen stylesheet
    // relies on CSS custom properties, flexbox and grid, none of which Dompdf
    // implements, so the PDF came out unstyled and with its labels and values
    // overlapping. See payslipPdfStyles() for the full list.
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Payslip</title>'
        . payslipPdfStyles()
        . '</head><body>'
        . renderPayslipCardHtml($details['payslip'], $details['earnings'], $details['deductions'], $identity['name'], $identity['address'], $logoPath)
        . '</body></html>';

    // Dompdf's default chroot only allows local files under its own vendor
    // package directory -- widen it to this project's root so the logo
    // (a real file, outside that default) is allowed to load, not silently
    // rejected as a chroot violation.
    $pdfOptions = new \Dompdf\Options();
    $pdfOptions->setChroot(realpath(__DIR__ . '/../'));
    // Dompdf falls back to the Adobe base-14 fonts (Helvetica/Times), which are
    // Latin-1 and have no peso sign -- every amount rendered as "?30,000.00".
    // DejaVu Sans ships with Dompdf and carries U+20B1.
    $pdfOptions->setDefaultFont('DejaVu Sans');
    $pdfOptions->setIsRemoteEnabled(false);
    $dompdf = new \Dompdf\Dompdf($pdfOptions);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'Payslip-' . $details['payslip']['run_number'] . '-' . preg_replace('/\s+/', '_', $employeeName) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $dompdf->output();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payslip — <?= htmlspecialchars($employeeName) ?> — <?= htmlspecialchars($details['payslip']['run_number']) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<?= payslipDocumentStyles() ?>
</head>
<body>

<div class="ps-toolbar">
    <button type="button" class="ps-btn" onclick="window.close()">Close</button>
    <a class="ps-btn" href="?id=<?= (int)$payslipId ?>&download=1"><i class="ph ph-download-simple"></i> Download PDF</a>
    <button type="button" class="ps-btn ps-btn-primary" onclick="window.print()"><i class="ph ph-printer"></i> Print</button>
</div>

<?= renderPayslipCardHtml($details['payslip'], $details['earnings'], $details['deductions'], $identity['name'], $identity['address']) ?>

</body>
</html>
