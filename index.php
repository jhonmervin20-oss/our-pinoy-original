<?php
// env.php was missing here -- this is the public homepage, so it was
// silently skipping the Asia/Manila timezone fix (any date/time logic on
// this exact page ran on PHP's raw ini timezone instead), the production
// error-display gate, and .env loading generally. Every other page in the
// app requires this first; env.php's own docblock names index.php
// explicitly as one of the files that should.
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/feedback_moderation.php';
require_once __DIR__ . '/config/location.php';      // mapsEmbedUrl(), mapsPlaceQuery()

Session::start();
$db = Database::getInstance()->getConnection();

// ---- Menu items (available + active), grouped by category name ----
$menuStmt = $db->prepare("
    SELECT mi.item_id, mi.item_name, mi.description, mi.selling_price,
           mi.image_url, mc.category_name AS category
    FROM   menu_items mi
    LEFT JOIN menu_categories mc ON mi.category_id = mc.category_id
    WHERE  mi.is_available = 1 AND mi.is_active = 1
    ORDER  BY mc.category_name ASC, mi.item_name ASC
");
$menuStmt->execute();
$menu_items = $menuStmt->fetchAll();
$menu_count = count($menu_items);

// ---- Distinct active categories that currently have available items ----
$catStmt = $db->prepare("
    SELECT DISTINCT mc.category_name
    FROM   menu_categories mc
    INNER JOIN menu_items mi ON mc.category_id = mi.category_id
    WHERE  mi.is_available = 1 AND mi.is_active = 1
      AND  mc.category_name IS NOT NULL
      AND  TRIM(mc.category_name) != ''
    ORDER  BY mc.category_name ASC
");
$catStmt->execute();
$categories = array_column($catStmt->fetchAll(), 'category_name');

// ---- Recent written feedback, joined to the customer who left it ----
// PUBLISHABLE ONLY -- the same two statuses isPubliclyVisibleStatus() allows.
// 'approved' is clean. 'masked' is mild profanity inside an otherwise positive
// or neutral review ("putangina ang sarap ng pagkain!"), printed with the word
// starred out: swearing as an intensifier is enthusiasm, and discarding it
// would throw away a real five-star review.
//
// 'flagged' and 'blocked' never reach this page. Those are aimed at somebody,
// and masking one leaves a one-star card reading "****" on a marketing landing
// page -- which advertises that a customer said something bad enough to censor
// and draws more attention than the words would have. 'pending' stays out for
// the older reason: no verdict yet, so nothing has cleared it.
$fbStmt = $db->prepare("
    SELECT f.feedback_id, f.comment,
           f.created_at, f.moderation_status, f.flagged_terms, u.first_name, u.last_name
    FROM   feedback f
    JOIN   users u ON f.customer_id = u.user_id
    WHERE  f.comment IS NOT NULL AND TRIM(f.comment) != ''
      AND  f.moderation_status IN ('approved', 'masked')
    ORDER  BY f.created_at DESC
    LIMIT  12
");
$fbStmt->execute();
$feedbacks = $fbStmt->fetchAll();

/**
 * Masks a comment's offending word(s) with asterisks.
 *
 * Now a live path, not just a guard: 'masked' rows are published here BY
 * DESIGN and reach the page with real profanity in them. It also still covers
 * the defensive case -- if a 'flagged' or 'blocked' row ever arrives through a
 * loosened WHERE clause or a new caller, statusRequiresMasking() catches it
 * and raw profanity still cannot render on the public marketing site.
 *
 * feedback.comment itself is never modified, only this rendered copy. If
 * nothing actually got masked (no captured flagged_terms, or a term that
 * didn't match verbatim), the whole comment is asterisked rather than ever
 * falling through to raw text.
 */
function publicFeedbackComment(array $fb): string
{
    $comment = (string)($fb['comment'] ?? '');
    if (!statusRequiresMasking($fb['moderation_status'] ?? null) || $comment === '') {
        return $comment;
    }
    $terms  = json_decode($fb['flagged_terms'] ?? '[]', true) ?: [];
    $masked = maskProfanityTerms($comment, $terms);
    return ($masked !== $comment) ? $masked : str_repeat('*', min(mb_strlen($comment), 60));
}

// The average-rating + review-count block lived here. Star ratings were
// removed from the system, and both figures were already computed but never
// printed (no .rating-summary markup exists on this page), so this was dead
// code sitting on top of a now-invalid column.

// ---- Current session state, used to swap the nav auth buttons ----
$isLoggedIn = Session::isLoggedIn();
$firstName  = $isLoggedIn ? ($_SESSION['first_name'] ?? '') : null;
$userRole   = $isLoggedIn ? Session::getRole() : null;

// ---- Contact details + operating hours, read from the live settings ----
// The exact rows Owner > Settings writes: General owns the address, phone and
// opening/closing time, Reservation settings owns operating_days. Reading them
// here is what stops this page advertising hours the booking calendar won't
// actually honour (it was hardcoded to "Daily - 3:00 PM to 12:00 AM" while the
// configured window was 11:00 AM to 1:00 AM).
$siteStmt = $db->query(
    "SELECT setting_key, setting_value
     FROM   system_settings
     WHERE  setting_key IN ('restaurant_address', 'restaurant_phone',
                            'restaurant_email', 'opening_time', 'closing_time',
                            'operating_days')"
);
$site = [];
foreach ($siteStmt->fetchAll() as $row) {
    $site[$row['setting_key']] = $row['setting_value'];
}

$address   = trim((string)($site['restaurant_address'] ?? ''));
$phone     = trim((string)($site['restaurant_phone'] ?? ''));
$phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
// The contact email was configured in Settings and shown by the chatbot and the
// reservation e-ticket, but this page never read it -- so the one public page a
// guest actually lands on offered no way to email the restaurant.
$email     = trim((string)($site['restaurant_email'] ?? ''));

/**
 * "Daily", "Mon - Fri", or "Mon - Wed, Sat" from the ISO weekday numbers
 * (1=Mon..7=Sun) reservation settings stores in operating_days.
 */
function operatingDaysLabel(string $csv): string
{
    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $days  = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $csv)),
        fn($d) => isset($names[$d])
    )));
    sort($days);

    if (!$days)              return '';
    if (count($days) === 7)  return 'Daily';

    // Collapse consecutive runs so five checked boxes read "Mon - Fri".
    $parts = [];
    $start = $prev = $days[0];
    foreach (array_slice($days, 1) as $day) {
        if ($day === $prev + 1) { $prev = $day; continue; }
        $parts[] = ($start === $prev) ? $names[$start] : $names[$start] . ' – ' . $names[$prev];
        $start   = $prev = $day;
    }
    $parts[] = ($start === $prev) ? $names[$start] : $names[$start] . ' – ' . $names[$prev];

    return implode(', ', $parts);
}

/** "23:00:00" -> "11:00 PM". Empty string if the setting isn't a usable time. */
function clockLabel(?string $time): string
{
    $time = trim((string)$time);
    if ($time === '') return '';
    $parsed = DateTime::createFromFormat('H:i:s', $time) ?: DateTime::createFromFormat('H:i', $time);
    return $parsed ? $parsed->format('g:i A') : '';
}

$daysLabel  = operatingDaysLabel((string)($site['operating_days'] ?? ''));
$openLabel  = clockLabel($site['opening_time'] ?? null);
$closeLabel = clockLabel($site['closing_time'] ?? null);
$hoursRange = ($openLabel !== '' && $closeLabel !== '') ? $openLabel . ' to ' . $closeLabel : '';
$hoursLabel = trim($daysLabel . (($daysLabel !== '' && $hoursRange !== '') ? ' — ' : ' ') . $hoursRange);

// See config/location.php -- the name leads, the address follows.
$mapPlace = mapsPlaceQuery($address);
$mapQuery = rawurlencode($mapPlace);

// renderStars() lived here, rendering feedback.rating as five icons. Removed
// with the rating column itself.

/**
 * users.first_name / users.last_name -> "Juan D." style display name,
 * matching how the `feedback` table's customer_id joins to `users`.
 */
