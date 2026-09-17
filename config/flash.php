<?php
/**
 * config/flash.php
 *
 * Tiny session-based flash-message helper for the classic
 * POST -> redirect -> GET pattern: a mutating action calls flash_set()
 * right before its header('Location: ...') redirect, and the page that
 * loads next calls flash_render() once to show (and clear) it. A page
 * refresh after that never re-shows a stale message.
 *
 * Requires Session::start() to have already run.
 *
 * Usage:
 *   require_once __DIR__ . '/../../config/flash.php';
 *   flash_set('success', 'Settings saved.');
 *   header('Location: general.php'); exit;
 *   ...
 *   <?= flash_render() ?>
 */

/**
 * Queue a one-time flash message. $type is 'success' or 'error'.
 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Read and clear the queued flash message, if any.
 */
function flash_consume(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

/**
 * Consume the queued flash message (if any) and return its markup,
 * or an empty string when nothing is queued.
 *
 * SUCCESS messages auto-dismiss after 5 seconds; ERRORS do not. A success
 * banner ("Draft payroll run PR-0001 created.") is a receipt for something
 * the user just did and already expected, so leaving it on screen until the
 * next page load is pure clutter. An error is the opposite: it is the only
 * notice that an action did NOT do what was intended, and quietly deleting
 * it a few seconds later means a user who glanced away never learns their
 * save failed. Errors therefore stay until dismissed by hand.
 *
 * The behaviour is implemented here rather than in CSS/JS assets on purpose:
 * flash_render() is shared by 40+ pages across owner/manager/admin/cashier/
 * customer, while owner-panel.css exists as several separate un-synced copies
 * per module folder -- a stylesheet-based fade would have to be duplicated
 * into every one of them and would silently not apply wherever a copy was
 * missed. Keeping it self-contained means one edit covers every page that
 * renders a flash message.
 */
function flash_render(): string
{
    $flash = flash_consume();

    if (!$flash) {
        return '';
    }

    $type    = $flash['type'] === 'success' ? 'success' : 'error';
    $icon    = $type === 'success' ? 'ph-check-circle' : 'ph-warning-circle';
    $message = htmlspecialchars($flash['message']);

    // role=status announces politely (waits for a pause); role=alert interrupts
    // immediately. Screen readers ignore silently-inserted markup otherwise, and
    // that matters more once the success banner removes itself on a timer.
    $role = $type === 'success' ? 'status' : 'alert';
    // Errors get double the dwell time. They used to persist indefinitely, which
    // was right while this was an in-flow banner -- but a floating overlay that
    // never leaves would sit on top of page content forever, so it now expires
    // too, just slowly enough to be read. Hovering still holds either kind open.
    $delay = $type === 'success' ? 5000 : 10000;

    $banner = <<<HTML
        <div class="flash-toast flash-toast-{$type}" role="{$role}" data-flash-autodismiss="{$delay}">
            <i class="ph {$icon}" aria-hidden="true"></i>
            <span>{$message}</span>
            <button type="button" class="flash-toast-dismiss" aria-label="Dismiss" onclick="this.closest('.flash-toast').remove()">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>
        HTML;

    // Deliberately its own class rather than .owner-alert: that class is also
    // hand-written into page markup for static, in-flow warnings, and floating
    // every one of those would be wrong. Styles ship inline with the markup
    // because customer pages define neither .owner-alert nor the --op-* tokens
    // it depends on -- the flash there was rendering completely unstyled.
    $styles = <<<'HTML'
        <style>
        .flash-toast{
            position: fixed;
            /* Clears the sticky 76px topbar instead of covering it -- the
               notification bell and avatar live at ITS top-right, and a toast
               parked over them would block the controls it sits next to. */
            top: calc(var(--op-topbar-h, var(--ca-topbar-h, 76px)) + 16px);
            right: 20px;
            z-index: 1200; /* above the highest existing z-index in the app (1000) */
            /* Exact-width: fit-content shrinks the box to icon + text + button
               + padding and no further, so a short message is a short toast.
               max-width caps it so a long one wraps to a second line rather
               than running off the viewport, and the vw term keeps it inside
               the screen on phones. */
            width: fit-content;
            max-width: min(420px, calc(100vw - 32px));
            /* Explicit, NOT inherited from the host page's reset: with the
               default content-box, the 14px padding and 1px border add on top
               of max-width, so on a 390px phone the cap resolves to 358 + 30 =
               388px of real box at right:20px -- i.e. the left edge lands at
               -18px and the toast hangs off the screen. Stated here so the
               cap means the same thing on every page that renders a flash. */
            box-sizing: border-box;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid;
            font-size: 0.87rem;
            line-height: 1.45;
            box-shadow: 0 16px 40px -20px rgba(30,24,16,0.22), 0 2px 8px rgba(30,24,16,0.06);
            animation: flash-toast-in .28s ease-out both;
        }
        /* OPAQUE backgrounds, not the in-flow banner's rgba(...,0.10) tints:
           a 10%-alpha fill lets page content read straight through a floating
           element. These are those same two tints composited over white, so
           the colour matches what the in-flow banner resolved to. */
        .flash-toast-success{ background: #eff3ee; border-color: rgba(92,138,82,0.28);  color: #5c8a52; }
        .flash-toast-error  { background: #faefec; border-color: rgba(207,90,68,0.28); color: #cf5a44; }
        .flash-toast > i:first-child{ font-size: 1.05rem; flex-shrink: 0; }
        .flash-toast span{ min-width: 0; overflow-wrap: anywhere; }
        .flash-toast-dismiss{
            flex-shrink: 0;
            width: 22px; height: 22px;
            border: none; background: none; color: inherit;
            opacity: 0.6; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            border-radius: 6px;
        }
        .flash-toast-dismiss:hover{ opacity: 1; }
        @keyframes flash-toast-in{
            from{ opacity: 0; transform: translateX(12px); }
            to  { opacity: 1; transform: none; }
        }
        @media (prefers-reduced-motion: reduce){
            .flash-toast{ animation: none; }
        }
        </style>
        HTML;

    $script = <<<'HTML'
        <script>
        (function () {
            // :not([data-flash-armed]) guards against double-arming if a page
            // ever renders more than one flash -- each gets exactly one timer.
            var toasts = document.querySelectorAll('.flash-toast[data-flash-autodismiss]:not([data-flash-armed])');
            Array.prototype.forEach.call(toasts, function (el) {
                el.setAttribute('data-flash-armed', '');
                var timer = setTimeout(close, parseInt(el.getAttribute('data-flash-autodismiss'), 10) || 5000);
                // Hovering holds the message open: the pointer resting on it is
                // the one reliable signal that someone is still reading it (or
                // reaching for the x). Leaving restarts a shorter timer rather
                // than the full delay, so it does not linger once they move on.
                el.addEventListener('mouseenter', function () { clearTimeout(timer); });
                el.addEventListener('mouseleave', function () { timer = setTimeout(close, 2000); });
                function close() {
                    // Slides back out the way it came in. Safe to animate now
                    // that the toast is out of flow -- nothing below it moves.
                    el.style.transition = 'opacity .3s ease, transform .3s ease';
                    el.style.opacity = '0';
                    el.style.transform = 'translateX(12px)';
                    setTimeout(function () { el.remove(); }, 350);
                }
            });
        })();
        </script>
        HTML;

    return $styles . $banner . $script;
}
