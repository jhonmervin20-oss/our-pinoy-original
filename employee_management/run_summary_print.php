<?php
/**
 * employee_management/run_summary_print.php?id=
 *
 * Standalone print target for a payroll run's Summary Report (own <html>,
 * no sidebar) -- same convention as payslip.php: on-screen preview and
 * printed output are the exact same markup via window.print(); ?download=1
 * streams the identical card through Dompdf instead. Manager-only.
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

$runId = (int)($_GET['id'] ?? 0);

$pdo = Database::getInstance()->getConnection();

$summary = getPayrollRunSummary($pdo, $runId);
if (!$summary) {
    http_response_code(404);
    echo 'Payroll run not found.';
    exit;
}

$identity = getRestaurantIdentity($pdo);
$runNumber = $summary['run']['run_number'];

$isDownload = ($_GET['download'] ?? '') === '1';

if ($isDownload) {
    require_once __DIR__ . '/../vendor/autoload.php';

    // Same reasoning as payslip.php: Dompdf resolves relative <img src>
    // against its own working directory, not this file's location.
    $logoPath = realpath(__DIR__ . '/../assets/images/logo.jpg') ?: '';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Payroll Summary</title>'
        . payslipPdfStyles()
        . '</head><body>'
        . renderPayrollSummaryHtml($summary, $identity['name'], $identity['address'], $logoPath)
        . '</body></html>';

    $pdfOptions = new \Dompdf\Options();
    $pdfOptions->setChroot(realpath(__DIR__ . '/../'));
    $pdfOptions->setDefaultFont('DejaVu Sans');
    $pdfOptions->setIsRemoteEnabled(false);
    $dompdf = new \Dompdf\Dompdf($pdfOptions);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'Payroll-Summary-' . $runNumber . '.pdf';
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
<title>Payroll Summary — <?= htmlspecialchars($runNumber) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<?= payslipDocumentStyles() ?>
</head>
<body>

<div class="ps-toolbar">
    <button type="button" class="ps-btn" onclick="window.close()">Close</button>
    <a class="ps-btn" href="?id=<?= (int)$runId ?>&download=1"><i class="ph ph-download-simple"></i> Download PDF</a>
    <button type="button" class="ps-btn ps-btn-primary" onclick="window.print()"><i class="ph ph-printer"></i> Print</button>
</div>

<?= renderPayrollSummaryHtml($summary, $identity['name'], $identity['address']) ?>

</body>
</html>