function displayName($first, $last) {
    $first = trim($first ?? '');
    $last  = trim($last ?? '');
    if ($first === '' && $last === '') return 'Anonymous';
    return $first . ($last !== '' ? ' ' . strtoupper($last[0]) . '.' : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>document.documentElement.classList.add('js');</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OPO! | Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   OPO — Our Pinoy Original · enhanced UI
   Signature: warm hearth glow + hand-set gold diamond motif
═══════════════════════════════════════════════════════════════════════════ */
:root{
    /* browns — deepened for richer contrast */
    --brown-dark:#211b17;
    --brown-mid:#2c2420;
    --brown-light:#453832;
    --brown-deep:#191410;
    --brown-card:#2a221d;
    /* golds */
    --gold-accent:#c9a24e;
    --gold-light:#e3c47b;
    --gold-deep:#9c7734;
    --gold-soft:rgba(201,162,78,0.12);
    /* warm ember — used sparingly for life */
    --ember:#c4623d;
    /* text */
    --cream-light:#f5efe4;
    --cream-dim:rgba(245,239,228,0.72);
    --white-soft:#fffdf8;
    --text-muted:#9c9086;
    --nav-h:70px;
    --ease:cubic-bezier(.22,.61,.36,1);
    --ring:0 0 0 3px rgba(201,162,78,0.45);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;overflow-x:hidden;width:100%;}
body{
    background:var(--brown-dark);
    color:var(--cream-light);
    font-family:'Poppins',sans-serif;
    line-height:1.6;
    overflow-x:hidden;
    width:100%;
    -webkit-font-smoothing:antialiased;
    text-rendering:optimizeLegibility;
}
.container{width:90%;margin:auto;max-width:1200px;}
img,svg,iframe,video{display:block;max-width:100%;}
a{text-decoration:none;}
button{font-family:'Poppins',sans-serif;}
::selection{background:var(--gold-accent);color:var(--brown-deep);}

/* keyboard focus — visible ring on every interactive element */
a:focus-visible,button:focus-visible,iframe:focus-visible{outline:none;box-shadow:var(--ring);border-radius:4px;}

/* anchor targets clear the fixed navbar */
section{scroll-margin-top:calc(var(--nav-h) + 10px);}

/* ── GLOBAL ATMOSPHERE — fine grain + soft edge vignette ─────────────────── */
.page-atmosphere{
    position:fixed;inset:0;z-index:1;pointer-events:none;
    background:radial-gradient(130% 90% at 50% -15%,transparent 62%,rgba(0,0,0,0.32) 100%);
}
.page-atmosphere::before{
    content:'';position:absolute;inset:0;opacity:0.035;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

/* ── UTILITIES ────────────────────────────────────────────────────────────── */
.label{
    color:var(--gold-accent);letter-spacing:4px;text-transform:uppercase;
    font-size:0.68rem;font-weight:600;display:inline-flex;align-items:center;gap:10px;margin-bottom:14px;
}
.label::before{content:'';width:8px;height:8px;background:var(--gold-accent);transform:rotate(45deg);}
.section-title{font-family:'Lexend',sans-serif;font-size:clamp(1.7rem,4vw,3rem);color:var(--white-soft);line-height:1.15;font-weight:700;}
.section-title span{color:var(--gold-accent);}
.gold-line{
    position:relative;width:56px;height:2px;
    background:linear-gradient(90deg,var(--gold-deep),var(--gold-accent));
    margin:20px 0 30px;border-radius:2px;
}
.gold-line::after{
    content:'';position:absolute;right:-4px;top:50%;width:8px;height:8px;
    background:var(--gold-accent);transform:translateY(-50%) rotate(45deg);
}
.gold-line.center{margin-left:auto;margin-right:auto;}
.gold-line.center::after{left:50%;right:auto;transform:translate(-50%,-50%) rotate(45deg);}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
section{padding:90px 0;position:relative;}
/* Content renders visible immediately if JS is unavailable — the hidden
   starting state below only applies once .js is present on <html>, so
   nothing depends on JS/scroll position to become visible for users
   without JS, while everyone else gets the scroll-reveal effect. */
.reveal{transition:opacity .7s var(--ease),transform .7s var(--ease);}
.js .reveal{opacity:0;transform:translateY(26px);}
.reveal.in-view{opacity:1;transform:none;}

/* ── NAVBAR ──────────────────────────────────────────────────────────────── */
nav{
    position:fixed;width:100%;top:0;z-index:1000;height:var(--nav-h);
    transition:background .4s ease,box-shadow .4s ease,backdrop-filter .4s ease;
}
nav.scrolled{
    background:rgba(28,23,20,0.94);
    box-shadow:0 4px 20px rgba(0,0,0,0.35);
    backdrop-filter:blur(8px) saturate(1.1);
}
.nav-inner{
    display:flex;align-items:center;justify-content:space-between;
    height:var(--nav-h);width:100%;padding:0 90px;gap:16px;position:relative;
}
.nav-inner::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(201,162,78,0.35),transparent);}

/* LEFT — logo */
.nav-logo{display:flex;align-items:center;gap:11px;}
.nav-logo img{height:34px;filter:brightness(0) invert(1);transition:filter .3s;}
.nav-logo:hover img{filter:none;}
.nav-logo-text{display:flex;flex-direction:column;gap:1px;}
.nav-logo-text strong{font-family:'Lexend',sans-serif;color:var(--white-soft);font-size:1.2rem;font-weight:900;letter-spacing:2px;}
.nav-logo-text span{font-size:0.58rem;color:var(--gold-accent);letter-spacing:3px;font-weight:700;text-transform:uppercase;}

/* CENTER — links */
.nav-links{display:flex;gap:30px;align-items:center;justify-content:center;flex:1;}
.nav-links a{
    color:rgba(245,239,228,0.72);font-size:0.85rem;letter-spacing:2px;
    text-transform:uppercase;font-weight:500;position:relative;transition:color .3s;white-space:nowrap;
}
.nav-links a::after{content:'';position:absolute;bottom:-5px;left:0;width:0;height:1.5px;background:var(--gold-accent);transition:width .3s var(--ease);}
.nav-links a:hover{color:var(--gold-light);}
.nav-links a:hover::after{width:100%;}
.nav-links a.active-link{color:var(--gold-light);}
.nav-links a.active-link::after{width:100%;}

/* RIGHT — auth + hamburger */
.nav-right{display:flex;align-items:center;gap:14px;flex-shrink:0;}
.nav-auth{display:flex;gap:10px;align-items:center;}

.hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;padding:12px;background:none;border:none;flex-shrink:0;}
.hamburger span{width:24px;height:2px;background:var(--cream-light);display:block;transition:all .3s var(--ease);border-radius:2px;}
.hamburger.active span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.active span:nth-child(2){opacity:0;}
.hamburger.active span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}

/* ── BUTTONS ─────────────────────────────────────────────────────────────── */
.btn-outline{
    border:1px solid var(--gold-accent);padding:9px 22px;border-radius:5px;
    color:var(--gold-accent);font-size:0.78rem;letter-spacing:1.5px;text-transform:uppercase;
    font-weight:600;transition:all .3s var(--ease);cursor:pointer;background:transparent;
    display:inline-flex;align-items:center;gap:8px;white-space:nowrap;
}
.btn-outline:hover{background:var(--gold-accent);color:var(--brown-deep);}
.btn-solid{
    position:relative;overflow:hidden;
    background:var(--gold-accent);
    border:1px solid var(--gold-accent);padding:9px 22px;border-radius:5px;color:var(--brown-deep);
    font-size:0.78rem;letter-spacing:1.5px;text-transform:uppercase;font-weight:700;
    transition:transform .3s var(--ease);cursor:pointer;
    display:inline-flex;align-items:center;gap:8px;white-space:nowrap;
}
.btn-solid:hover{transform:translateY(-2px);}
.btn-hero{
    position:relative;overflow:hidden;
    background:var(--gold-accent);
    color:var(--brown-deep);padding:15px 34px;font-size:0.75rem;letter-spacing:2px;
    text-transform:uppercase;font-weight:700;border-radius:5px;
    transition:transform .3s var(--ease);
    display:inline-flex;align-items:center;gap:10px;
}
.btn-hero:hover{transform:translateY(-3px);}
.btn-hero i{transition:transform .3s var(--ease);}
.btn-hero:hover i{transform:translateX(4px);}
.btn-hero-ghost{
    color:var(--cream-light);font-size:0.75rem;letter-spacing:2px;text-transform:uppercase;
    font-weight:500;display:inline-flex;align-items:center;gap:9px;transition:color .3s;
    padding:15px 4px;
}
.btn-hero-ghost i{color:var(--gold-accent);}
.btn-hero-ghost:hover{color:var(--gold-light);}

/* ── MOBILE MENU ─────────────────────────────────────────────────────────── */
.mobile-menu{
    display:none;position:fixed;top:0;left:0;width:100%;height:100vh;
    background:linear-gradient(160deg,var(--brown-dark),var(--brown-deep));z-index:999;
    padding:calc(var(--nav-h) + 24px) 36px 40px;flex-direction:column;gap:0;overflow-y:auto;
}
.mobile-menu.open{display:flex;animation:menuIn .4s var(--ease);}
@keyframes menuIn{from{opacity:0;}to{opacity:1;}}
.mobile-menu a{
    font-family:'Lexend',sans-serif;font-size:1.55rem;color:var(--cream-light);
    font-weight:700;letter-spacing:2px;border-bottom:1px solid rgba(201,162,78,0.12);
    padding:18px 0;transition:color .3s,padding-left .3s var(--ease);display:flex;align-items:center;gap:14px;
}
.mobile-menu a::before{content:'';width:7px;height:7px;background:var(--gold-accent);transform:rotate(45deg);opacity:0;transition:opacity .3s;}
.mobile-menu a:hover{color:var(--gold-light);padding-left:8px;}
.mobile-menu a:hover::before{opacity:1;}
.mobile-menu-auth{display:flex;gap:12px;margin-top:28px;}
.mobile-menu-auth a{flex:1;justify-content:center;border-bottom:none;padding:0;font-family:'Poppins',sans-serif;}
.mobile-menu-auth .btn-outline,.mobile-menu-auth .btn-solid{width:100%;justify-content:center;padding:13px;font-size:0.75rem;border-bottom:1px solid var(--gold-accent);}

/* ── HERO ────────────────────────────────────────────────────────────────── */
.hero{height:100vh;min-height:640px;display:flex;align-items:center;position:relative;overflow:hidden;padding-top:var(--nav-h);}
/* Separate wrapper for the scroll/mouse parallax translate (JS-driven) so it
   never touches .hero-bg's own transform -- that one is already spoken for
   by the CSS zoom animation below, and animation-held transforms win over
   anything JS sets inline on the SAME element for as long as the animation's
   fill-forwards keeps holding it. Two elements, two transforms, no fight. */
.hero-parallax{position:absolute;inset:0;z-index:0;will-change:transform;}
.hero-bg{position:absolute;top:0;left:0;width:100%;height:120%;background:url('assets/images/bg.jpg') center/cover no-repeat;z-index:0;animation:heroZoom 18s ease-out forwards;}
@keyframes heroZoom{from{transform:scale(1.04);}to{transform:scale(1);}}
.hero-overlay{position:absolute;inset:0;background:linear-gradient(100deg,rgba(21,16,13,0.95) 38%,rgba(33,27,23,0.55) 72%,rgba(33,27,23,0.25) 100%);z-index:1;}
.hero-glow{display:none;}
.hero-content{position:relative;z-index:3;max-width:660px;}
.hero-eyebrow{display:inline-flex;align-items:center;gap:12px;margin-bottom:24px;opacity:0;animation:riseIn .8s var(--ease) .1s forwards;}
.hero-eyebrow .mark{width:9px;height:9px;background:var(--gold-accent);transform:rotate(45deg);}
.hero-eyebrow span{color:var(--gold-light);font-size:0.68rem;letter-spacing:4px;text-transform:uppercase;font-weight:600;}
.hero h1{font-family:'Lexend',sans-serif;font-size:clamp(2.3rem,6vw,5rem);color:var(--white-soft);line-height:1.04;font-weight:900;margin-bottom:20px;opacity:0;animation:riseIn .8s var(--ease) .22s forwards;}
.hero h1 em{font-style:normal;color:var(--gold-accent);display:block;}
.hero-sub{font-size:clamp(0.9rem,2vw,1.05rem);color:var(--cream-dim);max-width:480px;margin-bottom:36px;line-height:1.8;opacity:0;animation:riseIn .8s var(--ease) .34s forwards;}
.hero-cta{display:flex;gap:18px;align-items:center;flex-wrap:wrap;opacity:0;animation:riseIn .8s var(--ease) .46s forwards;}
.hero-pills{display:flex;gap:14px;flex-wrap:wrap;margin-top:38px;opacity:0;animation:riseIn .8s var(--ease) .58s forwards;}
.hero-pill{
    display:inline-flex;align-items:center;gap:9px;padding:9px 16px;
    background:rgba(245,239,228,0.06);border:1px solid rgba(201,162,78,0.22);
    border-radius:6px;
}
.hero-pill i{color:var(--gold-accent);font-size:0.78rem;}
.hero-pill span{font-size:0.74rem;color:var(--cream-dim);letter-spacing:0.5px;font-weight:500;}
@keyframes riseIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

.scroll-hint{position:absolute;bottom:34px;left:50%;transform:translateX(-50%);z-index:3;display:flex;flex-direction:column;align-items:center;gap:9px;transition:opacity .3s;}
.scroll-hint:hover{opacity:0.7;}
.scroll-hint span{font-size:0.58rem;letter-spacing:3px;color:var(--text-muted);text-transform:uppercase;}
.scroll-dot{width:1px;height:46px;background:linear-gradient(to bottom,var(--gold-accent),transparent);animation:scrollDrop 1.8s infinite;}
@keyframes scrollDrop{0%{transform:scaleY(0);transform-origin:top;}50%{transform:scaleY(1);transform-origin:top;}51%{transform:scaleY(1);transform-origin:bottom;}100%{transform:scaleY(0);transform-origin:bottom;}}

