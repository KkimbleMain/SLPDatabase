// assets/js/print_report.js
// Small helper that tries to ensure canvases/chart images are captured before calling print.
// Strategy:
// 1. Find <canvas> elements or <img class="chart-img"> that point to canvas-generated images.
// 2. For canvases: ensure backing pixel size is set, call toDataURL, retry a couple times if the result is empty (data:,).
// 3. If any canvas fails to produce a usable image, ask the user whether to fall back to server snapshot generation.

(function(){
    function setCanvasBackingSize(canvas, scale) {
        if (!canvas) return;
        try {
            var w = canvas.width || canvas.offsetWidth || canvas.clientWidth || 600;
            var h = canvas.height || canvas.offsetHeight || canvas.clientHeight || 240;
            // If either dimension is 0, use computed style as fallback
            if (w === 0 || h === 0) {
                var cs = window.getComputedStyle(canvas);
                w = parseInt(cs.width, 10) || 600;
                h = parseInt(cs.height, 10) || 240;
            }
            var devicePixelRatio = window.devicePixelRatio || 1;
            var backingW = Math.max(1, Math.round(w * (scale || devicePixelRatio)));
            var backingH = Math.max(1, Math.round(h * (scale || devicePixelRatio)));
            canvas.width = backingW;
            canvas.height = backingH;
            canvas.style.width = w + 'px';
            canvas.style.height = h + 'px';
            var ctx = canvas.getContext('2d');
            if (ctx && ctx.setTransform) ctx.setTransform(scale || devicePixelRatio, 0, 0, scale || devicePixelRatio, 0, 0);
        } catch (e) {
            // ignore
        }
    }

    function captureCanvasDataURL(canvas, attempts, delay) {
        attempts = attempts || 6;
        delay = delay || 300;
        return new Promise(function(resolve){
            // If Chart.js instance exists for this canvas, prefer its toBase64Image
            var tryChartFirst = function() {
                try {
                    if (window.Chart) {
                        // Chart.js v3+ has Chart.getChart(canvas)
                        if (typeof Chart.getChart === 'function') {
                            var ch = Chart.getChart(canvas);
                            if (ch && typeof ch.toBase64Image === 'function') return ch.toBase64Image();
                        } else if (Chart.instances) {
                            var ci = Object.values(Chart.instances).find(function(x){ return x && x.canvas && x.canvas === canvas; });
                            if (ci && typeof ci.toBase64Image === 'function') return ci.toBase64Image();
                        }
                    }
                } catch (e) {
                    // ignore
                }
                return null;
            };

            var tryCapture = function(remaining) {
                try {
                    var chartData = tryChartFirst();
                    if (chartData && chartData.length > 10 && chartData.indexOf('data:,') !== 0) {
                        resolve(chartData);
                        return;
                    }
                } catch (e) {
                    // ignore
                }
                try {
                    var data = canvas.toDataURL('image/png');
                    if (data && data.length > 10 && data.indexOf('data:,') !== 0) {
                        resolve(data);
                        return;
                    }
                } catch (e) {
                    // ignore
                }
                if (remaining <= 0) {
                    resolve(null);
                } else {
                    setTimeout(function(){ tryCapture(remaining - 1); }, delay);
                }
            };
            tryCapture(attempts);
        });
    }

    async function ensureChartsReady() {
        var canvases = Array.from(document.querySelectorAll('canvas'));
        var failed = [];
        for (var i=0;i<canvases.length;i++) {
            var c = canvases[i];
            setCanvasBackingSize(c, window.devicePixelRatio || 1);
            var data = await captureCanvasDataURL(c, 6, 300);
            if (!data) {
                failed.push(c);
            } else {
                // Create or replace an <img> so printing uses a stable image instead of canvas
                try {
                    var id = c.id || c.getAttribute('data-canvas-id') || null;
                    var img = null;
                    if (id) img = document.querySelector('img[data-canvas-id="' + id + '"]');
                    // if no existing image, create one and insert after the canvas
                    if (!img) {
                        img = document.createElement('img');
                        img.className = 'chart-img print-replaced';
                        if (id) img.setAttribute('data-canvas-id', id);
                        // preserve visual size
                        try {
                            img.style.maxWidth = '100%';
                            img.style.height = 'auto';
                            img.style.display = 'block';
                            img.style.margin = getComputedStyle(c).margin || '';
                        } catch(e) {}
                        if (c.parentNode) c.parentNode.insertBefore(img, c.nextSibling);
                    }
                    img.src = data;
                    // hide canvas visually for the print to ensure browsers use the <img>
                    try { c.style.visibility = 'hidden'; c.setAttribute('data-print-hidden','1'); } catch(e) {}
                } catch (e) {
                    // ignore individual failures
                }
            }
        }
        // if some failed, still attempt print (we converted what we could)
        return failed.length === 0;
    }

    // Restore canvases replaced for printing when printing completes or page is unloaded
    function restorePrintReplacements() {
        try {
            Array.from(document.querySelectorAll('img.print-replaced')).forEach(function(img){
                var cid = img.getAttribute('data-canvas-id');
                var canvas = cid ? document.getElementById(cid) : (img.previousElementSibling && img.previousElementSibling.tagName === 'CANVAS' ? img.previousElementSibling : null);
                if (canvas && canvas.getAttribute('data-print-hidden') === '1') {
                    canvas.style.visibility = '';
                    canvas.removeAttribute('data-print-hidden');
                }
                img.parentNode && img.parentNode.removeChild(img);
            });
        } catch (e) { /* ignore */ }
    }

    // Ensure we restore after print (modern browsers support afterprint)
    if (typeof window !== 'undefined') {
        try { window.addEventListener('afterprint', restorePrintReplacements); } catch(e) {}
        // also cleanup on page hide/unload to avoid leaving images behind
        try { window.addEventListener('pagehide', restorePrintReplacements); window.addEventListener('beforeunload', restorePrintReplacements); } catch(e) {}
    }

    // Export helpers so other modules can reuse the same capture logic
    try {
        if (typeof window !== 'undefined') {
            window.prepareChartsForPrint = ensureChartsReady;
            window.restoreChartsFromPrint = restorePrintReplacements;
            window.captureCanvasDataURL = captureCanvasDataURL;
        }
    } catch (e) { /* ignore */ }

    async function onPrintClick(e) {
        e.preventDefault();
        var ok = await ensureChartsReady();
        if (ok) {
            // small delay to ensure DOM updates
            setTimeout(function(){ window.print(); }, 250);
            return;
        }
        // respect client-only flag on the button
        var clientOnly = false;
        try { clientOnly = !!(e.currentTarget && e.currentTarget.getAttribute && e.currentTarget.getAttribute('data-client-only') === '1'); } catch (err) { clientOnly = false; }
        if (clientOnly) {
            alert('Some charts failed to capture. Client-only mode is enabled so a server snapshot will not be generated. Try reloading the page or using the browser Print after ensuring charts are visible.');
            return;
        }
        // If not ok, ask user whether to fall back to server snapshot generation
    if (!await (window.showConfirm ? window.showConfirm('Some charts failed to capture reliably in this browser. Would you like to generate a server snapshot (recommended) and then open it to print?') : Promise.resolve(confirm('Some charts failed to capture reliably in this browser. Would you like to generate a server snapshot (recommended) and then open it to print?')))) return;
        // Call server snapshot endpoint via POST to includes/submit.php?action=save_student_report_snapshot
        // Use form POST to open the resulting snapshot in a new tab
        try {
            var form = document.createElement('form');
            form.method = 'POST';
            form.target = '_blank';
            form.action = '/includes/submit.php?action=save_student_report_snapshot';
            // If the page has a student_id field or data attribute, include it
            var sid = document.querySelector('[data-student-id]') ? document.querySelector('[data-student-id]').getAttribute('data-student-id') : null;
            if (!sid) {
                // fallback try common IDs
                var el = document.querySelector('#student_id');
                if (el) sid = el.value;
            }
            if (sid) {
                var inpt = document.createElement('input'); inpt.type='hidden'; inpt.name='student_id'; inpt.value=sid; form.appendChild(inpt);
            }
            document.body.appendChild(form);
            form.submit();
            // remove the form after a short time
            setTimeout(function(){ document.body.removeChild(form); }, 2000);
        } catch (err) {
            alert('Failed to request server snapshot: ' + (err && err.message ? err.message : err));
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        var btn = document.getElementById('print-report-btn');
        if (!btn) return;
        btn.addEventListener('click', onPrintClick);
    });
})();
