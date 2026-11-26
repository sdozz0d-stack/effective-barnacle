<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

ensure_auth_schema($conn);
require_admin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Positions</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        :root {
            color-scheme: light dark;
            --ink: #202a35;
            --muted: #687584;
            --line: #d5dce2;
            --paper: #fff;
            --canvas: #edf1f3;
            --field: #fff;
            --head: #f4f7f8;
            --green: #107c41;
            --green-dark: #0b5c30;
            --error: #a5332d;
            --warn: #805b00;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body { margin: 0; min-height: 100vh; padding: clamp(16px, 2.5vh, 30px) clamp(14px, 3vw, 42px); background: var(--canvas); color: var(--ink); font: 15px "Segoe UI", Arial, sans-serif; }
        main { max-width: 960px; margin: 0 auto; padding: clamp(20px, 3vw, 32px); background: var(--paper); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 10px 28px rgba(15, 23, 42, .08), 0 0 0 1px rgba(16, 124, 65, .08), 0 0 24px rgba(16, 124, 65, .08); }
        h1 { margin: 0 0 8px; font-size: clamp(28px, 4vw, 36px); letter-spacing: -.03em; }
        label { display: block; margin: 16px 0 6px; font-weight: 600; }
        input, textarea, select, button { font: inherit; }
        input[type="text"], select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #b8c2cb; border-radius: 8px; background: var(--field); color: var(--ink); }
        textarea { min-height: 260px; resize: vertical; font-family: ui-monospace, Consolas, monospace; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        button, .button { display: inline-block; padding: 9px 14px; border: 1px solid var(--green); border-radius: 8px; background: var(--green); color: #fff; text-decoration: none; cursor: pointer; font-weight: 600; }
        button:hover, .button:hover { background: var(--green-dark); }
        button.secondary, .button.secondary { background: var(--paper); color: var(--green); }
        button.secondary:hover, .button.secondary:hover { background: rgba(16,124,65,.08); }
        button:disabled { opacity: .6; cursor: wait; }
        .hint, #message { color: var(--muted); }
        #message.error { color: var(--error); }
        #preview { overflow-x: auto; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border: 1px solid var(--line); text-align: left; }
        th { background: var(--head); }
        .status-error { color: var(--error); font-weight: 600; }
        .status-update { color: var(--warn); font-weight: 600; }
        .status-new { color: var(--green); font-weight: 600; }
        html[data-theme="dark"] {
            color-scheme: dark;
            --ink: #f5f5f5;
            --muted: #a1a1aa;
            --line: #2f2f33;
            --paper: #1c1c1e;
            --canvas: #121212;
            --field: #2c2c2e;
            --head: #242426;
            --green: #107c41;
            --green-dark: #0b5c30;
            --error: #ffb4ab;
            --warn: #f6d365;
        }
        html[data-theme="dark"] {
            --ink: #f3f7f4;
            --muted: #b8c9be;
            --line: #304038;
            --paper: #18241d;
            --canvas: #121815;
            --field: #243029;
            --field-line: #3b4a42;
            --head: #213128;
        }
        html[data-theme="dark"] body { min-height: 100vh; background: var(--canvas); color: var(--ink); }
        html[data-theme="dark"] main { background: var(--paper); border-color: rgba(52,211,153,.18); box-shadow: 0 0 0 1px rgba(52,211,153,.06), 0 22px 52px rgba(0,0,0,.45), 0 0 36px rgba(16,185,129,.10); }
        html[data-theme="dark"] h1 { color: #6ee7a8; }
        html[data-theme="dark"] a { color: #6ee7a8; }
        html[data-theme="dark"] label { color: #e8f0eb; }
        html[data-theme="dark"] .hint { color: #b8c9be; }
        html[data-theme="dark"] input[type="text"],
        html[data-theme="dark"] select,
        html[data-theme="dark"] textarea { border-color: var(--field-line); background: var(--field); color: var(--ink); }
        html[data-theme="dark"] button.secondary,
        html[data-theme="dark"] .button.secondary { border-color: var(--field-line); background: var(--field); color: #e8f0eb; }
        html[data-theme="dark"] button.secondary:hover,
        html[data-theme="dark"] .button.secondary:hover { background: #2d3d34; border-color: #4a6a56; }
        html[data-theme="dark"] th { background: var(--head); color: #c9d8cf; }
        html[data-theme="dark"] td { background: var(--paper); color: #e8f0eb; border-color: var(--line); }
        html[data-theme="dark"] th { border-color: var(--line); }
        html[data-theme="dark"] tbody tr:hover td { background: #213128; }
        @media (max-width: 600px) { body { padding: 12px; } main { padding: 16px; border-radius: 12px; } }
    </style>
</head>
<body>
<main>
    <h1>Import Positions</h1>
    <p class="hint">Paste rows as title, salary_grade, level. Excel tab-separated rows are also accepted. Level may be B, I, or blank.</p>
    <label for="scope">Position scope</label>
    <select id="scope">
        <option value="REGIONAL_OFFICE" selected>REGIONAL_OFFICE</option>
    </select>
    <label for="newScope">Or type a new scope code</label>
    <input id="newScope" type="text" maxlength="40" placeholder="For example: PENRO">
    <label for="csv">Positions CSV</label>
    <textarea id="csv" spellcheck="false" placeholder="title,salary_grade,level&#10;Planning Officer III,18,B"></textarea>
    <div class="actions">
        <button type="button" class="secondary" id="previewButton">Preview</button>
        <button type="button" id="importButton">Import</button>
        <a class="button secondary" href="dashboard.php">Back to Dashboard</a>
        <a class="button secondary" href="template_manager.php">Back to Template Manager</a>
    </div>
    <p id="message" role="status" aria-live="polite"></p>
    <div id="preview"></div>
</main>
<script>
    const csvInput = document.getElementById('csv');
    const scopeSelect = document.getElementById('scope');
    const newScopeInput = document.getElementById('newScope');
    const previewEl = document.getElementById('preview');
    const messageEl = document.getElementById('message');
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    const previewButton = document.getElementById('previewButton');
    const importButton = document.getElementById('importButton');
    let existingTitles = new Set();
    let lastPreview = null;
    let importInProgress = false;

    function currentScope() {
        return (newScopeInput.value.trim() || scopeSelect.value).toUpperCase();
    }

    function splitLine(line) {
        if (line.includes('\t')) return line.split('\t').map(value => value.trim());
        const fields = [];
        let value = '';
        let quoted = false;
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"' && quoted && line[i + 1] === '"') {
                value += '"';
                i++;
            } else if (char === '"') {
                quoted = !quoted;
            } else if (char === ',' && !quoted) {
                fields.push(value.trim());
                value = '';
            } else {
                value += char;
            }
        }
        fields.push(value.trim());
        return fields;
    }

    function parseForPreview(text) {
        const rows = [];
        const errors = [];
        text.split(/\r\n|\n|\r/).forEach((line, index) => {
            const lineNumber = index + 1;
            if (!line.trim()) return;
            const fields = splitLine(line);
            if (fields.length >= 2
                && ['title', 'position', 'position title'].includes(fields[0].toLowerCase())
                && ['salary_grade', 'salary grade', 'sg'].includes(fields[1].toLowerCase())) return;
            if (fields.length !== 3) {
                errors.push({ line: lineNumber, title: line, status: 'Error', message: 'Expected three columns.' });
                return;
            }
            const [title, grade, rawLevel] = fields;
            const level = rawLevel.toUpperCase();
            if (!title || title.length > 255) {
                errors.push({ line: lineNumber, title, status: 'Error', message: 'Title must contain 1 to 255 characters.' });
            } else if (!/^(?:[1-9]|[12][0-9]|3[0-3])$/.test(grade)) {
                errors.push({ line: lineNumber, title, status: 'Error', message: 'Salary grade must be an integer from 1 to 33.' });
            } else if (!['', 'B', 'I'].includes(level)) {
                errors.push({ line: lineNumber, title, status: 'Error', message: 'Level must be blank, B, or I.' });
            } else {
                rows.push({ line: lineNumber, title, salary_grade: Number(grade), leadership_level: level });
            }
        });
        return { rows, errors };
    }

    async function loadExistingTitles() {
        const params = new URLSearchParams({ action: 'list', scope: currentScope() });
        const response = await fetch('positions_api.php?' + params.toString(), { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Could not load existing positions.');
        existingTitles = new Set(data.positions.map(position => position.title.toLocaleLowerCase()));
    }

    async function preview() {
        if (importInProgress) return null;
        messageEl.textContent = 'Loading existing positions…';
        messageEl.className = '';
        try {
            await loadExistingTitles();
            const parsed = parseForPreview(csvInput.value);
            const items = [
                ...parsed.rows.map(row => ({ ...row, status: existingTitles.has(row.title.toLocaleLowerCase()) ? 'Update' : 'New', message: '' })),
                ...parsed.errors
            ].sort((a, b) => a.line - b.line);
            const table = document.createElement('table');
            const thead = document.createElement('thead');
            const header = document.createElement('tr');
            ['Line', 'Title', 'Salary grade', 'Level', 'Status', 'Details'].forEach(label => {
                const th = document.createElement('th');
                th.textContent = label;
                header.appendChild(th);
            });
            thead.appendChild(header);
            table.appendChild(thead);
            const tbody = document.createElement('tbody');
            items.forEach(row => {
                const tr = document.createElement('tr');
                const values = [row.line, row.title, row.salary_grade ?? '', row.leadership_level ?? '', row.status, row.message];
                values.forEach((value, index) => {
                    const td = document.createElement('td');
                    td.textContent = String(value);
                    if (index === 4) td.className = row.status === 'Error' ? 'status-error' : row.status === 'Update' ? 'status-update' : 'status-new';
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            previewEl.replaceChildren(table);
            messageEl.textContent = items.length ? 'Preview ready: ' + parsed.rows.length + ' valid row(s), ' + parsed.errors.length + ' error(s).' : 'No position rows found.';
            lastPreview = {
                csv: csvInput.value,
                scope: currentScope(),
                errorCount: parsed.errors.length
            };
            return lastPreview;
        } catch (error) {
            lastPreview = null;
            messageEl.textContent = error.message;
            messageEl.className = 'error';
            return null;
        }
    }

    previewButton.addEventListener('click', preview);
    importButton.addEventListener('click', async () => {
        if (importInProgress) return;
        if (!lastPreview || lastPreview.csv !== csvInput.value || lastPreview.scope !== currentScope()) {
            if (!await preview()) return;
        }
        if (lastPreview.errorCount > 0
            && !window.confirm(`The preview contains ${lastPreview.errorCount} invalid row(s). Valid rows will still be imported and invalid rows skipped. Continue?`)) {
            return;
        }
        importInProgress = true;
        importButton.disabled = true;
        previewButton.disabled = true;
        messageEl.textContent = 'Importing…';
        messageEl.className = '';
        try {
            const body = new FormData();
            body.set('action', 'import');
            body.set('scope', currentScope());
            body.set('csv', csvInput.value);
            body.set('csrf_token', csrfToken);
            const response = await fetch('positions_api.php', { method: 'POST', body, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                const lines = (data.errors || []).map(error => 'Line ' + error.line + ': ' + error.message).join(' ');
                throw new Error((data.message || 'Import failed.') + (lines ? ' ' + lines : ''));
            }
            const lines = (data.errors || []).map(error => 'Line ' + error.line + ': ' + error.message).join(' ');
            messageEl.textContent = 'Import complete: ' + data.inserted + ' inserted, ' + data.updated + ' updated, ' + data.skipped + ' skipped.'
                + (lines ? ' ' + lines : '');
            lastPreview = null;
            await loadExistingTitles();
        } catch (error) {
            messageEl.textContent = error.message;
            messageEl.className = 'error';
        } finally {
            importInProgress = false;
            importButton.disabled = false;
            previewButton.disabled = false;
        }
    });
    csvInput.addEventListener('input', () => { lastPreview = null; });
    newScopeInput.addEventListener('input', () => {
        existingTitles = new Set();
        lastPreview = null;
    });
    scopeSelect.addEventListener('change', () => {
        existingTitles = new Set();
        lastPreview = null;
    });
</script>
</body>
</html>