/* ── ABOUT / CONCEPT & VISION ───────────────────────────────────────────── */
#about{background:var(--brown-dark);}
.concept-split{
    display:grid;grid-template-columns:1fr 1.1fr;min-height:clamp(420px,50vw,560px);
    border:1px solid rgba(201,162,78,0.15);border-radius:10px;overflow:hidden;
    background:var(--brown-mid);box-shadow:0 30px 60px -30px rgba(0,0,0,0.7);
}
.concept-panel-image{position:relative;display:flex;align-items:flex-end;}
.concept-panel-image img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:grayscale(10%) contrast(1.02);}
.concept-panel-image::before{
    content:'';position:absolute;inset:0;
    background:linear-gradient(180deg,rgba(21,16,13,0.15) 0%,rgba(15,12,10,0.55) 85%);
}
.concept-panel-content{position:relative;z-index:1;padding:50px;}
.concept-panel-content .label{margin-bottom:10px;}
.concept-panel-content .section-title{font-size:clamp(1.9rem,3.4vw,2.7rem);}
/* The title now sits on the GOLD panel, where the global .section-title colours
   (--white-soft, gold <span>) are unreadable and invisible respectively. */
/* Two-tone like the dark-background version, but inverted for gold: there the
   <span> was the BRIGHT accent, so here it must be the DARKEST ink, not the
   faded one -- "Vision" is the word meant to carry the emphasis. */
.concept-title{font-size:clamp(1.7rem,3vw,2.4rem);color:rgba(25,20,16,0.62);margin-bottom:22px;}
.concept-title span{color:var(--brown-deep);}
.concept-panel-text{
    background:var(--gold-accent);
    display:flex;align-items:center;padding:56px;
}
/* hyphens alongside justify: without it, justifying this narrow column opens
   rivers of white space between words. */
.concept-panel-text p{color:var(--brown-deep);font-size:1rem;line-height:1.85;text-align:justify;hyphens:auto;}
.concept-panel-text p strong{font-weight:800;}

/* ── LOCATION ────────────────────────────────────────────────────────────── */
#location{background:var(--brown-deep);}
/* Map only -- the address/phone/hours moved to #about, so this is a single
   full-width panel rather than the old info+map two-column split. */
.location-card{display:block;border:1px solid rgba(201,162,78,0.15);border-radius:10px;overflow:hidden;background:var(--brown-mid);box-shadow:0 30px 60px -30px rgba(0,0,0,0.7);}
.location-map{min-height:clamp(300px,40vw,500px);position:relative;}
.location-map iframe{width:100%;height:100%;min-height:clamp(300px,40vw,500px);border:none;display:block;filter:grayscale(30%) contrast(1.05);}

/* Contact block inside the ABOUT panel. That panel is gold with dark text, so
   these cannot reuse the old .location-detail colours (cream on dark) -- they
   would have been invisible. */
.concept-text-inner{width:100%;}
.about-contact{margin-top:26px;padding-top:22px;border-top:1px solid rgba(21,16,13,0.18);display:grid;gap:12px;}
.about-contact-row{display:flex;align-items:flex-start;gap:12px;}
.about-contact-row i{color:var(--brown-deep);opacity:0.65;font-size:0.9rem;margin-top:4px;width:16px;flex-shrink:0;}
.about-contact-row span,.about-contact-row a{color:var(--brown-deep);font-size:0.88rem;line-height:1.6;}
.about-contact-row a:hover{text-decoration:underline;}

/* ── MENU ────────────────────────────────────────────────────────────────── */
#menu{background:var(--brown-dark);}
.menu-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:16px;}
.menu-header-copy{display:flex;flex-direction:column;}
.menu-count-pill{display:inline-flex;align-items:center;gap:8px;align-self:flex-end;background:var(--gold-soft);border:1px solid rgba(201,162,78,0.3);color:var(--gold-light);padding:8px 16px;border-radius:6px;font-size:0.72rem;letter-spacing:1px;font-weight:600;white-space:nowrap;}
.menu-count-pill i{font-size:0.75rem;}
.menu-chips-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:22px;padding-bottom:20px;border-bottom:1px solid rgba(201,162,78,0.1);}
.menu-chips-scroll{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.menu-chip{background:transparent;border:1px solid rgba(201,162,78,0.25);color:var(--text-muted);padding:7px 17px;border-radius:6px;font-size:0.68rem;letter-spacing:1.5px;text-transform:uppercase;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .25s var(--ease);white-space:nowrap;flex-shrink:0;}
.menu-chip:hover{border-color:var(--gold-accent);color:var(--gold-light);transform:translateY(-1px);}
.menu-chip.active{background:var(--gold-accent);border-color:var(--gold-accent);color:var(--brown-deep);}
.menu-search{position:relative;flex:1 1 200px;min-width:180px;max-width:320px;margin-left:auto;}
.menu-search input{
    width:100%;background:var(--brown-card);border:1px solid rgba(201,162,78,0.22);
    border-radius:6px;padding:10px 40px 10px 18px;color:var(--cream-light);
    font-size:0.8rem;font-family:'Poppins',sans-serif;transition:border-color .3s var(--ease),box-shadow .3s var(--ease);
}
.menu-search input::placeholder{color:var(--text-muted);}
.menu-search input:focus{outline:none;border-color:var(--gold-accent);box-shadow:0 0 0 3px rgba(201,162,78,0.18);}
.menu-search i.fa-magnifying-glass{position:absolute;right:16px;top:50%;transform:translateY(-50%);color:var(--gold-accent);font-size:0.78rem;pointer-events:none;transition:opacity .2s;}
.menu-search-clear{
    position:absolute;right:8px;top:50%;transform:translateY(-50%);
    background:none;border:none;color:var(--text-muted);cursor:pointer;
    width:24px;height:24px;display:none;align-items:center;justify-content:center;
    border-radius:4px;transition:color .2s,background .2s;font-size:0.72rem;
}
.menu-search.has-value i.fa-magnifying-glass{opacity:0;}
.menu-search.has-value .menu-search-clear{display:flex;}
.menu-search-clear:hover{color:var(--gold-light);background:rgba(201,162,78,0.14);}
.menu-panel{position:relative;background:var(--brown-mid);border:1px solid rgba(201,162,78,0.12);border-radius:12px;overflow:hidden;box-shadow:0 30px 60px -34px rgba(0,0,0,0.7);}
.menu-panel::after{
    content:'';position:absolute;left:0;right:0;bottom:0;height:56px;
    background:linear-gradient(to top,var(--brown-mid),transparent);
    pointer-events:none;opacity:0;transition:opacity .35s ease;border-radius:0 0 12px 12px;
}
.menu-panel.can-scroll::after{opacity:1;}
.menu-panel-inner{max-height:620px;overflow-y:auto;padding:22px;scrollbar-width:thin;scrollbar-color:rgba(201,162,78,0.35) transparent;}
.menu-panel-inner::-webkit-scrollbar{width:6px;}
.menu-panel-inner::-webkit-scrollbar-track{background:transparent;}
.menu-panel-inner::-webkit-scrollbar-thumb{background:rgba(201,162,78,0.35);border-radius:10px;}
.menu-grid-items{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px;}

/* Cards are clickable now. */
.menu-item{cursor:pointer;}
.menu-item:hover,.menu-item:focus-visible{border-color:rgba(201,162,78,0.45);transform:translateY(-2px);}
.menu-item:focus-visible{outline:2px solid var(--gold-accent);outline-offset:2px;}

/* ── Dish detail modal ─────────────────────────────────────────────────── */
/* Above everything else on the page. The stacking order here is
   navbar 1000 > mobile nav panel 999 > back-to-top 900, and at its old
   z-index of 200 the dish modal opened UNDER all three: the scrim dimmed
   the dish grid but left the navbar and the floating back-to-top button
   sitting bright on top of it, still clickable. A modal that something
   else overlaps isn't modal. */
.dish-modal{position:fixed;inset:0;z-index:1100;display:flex;align-items:center;justify-content:center;padding:20px;}
/* .dish-modal sets its own display, which silently defeats the native [hidden]
   attribute -- without this the modal would sit open over the page forever. */
.dish-modal[hidden]{display:none;}
.dish-modal-backdrop{
    position:absolute;inset:0;background:rgba(8,6,5,0.8);
    backdrop-filter:blur(7px) saturate(1.1);-webkit-backdrop-filter:blur(7px) saturate(1.1);
    animation:dishFade .3s var(--ease) both;
}
.dish-modal-card{
    position:relative;z-index:1;width:min(520px,100%);max-height:88vh;overflow-y:auto;
    background:linear-gradient(180deg,var(--brown-mid) 0%,var(--brown-card) 100%);
    border:1px solid rgba(201,162,78,0.28);border-radius:18px;
    box-shadow:0 50px 90px -34px rgba(0,0,0,0.92);
    scrollbar-width:thin;scrollbar-color:rgba(201,162,78,0.35) transparent;
    animation:dishPop .45s var(--ease) both;
}
.dish-modal-card::-webkit-scrollbar{width:6px;}
.dish-modal-card::-webkit-scrollbar-track{background:transparent;}
.dish-modal-card::-webkit-scrollbar-thumb{background:rgba(201,162,78,0.35);border-radius:10px;}
@keyframes dishFade{from{opacity:0;}to{opacity:1;}}
@keyframes dishPop{from{opacity:0;transform:translateY(22px) scale(0.965);}to{opacity:1;transform:none;}}
@keyframes dishImgIn{from{transform:scale(1.07);}to{transform:scale(1);}}

/* The photo is the selling point, so it leads: full-bleed, taller than
   before, with the category and price set ON it rather than stacked as two
   more lines of text underneath. */
.dish-modal-media{position:relative;}
.dish-modal-img{height:270px;background:var(--brown-dark);display:flex;align-items:center;justify-content:center;
    border-radius:18px 18px 0 0;overflow:hidden;}
.dish-modal-img img{width:100%;height:100%;object-fit:cover;display:block;animation:dishImgIn .9s var(--ease) both;}
.dish-modal-img i{font-size:2.9rem;color:rgba(201,162,78,0.4);}
/* Dark at the top so the close button reads, clear through the middle so the
   food does, dark again at the bottom so the price badge reads and the photo
   melts into the card body instead of ending on a hard horizontal line. */
.dish-modal-media-scrim{
    position:absolute;inset:0;pointer-events:none;border-radius:18px 18px 0 0;
    background:linear-gradient(180deg,
        rgba(10,8,6,0.62) 0%, rgba(10,8,6,0.06) 32%,
        rgba(10,8,6,0.28) 62%, rgba(35,28,23,0.95) 100%);
}
.dish-modal-cat{
    position:absolute;top:14px;left:14px;z-index:2;
    display:inline-flex;align-items:center;gap:7px;padding:6px 13px;border-radius:999px;
    font-size:0.58rem;letter-spacing:2.2px;text-transform:uppercase;font-weight:700;color:var(--gold-light);
    background:rgba(10,8,6,0.55);border:1px solid rgba(201,162,78,0.35);
    backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
}
/* Same hand-set diamond the section labels and footer headings use. */
.dish-modal-cat::before{content:'';width:6px;height:6px;background:var(--gold-accent);transform:rotate(45deg);flex-shrink:0;}
.dish-modal-body{padding:24px 26px 22px;}
/* Name and price share a line, the price right-aligned in gold -- the same
   way every dish card in the grid above already reads, and it keeps both
   bottom corners of the photo clear for the gallery arrows. */
.dish-modal-head{display:flex;align-items:baseline;justify-content:space-between;gap:16px;}
.dish-modal-body h3{font-family:'Lexend',sans-serif;font-size:1.5rem;font-weight:800;color:var(--white-soft);line-height:1.22;min-width:0;}
.dish-modal-price{
    font-family:'Lexend',sans-serif;font-size:1.35rem;font-weight:900;letter-spacing:0.3px;
    color:var(--gold-accent);white-space:nowrap;flex-shrink:0;
}
.dish-modal-body p{color:var(--cream-dim);font-size:0.9rem;line-height:1.75;margin-top:12px;}
.dish-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
    margin-top:22px;padding-top:17px;border-top:1px solid rgba(201,162,78,0.14);}
