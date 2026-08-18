<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orange Cafe — حالة الخادم</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f7f9;
            --card: #ffffff;
            --border: #e4e7ec;
            --text: #101828;
            --text-soft: #667085;
            --text-faint: #98a2b3;
            --brand: #ea580c;
            --brand-soft: #fff4ed;
            --ok: #16a34a;
            --bad: #dc2626;
            --warn: #d97706;
            --idle: #cbd5e1;
            --input-bg: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --card: #161b22;
                --border: #2a313c;
                --text: #e6edf3;
                --text-soft: #9198a1;
                --text-faint: #6e7681;
                --brand: #fb923c;
                --brand-soft: #2c1a0d;
                --ok: #4ade80;
                --bad: #f87171;
                --warn: #fbbf24;
                --idle: #3d444d;
                --input-bg: #0d1117;
            }
        }

        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }

        header {
            background: var(--brand);
            color: #fff;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        header .title { font-weight: 700; font-size: 16px; letter-spacing: .2px; }
        header .pill {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,.2);
            white-space: nowrap;
        }

        /* Landscape window (1040×660): columns so everything is visible at
           once instead of stacking into a scroll. auto-fit keeps it sensible
           if the window is resized narrow. */
        main {
            padding: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            align-items: start;
            gap: 16px;
            max-width: 1400px;
            margin: 0 auto;
        }
        /* The status card is the one people actually watch — give it the
           first column and let it span two rows so the shorter cards stack
           neatly beside it rather than leaving a ragged gap. */
        .card--status { grid-row: span 2; }

        /* Full width and visually loud: it appears exactly once, on the very
           first launch, and carries the only copy of the admin password. */
        .card--credentials {
            grid-column: 1 / -1;
            border-color: var(--brand);
            background: var(--brand-soft);
        }
        .cred {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 0;
        }
        .cred span { font-size: 12px; font-weight: 700; color: var(--text-soft); }
        .cred code {
            direction: ltr;
            font-family: Consolas, monospace;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 12px;
            user-select: all;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
        }
        .card h2 {
            margin: 0 0 12px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-faint);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        /* ---- status rows ---- */
        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
        }
        .row:last-child { border-bottom: none; padding-bottom: 0; }
        .row:first-child { padding-top: 0; }
        .label { display: flex; align-items: center; gap: 9px; color: var(--text); font-weight: 500; }
        .dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
        .dot.ok { background: var(--ok); box-shadow: 0 0 0 3px color-mix(in srgb, var(--ok) 18%, transparent); }
        .dot.bad { background: var(--bad); box-shadow: 0 0 0 3px color-mix(in srgb, var(--bad) 18%, transparent); }
        .dot.warn { background: var(--warn); box-shadow: 0 0 0 3px color-mix(in srgb, var(--warn) 18%, transparent); }
        .dot.unknown { background: var(--idle); }
        .value { font-weight: 700; font-size: 12.5px; direction: ltr; text-align: left; white-space: nowrap; }
        .value.ok { color: var(--ok); }
        .value.bad { color: var(--bad); }
        .value.warn { color: var(--warn); }
        .value.unknown { color: var(--text-faint); }
        .placeholder { color: var(--text-faint); font-size: 13px; }

        .banner {
            margin-bottom: 12px;
            padding: 9px 11px;
            border: 1px solid color-mix(in srgb, var(--warn) 45%, transparent);
            border-radius: 9px;
            background: color-mix(in srgb, var(--warn) 12%, transparent);
            color: var(--warn);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.45;
        }

        /* ---- LAN links ---- */
        .lan-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            direction: ltr;
            padding: 10px 12px;
            margin-bottom: 8px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--card);
            color: var(--brand);
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .lan-link:last-child { margin-bottom: 0; }
        .lan-link:hover { border-color: var(--brand); background: var(--brand-soft); }
        .lan-link .hint { font-size: 10px; color: var(--text-faint); font-weight: 600; }

        /* ---- forms ---- */
        .field { margin-bottom: 12px; }
        label { display: block; font-size: 11.5px; font-weight: 700; color: var(--text-soft); margin-bottom: 5px; }
        input[type=text], input[type=number] {
            width: 100%;
            padding: 9px 11px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--input-bg);
            color: var(--text);
            font-size: 13px;
            font-family: inherit;
            direction: ltr;
            text-align: left;
        }
        input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 15%, transparent);
        }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .help { font-size: 11px; color: var(--text-faint); margin: 4px 0 0; }

        /* ---- buttons ---- */
        button {
            border: none;
            border-radius: 10px;
            padding: 11px 14px;
            font-weight: 700;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
            width: 100%;
            transition: filter .15s, opacity .15s;
        }
        button:hover:not(:disabled) { filter: brightness(.95); }
        button:disabled { opacity: .55; cursor: default; }
        .btn-primary { background: var(--brand); color: #fff; margin-top: 6px; }
        .btn-danger { background: transparent; color: var(--bad); border: 1px solid var(--border); margin-top: 10px; }

        .msg { font-size: 12px; margin-top: 9px; font-weight: 600; }
        .msg.ok { color: var(--ok); }
        .msg.bad { color: var(--bad); }
        .muted { color: var(--text-faint); font-size: 11.5px; margin: 0; }
        .divider { border: none; border-top: 1px solid var(--border); margin: 14px 0; }
    </style>
</head>
<body>
    <header>
        <span class="title">Orange Cafe — حالة الخادم</span>
        <span class="pill" id="headerPill">جارِ التحقق…</span>
    </header>

    <main>
        @if ($firstRun)
            {{-- Spans the full grid so it can't be missed: this is the only
                 place the generated admin password is ever shown. --}}
            <div class="card card--credentials" id="credentialsCard">
                <h2>حساب المدير — أول تشغيل</h2>
                <p class="muted" style="margin-bottom:10px;">
                    سجّل الدخول بهذه البيانات من المتصفح، ثم غيّر كلمة المرور فوراً.
                </p>
                <div class="cred"><span>البريد</span><code>{{ $firstRun['email'] }}</code></div>
                <div class="cred"><span>كلمة المرور</span><code>{{ $firstRun['password'] }}</code></div>
                <button type="button" class="btn-primary" id="dismissCredsBtn">حفظتها — أخفِ هذه البطاقة</button>
                <div class="msg" id="credsMsg"></div>
            </div>
        @endif

        <div class="card card--status">
            <h2>حالة النظام</h2>
            {{-- Hidden until the status poll finds saved-but-unapplied settings. --}}
            <div class="banner" id="pendingBanner" style="display:none;">
                إعدادات محفوظة بانتظار إعادة التشغيل — القيم بالأسفل تعكس الإعدادات القديمة
                التي ما زالت تعمل فعلياً.
            </div>
            <div id="status"><p class="placeholder">جارِ التحقق…</p></div>
        </div>

        <div class="card">
            <h2>الشبكة المحلية</h2>
            @if (count($network['lanUrls']))
                <p class="muted" style="margin-bottom:10px;">
                    افتح هذا الرابط من أي جهاز على نفس الشبكة (تابلت النادل، شاشة المطبخ، الكاشير):
                </p>
                @foreach ($network['lanUrls'] as $url)
                    <button type="button" class="lan-link" data-url="{{ $url }}">
                        <span>{{ $url }}</span>
                        <span class="hint">نسخ</span>
                    </button>
                @endforeach
            @else
                <p class="placeholder">تعذر اكتشاف عنوان الشبكة تلقائياً — تأكد من اتصال الجهاز بالشبكة.</p>
            @endif
        </div>

        <div class="card">
            <h2>إعدادات الخادم</h2>
            <form id="settingsForm">
                <div class="field">
                    <label>منفذ خادم الشبكة (LAN Server Port)</label>
                    <input type="number" name="lan_server_port"
                           value="{{ $network['editable']['LAN_SERVER_PORT'] ?: $network['port'] }}">
                </div>
                <div class="grid2">
                    <div class="field">
                        <label>Reverb Host</label>
                        <input type="text" name="reverb_host"
                               value="{{ $network['editable']['REVERB_HOST'] ?: '127.0.0.1' }}">
                    </div>
                    <div class="field">
                        <label>Reverb Port</label>
                        <input type="number" name="reverb_port"
                               value="{{ $network['editable']['REVERB_PORT'] ?: 8080 }}">
                    </div>
                </div>
                <div class="field">
                    <label>عنوان رموز QR للطاولات</label>
                    <input type="text" name="public_base_url" dir="ltr"
                           placeholder="{{ $network['detectedPublicUrl'] }}"
                           value="{{ $network['editable']['PUBLIC_BASE_URL'] }}">
                    <p class="help">
                        العنوان المطبوع على لافتات الطاولات. اتركه فارغاً ليُستخدم العنوان المكتشَف تلقائياً
                        ({{ $network['detectedPublicUrl'] }}). أعد طباعة اللافتات بعد أي تغيير.
                    </p>
                </div>
                <button type="submit" class="btn-primary" id="saveBtn">حفظ الإعدادات</button>
                <div class="msg" id="saveMsg"></div>
            </form>

            <hr class="divider">

            <p class="muted" style="margin-bottom:2px;">التغييرات لا تُطبَّق إلا بعد إعادة التشغيل.</p>
            <button type="button" class="btn-danger" id="restartBtn">إعادة تشغيل الخادم</button>
            <div class="msg" id="restartMsg"></div>
        </div>
    </main>

    <script>
        const statusUrl = @json(route('native.panel.status'));
        const updateUrl = @json(route('native.panel.update'));
        const restartUrl = @json(route('native.panel.restart'));
        const dismissCredsUrl = @json(route('native.panel.credentials.dismiss'));

        const post = (url, body) => fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });

        const setMsg = (id, text, kind) => {
            const el = document.getElementById(id);
            el.textContent = text;
            el.className = 'msg' + (kind ? ' ' + kind : '');
        };

        // state: true → ok, false → bad, null/undefined → unknown, 'warn' → degraded
        function statusRow(label, state, text) {
            const cls = state === true ? 'ok' : state === false ? 'bad' : state === 'warn' ? 'warn' : 'unknown';
            return `<div class="row">
                <span class="label"><span class="dot ${cls}"></span>${label}</span>
                <span class="value ${cls}">${text}</span>
            </div>`;
        }

        function printerRow(printers) {
            if (!printers) return statusRow('الطابعات', null, '—');
            if (printers.active === 0) return statusRow('الطابعات', 'warn', 'لا توجد طابعات مفعّلة');

            const allUp = printers.online === printers.active;
            return statusRow(
                'الطابعات',
                allUp ? true : (printers.online === 0 ? false : 'warn'),
                `${printers.online} / ${printers.active} متصلة`,
            );
        }

        async function refreshStatus() {
            const pill = document.getElementById('headerPill');

            try {
                const data = await (await fetch(statusUrl)).json();

                // Saved settings that are still waiting on a restart. Every
                // reading below describes the OLD configuration, so say so
                // rather than letting the panel look broken.
                const pending = data.pending_restart ?? [];
                const banner = document.getElementById('pendingBanner');
                banner.style.display = pending.length ? 'block' : 'none';

                const rows = [
                    statusRow('قاعدة البيانات', data.database?.ok, data.database?.ok ? 'متصلة' : 'غير متصلة'),
                    statusRow('خادم الشبكة (LAN)', data.lan_server?.ok, `منفذ ${data.lan_server?.port}`),
                    statusRow('البث اللحظي (Reverb)', data.reverb?.ok, `${data.reverb?.host}:${data.reverb?.port}`),
                    statusRow('الطابور (Queue)', data.queue?.process,
                        `انتظار ${data.queue?.pending ?? '—'} / فشل ${data.queue?.failed ?? '—'}`),
                    statusRow('الجدولة', data.scheduler?.process, data.scheduler?.process ? 'تعمل' : 'متوقفة'),
                    // Without pdftoppm no thermal ticket can be rasterized at
                    // all, so this is a hard failure, not a warning.
                    statusRow('الطباعة الحرارية', data.thermal?.ok, data.thermal?.ok ? 'جاهزة' : 'pdftoppm غير موجود'),
                    printerRow(data.printers),
                ];
                document.getElementById('status').innerHTML = rows.join('');

                // The pill summarises only what would stop the restaurant
                // working. Printer counts get their own row: one unplugged
                // printer is a kitchen problem, not a server outage.
                const core = [
                    data.database?.ok, data.lan_server?.ok, data.reverb?.ok,
                    data.queue?.process, data.scheduler?.process, data.thermal?.ok,
                ];
                const down = core.filter((v) => v === false).length;
                pill.textContent = down === 0 ? 'كل الخدمات تعمل' : `${down} خدمة متوقفة`;
            } catch (e) {
                document.getElementById('status').innerHTML = '<p class="placeholder">تعذر جلب حالة الخدمات.</p>';
                pill.textContent = 'غير متصل';
            }
        }

        refreshStatus();
        setInterval(refreshStatus, 5000);

        document.querySelectorAll('.lan-link').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const hint = btn.querySelector('.hint');
                try {
                    await navigator.clipboard.writeText(btn.dataset.url);
                    hint.textContent = 'تم النسخ ✓';
                } catch {
                    hint.textContent = 'تعذر النسخ';
                }
                setTimeout(() => { hint.textContent = 'نسخ'; }, 1500);
            });
        });

        document.getElementById('settingsForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            setMsg('saveMsg', '');

            try {
                const res = await post(updateUrl, {
                    reverb_host: form.reverb_host.value,
                    reverb_port: Number(form.reverb_port.value),
                    lan_server_port: Number(form.lan_server_port.value),
                    public_base_url: form.public_base_url.value.trim() || null,
                });

                if (res.status === 422) {
                    const messages = Object.values((await res.json()).errors || {}).flat();
                    throw new Error(messages.join(' — ') || 'قيم غير صالحة.');
                }
                if (!res.ok) throw new Error('تعذر الحفظ — حاول مرة أخرى.');

                setMsg('saveMsg', 'تم الحفظ. اضغط "إعادة تشغيل الخادم" لتطبيق التغييرات.', 'ok');
            } catch (err) {
                setMsg('saveMsg', err.message || 'تعذر الحفظ.', 'bad');
            } finally {
                btn.disabled = false;
            }
        });

        document.getElementById('dismissCredsBtn')?.addEventListener('click', async (e) => {
            if (!confirm('لن تظهر كلمة المرور مرة أخرى. هل سجّلتها؟')) return;

            e.currentTarget.disabled = true;

            try {
                if (!(await post(dismissCredsUrl)).ok) throw new Error();
                document.getElementById('credentialsCard').remove();
            } catch {
                e.currentTarget.disabled = false;
                setMsg('credsMsg', 'تعذر حذف الملف — احذفه يدوياً من مجلد storage.', 'bad');
            }
        });

        document.getElementById('restartBtn').addEventListener('click', async () => {
            if (!confirm('سيتوقف الخادم مؤقتاً لكل الأجهزة على الشبكة — متأكد؟')) return;

            // On success this window closes and a new one opens, so there is
            // no "done" state to render — only a failure needs reporting.
            setMsg('restartMsg', 'جارِ إعادة التشغيل… سيغلق التطبيق ويفتح خلال ثوانٍ.');

            try {
                if (!(await post(restartUrl)).ok) throw new Error();
            } catch {
                setMsg('restartMsg', 'تعذر إعادة التشغيل — أغلق التطبيق وشغّله يدوياً.', 'bad');
            }
        });
    </script>
</body>
</html>
