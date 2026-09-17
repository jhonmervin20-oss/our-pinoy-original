/**
 * customer/assets/js/chatbot.js
 *
 * Drives the floating assistant widget (customer/includes/navbar.php).
 * Conversation history lives only in this page's own JS memory -- it
 * resends the whole history back to api/chatbot.php each turn (see that
 * file's own doc comment for why there's no server-side chat table) and
 * is lost on refresh, which is an acceptable trade for not adding one.
 *
 * Voice is the browser's own Web Speech API (SpeechRecognition for the
 * mic button, SpeechSynthesis for reading replies aloud) -- no OpenAI
 * audio endpoint, no extra API cost. Feature-detected throughout: on a
 * browser without support (notably Safari for SpeechRecognition), the mic
 * button and voice toggle simply don't render rather than throwing.
 */

(function () {
    'use strict';

    const widget = document.getElementById('caChatbot');
    if (!widget) return;

    const toggle = document.getElementById('caChatbotToggle');
    const scrim = document.getElementById('caChatbotScrim');
    const panel = document.getElementById('caChatbotPanel');
    const newChatBtn = document.getElementById('caChatbotNewChat');
    const messagesEl = document.getElementById('caChatbotMessages');
    const welcomeRow = document.getElementById('caChatbotWelcomeRow');
    const chipsEl = document.getElementById('caChatbotChips');
    const scrollBtn = document.getElementById('caChatbotScrollBtn');
    const form = document.getElementById('caChatbotForm');
    const input = document.getElementById('caChatbotInput');
    const sendBtn = document.getElementById('caChatbotSend');
    const micBtn = document.getElementById('caChatbotMic');
    const voiceToggle = document.getElementById('caChatbotVoiceToggle');
    const logoSrc = '../assets/images/logo.jpg';
    // Reuses the profile button's own initials badge (navbar.php) rather
    // than asking the server for the customer's name separately -- it's
    // already on every page this widget renders on.
    const customerInitial = (document.querySelector('.ca-avatar')?.textContent || '').trim();

    const FOLLOWUP_SUGGESTIONS = [
        "Show today's menu",
        'What are your opening hours?',
        'Can I cancel my reservation?',
    ];

    let history = [];
    let sending = false;
    let voiceOn = true;
    let isOpen = false;
    let hasOpenedOnce = false;

    function timeLabel() {
        return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    const isMobileViewport = () => window.innerWidth <= 767;

    function openPanel() {
        isOpen = true;
        panel.classList.add('is-open');
        toggle.classList.add('is-open');
        scrim.classList.add('is-open');
        // The panel is opacity:0/visibility:hidden while closed (not
        // display:none, so the slide/fade transition has something to
        // animate) -- an animation on the welcome row would otherwise have
        // already finished playing, invisibly, before the panel is ever
        // shown. Trigger it for real the first time the panel actually opens.
        if (!hasOpenedOnce) {
            hasOpenedOnce = true;
            if (welcomeRow) welcomeRow.classList.add('ca-anim-in');
        }
        // Auto-focus is fine on desktop (no on-screen keyboard consequence),
        // but on mobile it pops the keyboard the instant the sheet opens,
        // covering half the screen before the slide-up animation even
        // settles -- most mobile chat UIs wait for a deliberate tap instead.
        if (!isMobileViewport()) input.focus();
    }

    function closePanel() {
        isOpen = false;
        panel.classList.remove('is-open');
        toggle.classList.remove('is-open');
        scrim.classList.remove('is-open');
        panel.style.height = ''; // drop any visualViewport override from syncPanelToVisualViewport()
    }

    // -- Keep the sheet clear of the on-screen keyboard ------------------------
    // position:fixed anchors to the *layout* viewport, which most mobile
    // browsers do NOT shrink when the keyboard opens (the keyboard just
    // covers whatever was there) -- 100dvh alone doesn't reliably track
    // keyboard state on every browser either. visualViewport.height does,
    // so once the gap between it and window.innerHeight is large enough to
    // mean "keyboard is open," the sheet's height is pinned to exactly
    // what's still visible; otherwise it falls back to the CSS default.
    function syncPanelToVisualViewport() {
        if (!isOpen || !window.visualViewport || !isMobileViewport()) return;
        const vv = window.visualViewport;
        const keyboardLikelyOpen = (window.innerHeight - vv.height) > 100;
        panel.style.height = keyboardLikelyOpen ? vv.height + 'px' : '';
    }

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncPanelToVisualViewport);
    }

    toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        if (isOpen) closePanel(); else openPanel();
    });
    scrim.addEventListener('click', closePanel);
    panel.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen) closePanel();
    });
    // Minimize button is gone -- closing now happens by tapping/clicking
    // anywhere outside the widget. This listener runs in the CAPTURE phase
    // (third arg true) because many UI elements elsewhere on the page --
    // the navbar notification bell, the profile dropdown toggle, etc. --
    // call stopPropagation() in their own click handlers to keep their own
    // dropdowns from closing. A bubble-phase listener on document would
    // never fire for those clicks, leaving the chat sheet stuck open.
    // The closest('#caChatbot') check keeps clicks that START inside the
    // widget (toggle button, panel, scrim, chips) from closing it -- the
    // toggle and panel still handle their own open/close logic normally.
    document.addEventListener('click', (e) => {
        if (isOpen && !e.target.closest('#caChatbot')) closePanel();
    }, true);

    // -- New chat -- resets local history and clears every row added after
    // the original welcome message/quick-action cards (those two are never
    // removed, just reused). ---------------------------------------------
    if (newChatBtn) {
        newChatBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            history = [];
            Array.from(messagesEl.children).forEach((el) => {
                if (el !== welcomeRow && el !== chipsEl) el.remove();
            });
            if (chipsEl) chipsEl.hidden = false;
            if (window.speechSynthesis) window.speechSynthesis.cancel();
            messagesEl.scrollTop = 0;
        });
    }

    // -- Scroll-to-bottom button -- only visible once the user has scrolled
    // away from the latest message. ---------------------------------------
    function isNearBottom() {
        return (messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight) < 80;
    }
    function updateScrollBtn() {
        if (scrollBtn) scrollBtn.hidden = isNearBottom();
    }
    messagesEl.addEventListener('scroll', updateScrollBtn);
    if (scrollBtn) {
        scrollBtn.addEventListener('click', () => {
            messagesEl.scrollTo({ top: messagesEl.scrollHeight, behavior: 'smooth' });
        });
    }

    // -- Rendering -------------------------------------------------------------
    function addUserMessage(text) {
        const row = document.createElement('div');
        row.className = 'ca-chatbot-row is-user ca-anim-in';
        row.innerHTML =
            '<span class="ca-chatbot-avatar-initial">' + escapeHtml(customerInitial) + '</span>' +
            '<div class="ca-chatbot-bubble-wrap">' +
                '<div class="ca-chatbot-msg is-user"></div>' +
                '<span class="ca-chatbot-msg-time"></span>' +
            '</div>';
        row.querySelector('.ca-chatbot-msg').textContent = text;
        row.querySelector('.ca-chatbot-msg-time').textContent = timeLabel();
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s == null ? '' : String(s);
        return div.innerHTML;
    }

    function pesos(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // -- Real menu item cards (only rendered when the bot actually called
    // get_menu this turn -- never fabricated client-side). No star rating
    // here -- menu_items has no real rating/review column anywhere in this
    // app, so a rating widget would just be invented UI. ---------------------
    function buildMenuCardsHtml(items) {
        if (!items || !items.length) return '';
        const cards = items.map((it) => {
            const img = it.image_url
                ? '<img class="ca-chatbot-card-img" src="' + escapeHtml(it.image_url) + '" alt="">'
                : '<i class="ph ph-fork-knife" aria-hidden="true"></i>';
            const desc = it.description
                ? '<div class="ca-chatbot-card-desc">' + escapeHtml(it.description) + '</div>'
                : '';
            return (
                '<div class="ca-chatbot-card">' +
                    '<div class="ca-chatbot-card-img-wrap">' + img + '</div>' +
                    '<div class="ca-chatbot-card-body">' +
                        '<div class="ca-chatbot-card-name">' + escapeHtml(it.name) + '</div>' +
                        '<div class="ca-chatbot-card-category">' + escapeHtml(it.category) + '</div>' +
                        desc +
                        '<div class="ca-chatbot-card-price">' + pesos(it.price) + '</div>' +
                    '</div>' +
                '</div>'
            );
        }).join('');
        return '<div class="ca-chatbot-cards">' + cards + '</div>';
    }

    // -- Follow-up suggestion chips, appended under every completed reply --
    function buildFollowupsHtml() {
        const picked = FOLLOWUP_SUGGESTIONS.slice().sort(() => Math.random() - 0.5).slice(0, 3);
        const chips = picked.map((s) =>
            '<button type="button" class="ca-chatbot-followup-chip" data-chip="' + escapeHtml(s) + '">' + escapeHtml(s) + '</button>'
        ).join('');
        return (
            '<div class="ca-chatbot-followups">' +
                '<span class="ca-chatbot-followups-label">Try asking</span>' +
                '<div class="ca-chatbot-followups-row">' + chips + '</div>' +
            '</div>'
        );
    }

    // isFinal marks a real, completed assistant reply (adds the follow-up
    // suggestions) -- error/connectivity messages skip them, since there's
    // nothing to follow up on.
    function addBotMessage(text, menuItems, isFinal) {
        const row = document.createElement('div');
        row.className = 'ca-chatbot-row is-bot ca-anim-in';
        row.innerHTML =
            '<img src="' + logoSrc + '" alt="" class="ca-chatbot-avatar">' +
            '<div class="ca-chatbot-bubble-wrap">' +
                '<div class="ca-chatbot-msg is-bot"></div>' +
                buildMenuCardsHtml(menuItems) +
                '<span class="ca-chatbot-msg-time"></span>' +
                (isFinal ? buildFollowupsHtml() : '') +
            '</div>';
        row.querySelector('.ca-chatbot-msg').textContent = text;
        row.querySelector('.ca-chatbot-msg-time').textContent = timeLabel();
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        speak(text);
    }

    function addTyping() {
        const row = document.createElement('div');
        row.className = 'ca-chatbot-row is-bot ca-anim-in';
        row.innerHTML = '<img src="' + logoSrc + '" alt="" class="ca-chatbot-avatar">' +
            '<div class="ca-chatbot-msg is-bot is-typing">' +
                '<span class="ca-chatbot-typing-label">OPO! Assistant is typing</span>' +
                '<span class="ca-chatbot-typing-dots"><span></span><span></span><span></span></span>' +
            '</div>';
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return row;
    }

    // -- Quick-reply cards (only shown before the first message) ----------------
    if (chipsEl) {
        chipsEl.querySelectorAll('.ca-chatbot-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                const text = chip.dataset.chip;
                if (text) sendMessage(text);
            });
        });
    }

    // -- Delegated handling for dynamically-added follow-up chips. ---------
    messagesEl.addEventListener('click', (e) => {
        const followup = e.target.closest('.ca-chatbot-followup-chip');
        if (followup && followup.dataset.chip) {
            sendMessage(followup.dataset.chip);
        }
    });

    // -- Voice output (SpeechSynthesis) ------------------------------------
    const canSpeak = 'speechSynthesis' in window;
    if (!canSpeak && voiceToggle) {
        voiceToggle.hidden = true;
    }

    function speak(text) {
        if (!canSpeak || !voiceOn) return;
        window.speechSynthesis.cancel(); // don't stack replies
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.02;
        window.speechSynthesis.speak(utterance);
    }

    if (voiceToggle && canSpeak) {
        voiceToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            voiceOn = !voiceOn;
            voiceToggle.classList.toggle('is-on', voiceOn);
            voiceToggle.setAttribute('aria-pressed', String(voiceOn));
            voiceToggle.querySelector('i').className = voiceOn ? 'ph ph-speaker-high' : 'ph ph-speaker-slash';
            voiceToggle.setAttribute('aria-label', voiceOn ? 'Mute voice replies' : 'Unmute voice replies');
            if (!voiceOn) window.speechSynthesis.cancel();
        });
    }

    // -- Voice input (SpeechRecognition) ------------------------------------
    const SpeechRecognitionApi = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognitionApi && micBtn) {
        micBtn.hidden = true;
    } else if (micBtn) {
        const recognition = new SpeechRecognitionApi();
        recognition.lang = 'en-PH';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        let listening = false;

        micBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (listening) { recognition.stop(); return; }
            try { recognition.start(); } catch (err) { /* already started */ }
        });

        recognition.addEventListener('start', () => {
            listening = true;
            micBtn.classList.add('is-listening');
            micBtn.setAttribute('aria-pressed', 'true');
        });

        recognition.addEventListener('end', () => {
            listening = false;
            micBtn.classList.remove('is-listening');
            micBtn.setAttribute('aria-pressed', 'false');
        });

        recognition.addEventListener('result', (e) => {
            const transcript = e.results?.[0]?.[0]?.transcript;
            if (transcript) {
                input.value = transcript;
                input.dispatchEvent(new Event('input'));
                input.focus();
            }
        });

        recognition.addEventListener('error', () => {
            listening = false;
            micBtn.classList.remove('is-listening');
        });
    }

    // -- Send button only active once there's something to send -----------------
    input.addEventListener('input', () => {
        sendBtn.disabled = input.value.trim() === '';
    });

    // -- Sending a message ---------------------------------------------------
    async function sendMessage(text) {
        if (!text || sending) return;

        sending = true;
        sendBtn.disabled = true;
        input.value = '';
        if (chipsEl) chipsEl.hidden = true;
        addUserMessage(text);
        const typingRow = addTyping();

        try {
            const res = await fetch('api/chatbot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, history }),
            });
            const data = await res.json();

            typingRow.remove();

            if (!res.ok || data.error) {
                addBotMessage(data.error || "Something went wrong. Please try again.");
            } else {
                addBotMessage(data.reply, data.menu_items, true);
                history = data.history || history;
            }
        } catch (err) {
            typingRow.remove();
            addBotMessage("I couldn't reach the server. Please check your connection and try again.");
        } finally {
            sending = false;
            sendBtn.disabled = input.value.trim() === '';
            input.focus();
        }
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        sendMessage(input.value.trim());
    });
})();