.dish-modal-counter{font-size:0.66rem;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;font-weight:600;}
/* Same auth-aware booking action the hero offers -- a dish someone stopped to
   look at is the best possible moment to hand them the way to come eat it. */
.dish-modal-cta{
    display:inline-flex;align-items:center;gap:9px;background:var(--gold-accent);color:var(--brown-deep);
    padding:11px 20px;border-radius:8px;font-size:0.7rem;letter-spacing:1.6px;text-transform:uppercase;font-weight:700;
    transition:transform .3s var(--ease),box-shadow .3s var(--ease);
}
.dish-modal-cta:hover{transform:translateY(-2px);box-shadow:0 16px 28px -16px rgba(201,162,78,0.95);}
.dish-modal-cta i{transition:transform .3s var(--ease);}
.dish-modal-cta:hover i{transform:translateX(3px);}

/* Prev/next moved off the footer and onto the photo, where a gallery's
   controls belong -- that also frees the footer for the booking action. */
.dish-nav,.dish-close{
    display:inline-flex;align-items:center;justify-content:center;
    width:40px;height:40px;border-radius:50%;cursor:pointer;z-index:3;
    background:rgba(10,8,6,0.52);border:1px solid rgba(245,239,228,0.18);color:var(--cream-light);
    backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);font-size:0.85rem;
    transition:background .25s var(--ease),color .25s var(--ease),border-color .25s var(--ease),transform .35s var(--ease);
}
/* Bottom corners of the photo, opposite each other. */
.dish-nav{position:absolute;bottom:14px;}
.dish-nav-prev{left:14px;}
.dish-nav-next{right:14px;}
.dish-nav:hover:not(:disabled),.dish-close:hover{background:var(--gold-accent);color:var(--brown-deep);border-color:var(--gold-accent);}
/* One dish in the deck means nowhere to page to: hide the arrows outright
   rather than leaving two dimmed, dead controls sitting on the photo. */
.dish-nav:disabled{opacity:0;pointer-events:none;}
.dish-close{position:absolute;top:13px;right:13px;}
.dish-close:hover{transform:rotate(90deg);}
/* The global button:focus-visible rule forces border-radius:4px, and the modal
   focuses Close on open -- which squared off a button that is meant to be round.
   Keep the focus ring, restore the shape. */
.dish-nav:focus-visible,.dish-close:focus-visible{border-radius:50%;}
@media (max-width:640px){
    .dish-modal{padding:12px;}
    .dish-modal-card{border-radius:16px;}
    .dish-modal-img{height:210px;border-radius:16px 16px 0 0;}
    .dish-modal-media-scrim{border-radius:16px 16px 0 0;}
    .dish-modal-body{padding:19px 19px 18px;}
    .dish-modal-body h3{font-size:1.28rem;}
    .dish-modal-price{font-size:1.18rem;}
    .dish-nav,.dish-close{width:36px;height:36px;}
    .dish-nav{bottom:12px;}
    .dish-nav-prev{left:12px;}
    .dish-nav-next{right:12px;}
    /* Stacked at this width: side by side, the counter squeezes the CTA into
       a two-line button. */
    .dish-modal-foot{gap:12px;}
    .dish-modal-cta{flex:1 1 100%;justify-content:center;}
}
.menu-item{
    display:flex;gap:15px;background:var(--brown-card);
    border:1px solid rgba(201,162,78,0.08);border-radius:10px;padding:13px;
    transition:border-color .3s,transform .3s var(--ease),box-shadow .3s,opacity .26s var(--ease);
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.03);
}
.menu-item:hover{border-color:rgba(201,162,78,0.55);transform:translateY(-3px);box-shadow:0 18px 34px -22px rgba(0,0,0,0.85),0 0 0 1px rgba(201,162,78,0.25);}
.menu-item.hiding{opacity:0;transform:scale(0.94);pointer-events:none;}
/* Per-dish scroll reveal. Written as ":not(.reveal-in)" rather than the usual
   ".reveal{opacity:0} .reveal.in-view{opacity:1}" pair so revealing a card
   never has to fight its own hover transform: once .reveal-in is added this
   whole rule stops matching, and the card falls straight back to .menu-item's
   own base/hover rules instead of an explicit "transform:none" contesting
   :hover's translateY at near-equal specificity. */
.js .menu-item:not(.reveal-in){opacity:0;transform:translateY(22px);}
.menu-item.reveal-in{opacity:1;}
.menu-item-img{width:84px;height:84px;border-radius:8px;overflow:hidden;flex-shrink:0;background:var(--brown-light);display:flex;align-items:center;justify-content:center;color:var(--gold-accent);font-size:1.4rem;}
.menu-item-img img{width:100%;height:100%;object-fit:cover;opacity:0;transition:transform .5s var(--ease),opacity .4s ease;}
.menu-item-img img.img-loaded{opacity:1;}
.menu-item:hover .menu-item-img img{transform:scale(1.08);}
.menu-item-info{flex:1;display:flex;flex-direction:column;gap:5px;min-width:0;}
.menu-item-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.menu-item-info h4{font-family:'Lexend',sans-serif;font-size:0.96rem;color:var(--white-soft);font-weight:700;}
.menu-item-price{font-family:'Lexend',sans-serif;color:var(--gold-accent);font-weight:900;font-size:0.95rem;white-space:nowrap;}
.menu-item-info p{font-size:0.75rem;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.5;}
.menu-item-cat{align-self:flex-start;font-size:0.56rem;letter-spacing:2px;text-transform:uppercase;color:var(--gold-accent);font-weight:700;}
.menu-empty{text-align:center;padding:56px 20px;color:var(--text-muted);grid-column:1/-1;}

/* ── FEEDBACK ────────────────────────────────────────────────────────────── */
#feedback{background:var(--brown-deep);overflow:hidden;}
#feedback::before{content:none;}
.feedback-carousel-controls{display:flex;justify-content:flex-end;margin:0 auto 18px;}
.carousel-toggle-btn{display:inline-flex;align-items:center;gap:9px;background:transparent;border:1px solid rgba(201,162,78,0.3);color:var(--cream-dim);padding:9px 18px;border-radius:6px;font-size:0.68rem;letter-spacing:1.5px;text-transform:uppercase;font-weight:600;cursor:pointer;transition:all .3s var(--ease);font-family:'Poppins',sans-serif;}
.carousel-toggle-btn:hover{border-color:var(--gold-accent);color:var(--gold-light);}
.carousel-toggle-btn i{color:var(--gold-accent);font-size:0.72rem;width:12px;text-align:center;}
.feedback-carousel{position:relative;}
.feedback-track-viewport{overflow:hidden;-webkit-mask-image:linear-gradient(90deg,transparent 0,#000 5%,#000 95%,transparent 100%);mask-image:linear-gradient(90deg,transparent 0,#000 5%,#000 95%,transparent 100%);padding:6px 0 14px;}
.feedback-track{display:flex;gap:20px;width:max-content;opacity:0;transition:opacity .5s ease;will-change:transform;}
.feedback-track.is-ready{opacity:1;}
.feedback-track.is-scrolling{animation:feedbackScroll var(--scroll-duration,60s) linear infinite;}
.feedback-track-viewport:hover .feedback-track,
.feedback-track-viewport:focus-within .feedback-track,
.feedback-track.is-paused,
.feedback-track.is-touch-paused{animation-play-state:paused;}
@keyframes feedbackScroll{from{transform:translateX(0);}to{transform:translateX(var(--scroll-distance,-50%));}}
.feedback-card{position:relative;flex:0 0 320px;width:320px;min-height:270px;background:var(--brown-mid);border:1px solid rgba(201,162,78,0.12);border-radius:12px;padding:28px;display:flex;flex-direction:column;gap:14px;transition:border-color .3s,transform .3s var(--ease),box-shadow .3s;box-shadow:inset 0 1px 0 rgba(255,255,255,0.03);}
.feedback-card::before{content:'\201C';position:absolute;top:12px;right:22px;font-family:'Lexend',sans-serif;font-size:3.4rem;line-height:1;color:rgba(201,162,78,0.16);}
.feedback-card:hover{border-color:rgba(201,162,78,0.5);transform:translateY(-5px);box-shadow:0 22px 44px -26px rgba(0,0,0,0.85);}
.feedback-quote{color:var(--cream-dim);font-size:0.9rem;font-style:italic;flex:1;line-height:1.7;position:relative;z-index:1;display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden;}
.feedback-footer{display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(201,162,78,0.1);padding-top:14px;}
.feedback-user{display:flex;align-items:center;gap:11px;}
.feedback-avatar{width:38px;height:38px;border-radius:8px;background:var(--gold-accent);display:flex;align-items:center;justify-content:center;font-family:'Lexend',sans-serif;font-weight:900;color:var(--brown-deep);font-size:0.85rem;flex-shrink:0;}
.feedback-name{font-size:0.8rem;color:var(--white-soft);font-weight:600;}
.feedback-date{font-size:0.62rem;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;}
.feedback-empty{text-align:center;padding:56px 20px;color:var(--text-muted);}

/* ── CTA STRIP ───────────────────────────────────────────────────────────── */
.cta-strip{position:relative;background:linear-gradient(120deg,var(--brown-mid),var(--brown-deep));border-top:1px solid rgba(201,162,78,0.15);border-bottom:1px solid rgba(201,162,78,0.15);padding:64px 0;overflow:hidden;}
.cta-strip::before{content:none;}
.cta-inner{display:flex;justify-content:space-between;align-items:center;gap:24px;flex-wrap:wrap;position:relative;z-index:1;}
.cta-inner h2{font-family:'Lexend',sans-serif;font-size:clamp(1.4rem,3vw,2.2rem);color:var(--white-soft);font-weight:900;max-width:540px;line-height:1.25;}
.cta-inner h2 span{color:var(--gold-accent);}
.cta-actions{display:flex;gap:14px;flex-wrap:wrap;}

/* ── FOOTER ──────────────────────────────────────────────────────────────── */
footer{background:var(--brown-deep);padding:72px 0 0;border-top:1px solid rgba(201,162,78,0.1);}
.footer-grid{display:grid;grid-template-columns:1.6fr 1fr 1fr 1.2fr;gap:44px;padding-bottom:44px;}
.footer-brand-logo{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.footer-brand-logo img{height:32px;filter:brightness(0) invert(1);}
.footer-brand-logo span{font-family:'Lexend',sans-serif;font-weight:900;color:var(--white-soft);font-size:1.05rem;letter-spacing:2px;}
.footer-col p{color:var(--text-muted);font-size:0.82rem;max-width:300px;line-height:1.7;}
.footer-col h4{font-family:'Lexend',sans-serif;color:var(--white-soft);font-size:0.75rem;letter-spacing:3px;text-transform:uppercase;margin-bottom:20px;display:inline-flex;align-items:center;gap:9px;}
.footer-col h4::before{content:'';width:6px;height:6px;background:var(--gold-accent);transform:rotate(45deg);}
.footer-col ul{display:flex;flex-direction:column;gap:11px;list-style:none;}
.footer-col ul li a{color:var(--text-muted);font-size:0.82rem;transition:color .3s,padding-left .3s var(--ease);}
.footer-col ul li a:hover{color:var(--gold-light);padding-left:5px;}
.footer-social{display:flex;gap:10px;margin-top:18px;}
.footer-social a{width:38px;height:38px;border:1px solid rgba(201,162,78,0.2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--cream-dim);transition:all .3s var(--ease);}
.footer-social a:hover{background:var(--gold-accent);color:var(--brown-deep);border-color:var(--gold-accent);transform:translateY(-3px);}
.footer-contact-item{display:flex;gap:11px;align-items:flex-start;color:var(--text-muted);font-size:0.82rem;margin-bottom:13px;}
.footer-contact-item i{color:var(--gold-accent);margin-top:3px;width:15px;flex-shrink:0;}
.footer-contact-item a{color:var(--text-muted);}
.footer-contact-item a:hover{color:var(--gold-light);}
.footer-bottom{border-top:1px solid rgba(201,162,78,0.08);padding:24px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;font-size:0.7rem;color:var(--text-muted);letter-spacing:1px;}

/* ── BACK TO TOP ─────────────────────────────────────────────────────────── */
.back-to-top{
    position:fixed;bottom:26px;right:26px;z-index:900;width:46px;height:46px;border-radius:10px;
    background:var(--gold-accent);color:var(--brown-deep);
    border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;
    box-shadow:0 10px 26px -10px rgba(0,0,0,0.65);
    opacity:0;visibility:hidden;transform:translateY(14px);
    transition:opacity .35s var(--ease),transform .35s var(--ease),visibility .35s,box-shadow .3s;
}
.back-to-top.show{opacity:1;visibility:visible;transform:translateY(0);}
.back-to-top:hover{transform:translateY(-4px);}
@media(max-width:640px){.back-to-top{bottom:18px;right:18px;width:42px;height:42px;font-size:0.9rem;}}

/* ── RESPONSIVE ──────────────────────────────────────────────────────────── */
@media(max-width:1100px){
    .footer-grid{grid-template-columns:1fr 1fr;gap:36px;}
    .nav-inner{padding:0 40px;}
}
@media(max-width:1024px){
    .nav-auth{display:none;}
    .nav-links{display:none;}
    .hamburger{display:flex;}
    .nav-inner{padding:0 32px;}
    .concept-split{grid-template-columns:1fr;}
    .concept-panel-image{min-height:260px;}
    .concept-panel-content,.concept-panel-text{padding:34px;}
    .menu-grid-items{grid-template-columns:repeat(auto-fit,minmax(240px,1fr));}
    .menu-panel-inner{max-height:none;overflow-y:visible;}
    .cta-inner{flex-direction:column;text-align:center;}
    .cta-actions{justify-content:center;}
}
@media(max-width:768px){
    .hero-content{max-width:100%;}
    .hero-pills{gap:12px;}
}
@media(max-width:640px){
    :root{--nav-h:60px;}
    .container{width:92%;}
    section{padding:70px 0;}
    .nav-logo-text span{font-size:0.48rem;letter-spacing:2px;}
    .nav-logo img{height:28px;}
    .nav-right{gap:8px;}
    .nav-auth{gap:6px;}
    .nav-auth .btn-outline,.nav-auth .btn-solid{padding:7px 12px;font-size:0.6rem;letter-spacing:1px;}
    .nav-inner{padding:0 18px;}
    .hero{height:auto;min-height:100svh;padding-bottom:50px;}
    .hero h1{font-size:clamp(1.9rem,8vw,2.8rem);}
    .hero-sub{font-size:0.9rem;}
    .hero-cta{gap:14px;}
    .hero-pills{gap:10px;margin-top:30px;}
    .hero-pill{padding:8px 13px;}
    .hero-pill span{font-size:0.68rem;}
    .btn-hero{padding:13px 26px;font-size:0.7rem;}
    .scroll-hint{display:none;}
    .concept-panel-content,.concept-panel-text{padding:26px;}
    .menu-header{flex-direction:column;align-items:flex-start;}
    .menu-count-pill{align-self:flex-start;}

    /* The <=1024 rule above lifts the desktop cap, which on a phone leaves
       all ~47 dishes sitting between the visitor and the rest of the page --
       roughly 4,600px of swiping to reach the next section. Phones get the
       cap back instead: ~5 rows tall, scrolling inside itself, with the 6th
       row deliberately cut off at the edge as the cue that there is more.
       Scroll chaining is left at its default so that reaching the end of the
       list carries straight on into the page rather than trapping the finger. */
    /* 695 = the 12px top padding + three 212px card rows + two 10px gaps,
       plus ~25px over so the fourth row is visibly cut rather than landing
       flush against the edge. Six dishes in view, the rest a swipe away. */
    .menu-panel-inner{
        max-height:695px;overflow-y:auto;padding:12px;
        -webkit-overflow-scrolling:touch;
    }

    /* Chip filter: one scrollable row instead of wrapping to several lines,
       so the search box (now on its own full-width line below) doesn't get
       pushed around as chips wrap. Scrollbar hidden -- the fade at the right
       edge is the affordance instead, since a chip cut flat by the container
       edge just reads as broken. */
    .menu-chips-bar{flex-direction:column;align-items:stretch;gap:10px;margin-bottom:18px;padding-bottom:16px;}
    .menu-chips-scroll{
        flex:none;width:100%;min-width:0;
        flex-wrap:nowrap;overflow-x:auto;
        scrollbar-width:none;-ms-overflow-style:none;
        touch-action:pan-x;-webkit-overflow-scrolling:touch;
        -webkit-mask-image:linear-gradient(to right,#000 calc(100% - 30px),transparent);
                mask-image:linear-gradient(to right,#000 calc(100% - 30px),transparent);
    }
    .menu-chips-scroll::-webkit-scrollbar{display:none;}
    /* These are tapped with a thumb, not clicked with a cursor: the desktop
       padding makes a ~25px-tall target, under the ~36px minimum. */
    .menu-chip{padding:10px 16px;font-size:0.66rem;}

    /* .menu-search's base rule sets flex:1 1 200px for the desktop row
       layout -- with .menu-chips-bar now flex-direction:column, that basis
       applies to HEIGHT instead of width, stretching this box ~200px tall
       and leaving the icon (vertically centered in it) floating well below
       the visible input. flex:none stops that. */
    .menu-search{flex:none;margin-left:0;max-width:none;min-width:0;width:100%;margin-top:14px;}
    /* 16px exactly, not 0.8rem: iOS Safari zooms the whole page in when a
       focused input is under 16px, and never zooms back out afterwards. */
    .menu-search input{font-size:16px;padding:12px 46px 12px 15px;}
    .menu-search-clear{width:32px;height:32px;right:7px;font-size:0.8rem;}

    /* 2 portrait cards per row -- image on top, then name, price, blurb.
       Grid stretches every card in a row to the tallest of the pair, so the
       pair always lines up; only the name can vary in height, and it is
       clamped to two lines so a long one can't run away with the card. */
    .menu-grid-items{grid-template-columns:repeat(2,1fr);gap:10px;}
    .menu-item{flex-direction:column;align-items:stretch;padding:9px;gap:8px;}
    .menu-item-img{width:100%;height:98px;}
    .menu-item-info{gap:2px;}
    .menu-item-top{flex-direction:column;align-items:flex-start;gap:1px;}
    .menu-item-info h4{
        font-size:0.8rem;line-height:1.3;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
    }
    .menu-item-price{font-size:0.8rem;}
    .menu-item-info p{font-size:0.68rem;line-height:1.45;-webkit-line-clamp:2;}
    .menu-item-cat{font-size:0.5rem;letter-spacing:1.4px;}
    .feedback-card{flex:0 0 280px;width:280px;min-height:250px;padding:22px;}
    .feedback-carousel-controls{justify-content:center;}
    .footer-grid{grid-template-columns:1fr;gap:30px;}
    .footer-bottom{flex-direction:column;text-align:center;gap:6px;}
    .cta-inner h2{font-size:1.3rem;}
}
@media(max-width:480px){
    .hero h1{font-size:clamp(1.7rem,9vw,2.4rem);}
    .hero-eyebrow span{font-size:0.6rem;letter-spacing:2px;}
    .hero-pills{flex-direction:column;align-items:flex-start;gap:8px;}
    .hero-pill{width:100%;}
    /* Stacked, but sized to their own labels and left-aligned under the
       headline rather than stretched edge to edge -- a full-bleed bar reads
       as a form submit, not as the hero's invitation. */
    .hero-cta{flex-direction:column;align-items:flex-start;gap:12px;}
    .hero-cta .btn-hero,.hero-cta .btn-hero-ghost{justify-content:center;width:auto;align-self:flex-start;}
    /* Same three rows, against this breakpoint's shorter cards and tighter
       gaps: 10 + 3x197 + 2x8, again with ~25px of the fourth row showing. */
    .menu-panel-inner{max-height:645px;padding:10px;}
    .menu-grid-items{gap:8px;}
    .menu-item{padding:8px;gap:7px;}
    .menu-item-img{height:88px;}
    .menu-item-info h4{font-size:0.77rem;}
    .menu-item-price{font-size:0.77rem;}
    .menu-item-info p{font-size:0.66rem;}
    .btn-hero{padding:12px 20px;}
    .nav-logo-text strong{font-size:0.95rem;letter-spacing:1px;}
    .nav-logo-text span{font-size:0.42rem;letter-spacing:1.5px;}
    .nav-right{gap:8px;}
    .feedback-card{flex:0 0 250px;width:250px;padding:20px;}
    .footer-social a{width:34px;height:34px;}
}
@media(max-width:360px){
    .nav-logo-text{display:none;}
    .container{width:94%;}
    .nav-inner{padding:0 14px;}
    .btn-hero{padding:11px 18px;font-size:0.66rem;}
}
@media(max-height:480px) and (orientation:landscape){
    .hero{height:auto;min-height:auto;padding:calc(var(--nav-h) + 28px) 0 40px;}
    .hero-pills{display:none;}
    .scroll-hint{display:none;}
    .mobile-menu{padding-top:calc(var(--nav-h) + 10px);padding-bottom:20px;}
    .mobile-menu a{padding:10px 0;font-size:1.2rem;}
}

/* Touch devices have no hover to leave: the lift a finger triggers on tap
   sticks until something else is tapped, so a tapped dish stays raised and
   outlined as if it were selected. It isn't -- these cards do nothing. */
@media(hover:none){
    .menu-item:hover{transform:none;border-color:rgba(201,162,78,0.08);box-shadow:inset 0 1px 0 rgba(255,255,255,0.03);}
    .menu-item:hover .menu-item-img img{transform:none;}
    .menu-chip:hover{transform:none;border-color:rgba(201,162,78,0.25);color:var(--text-muted);}
    .menu-chip.active:hover{border-color:var(--gold-accent);color:var(--brown-deep);}
}

/* reduced motion — respect the user's preference */
@media(prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;scroll-behavior:auto!important;}
    .hero-eyebrow,.hero h1,.hero-sub,.hero-cta,.hero-pills{opacity:1;animation:none;}
    .hero-bg{animation:none;transform:none;}
    .hero-parallax{transform:none!important;}
    .js .reveal{opacity:1;transform:none;}
    .js .menu-item:not(.reveal-in){opacity:1;transform:none;}
    .feedback-track{animation:none!important;opacity:1!important;}
    .feedback-track-viewport{overflow-x:auto;-webkit-mask-image:none;mask-image:none;}
}
</style>
</head>
<body>

<div class="page-atmosphere" aria-hidden="true"></div>

<!-- ============ NAVBAR ============ -->
<nav id="navbar">
    <div class="nav-inner">

        <!-- LEFT: Logo -->
        <a href="#home" class="nav-logo">
            <img src="assets/images/logo.jpg" alt="OPO Logo">
            <div class="nav-logo-text">
                <strong>OPO!</strong>
                <span>Our Pinoy Original</span>
            </div>
        </a>

        <!-- CENTER: Links -->
        <div class="nav-links">
            <a href="#home">Home</a>
            <a href="#about">About us</a>
            <a href="#location">Location</a>
            <a href="#menu">Menu</a>
            <a href="#feedback">Feedback</a>
        </div>

        <!-- RIGHT: Auth + Hamburger -->
        <div class="nav-right">
            <div class="nav-auth">
                <?php if ($isLoggedIn): ?>
                    <a href="customer/dashboard.php" class="btn-outline"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($firstName ?: 'Account') ?></a>
                    <a href="auth/logout.php" class="btn-solid">Logout</a>
                <?php else: ?>
                    <a href="auth/login.php"   class="btn-outline">Login</a>
                    <a href="auth/sign_up.php" class="btn-solid">Sign Up</a>
                <?php endif; ?>
            </div>

            <!-- Mobile hamburger (shown ≤900px) -->
            <button class="hamburger" id="hamburgerBtn" aria-label="Open menu">
                <span></span><span></span><span></span>
            </button>
        </div>

    </div>
</nav>

<!-- ============ MOBILE MENU ============ -->
<div class="mobile-menu" id="mobileMenu">
    <a href="#home">Home</a>
    <a href="#about">About</a>
    <a href="#location">Location</a>
    <a href="#menu">Menu</a>
    <a href="#feedback">Feedback</a>
    <div class="mobile-menu-auth">
        <?php if ($isLoggedIn): ?>
            <a href="customer/dashboard.php" class="btn-outline"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($firstName ?: 'Account') ?></a>
            <a href="auth/logout.php" class="btn-solid">Logout</a>
        <?php else: ?>
            <a href="auth/login.php"   class="btn-outline">Login</a>
            <a href="auth/sign_up.php" class="btn-solid">Sign Up</a>
        <?php endif; ?>
    </div>
</div>

<!-- ============ HERO ============ -->
<section id="home" class="hero">
    <div class="hero-parallax" id="heroParallax">
        <div class="hero-bg"></div>
    </div>
    <div class="hero-overlay"></div>
    <div class="hero-glow"></div>
    <div class="container">
        <div class="hero-content">
            <h1>Elevating Filipino <em>Comfort Food</em></h1>
            <p>Experience the perfect blend of authentic Filipino flavors, modern dining, and unforgettable moments at OPO!</p><br>
             <div class="hero-cta">
                <?php // Auth-aware, same convention as the nav above: telling someone who is
                      // already signed in to "log in" would send them to a login page they
                      // do not need, so they go straight to the booking wizard instead. ?>
                <?php if ($isLoggedIn): ?>
                <a href="customer/make_reservation.php" class="btn-hero">Book a Table <i class="fa-solid fa-arrow-right"></i></a>
                <?php else: ?>
                <a href="auth/login.php" class="btn-hero">Login to Book <i class="fa-solid fa-arrow-right"></i></a>
                <?php endif; ?>
                <a href="#location" class="btn-hero-ghost"><i class="fa-solid fa-location-dot"></i> Find Us</a>
            </div>
           
        </div>
    </div>
    <a href="#about" class="scroll-hint" aria-label="Scroll to about"><span>Scroll</span><div class="scroll-dot"></div></a>
</section>

<!-- ============ ABOUT / CONCEPT & VISION ============ -->
<section id="about">
    <div class="container">
        <div class="concept-split reveal">
            <div class="concept-panel-image">
                <img src="assets/images/about.jpg" alt="OPO dining space">
            </div>
            <div class="concept-panel-text">
                <div class="concept-text-inner">
                    <h2 class="section-title concept-title">Concept &amp; <span>Vision</span></h2>
                    <p><strong>OPO! (Our Pinoy Original)</strong> introduces a new definition of Filipino fast food&mdash;quick, comforting, and elevated. Set in a modern, elegant open-space environment, OPO! brings together Filipino comfort food, Kamayan dining, sizzling specialties, and a dedicated bar and entertainment area. Our vision is to create a welcoming space where Filipinos can enjoy great food, relaxed social experiences, and modern Filipino culture in one destination.</p>

                    <?php // Same settings-driven values as the footer -- see the block at the
                          // top of this file. Moved here from #location so that section can be
                          // just the map. ?>
                    <div class="about-contact">
                        <?php if ($address !== ''): ?>
                        <div class="about-contact-row">
                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($address) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($phone !== ''): ?>
                        <div class="about-contact-row">
                            <i class="fa-solid fa-phone" aria-hidden="true"></i>
                            <a href="<?= htmlspecialchars($phoneHref) ?>"><?= htmlspecialchars($phone) ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if ($email !== ''): ?>
                        <div class="about-contact-row">
                            <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                            <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if ($hoursLabel !== ''): ?>
                        <div class="about-contact-row">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($hoursLabel) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="about-contact-row">
                            <i class="fa-brands fa-facebook" aria-hidden="true"></i>
                            <a href="https://www.facebook.com/ourpinoyoriginal" target="_blank" rel="noopener">facebook.com/ourpinoyoriginal</a>
                        </div>
                        <div class="about-contact-row">
                            <i class="fa-brands fa-instagram" aria-hidden="true"></i>
                            <a href="https://www.instagram.com/opofilipinocomfortfood" target="_blank" rel="noopener">@opofilipinocomfortfood</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ LOCATION ============ -->
<section id="location">
    <div class="container">
        <div class="reveal" style="text-align:center;margin-bottom:48px;">
            <span class="label" style="justify-content:center;">Find Us</span>
            <h2 class="section-title">Visit <span>OPO!</span></h2>
            <div class="gold-line center"></div>
        </div>

        <div class="location-card reveal">
            <div class="location-map">
                <iframe
                    src="https://maps.google.com/maps?q=<?= htmlspecialchars($mapQuery) ?>&amp;t=&amp;z=17&amp;ie=UTF8&amp;iwloc=B&amp;output=embed"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    title="<?= htmlspecialchars($mapPlace) ?> on Google Maps">
                </iframe>
            </div>
        </div>
    </div>
</section>

<!-- ============ MENU ============ -->
<section id="menu">
    <div class="container">
        <div class="menu-header reveal">
            <div class="menu-header-copy">
                <span class="label">Our Menu</span>
                <h2 class="section-title">Comfort Food <span>Favorites</span></h2>
                <p style="color:var(--text-muted);font-size:0.83rem;max-width:400px;margin-top:12px;">From hearty ulam to merienda treats, every plate is cooked fresh and built for sharing.</p>
            </div>

            <?php if ($menu_count > 0): ?>
            <label class="menu-search" id="menuSearchWrap">
                <span class="sr-only">Search the menu</span>
                <input type="text" id="menuSearchInput" placeholder="Search dishes&hellip;" autocomplete="off">
                <i class="fa-solid fa-magnifying-glass"></i>
                <button type="button" class="menu-search-clear" id="menuSearchClear" aria-label="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </label>
            <?php endif; ?>
        </div>

        <div class="menu-chips-bar reveal">
            <div class="menu-chips-scroll">
                <button class="menu-chip active" data-cat="all">All</button>
                <?php foreach ($categories as $cat): ?>
                    <button class="menu-chip" data-cat="<?= htmlspecialchars(strtolower($cat)) ?>">
                        <?= htmlspecialchars($cat) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="menu-panel reveal">
            <div class="menu-panel-inner">
                <div class="menu-grid-items" id="menuGrid">
                    <?php if ($menu_count > 0): ?>
                        <?php foreach ($menu_items as $item): ?>
                            <?php
                                $m_name  = $item['item_name'];
                                $m_desc  = $item['description'] ?? '';
                                $m_price = $item['selling_price'];
                                $m_cat   = $item['category'] ?? '';
                                $m_img   = $item['image_url'] ?? '';
                            ?>
                            <div class="menu-item" role="button" tabindex="0"
                                 aria-label="View <?= htmlspecialchars($m_name) ?>"
                                 data-cat="<?= htmlspecialchars(strtolower($m_cat)) ?>">
                                <div class="menu-item-img">
                                    <?php if (!empty($m_img)): ?>
                                        <img src="<?= htmlspecialchars('owner/' . $m_img) ?>" alt="<?= htmlspecialchars($m_name) ?>" loading="lazy">
                                    <?php else: ?>
                                        <i class="fa-solid fa-bowl-rice"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="menu-item-info">
                                    <?php if (!empty($m_cat)): ?>
                                        <span class="menu-item-cat"><?= htmlspecialchars($m_cat) ?></span>
                                    <?php endif; ?>
                                    <div class="menu-item-top">
                                        <h4><?= htmlspecialchars($m_name) ?></h4>
                                        <span class="menu-item-price">&#8369;<?= number_format((float)$m_price, 0) ?></span>
                                    </div>
                                    <?php if (!empty($m_desc)): ?>
                                        <p><?= htmlspecialchars($m_desc) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="menu-empty" id="menuNoResults" style="display:none;">
                            <i class="fa-solid fa-magnifying-glass" style="font-size:1.3rem;margin-bottom:10px;display:block;color:var(--gold-accent);"></i>
                            No dishes match your search. Try a different keyword or category.
                        </div>
                    <?php else: ?>
                        <div class="menu-empty">No menu items available yet. Check back soon!</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Dish detail. Content is copied from the clicked card, so there is no
         second copy of the menu data to keep in sync. -->
    <div class="dish-modal" id="dishModal" hidden>
        <div class="dish-modal-backdrop" data-dish-close></div>
        <div class="dish-modal-card" role="dialog" aria-modal="true" aria-labelledby="dishModalName">
            <?php /* Photo first, with the category, price and gallery controls set
                     ON it. Every id below is unchanged -- render() in the script at
                     the foot of this page fills these by id and only ever touches
                     textContent/innerHTML, so the layout can move without it. */ ?>
            <div class="dish-modal-media">
                <div class="dish-modal-img" id="dishModalImg"></div>
                <div class="dish-modal-media-scrim" aria-hidden="true"></div>
                <button type="button" class="dish-close" id="dishClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                <button type="button" class="dish-nav dish-nav-prev" id="dishPrev" aria-label="Previous dish"><i class="fa-solid fa-chevron-left"></i></button>
                <button type="button" class="dish-nav dish-nav-next" id="dishNext" aria-label="Next dish"><i class="fa-solid fa-chevron-right"></i></button>
                <span class="dish-modal-cat" id="dishModalCat"></span>
            </div>
            <div class="dish-modal-body">
                <div class="dish-modal-head">
                    <h3 id="dishModalName"></h3>
                    <div class="dish-modal-price" id="dishModalPrice"></div>
                </div>
                <p id="dishModalDesc"></p>
                <div class="dish-modal-foot">
                    <span class="dish-modal-counter" id="dishModalCounter"></span>
                    <?php // Auth-aware, same convention as the hero and nav. ?>
                    <?php if ($isLoggedIn): ?>
                        <a href="customer/make_reservation.php" class="dish-modal-cta">Book a table <i class="fa-solid fa-arrow-right"></i></a>
                    <?php else: ?>
                        <a href="auth/login.php" class="dish-modal-cta">Login to book <i class="fa-solid fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ FEEDBACK ============ -->
<section id="feedback">
    <div class="container">
        <div class="reveal" style="text-align:center;margin-bottom:20px;">
            <span class="label" style="justify-content:center;">Feedbacks</span>
            <h2 class="section-title">What Our <span>Customers</span> Say</h2>
            <div class="gold-line center"></div>
        </div>

       
        <?php if (count($feedbacks) > 0): ?>
            <?php if (count($feedbacks) > 1): ?>
            <div class="feedback-carousel-controls reveal">
                <button type="button" class="carousel-toggle-btn" id="feedbackToggleBtn" aria-pressed="false" aria-label="Pause testimonial auto-scroll">
                    <i class="fa-solid fa-pause"></i><span>Pause</span>
                </button>
            </div>
            <?php endif; ?>
            <div class="feedback-carousel reveal">
                <div class="feedback-track-viewport" aria-label="Customer testimonials, auto-scrolling">
                    <div class="feedback-track" id="feedbackTrack" role="list">
                        <?php foreach ($feedbacks as $fb): ?>
                            <?php
                                $disp_name = displayName($fb['first_name'], $fb['last_name']);
                                $initial   = strtoupper(substr(trim($fb['first_name'] ?? '') ?: 'O', 0, 1));
                                $date      = !empty($fb['created_at'])
                                    ? date('M d, Y', strtotime($fb['created_at']))
                                    : '';
                            ?>
                            <div class="feedback-card" role="listitem">
                                <p class="feedback-quote">"<?= htmlspecialchars(publicFeedbackComment($fb)) ?>"</p>
                                <div class="feedback-footer">
                                    <div class="feedback-user">
                                        <div class="feedback-avatar"><?= $initial ?></div>
                                        <div>
                                            <div class="feedback-name"><?= htmlspecialchars($disp_name) ?></div>
                                            <?php if ($date): ?><div class="feedback-date"><?= $date ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="feedback-empty reveal">No feedback yet &mdash; be the first to share your experience!</div>
        <?php endif; ?>
    </div>
</section>

<!-- ============ CTA STRIP ============ -->
<div class="cta-strip">
    <div class="container cta-inner reveal">
        <h2>Craving Pinoy comfort food? <span>Visit us today.</span></h2>
        <div class="cta-actions">
            <?php if ($phone !== ''): ?>
            <a href="<?= htmlspecialchars($phoneHref) ?>" class="btn-hero">Call Us</a>
            <?php endif; ?>
            <a href="#location" class="btn-hero-ghost"><i class="fa-solid fa-location-dot"></i> Get Directions</a>
        </div>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer>
    <div class="container">
        <div class="footer-grid">
            <div class="footer-col">
                <div class="footer-brand-logo">
                    <img src="assets/images/logo.jpg" alt="OPO Logo">
                    <span>OPO!</span>
                </div>
                <p>OPO! &mdash; Our Pinoy Original. Filipino comfort food cooked fresh and served with heart, right here in Calamba City.</p>
                <div class="footer-social">
                    <a href="https://www.facebook.com/ourpinoyoriginal" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook"></i></a>
                    <a href="https://www.instagram.com/opofilipinocomfortfood?igsh=MXB2Z3FsZmNveHE4aQ==" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="https://www.tiktok.com/@ourpinoyoriginal?_r=1&_t=ZS-97AFIQWf5Un" target="_blank" rel="noopener" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a>
                </div>
            </div>
            <div class="footer-col">
                <h4>Explore</h4>
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#about">About Us</a></li>
                    <li><a href="#location">Location</a></li>
                    <li><a href="#menu">Menu</a></li>
                    <li><a href="#feedback">Feedback</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Account</h4>
                <ul>
                    <li><a href="auth/login.php">Login</a></li>
                    <li><a href="auth/sign_up.php">Sign Up</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Contact</h4>
                <?php if ($address !== ''): ?>
                <div class="footer-contact-item">
                    <i class="fa-solid fa-location-dot"></i>
                    <span><?= htmlspecialchars($address) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($phone !== ''): ?>
                <div class="footer-contact-item">
                    <i class="fa-solid fa-phone"></i>
                    <a href="<?= htmlspecialchars($phoneHref) ?>"><?= htmlspecialchars($phone) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                <div class="footer-contact-item">
                    <i class="fa-regular fa-envelope"></i>
                    <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($hoursLabel !== ''): ?>
                <div class="footer-contact-item">
                    <i class="fa-regular fa-clock"></i>
                    <span><?= htmlspecialchars($hoursLabel) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <span id="year"></span> OPO! &ndash; Our Pinoy Original. All rights reserved.</span>
            <span>Made with care in Calamba City, Laguna</span>
        </div>
    </div>
</footer>

<!-- ============ BACK TO TOP ============ -->
<button id="backToTop" class="back-to-top" aria-label="Back to top">
    <i class="fa-solid fa-arrow-up"></i>
</button>

<!-- ============ SCRIPTS ============ -->
<script>
    // Navbar scroll state
    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', () => {
        navbar.classList.toggle('scrolled', window.scrollY > 40);
    });

    // Mobile menu controls
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const mobileMenu   = document.getElementById('mobileMenu');

    function openMobileMenu(){
        mobileMenu.classList.add('open');
        hamburgerBtn.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeMobileMenu(){
        mobileMenu.classList.remove('open');
        hamburgerBtn.classList.remove('active');
        document.body.style.overflow = '';
    }
    hamburgerBtn.addEventListener('click', () => {
        mobileMenu.classList.contains('open') ? closeMobileMenu() : openMobileMenu();
    });
    document.querySelectorAll('.mobile-menu a').forEach(a => a.addEventListener('click', closeMobileMenu));
    window.addEventListener('resize', () => {
        if (window.innerWidth > 900) closeMobileMenu();
    });

    // ---- Menu: category filter + live search, with soft transitions ----
    (function () {
        const chips         = document.querySelectorAll('.menu-chip');
        const menuGrid       = document.getElementById('menuGrid');
        const menuItems      = document.querySelectorAll('#menuGrid .menu-item');
        const searchInput    = document.getElementById('menuSearchInput');
        const searchWrap     = document.getElementById('menuSearchWrap');
        const searchClear    = document.getElementById('menuSearchClear');
        const noResultsEl    = document.getElementById('menuNoResults');
        const countTextEl    = document.getElementById('menuCountText');
        const menuPanel      = document.querySelector('.menu-panel');
        const menuPanelInner = document.querySelector('.menu-panel-inner');
        if (!menuGrid || menuItems.length === 0) return;

        const totalCount = menuItems.length;
        let searchDebounce;

        function updateScrollHint() {
            if (!menuPanel || !menuPanelInner) return;
            const canScroll = menuPanelInner.scrollHeight > menuPanelInner.clientHeight + 4;
            const atBottom  = menuPanelInner.scrollHeight - menuPanelInner.scrollTop - menuPanelInner.clientHeight < 12;
            menuPanel.classList.toggle('can-scroll', canScroll && !atBottom);
        }

        function applyMenuFilter() {
            const activeChip = document.querySelector('.menu-chip.active');
            const activeCat  = activeChip ? activeChip.dataset.cat : 'all';
            const query      = (searchInput ? searchInput.value : '').trim().toLowerCase();
            let visible = 0;

            menuItems.forEach(item => {
                const matchesCat    = activeCat === 'all' || item.dataset.cat === activeCat;
                const name          = item.querySelector('h4')?.textContent.toLowerCase() || '';
                const desc          = item.querySelector('.menu-item-info p')?.textContent.toLowerCase() || '';
                const matchesSearch = query === '' || name.includes(query) || desc.includes(query);
                const show          = matchesCat && matchesSearch;

                if (show) {
                    visible++;
                    if (item.style.display === 'none') {
                        item.style.display = 'flex';
                        void item.offsetWidth; // force reflow so the fade-in transition actually runs
                    }
                    item.classList.remove('hiding');
                } else if (item.style.display !== 'none') {
                    item.classList.add('hiding');
                    window.setTimeout(() => {
                        if (item.classList.contains('hiding')) item.style.display = 'none';
                    }, 240);
                }
            });

            if (noResultsEl) noResultsEl.style.display = visible === 0 ? 'block' : 'none';
            if (countTextEl) {
                countTextEl.textContent = (query === '' && activeCat === 'all')
                    ? `${totalCount} dish${totalCount === 1 ? '' : 'es'}`
                    : `${visible} of ${totalCount} dishes`;
            }
            window.setTimeout(updateScrollHint, 260);
        }

        chips.forEach(chip => {
            chip.addEventListener('click', () => {
                chips.forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                applyMenuFilter();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                if (searchWrap) searchWrap.classList.toggle('has-value', searchInput.value.trim() !== '');
                clearTimeout(searchDebounce);
                searchDebounce = window.setTimeout(applyMenuFilter, 160);
            });

        // ---- Dish detail modal ---------------------------------------
        // Navigation walks the CURRENTLY VISIBLE cards, not all of them:
        // paging past a dish the active filter excluded would be baffling.
        const dishModal   = document.getElementById('dishModal');
        if (dishModal) {
            const dImg     = document.getElementById('dishModalImg');
            const dCat     = document.getElementById('dishModalCat');
            const dName    = document.getElementById('dishModalName');
            const dPrice   = document.getElementById('dishModalPrice');
            const dDesc    = document.getElementById('dishModalDesc');
            const dCount   = document.getElementById('dishModalCounter');
            const dPrev    = document.getElementById('dishPrev');
            const dNext    = document.getElementById('dishNext');
            const dClose   = document.getElementById('dishClose');
            let deck = [];
            let pos  = 0;
            let lastFocus = null;

            const visibleCards = () =>
                Array.from(menuItems).filter(el => el.style.display !== 'none' && !el.classList.contains('hiding'));

            function render() {
                const card = deck[pos];
                if (!card) return;
                const img  = card.querySelector('.menu-item-img img');
                const cat  = card.querySelector('.menu-item-cat');
                const desc = card.querySelector('.menu-item-info p');

                dImg.innerHTML = '';
                if (img) {
                    const clone = document.createElement('img');
                    clone.src = img.getAttribute('src');
                    clone.alt = img.getAttribute('alt') || '';
                    dImg.appendChild(clone);
                } else {
                    // Mirror the card's own placeholder rather than an empty box.
                    const ico = document.createElement('i');
                    ico.className = 'fa-solid fa-bowl-rice';
                    dImg.appendChild(ico);
                }

                dCat.textContent   = cat ? cat.textContent.trim() : '';
                dCat.style.display = cat ? '' : 'none';
                dName.textContent  = card.querySelector('h4').textContent.trim();
                dPrice.textContent = card.querySelector('.menu-item-price').textContent.trim();
                dDesc.textContent  = desc ? desc.textContent.trim() : '';
                dDesc.style.display = desc ? '' : 'none';
                dCount.textContent = (pos + 1) + ' of ' + deck.length;
                dPrev.disabled = deck.length < 2;
                dNext.disabled = deck.length < 2;
            }

            function openDish(card) {
                deck = visibleCards();
                pos  = deck.indexOf(card);
                if (pos < 0) { deck = [card]; pos = 0; }
                lastFocus = card;
                render();
                dishModal.hidden = false;
                document.body.style.overflow = 'hidden';
                dClose.focus();
            }

            function closeDish() {
                dishModal.hidden = true;
                document.body.style.overflow = '';
                if (lastFocus) lastFocus.focus();
            }

            // Wraps around, so Next on the last dish returns to the first.
            function step(delta) {
                if (deck.length < 2) return;
                pos = (pos + delta + deck.length) % deck.length;
                render();
            }

            menuItems.forEach(card => {
                card.addEventListener('click', () => openDish(card));
                card.addEventListener('keydown', ev => {
                    if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openDish(card); }
                });
            });

            dPrev.addEventListener('click', () => step(-1));
            dNext.addEventListener('click', () => step(1));
            dClose.addEventListener('click', closeDish);
            dishModal.querySelector('[data-dish-close]').addEventListener('click', closeDish);

            document.addEventListener('keydown', ev => {
                if (dishModal.hidden) return;
                if (ev.key === 'Escape')     closeDish();
                if (ev.key === 'ArrowLeft')  step(-1);
                if (ev.key === 'ArrowRight') step(1);
            });
        }
        }
        if (searchClear && searchInput) {
            searchClear.addEventListener('click', () => {
                searchInput.value = '';
                if (searchWrap) searchWrap.classList.remove('has-value');
                searchInput.focus();
                applyMenuFilter();
            });
        }

        // Each dish fades/slides in on its own as it scrolls into view --
        // not just once when the panel first appears, but again for every
        // card further down the list as the visitor scrolls through it.
        // Ordered by arrival (not list position), so cards revealed together
        // stagger relative to EACH OTHER rather than all sharing one delay.
        if (menuItems.length && 'IntersectionObserver' in window) {
            const itemRevealObserver = new IntersectionObserver((entries) => {
                // Staggered relative to the other cards that crossed the
                // threshold in THIS SAME batch, not by list position -- a
                // lone card revealed by a slow scroll pops in immediately
                // rather than waiting on a fixed delay meant for a cascade.
                entries.filter(e => e.isIntersecting).forEach((entry, i) => {
                    const item = entry.target;
                    // Skipped under reduced motion: the CSS collapses the
                    // transition itself, but not the delay, so the stagger
                    // would just be dead waiting time.
                    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        item.style.transitionDelay = Math.min(i * 55, 400) + 'ms';
                    }
                    item.classList.add('reveal-in');
                    itemRevealObserver.unobserve(item);
                });
            }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });
            menuItems.forEach(item => itemRevealObserver.observe(item));
        } else {
            menuItems.forEach(item => item.classList.add('reveal-in'));
        }

        // Fade each dish photo in once it loads; fall back to a plate icon if it 404s
        document.querySelectorAll('.menu-item-img img').forEach(img => {
            const markLoaded = () => img.classList.add('img-loaded');
            if (img.complete && img.naturalWidth > 0) {
                markLoaded();
            } else {
                img.addEventListener('load', markLoaded);
                img.addEventListener('error', () => {
                    const wrap = img.closest('.menu-item-img');
                    if (wrap) wrap.innerHTML = '<i class="fa-solid fa-bowl-rice"></i>';
                });
            }
        });

        if (menuPanelInner) menuPanelInner.addEventListener('scroll', updateScrollHint, { passive: true });
        window.addEventListener('resize', updateScrollHint);
        updateScrollHint();
    })();

    // Auto-scrolling testimonial carousel
    (function () {
        const viewport = document.querySelector('.feedback-track-viewport');
        const track     = document.getElementById('feedbackTrack');
        if (!track || !viewport) return;

        const originalCards = Array.from(track.children);
        const originalCount = originalCards.length;
        if (originalCount === 0) return;

        // Single testimonial: nothing to loop, just show it centered.
        if (originalCount === 1) {
            viewport.style.justifyContent = 'center';
            track.classList.add('is-ready');
            return;
        }

        const originalHTML = track.innerHTML;

        requestAnimationFrame(() => {
            const roughSetWidth = track.scrollWidth; // width of one original set
            const viewportWidth = viewport.clientWidth || window.innerWidth;
            const copies = Math.min(8, Math.max(2, Math.ceil((viewportWidth * 2.2) / roughSetWidth) + 1));

            for (let i = 1; i < copies; i++) {
                track.insertAdjacentHTML('beforeend', originalHTML);
            }
            // Hide duplicated copies from assistive tech; only the first set is "real".
            Array.from(track.children).slice(originalCount).forEach(card => {
                card.setAttribute('aria-hidden', 'true');
            });

            // Re-measure the actual total width so the loop distance is exact,
            // then travel exactly one repeating "period" for a seamless wrap.
            const totalWidth  = track.scrollWidth;
            const unitWidth   = totalWidth / copies;
            const pxPerSecond = 42; // gentle, unhurried drift
            const duration    = Math.max(unitWidth / pxPerSecond, 20);

            track.style.setProperty('--scroll-distance', (-100 / copies) + '%');
            track.style.setProperty('--scroll-duration', duration.toFixed(1) + 's');
            track.classList.add('is-ready', 'is-scrolling');
        });

        // Touch devices: pause while a finger is on the strip (no CSS :hover there).
        viewport.addEventListener('touchstart', () => track.classList.add('is-touch-paused'), { passive: true });
        viewport.addEventListener('touchend',   () => track.classList.remove('is-touch-paused'), { passive: true });

        // Manual pause / play control
        const toggleBtn = document.getElementById('feedbackToggleBtn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                const paused = track.classList.toggle('is-paused');
                toggleBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
                toggleBtn.setAttribute('aria-label', paused ? 'Resume testimonial auto-scroll' : 'Pause testimonial auto-scroll');
                toggleBtn.querySelector('i').className = paused ? 'fa-solid fa-play' : 'fa-solid fa-pause';
                toggleBtn.querySelector('span').textContent = paused ? 'Play' : 'Pause';
            });
        }
    })();

    // Scroll-triggered reveal animations. Staggered the same way the dish grid
    // above is: by position within THIS batch of arrivals, not by document
    // order -- so a group that scrolls in together cascades, while a single
    // block reached by a slow scroll still appears immediately instead of
    // sitting behind a delay meant for a cascade it isn't part of.
    const revealEls = document.querySelectorAll('.reveal');
    // The reduced-motion CSS collapses the transition to ~0s but says nothing
    // about delay, so without this check those users would still sit through
    // up to 320ms of waiting for an animation that never plays.
    const staggerOff = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver((entries) => {
            entries.filter(e => e.isIntersecting).forEach((entry, i) => {
                if (!staggerOff) {
                    entry.target.style.transitionDelay = Math.min(i * 80, 320) + 'ms';
                }
                entry.target.classList.add('in-view');
                revealObserver.unobserve(entry.target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        revealEls.forEach(el => revealObserver.observe(el));
    } else {
        revealEls.forEach(el => el.classList.add('in-view'));
    }

    // Animated stat counters -- any element carrying data-count-to counts up
    // from 0 the first time it scrolls into view, once, then stays put. Nothing
    // uses it since the rating summary was removed; it no-ops on an empty match
    // and is kept for the next figure that wants it.
    (function () {
        const counters = document.querySelectorAll('[data-count-to]');
        if (!counters.length) return;

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function animateCount(el) {
            const target = parseFloat(el.dataset.countTo);
            const decimals = parseInt(el.dataset.decimals || '0', 10);
            if (reduceMotion || !isFinite(target)) {
                el.textContent = target.toFixed(decimals);
                return;
            }
            const duration = 1400;
            const start = performance.now();
            (function tick(now) {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
                el.textContent = (target * eased).toFixed(decimals);
                if (progress < 1) requestAnimationFrame(tick);
            })(start);
        }

        if ('IntersectionObserver' in window) {
            const counterObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        animateCount(entry.target);
                        counterObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.6 });
            counters.forEach(el => counterObserver.observe(el));
        } else {
            counters.forEach(animateCount);
        }
    })();

    // Hero parallax: the background drifts slightly slower than the page on
    // scroll, and (desktop only) leans subtly away from the cursor. Applied
    // to the #heroParallax WRAPPER around .hero-bg, never to .hero-bg itself
    // -- that element's own zoom-in keyframe holds its transform via
    // animation-fill-mode, which would silently out-rank anything set here.
    (function () {
        const heroParallax = document.getElementById('heroParallax');
        const hero = document.querySelector('.hero');
        if (!heroParallax || !hero) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        let scrollOffset = 0, mouseX = 0, mouseY = 0;
        let currentX = 0, currentY = 0, ticking = false;

        function render() {
            const targetY = scrollOffset + mouseY;
            currentX += (mouseX - currentX) * 0.08;
            currentY += (targetY - currentY) * 0.08;
            heroParallax.style.transform = `translate3d(${currentX.toFixed(2)}px, ${currentY.toFixed(2)}px, 0)`;
            if (Math.abs(mouseX - currentX) > 0.05 || Math.abs(targetY - currentY) > 0.05) {
                requestAnimationFrame(render);
            } else {
                ticking = false;
            }
        }
        function requestFrame() {
            if (!ticking) { ticking = true; requestAnimationFrame(render); }
        }

        window.addEventListener('scroll', () => {
            // Stop moving it once the hero itself has scrolled out of view --
            // nothing to see, and scrollY alone would otherwise grow the
            // offset well past the background's own overscan buffer.
            if (hero.getBoundingClientRect().bottom > 0) {
                scrollOffset = Math.min(window.scrollY * 0.18, 70);
                requestFrame();
            }
        }, { passive: true });

        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            hero.addEventListener('mousemove', (e) => {
                const rect = hero.getBoundingClientRect();
                mouseX = ((e.clientX - rect.left) / rect.width - 0.5) * -18;
                mouseY = ((e.clientY - rect.top) / rect.height - 0.5) * -10;
                requestFrame();
            });
            hero.addEventListener('mouseleave', () => {
                mouseX = 0; mouseY = 0;
                requestFrame();
            });
        }
    })();

    // Back to top
    const backToTop = document.getElementById('backToTop');
    if (backToTop) {
        window.addEventListener('scroll', () => {
            backToTop.classList.toggle('show', window.scrollY > 600);
        }, { passive: true });
        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // Highlight the nav link for whichever section is currently in view
    (function () {
        const navLinks = document.querySelectorAll('.nav-links a[href^="#"]');
        if (!navLinks.length || !('IntersectionObserver' in window)) return;

        const sectionToLink = new Map();
        navLinks.forEach(a => {
            const sec = document.querySelector(a.getAttribute('href'));
            if (sec) sectionToLink.set(sec, a);
        });
        if (!sectionToLink.size) return;

        const navObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const link = sectionToLink.get(entry.target);
                if (!link) return;
                navLinks.forEach(a => a.classList.remove('active-link'));
                link.classList.add('active-link');
            });
        }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });

        sectionToLink.forEach((link, sec) => navObserver.observe(sec));
    })();

    // Footer year
    document.getElementById('year').textContent = new Date().getFullYear();
</script>
</body>
</html>