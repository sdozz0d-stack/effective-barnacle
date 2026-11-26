<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/AccessGroups.php';
require_once __DIR__ . '/Raters.php';
require_once __DIR__ . '/PositionsSchema.php';

ensure_auth_schema($conn);
require_admin();
ensure_positions_schema($conn);
$regionCatalogStmt = $conn->prepare("SELECT title, salary_grade
    FROM positions
    WHERE office_scope = 'REGIONAL_OFFICE'
    ORDER BY salary_grade DESC, title ASC");
$regionCatalogStmt->execute();
$regionCatalogPositions = $regionCatalogStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$regionCatalogStmt->close();
$regionSheetNames = [];
$fieldCatalogSheets = [];
foreach (OFFICE_TYPE_SHEET_MAP as $sheetName => $sheetMapping) {
    if (($sheetMapping['office_type'] ?? '') === 'RO') $regionSheetNames[] = $sheetName;
}
foreach (FIELD_POSITION_SHEETS as $sheetName => $sheetMapping) {
    $fieldCatalogSheets[] = [
        'name' => $sheetName,
        'min_salary_grade' => (int)$sheetMapping['min_salary_grade'],
        'max_salary_grade' => (int)$sheetMapping['max_salary_grade'],
        'positions' => array_values($sheetMapping['positions']),
    ];
}
$defaultLeadershipRowsJson = json_encode(
    leadership_rows_for_level('B'),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$advancedLeadershipRowsJson = json_encode(
    leadership_rows_for_level('I'),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$officeSheetChoices = [];
$officeSheetLabels = [
    'competency_RO' => 'General',
    'competency_RO_Basic' => 'Basic',
    'advance_RO' => 'Advanced',
    'competency_Basic_FO' => 'Basic',
    'competency_Intermediate_FO' => 'Intermediate',
    'competency_advance_FO' => 'Advanced',
];
foreach (OFFICE_TYPE_SHEET_MAP as $sheetName => $sheetMapping) {
    if (in_array($sheetMapping['office_type'] ?? '', ['RO', 'FO'], true)) {
        $officeSheetChoices[$sheetName] = $sheetMapping['office_type'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Competency Templates</title>
    <link rel="stylesheet" href="ui-enhancements.css?v=<?php echo filemtime(__DIR__ . '/ui-enhancements.css'); ?>">
    <script src="ui-enhancement.js?v=<?php echo filemtime(__DIR__ . '/ui-enhancement.js'); ?>" defer></script>
    <style>
        :root {
            color-scheme: light;
            --ink: #202a35;
            --muted: #687584;
            --line: #d5dce2;
            --paper: #fff;
            --canvas: #edf1f3;
            --green: #107c41;
            --green-dark: #0b5f32;
        }
        html[data-theme="dark"] { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 14px "Segoe UI", Calibri, sans-serif; }
        button, input, textarea, select { font: inherit; }
        .app { min-height: 100vh; display: flex; flex-direction: column; }
        .topbar { display: flex; align-items: center; gap: 14px; min-height: 60px; padding: 10px 22px; background: #fff; border-bottom: 1px solid var(--line); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 650; }
        .brand-mark { width: 28px; height: 28px; display: grid; place-items: center; border-radius: 6px; background: var(--green); color: white; font-size: 18px; font-weight: 700; }
        .doc-title { width: min(360px, 35vw); min-width: 140px; border: 1px solid transparent; border-radius: 4px; padding: 7px 9px; color: var(--ink); font-weight: 600; }
        .doc-title:hover, .doc-title:focus { border-color: #aebbc5; outline: none; }
        .top-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .user-name { color: var(--muted); white-space: nowrap; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 0 12px; border: 1px solid #b8c2cb; border-radius: 4px; background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; }
        .button:hover { background: #f4f7f8; }
        .button.primary { border-color: var(--green); background: var(--green); color: #fff; font-weight: 650; }
        .button.primary:hover { background: var(--green-dark); }
        .button.danger { color: #a5332d; }
        .toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 10px 18px; background: #f8fafb; border-bottom: 1px solid var(--line); }
        .save-status { margin-left: auto; color: var(--muted); font-size: 12px; }
        .save-status[data-state="error"] { color: #a5332d; }
        .template-tabs { display: flex; align-items: stretch; min-height: 42px; padding: 0 16px; gap: 2px; overflow-x: auto; background: #e3e8eb; border-bottom: 1px solid #c6d0d7; }
        .template-tab { flex: 0 0 auto; max-width: 240px; padding: 11px 14px 9px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #51606b; cursor: pointer; border: 0; border-bottom: 3px solid transparent; background: transparent; }
        .template-tab.active { background: #fff; color: var(--green-dark); border-color: var(--green); font-weight: 650; }
        .content { flex: 1; min-height: 0; overflow: auto; padding: 18px; }
        .paper { min-height: 300px; padding: 20px; background: var(--paper); border: 1px solid #cbd4da; box-shadow: 0 2px 8px rgba(24, 43, 56, .08); }
        .empty-state { max-width: 560px; margin: 8vh auto; padding: 32px; text-align: center; }
        .empty-state h1 { margin: 0 0 8px; font-size: 23px; font-weight: 650; }
        .empty-state p { margin: 0 0 20px; color: var(--muted); line-height: 1.5; }
        .section-table { width: 100%; margin: 0 0 22px; border-collapse: collapse; table-layout: fixed; }
        .section-table th, .section-table td { border: 1px solid #111; padding: 6px 8px; vertical-align: middle; }
        .section-table .sec-header { background: #d9d9d9; font-weight: bold; text-align: center; vertical-align: middle; }
        .section-table .sec-label, .section-table .sec-level, .section-table .sec-rating-label { width: 100%; border: 0; background: transparent; font-weight: bold; text-align: center; }
        .section-table .sec-label { width: 100%; flex: 1 1 auto; }
        .section-table .sec-header-inner, .section-table .comp-title-inner { display: flex; align-items: center; gap: 6px; width: 100%; }
        .section-table .comp-title-inner { align-items: flex-start; }
        .section-table .comp-title { font-weight: bold; }
        .section-table input.row-title, .section-table textarea.row-desc { width: 100%; border: 0; resize: vertical; background: transparent; }
        .section-table input.row-title { font-weight: bold; flex: 1 1 auto; width: auto; min-width: 0; }
        .section-table .rating-placeholder { text-align: center; font-weight: bold; }
        .section-table .evidence-placeholder { color: #555; }
        .del-row-btn { border: 0; background: transparent; color: #a5332d; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px 4px; border-radius: 4px; flex: 0 0 auto; }
        .del-row-btn:hover { background: rgba(165, 51, 45, 0.08); }
        .del-row-btn:focus-visible { outline: 2px solid rgba(165, 51, 45, 0.45); outline-offset: 2px; }
        .section-actions { margin: -14px 0 24px; }
        .section-note { color: var(--muted); font-size: 12px; }
        .hidden { display: none !important; }
        @media (max-width: 700px) {
            .topbar { flex-wrap: wrap; gap: 8px; padding: 10px 12px; }
            .doc-title { flex: 1; width: auto; }
            .top-actions { width: 100%; margin-left: 0; justify-content: flex-end; }
            .user-name { margin-right: auto; }
            .content { padding: 10px; }
            .paper { padding: 10px; overflow-x: auto; }
            .section-table { min-width: 640px; }
        }
        @media print {
            .topbar, .toolbar, .template-tabs, .section-actions, .del-row-btn, .section-note { display: none !important; }
            .content { padding: 0; overflow: visible; }
            .paper { border: 0; box-shadow: none; }
        }
        body { height: 100vh; display: flex; overflow: hidden; background: #f8f9fa; color: #000; font-family: Calibri, "Segoe UI", Arial, sans-serif; }
        .rail { width: 188px; flex: 0 0 188px; display: flex; flex-direction: column; gap: 4px; padding: 12px 10px; background: #0d0d0d; }
        .rail button { display: flex; align-items: center; justify-content: flex-start; gap: 10px; width: 100%; border: 0; border-left: 3px solid transparent; border-radius: 10px; padding: 11px 12px; background: none; color: #fff; font-size: 14px; cursor: pointer; text-align: left; }
        .rail button:hover { background: #1c1c1c; }
        .rail button.active { border-left-color: #6ea8fe; background: rgba(110,168,254,.12); }
        .rail .spacer { flex: 1; min-height: 8px; }
        .rail .small { font-size: 13px; }
        .rail .office-nav { border-top: 1px solid var(--line); margin-top: 4px; padding-top: 14px; font-weight: 650; }
        .rail form { margin: 0; width: 100%; }
        .rail form button { width: 100%; }
        #homeView { flex: 1; overflow: auto; padding: 24px 40px; background: #fff; }
        #homeView h1 { margin: 0 0 22px; font-size: 24px; }
        #editorView { flex: 1; min-width: 0; display: none; flex-direction: column; background: #fff; }
        .bar { display: flex; align-items: center; gap: 14px; padding: 8px 14px; background: #2b579a; color: #fff; }
        .bar button { border: 0; border-radius: 4px; padding: 6px 12px; background: rgba(255,255,255,.15); color: #fff; cursor: pointer; font-size: 13px; white-space: nowrap; }
        .bar button:hover { background: rgba(255,255,255,.28); }
        .bar button.primary { background: #107c41; }
        .bar button.primary:hover { background: #0b5c30; }
        #templateTitle { min-width: 100px; max-width: 360px; border: 1px solid transparent; border-radius: 4px; padding: 6px 8px; background: transparent; color: #fff; font-size: 16px; font-weight: bold; }
        #templateTitle:focus, #templateTitle:hover { border-color: rgba(255,255,255,.5); outline: none; }
        #templateTitle::placeholder { color: rgba(255,255,255,.75); }
        .save-status { margin-left: auto; color: #fff; white-space: nowrap; }
        .save-status[data-state="error"] { color: #ffd1cc; }
        .gridwrap { flex: 1; min-height: 0; overflow: auto; display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 24px; background: #f8f9fa; }
        .editor-toolbar { width: 100%; max-width: 960px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; margin-bottom: 0; padding: 12px 14px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 4px 12px rgba(15,23,42,.04); }
        .editor-toolbar .button { border-color: #107c41; background: #e8f5e9; color: #107c41; font-weight: bold; }
        .editor-toolbar .button:hover:not(:disabled) { background: #107c41; color: #fff; }
        .editor-toolbar .button:disabled { cursor: not-allowed; opacity: .5; }
        .editor-toolbar select { min-height: 34px; max-width: 240px; border: 1px solid #b8c2cb; border-radius: 4px; padding: 0 8px; background: #fff; }
        .editor-toolbar .scope-select { max-width: 190px; }
        .sheet-label-editor { width: 100%; max-width: 960px; margin-bottom: 0; padding: 12px 14px; background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 4px 12px rgba(15,23,42,.03); }
        .sheet-label-editor summary { cursor: pointer; font-weight: 650; }
        .sheet-label-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-top: 12px; }
        .sheet-label-grid label { display: grid; gap: 4px; color: var(--muted); font-size: 12px; }
        .sheet-label-grid input { width: 100%; min-height: 34px; padding: 6px 8px; border: 1px solid var(--line); border-radius: 4px; color: var(--ink); }
        .excel-tabs-container { width: 100%; max-width: 960px; display: flex; align-items: flex-end; gap: 5px; padding: 8px 10px 0; border: 1px solid #b0b0b0; border-bottom: 0; border-radius: 10px 10px 0 0; background: #e1e1e1; }
        .template-tabs { min-height: 0; flex: 1; align-items: flex-end; gap: 3px; padding: 0; overflow-x: auto; border: 0; background: transparent; }
        .template-tab { flex: 0 0 auto; padding: 6px 14px; border: 1px solid #a6a6a6; border-bottom: 0; border-radius: 4px 4px 0 0; background: #d6d6d6; color: #444; font-size: 12px; font-weight: 600; }
        .template-tab.active { border-top: 3px solid #107c41; border-bottom: 1px solid #fff; background: #fff; color: #107c41; font-weight: bold; }
        .content { width: 100%; max-width: 960px; flex: 0 0 auto; padding: 0; overflow: visible; }
        .paper { min-height: 300px; padding: 24px; border: 0; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .section-table { font: 13px Calibri, Arial, sans-serif; color: #000; }
        .section-table th, .section-table td { padding: 6px 8px; }
        .section-table .sec-header { background: #d9d9d9; }
        .section-table input.row-title, .section-table textarea.row-desc { font: inherit; }
        .section-table .rating-placeholder { color: #555; }
        .section-actions { margin: 0 0 20px; }
        #templateList .sheet-row { cursor: pointer; }
        #templateList .name { width: 100%; border: 0; padding: 0; background: transparent; color: inherit; text-align: left; cursor: pointer; }
        #templateList .name:focus-visible { outline: 2px solid #107c41; outline-offset: 3px; }
        #templateList .col-date { width: 200px; }
        #templateList .col-actions { width: 40px; }
        #templateList .delete-sheet { padding: 4px; opacity: .82; visibility: visible; transition: opacity .15s ease, color .15s ease; }
        #templateList .sheet-row:hover .delete-sheet,
        #templateList .sheet-row:focus-within .delete-sheet,
        #templateList .sheet-row.show-actions .delete-sheet,
        #templateList .delete-sheet:hover { opacity: 1; }
        #templateList .delete-sheet:focus-visible { outline: 2px solid #c0392b; outline-offset: 2px; }
        #templateList .empty-list { padding: 20px 12px; color: #687584; }
        #templateList .template-group-heading { margin: 18px 0 0; padding: 10px 12px; border-bottom: 1px solid var(--line); background: #f4f7f5; color: var(--green-dark); font-size: 15px; font-weight: 700; }
        #templateList .template-group-heading:first-child { margin-top: 0; }
        .template-group-jumps { display: flex; flex-wrap: wrap; gap: 8px; margin: -10px 0 14px; }
        .template-group-jumps .button { min-width: 100px; }
        .templates { display: flex; gap: 22px; padding-bottom: 22px; border-bottom: 1px solid #ccc; margin-bottom: 20px; }
        .tpl { width: 225px; padding: 18px 0 12px; border: 2px solid transparent; border-radius: 4px; background: none; color: #000; text-align: center; cursor: pointer; }
        .tpl:hover { border-color: #107c41; background: #eef2f5; }
        .tpl .page { width: 90px; height: 115px; margin: 0 auto 14px; border: 1px solid #999; background: #fff; }
        #search { width: 300px; max-width: 100%; margin-bottom: 20px; padding: 10px 14px; border: 1px solid #ccc; }
        .list-head { display: flex; padding: 0 12px 10px; border-bottom: 1px solid #ccc; color: #555; font-size: 14px; }
        .list-head > div:first-child { flex: 1; }
        .list-head .name-heading { display: flex; align-items: center; gap: 12px; }
        .list-head .name-heading label { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .list-actions { display: flex; align-items: center; gap: 12px; min-height: 38px; margin: -8px 0 10px; }
        #bulkDeleteStatus { color: #687584; }
        .list-head .col-date { width: 200px; }
        .list-head .col-actions { width: 40px; }
        .sheet-row { display: flex; align-items: center; min-height: 60px; padding: 0 12px; border-bottom: 1px solid #eee; background: #fff; }
        .sheet-row:hover { background: #f4f8f4; }
        .sheet-row .name { flex: 1; font-size: 16px; }
        .sheet-row .name small { display: block; color: #777; font-size: 12px; }
        .sheet-row .col-date { width: 200px; color: #555; font-size: 13px; }
        .sheet-row .col-actions { width: 40px; text-align: right; }
        #officeCatalogView { max-width: 1200px; margin: 0 auto; }
        #officeCatalogView h1 { margin-bottom: 8px; }
        .catalog-summary { color: var(--muted); line-height: 1.5; }
        .catalog-card { margin: 18px 0; padding: 18px; overflow-x: auto; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .catalog-card h2 { margin: 0 0 6px; font-size: 18px; }
        .catalog-card p { margin: 0 0 12px; color: var(--muted); }
        .catalog-sheet-list { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
        .catalog-sheet { padding: 7px 10px; border: 1px solid var(--line); border-radius: 7px; background: #f7faf8; color: var(--ink); font: inherit; font-size: 13px; cursor: pointer; }
        .catalog-sheet:hover { border-color: var(--green); background: #eef7f0; }
        .catalog-sheet:focus-visible { outline: 2px solid var(--green); outline-offset: 2px; }
        .catalog-card[id] { scroll-margin-top: 16px; }
        .catalog-table { width: 100%; border-collapse: collapse; }
        .catalog-table th, .catalog-table td { padding: 9px 10px; border: 1px solid var(--line); text-align: left; vertical-align: top; }
        .catalog-table th { background: #f4f7f5; color: var(--muted); font-size: 12px; }
        .catalog-empty { padding: 14px; border: 1px dashed var(--line); border-radius: 8px; color: var(--muted); }
        .delete-sheet { border: 0; background: none; color: #c0392b; font-size: 16px; cursor: pointer; }
        @media (max-width: 700px) {
            .rail { width: 68px; flex-basis: 68px; }
            .rail button { font-size: 11px; }
            #homeView { padding: 20px 14px; }
            .gridwrap { padding: 10px; }
            .bar { flex-wrap: wrap; }
            .bar .save-status { margin-left: 0; }
            #templateTitle { flex: 1; }
            .list-head .col-date, .sheet-row .col-date { width: 115px; }
            .section-table { min-width: 640px; }
            .paper { overflow-x: auto; }
        }
        :root {
            --ink: #1c2420;
            --muted: #6b756f;
            --line: #d6ddd8;
            --canvas: #e3e9e5;
            --green: #107c41;
            --green-dark: #0b5c30;
        }
        body { background: var(--canvas); color: var(--ink); font-family: "Segoe UI", system-ui, sans-serif; }
        .rail { background: #fff; border-right: 1px solid var(--line); }
        .rail button { border-left-color: transparent; color: var(--ink); }
        .rail button:hover { background: #f4f7f5; }
        .rail button.active { border-left-color: var(--green); background: #e3f1d9; color: var(--green-dark); font-weight: 700; }
        body.template-manager-page > nav.rail { gap: 6px; padding: 14px 10px; background: #fff; border-right: 1px solid var(--line); box-shadow: 2px 0 14px rgba(15,23,42,.04); }
        body.template-manager-page > nav.rail button { color: var(--ink); }
        body.template-manager-page > nav.rail button:hover { background: #f4f7f5; }
        body.template-manager-page > nav.rail button.active { border-left-color: var(--green); background: #e3f1d9; color: var(--green-dark); }
        body.template-manager-page > nav.rail .office-nav { border-top-color: #e0e7e2; }
        #homeView { padding: 24px clamp(16px, 4vw, 40px); background: var(--canvas); }
        #homeView h1 { margin-bottom: 8px; }
        #templateHomeContent > p { color: var(--muted); line-height: 1.5; }
        .button { min-height: 36px; border-color: var(--line); border-radius: 8px; color: var(--ink); }
        .button:hover { background: #f4f7f5; border-color: #bfc9c3; }
        .button.primary { border-color: var(--green); background: var(--green); }
        .button.primary:hover { background: var(--green-dark); }
        .button.danger { color: #b3261e; border-color: #f0c4c0; }
        .button.danger:hover { background: #fdecea; }
        .templates { gap: 12px; padding: 0 0 18px; border-color: var(--line); }
        .tpl { padding: 14px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); }
        .tpl:hover { border-color: var(--green); background: #f6fbf7; box-shadow: 0 7px 18px rgba(16, 124, 65, .12); }
        .tpl .page { border-color: var(--line); border-radius: 4px; box-shadow: 0 2px 6px rgba(0, 0, 0, .06); }
        #search { min-height: 38px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
        #search:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(16, 124, 65, .12); }
        .list-head { padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px 8px 0 0; background: #f4f7f5; color: var(--muted); font-size: 12px; }
        .sheet-row { min-height: 58px; border: 1px solid var(--line); border-top: 0; }
        .sheet-row:last-child { border-radius: 0 0 8px 8px; }
        .sheet-row:hover { background: #f6fbf7; }
        .sheet-row .name { color: var(--ink); font: inherit; font-weight: 600; }
        .sheet-row .name small { color: var(--muted); }
        .sheet-row .col-date { color: var(--muted); }
        #editorView { background: var(--canvas); }
        .bar { min-height: 58px; flex-wrap: wrap; padding: 10px 16px; background: #fff; color: var(--ink); border-bottom: 1px solid var(--line); }
        .bar button { min-height: 36px; padding: 7px 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--ink); font: inherit; font-weight: 600; }
        .bar button:hover { background: #f4f7f5; border-color: #bfc9c3; }
        .bar button.primary { border-color: var(--green); background: var(--green); color: #fff; }
        .bar button.primary:hover { background: var(--green-dark); }
        #templateTitle { border-radius: 8px; color: var(--ink); }
        #templateTitle:focus, #templateTitle:hover { border-color: var(--line); }
        #templateTitle::placeholder { color: var(--muted); }
        .save-status { color: var(--muted); }
        .save-status[data-state="error"] { color: #b3261e; }
        .template-dialog-backdrop { position: fixed; z-index: 10000; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(15, 23, 42, .48); }
        .template-dialog { width: min(100%, 480px); padding: 24px; border: 1px solid var(--line); border-radius: 14px; background: #fff; color: var(--ink); box-shadow: 0 18px 56px rgba(15, 23, 42, .28); }
        .template-dialog h2 { margin: 0 0 10px; font-size: 19px; }
        .template-dialog p { margin: 0; color: var(--muted); line-height: 1.55; white-space: pre-line; overflow-wrap: anywhere; }
        .template-dialog-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
        .template-dialog-actions button { min-width: 84px; min-height: 42px; padding: 8px 16px; border: 1px solid var(--line); border-radius: 7px; background: #fff; color: var(--ink); font: inherit; font-weight: 650; cursor: pointer; }
        .template-dialog-actions .danger { border-color: #b3261e; background: #b3261e; color: #fff; }
        .template-dialog-actions .primary { border-color: var(--green); background: var(--green); color: #fff; }
        @media (max-width: 520px) { .template-dialog-actions { flex-direction: column-reverse; } .template-dialog-actions button { width: 100%; } }
        .gridwrap { background: var(--canvas); }
        .editor-toolbar { max-width: 1100px; padding: 14px; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
        .editor-toolbar label { color: var(--muted); font-size: 12px; font-weight: 600; }
        .editor-toolbar input:not([type="checkbox"]), .editor-toolbar select { min-height: 36px; border-color: var(--line); border-radius: 8px; font: inherit; }
        .editor-toolbar input:focus, .editor-toolbar select:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(16, 124, 65, .12); }
        .editor-toolbar .button { border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--ink); font-weight: 600; }
        .editor-toolbar .button:hover:not(:disabled) { border-color: #bfc9c3; background: #f4f7f5; color: var(--ink); }
        .editor-toolbar .button:disabled { opacity: .55; }
        .sheet-label-editor { border-color: var(--line); border-radius: 10px; }
        .excel-tabs-container { border-color: var(--line); border-radius: 10px 10px 0 0; background: #e8ede9; }
        .template-tabs { gap: 4px; }
        .template-tab { border-color: transparent; border-radius: 8px 8px 0 0; background: transparent; color: #55605a; font-size: 13px; }
        .template-tab:hover { background: #dde4df; }
        .template-tab.active { border: 0; border-top: 3px solid var(--green); background: #fff; color: var(--green); }
        .content { max-width: 1100px; }
        .paper { border: 1px solid var(--line); border-radius: 0 0 10px 10px; box-shadow: 0 4px 14px rgba(0, 0, 0, .05); }
        .section-table th, .section-table td { border-color: var(--line); }
        .section-table .sec-header { background: #e8ede9; }
        .section-table input.row-title:focus, .section-table textarea.row-desc:focus, .section-table .sec-label:focus, .section-table .sec-level:focus { outline: 2px solid rgba(16, 124, 65, .35); outline-offset: 1px; }
        .section-actions .button { border-radius: 8px; }
        .empty-state p, .section-note { color: var(--muted); }
        @media (max-width: 700px) {
            .editor-toolbar { padding: 10px; }
            .bar { gap: 8px; }
            .bar button { flex: 1 1 auto; }
            .save-status { width: 100%; }
            .list-head .name-heading { flex-wrap: wrap; }
        }
        #homeView { padding: clamp(16px, 2.5vw, 36px); }
        #templateHomeContent { width: min(100%, 1560px); margin: 0 auto; }
        .template-home-hero { display: flex; min-height: 180px; align-items: center; justify-content: space-between; gap: 24px; padding: clamp(24px, 3.5vw, 40px); border-radius: 20px; background: linear-gradient(118deg, #0b5c30 0%, #107c41 58%, #239556 100%); box-shadow: 0 16px 38px rgba(17, 91, 48, .16); color: #fff; }
        .template-home-hero .eyebrow { margin: 0 0 10px; color: #c5eed3; font-size: 11px; font-weight: 750; letter-spacing: .14em; text-transform: uppercase; }
        .template-home-hero h1 { margin: 0 0 9px; color: #fff; font-size: clamp(29px, 4vw, 42px); letter-spacing: -.04em; }
        .template-home-hero p:last-child { max-width: 660px; margin: 0; color: rgba(255,255,255,.82); font-size: 13px; line-height: 1.6; }
        .template-home-hero .button { flex: 0 0 auto; min-height: 44px; padding: 0 16px; border-color: rgba(255,255,255,.42); border-radius: 9px; background: rgba(0,0,0,.16); color: #fff; white-space: nowrap; }
        .template-home-hero .button:hover { background: rgba(0,0,0,.25); }
        .template-home-panel { margin-top: 16px; padding: clamp(17px, 2.5vw, 28px); border: 1px solid var(--line); border-radius: 17px; background: #fff; box-shadow: 0 7px 22px rgba(26,52,35,.045); }
        .template-home-controls { display: flex; align-items: stretch; gap: 16px; margin-bottom: 20px; }
        .template-home-controls .templates { flex: 0 0 auto; align-items: stretch; padding: 0; margin: 0; border: 0; }
        .template-home-controls .tpl { display: flex; width: auto; min-width: 230px; align-items: center; gap: 12px; padding: 12px 15px; border: 1px solid #dfe8e1; border-radius: 11px; background: #f7faf8; color: var(--ink); text-align: left; }
        .template-home-controls .tpl:hover { border-color: #b6d6c1; background: #f0f8f2; box-shadow: 0 5px 14px rgba(16,124,65,.08); }
        .template-home-controls .tpl .page { width: 36px; height: 44px; flex: 0 0 36px; margin: 0; border-color: #d3ded5; border-radius: 5px; background: linear-gradient(#fff 0 20%, #edf5ef 20% 24%, #fff 24% 40%, #edf5ef 40% 44%, #fff 44% 60%, #edf5ef 60% 64%, #fff 64%); box-shadow: none; }
        #search { flex: 1 1 300px; width: min(100%, 520px); min-height: 44px; align-self: center; margin: 0; padding: 10px 14px; border-color: var(--line); border-radius: 10px; }
        .template-home-actions { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
        .template-group-jumps { margin: 0; }
        .template-group-jumps .button, .list-actions .button { min-height: 38px; }
        .list-actions { min-height: 38px; margin: 0; }
        .list-head { min-height: 42px; align-items: center; border: 1px solid var(--line); border-radius: 10px 10px 0 0; }
        #templateList { overflow: hidden; border: 1px solid var(--line); border-top: 0; border-radius: 0 0 10px 10px; }
        #templateList:empty { display: none; }
        #templateList .template-group-heading { padding: 12px 14px; background: #f4f7f5; }
        #templateList .sheet-row { padding-right: 14px; padding-left: 14px; }
        #officeCatalogView { width: min(100%, 1560px); }
        @media (max-width: 760px) {
            #homeView { padding: 16px 12px; }
            .template-home-hero { min-height: 0; align-items: flex-start; flex-direction: column; padding: 25px; border-radius: 17px; }
            .template-home-hero .button { white-space: normal; }
            .template-home-controls { align-items: stretch; flex-direction: column; gap: 10px; }
            .template-home-controls .tpl { width: 100%; }
            #search { width: 100%; }
        }
        @media (max-width: 520px) {
            #homeView { padding: 10px; }
            .template-home-hero { gap: 17px; padding: 22px 18px; border-radius: 15px; }
            .template-home-panel { margin-top: 10px; padding: 13px; border-radius: 13px; }
            .template-home-actions { align-items: stretch; flex-direction: column; gap: 10px; }
            .template-group-jumps .button { flex: 1; }
            .list-actions { width: 100%; }
            .list-actions .button { width: 100%; }
            .list-head { gap: 12px; padding-right: 9px; padding-left: 9px; font-size: 12px; }
        }
        html[data-theme="dark"] body.template-manager-page > nav.rail { border-color: rgba(52, 211, 153, 0.32); background: #18241d; box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.12), 0 24px 60px rgba(0, 0, 0, 0.45), 0 0 52px rgba(16, 185, 129, 0.20), 0 0 24px rgba(16, 185, 129, 0.14); }
        html[data-theme="dark"] body.template-manager-page > nav.rail button { color: #e5eaf0; border: 1px solid rgba(52, 211, 153, 0.32); background: rgba(24, 36, 29, 0.92); box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.10), 0 0 22px rgba(16, 185, 129, 0.14); transition: background .15s ease, box-shadow .15s ease, border-color .15s ease, color .15s ease; }
        html[data-theme="dark"] body.template-manager-page > nav.rail button:hover,
        html[data-theme="dark"] body.template-manager-page > nav.rail button:focus-visible { background: #1e3328; border-color: rgba(52, 211, 153, 0.45); box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.16), 0 0 34px rgba(16, 185, 129, 0.28); color: #ecfdf5; }
        html[data-theme="dark"] body.template-manager-page > nav.rail button.active { border-left: 3px solid #5bbd7e; border-color: rgba(52, 211, 153, 0.42); background: #203326; color: #b8e4c4; box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.14), 0 0 30px rgba(16, 185, 129, 0.22); }
        html[data-theme="dark"] #homeView,
        html[data-theme="dark"] #editorView { border: 0 !important; background: transparent !important; background-color: transparent !important; box-shadow: none !important; }
        html[data-theme="dark"] .template-home-panel { border: 1px solid rgba(52, 211, 153, 0.32) !important; background: #18241d !important; background-color: #18241d !important; box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.12), 0 24px 60px rgba(0, 0, 0, 0.45), 0 0 52px rgba(16, 185, 129, 0.20), 0 0 24px rgba(16, 185, 129, 0.14) !important; }
        html[data-theme="dark"] .template-home-controls .tpl { border: 0 !important; border-color: transparent !important; background: transparent !important; background-color: transparent !important; box-shadow: none !important; }
        html[data-theme="dark"] .template-home-controls .tpl:hover { background: transparent !important; background-color: transparent !important; }
        html[data-theme="dark"] .template-home-controls .tpl .page { border-color: transparent !important; background: transparent !important; background-color: transparent !important; }
        html[data-theme="dark"] .template-home-actions .button:not(.danger) { border-color: #3b4a42; background: #243029; color: #e8f0eb; }
        html[data-theme="dark"] #templateList .template-group-heading { border: 0 !important; border-color: transparent !important; background: transparent !important; background-color: transparent !important; color: #d6d6dc; box-shadow: none !important; }
        html[data-theme="dark"] .list-head { border: 0 !important; border-color: transparent !important; background: transparent !important; background-color: transparent !important; color: #a1a1aa; box-shadow: none !important; }
        html[data-theme="dark"] #templateList { border: 0 !important; border-color: transparent !important; background: transparent !important; background-color: transparent !important; box-shadow: none !important; }
        html[data-theme="dark"] .sheet-row { background: transparent !important; background-color: transparent !important; border: 0 !important; border-color: transparent !important; box-shadow: none !important; }
        html[data-theme="dark"] .sheet-row:last-child { border-bottom: 0 !important; border-color: transparent !important; }
        html[data-theme="dark"] .sheet-row:hover { background: transparent !important; background-color: transparent !important; }
        html[data-theme="dark"] .sheet-row .name,
        html[data-theme="dark"] .sheet-row .name:hover,
        html[data-theme="dark"] .sheet-row .name:active { background: transparent !important; background-color: transparent !important; border: 0 !important; border-bottom: 0 !important; box-shadow: none !important; outline: none !important; color: inherit !important; text-decoration: none !important; text-decoration-color: transparent !important; }
        html[data-theme="dark"] .sheet-row .name:focus:not(:focus-visible) { background: transparent !important; background-color: transparent !important; border: 0 !important; border-bottom: 0 !important; box-shadow: none !important; outline: none !important; color: inherit !important; text-decoration: none !important; text-decoration-color: transparent !important; }
        html[data-theme="dark"] #templateList .sheet-row .name:focus-visible { outline: 2px solid #34d399 !important; outline-offset: 3px !important; }
        html[data-theme="dark"] .sheet-row .name small { color: #c9d8cf !important; }
        html[data-theme="dark"] .sheet-row .col-date, html[data-theme="dark"] #bulkDeleteStatus, html[data-theme="dark"] .list-head, html[data-theme="dark"] .save-status { color: #c9d8cf !important; }
        html[data-theme="dark"] #homeView .template-home-panel,
        html[data-theme="dark"] #homeView .template-home-panel * { border-color: transparent !important; }
        html[data-theme="dark"] #homeView .template-home-panel :is(.list-head, #templateList, .template-group-heading, .sheet-row, .sheet-row .name, .sheet-row .col-date, .sheet-row .col-actions) { background: transparent !important; background-color: transparent !important; background-image: none !important; border: 0 !important; box-shadow: none !important; }
        /* Template Manager dark-mode: seamless canvas, no stale gray layers, no green focus "spaces" */
        html[data-theme="dark"],
        html[data-theme="dark"] body.template-manager-page {
            background: #121815 !important;
            background-color: #121815 !important;
            background-image: none !important;
            color: #e8f0eb !important;
        }
        html[data-theme="dark"] #homeView,
        html[data-theme="dark"] #editorView,
        html[data-theme="dark"] .content,
        html[data-theme="dark"] .gridwrap,
        html[data-theme="dark"] .paper,
        html[data-theme="dark"] .editor-toolbar,
        html[data-theme="dark"] .sheet-label-editor,
        html[data-theme="dark"] .excel-tabs-container,
        html[data-theme="dark"] .section-table {
            background-image: none !important;
            box-shadow: none !important;
        }
        html[data-theme="dark"] #homeView,
        html[data-theme="dark"] #editorView,
        html[data-theme="dark"] .content,
        html[data-theme="dark"] .gridwrap {
            background: transparent !important;
            background-color: transparent !important;
        }
        html[data-theme="dark"] .topbar,
        html[data-theme="dark"] .toolbar,
        html[data-theme="dark"] .template-tabs,
        html[data-theme="dark"] .excel-tabs-container,
        html[data-theme="dark"] .editor-toolbar,
        html[data-theme="dark"] .sheet-label-editor,
        html[data-theme="dark"] .paper,
        html[data-theme="dark"] .catalog-card { background: #18241d !important; border: 0 !important; border-color: transparent !important; color: #e8f0eb !important; box-shadow: none !important; }
        html[data-theme="dark"] .template-home-panel { background: #18241d !important; border: 1px solid rgba(52,211,153,.32) !important; box-shadow: 0 0 0 1px rgba(52,211,153,.12), 0 24px 60px rgba(0,0,0,.45), 0 0 52px rgba(16,185,129,.20), 0 0 24px rgba(16,185,129,.14) !important; }
        html[data-theme="dark"] .catalog-card { border: 1px solid rgba(52,211,153,.18) !important; }
        html[data-theme="dark"] .paper { background: #18241d !important; border: 0 !important; border-radius: 0 !important; }
        html[data-theme="dark"] .template-tab { background: #213128 !important; border-color: #304038 !important; color: #b8c9be !important; }
        html[data-theme="dark"] .template-tab.active { background: #18241d !important; color: #6ee7a8 !important; border-top-color: #34d399 !important; border-bottom-color: #18241d !important; }
        html[data-theme="dark"] .bar { background: #145232 !important; color: #f3f7f4 !important; border-bottom: 1px solid #304038; }
        html[data-theme="dark"] .doc-title,
        html[data-theme="dark"] #templateTitle,
        html[data-theme="dark"] #search,
        html[data-theme="dark"] .sheet-label-grid input,
        html[data-theme="dark"] .editor-toolbar select,
        html[data-theme="dark"] .section-table input.row-title,
        html[data-theme="dark"] .section-table textarea.row-desc,
        html[data-theme="dark"] .section-table .sec-label,
        html[data-theme="dark"] .section-table .sec-level,
        html[data-theme="dark"] .section-table .sec-rating-label { background: #243029 !important; color: #e8f0eb !important; border-color: #3b4a42 !important; }
        html[data-theme="dark"] .doc-title:hover,
        html[data-theme="dark"] .doc-title:focus,
        html[data-theme="dark"] #templateTitle:hover,
        html[data-theme="dark"] #templateTitle:focus { border-color: #4a6a56 !important; }
        html[data-theme="dark"] body.template-manager-page :is(input, select, textarea):focus,
        html[data-theme="dark"] body.template-manager-page .doc-title:focus,
        html[data-theme="dark"] body.template-manager-page #templateTitle:focus,
        html[data-theme="dark"] body.template-manager-page .section-table input:focus,
        html[data-theme="dark"] body.template-manager-page .section-table textarea:focus,
        html[data-theme="dark"] body.template-manager-page #search:focus { outline: 2px solid #34d399 !important; outline-offset: -1px !important; box-shadow: none !important; border-color: #34d399 !important; }
        html[data-theme="dark"] body.template-manager-page :is(button, a, [tabindex], .template-tab, .sheet-row .name, .catalog-sheet, .delete-sheet):focus-visible { outline: 2px solid #34d399 !important; outline-offset: 2px !important; box-shadow: none !important; }
        html[data-theme="dark"] body.template-manager-page :is(button, a, [tabindex], .template-tab, .sheet-row .name, .catalog-sheet, .delete-sheet):focus:not(:focus-visible) { outline: none !important; box-shadow: none !important; }
        html[data-theme="dark"] body.template-manager-page > main,
        html[data-theme="dark"] body.template-manager-page #homeView,
        html[data-theme="dark"] body.template-manager-page #editorView { background: transparent !important; background-color: transparent !important; background-image: none !important; border: 0 !important; box-shadow: none !important; }
        html[data-theme="dark"] body.template-manager-page .gridwrap,
        html[data-theme="dark"] body.template-manager-page .content { background: transparent !important; background-color: transparent !important; }
        html[data-theme="dark"] body.template-manager-page .template-home-hero,
        html[data-theme="dark"] body.template-manager-page .template-home-controls,
        html[data-theme="dark"] body.template-manager-page .templates { background: transparent !important; }
        html[data-theme="dark"] body.template-manager-page .tpl,
        html[data-theme="dark"] body.template-manager-page .tpl:hover { background: transparent !important; border-color: #304038 !important; }
        html[data-theme="dark"] body.template-manager-page .tpl .page { background: #243029 !important; border-color: #3b4a42 !important; }
        html[data-theme="dark"] body.template-manager-page .sheet-row { background: transparent !important; background-color: transparent !important; }
        html[data-theme="dark"] body.template-manager-page .list-head { background: transparent !important; color: #b8c9be !important; }
        html[data-theme="dark"] body.template-manager-page .rail { background: #18241d !important; border-right-color: #304038 !important; box-shadow: none !important; }
        html[data-theme="dark"] body.template-manager-page > nav.rail { background: #18241d !important; }
        html[data-theme="dark"] .button { background: #243029 !important; color: #e8f0eb !important; border-color: #3b4a42 !important; }
        html[data-theme="dark"] .button:hover { background: #2d3d34 !important; border-color: #4a6a56 !important; }
        html[data-theme="dark"] .button.primary,
        html[data-theme="dark"] .bar button.primary { background: #107c41 !important; border-color: #107c41 !important; color: #fff !important; }
        html[data-theme="dark"] .bar button { background: rgba(255,255,255,.12) !important; color: #f3f7f4 !important; border-color: transparent !important; }
        html[data-theme="dark"] .bar button:hover { background: rgba(255,255,255,.2) !important; }
        html[data-theme="dark"] .section-table,
        html[data-theme="dark"] .section-table th,
        html[data-theme="dark"] .section-table td { border-color: #304038 !important; color: #e8f0eb !important; background: #18241d !important; background-image: none !important; box-shadow: none !important; }
        html[data-theme="dark"] .section-table .sec-header { background: #213128 !important; color: #f3f7f4 !important; border-color: #304038 !important; }
        html[data-theme="dark"] .section-table .sec-label,
        html[data-theme="dark"] .section-table .sec-level,
        html[data-theme="dark"] .section-table .sec-rating-label,
        html[data-theme="dark"] .section-table input.row-title,
        html[data-theme="dark"] .section-table textarea.row-desc { background: transparent !important; background-color: transparent !important; box-shadow: none !important; border: 0 !important; color: #e8f0eb !important; }
        html[data-theme="dark"] .section-table .rating-placeholder { background: transparent !important; color: #b8c9be !important; box-shadow: none !important; }
        html[data-theme="dark"] .section-table .evidence-placeholder { color: #b8c9be !important; }
        html[data-theme="dark"] .section-table .comp-title td { border-bottom-color: transparent !important; }
        html[data-theme="dark"] .section-table .comp-desc-row td { border-top-color: transparent !important; }
        html[data-theme="dark"] .section-table .del-row-btn { color: #ff8a80 !important; }
        html[data-theme="dark"] .section-table .del-row-btn:hover { background: rgba(255,138,128,0.12) !important; }
        html[data-theme="dark"] .section-table .del-row-btn:focus-visible { outline-color: rgba(255,138,128,0.55) !important; }
        html[data-theme="dark"] .list-head,
        html[data-theme="dark"] .sheet-row,
        html[data-theme="dark"] #templateList .template-group-heading { border-color: #304038 !important; }
        html[data-theme="dark"] .sheet-row:hover { background: #213128 !important; background-color: #213128 !important; }
        html[data-theme="dark"] h1, html[data-theme="dark"] h2, html[data-theme="dark"] h3 { color: #f3f7f4 !important; }
        html[data-theme="dark"] p, html[data-theme="dark"] .catalog-summary, html[data-theme="dark"] .catalog-card p, html[data-theme="dark"] .section-note, html[data-theme="dark"] .save-status, html[data-theme="dark"] #bulkDeleteStatus, html[data-theme="dark"] .user-name { color: #b8c9be !important; }
        html[data-theme="dark"] .catalog-sheet { background: #243029 !important; border-color: #3b4a42 !important; color: #e8f0eb !important; }
        html[data-theme="dark"] .catalog-sheet:hover { background: #2d3d34 !important; border-color: #4a6a56 !important; }
        html[data-theme="dark"] .save-status[data-state="error"] { color: #ffb4ab !important; }
    </style>
</head>
<body class="template-manager-page">
<nav class="rail" aria-label="Sheets navigation">
    <button id="navHome" class="active" type="button">Home</button>
    <button id="navNew" type="button">New</button>
    <button id="navOpen" type="button">Open</button>
    <button id="navRegion" class="office-nav" type="button">Region</button>
    <button id="navField" type="button">Field</button>
    <div class="spacer"></div>
    <button class="small" type="button" onclick="location.href='dashboard.php'">Dashboard</button>
    <button class="small" type="button" onclick="location.href='positions_import.php'">Import Positions</button>
    <button class="small" type="button" onclick="location.href='results_2.php'">Results</button>
    <form method="post" action="logout.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="small">Log out</button>
    </form>
</nav>

<main id="homeView">
    <div id="templateHomeContent">
        <header class="template-home-hero">
            <div>
                <p class="eyebrow">Admin workspace</p>
                <h1>Competency Templates</h1>
                <p>Build and share scoring forms, or generate position-specific sheets while keeping existing competency content intact.</p>
            </div>
            <a class="button primary" href="template_seed.php">Generate Position Sheets</a>
        </header>
        <section class="template-home-panel" aria-label="Template library">
            <div class="template-home-controls">
                <div class="templates">
                    <button class="tpl" type="button" id="tplNew">
                        <div class="page" aria-hidden="true"></div>
                        New Competency Template
                    </button>
                </div>
                <input id="search" type="search" placeholder="Search for a template" autocomplete="off" aria-label="Search templates">
            </div>
            <div class="template-home-actions">
                <div class="template-group-jumps" aria-label="Jump to template group">
                    <button class="button" type="button" id="jumpToRegionSheets">Region</button>
                    <button class="button" type="button" id="jumpToFieldSheets">Field</button>
                </div>
                <div class="list-actions">
                    <button class="button danger" type="button" id="deleteSelectedBtn" disabled>Delete selected (0)</button>
                    <span id="bulkDeleteStatus" role="status" aria-live="polite"></span>
                </div>
            </div>
            <div class="list-head">
                <div class="name-heading"><label><input id="selectAllVisible" type="checkbox"> Select all (visible)</label><span>Name</span></div>
                <div class="col-date">Date modified</div>
                <div class="col-actions"></div>
            </div>
            <div id="templateList" aria-live="polite"></div>
        </section>
    </div>
    <div id="officeCatalogView" class="hidden" aria-live="polite"></div>
</main>

<main id="editorView">
    <header class="bar">
        <button type="button" id="btnBack">&larr; Home</button>
        <input id="templateTitle" type="text" maxlength="120" placeholder="Template name (spaces allowed)" aria-label="Template name">
        <button class="primary" type="button" id="saveTemplateBtn" disabled>Save Sheet</button>
        <button class="primary" type="button" id="useTemplateBtn" disabled>Use in rating form</button>
        <span class="save-status" id="saveStatus" data-state="" role="status" aria-live="polite" aria-atomic="true">All changes saved</span>
    </header>
    <section class="gridwrap" aria-label="Template competency layout">
        <div class="editor-toolbar">
            <button class="button" id="newTemplateBtn" type="button">＋ New Template</button>
            <label>Salary grades
                <input id="minSalaryGrade" type="number" min="0" max="26" value="0" aria-label="Minimum salary grade">
                to
                <input id="maxSalaryGrade" type="number" min="0" max="26" value="17" aria-label="Maximum salary grade">
            </label>
            <select id="templateOfficeType" class="scope-select" aria-label="Region or field">
                <option value="RO">Region</option>
                <option value="FO">Field</option>
            </select>
            <label>Template type
                <select id="templateSourceSheet" class="scope-select" aria-label="Template type">
                    <option value="">Not specified</option>
                    <?php foreach ($officeSheetChoices as $sheetName => $officeType): ?>
                        <option value="<?php echo htmlspecialchars($sheetName, ENT_QUOTES, 'UTF-8'); ?>" data-office-type="<?php echo htmlspecialchars($officeType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($officeSheetLabels[$sheetName] ?? 'Other', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Region
                <select id="templateRegion" class="scope-select" aria-label="Template region scope">
                    <option value="">All regions</option>
                    <?php foreach (ACCESS_REGIONS as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><input id="templateShared" type="checkbox"> Available to raters</label>
            <button class="button" id="addEmptyRowBtn" type="button" disabled>＋ Add Section</button>
            <label for="sectionSelect">Add competency to:</label>
            <select id="sectionSelect" disabled></select>
            <button class="button" id="addCompetencyBtn" type="button" disabled>＋ Add Competency</button>
            <button class="button" id="deleteTemplateBtn" type="button" disabled>Delete Template</button>
        </div>
        <details class="sheet-label-editor">
            <summary>Edit sheet headings and labels</summary>
            <div class="sheet-label-grid" id="sheetLabelFields"></div>
        </details>
        <div class="excel-tabs-container">
            <div class="template-tabs" id="templateTabs" role="tablist" aria-label="Template sections"></div>
        </div>
        <div class="content">
            <div class="paper">
                <div class="empty-state" id="emptyState">
                    <h1>No sections in this template</h1>
                    <p>Add a section, then add competencies and descriptions to build your custom scoring layout.</p>
                    <button class="button primary" type="button" id="emptyNewBtn">＋ Add Section</button>
                </div>
                <div id="sectionList"></div>
                <p id="sectionNote" class="section-note hidden">Custom sections are available on the official scoring form when this template is selected.</p>
            </div>
        </div>
    </section>
</main>
<script>
    const templateShowAlert = function (message) {
            return new Promise(resolve => {
                const previousFocus = document.activeElement;
                const backdrop = document.createElement('div');
                backdrop.className = 'template-dialog-backdrop';
                const dialog = document.createElement('section');
                dialog.className = 'template-dialog';
                dialog.setAttribute('role', 'alertdialog');
                dialog.setAttribute('aria-modal', 'true');
                const title = document.createElement('h2');
                title.textContent = 'Notice';
                const body = document.createElement('p');
                body.textContent = String(message ?? '');
                const actions = document.createElement('div');
                actions.className = 'template-dialog-actions';
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'primary';
                button.textContent = 'OK';
                actions.appendChild(button);
                dialog.append(title, body, actions);
                backdrop.appendChild(dialog);
                document.body.appendChild(backdrop);
                const close = () => {
                    backdrop.remove();
                    resolve();
                    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
                };
                button.addEventListener('click', close);
                backdrop.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        close();
                    }
                });
                button.focus();
            });
    };
    const templateShowConfirm = function (message, options = {}) {
            return new Promise(resolve => {
                const previousFocus = document.activeElement;
                const backdrop = document.createElement('div');
                backdrop.className = 'template-dialog-backdrop';
                const dialog = document.createElement('section');
                dialog.className = 'template-dialog';
                dialog.setAttribute('role', 'alertdialog');
                dialog.setAttribute('aria-modal', 'true');
                const title = document.createElement('h2');
                title.textContent = options.title || 'Please confirm';
                const body = document.createElement('p');
                body.textContent = String(message ?? '');
                const actions = document.createElement('div');
                actions.className = 'template-dialog-actions';
                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.textContent = options.cancelText || 'Cancel';
                const confirmButton = document.createElement('button');
                confirmButton.type = 'button';
                confirmButton.className = 'danger';
                confirmButton.textContent = options.confirmText || 'Delete';
                actions.append(cancelButton, confirmButton);
                dialog.append(title, body, actions);
                backdrop.appendChild(dialog);
                document.body.appendChild(backdrop);
                let finished = false;
                const close = confirmed => {
                    if (finished) return;
                    finished = true;
                    backdrop.remove();
                    resolve(confirmed);
                    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
                };
                cancelButton.addEventListener('click', () => close(false));
                confirmButton.addEventListener('click', () => close(true));
                backdrop.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        close(false);
                    } else if (event.key === 'Tab') {
                        event.preventDefault();
                        (document.activeElement === cancelButton ? confirmButton : cancelButton).focus();
                    }
                });
                cancelButton.focus();
            });
    };

    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    const LEADERSHIP_ROWS_BASIC = <?php echo $defaultLeadershipRowsJson; ?>;
    const LEADERSHIP_ROWS_ADVANCED = <?php echo $advancedLeadershipRowsJson; ?>;
    const DEFAULT_TEMPLATE_REGION = <?php echo json_encode(ACCESS_DEFAULT_REGION); ?>;
    const DEFAULT_TEMPLATE_REGION_LABEL = <?php echo json_encode(ACCESS_REGIONS[ACCESS_DEFAULT_REGION]); ?>;
    const SOURCE_SHEET_LABELS = <?php echo json_encode($officeSheetLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let templates = [];
    let currentTemplate = null;
    let activeSectionKey = null;
    let saveTimer = null;
    let isDirty = false;
    let savePromise = null;
    let isNewTemplate = false;
    let activeOfficeCatalogType = null;

    const tabs = document.getElementById('templateTabs');
    const titleInput = document.getElementById('templateTitle');
    const sectionList = document.getElementById('sectionList');
    const emptyState = document.getElementById('emptyState');
    const statusEl = document.getElementById('saveStatus');
    const sectionSelect = document.getElementById('sectionSelect');
    const minSalaryGradeInput = document.getElementById('minSalaryGrade');
    const maxSalaryGradeInput = document.getElementById('maxSalaryGrade');
    const templateRegionInput = document.getElementById('templateRegion');
    const templateOfficeTypeInput = document.getElementById('templateOfficeType');
    const templateSourceSheetInput = document.getElementById('templateSourceSheet');
    const templateSharedInput = document.getElementById('templateShared');
    const sheetLabelFields = document.getElementById('sheetLabelFields');
    const homeView = document.getElementById('homeView');
    const editorView = document.getElementById('editorView');
    const templateHomeContent = document.getElementById('templateHomeContent');
    const officeCatalogView = document.getElementById('officeCatalogView');
    const OFFICE_CATALOG = {
        RO: {
            label: 'Region',
            sheetNames: <?php echo json_encode($regionSheetNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            positions: <?php echo json_encode($regionCatalogPositions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        },
        FO: {
            label: 'Field',
            sheets: <?php echo json_encode($fieldCatalogSheets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        }
    };

    function syncSourceSheetChoices() {
        Array.from(templateSourceSheetInput.options).forEach(option => {
            if (!option.value) return;
            option.hidden = option.dataset.officeType !== templateOfficeTypeInput.value;
        });
        const selectedOption = templateSourceSheetInput.selectedOptions[0];
        if (selectedOption?.value && selectedOption.dataset.officeType !== templateOfficeTypeInput.value) {
            templateSourceSheetInput.value = '';
        }
    }
    const templateList = document.getElementById('templateList');
    const searchInput = document.getElementById('search');
    const selectAllVisible = document.getElementById('selectAllVisible');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    const bulkDeleteStatus = document.getElementById('bulkDeleteStatus');
    const selectedTemplateIds = new Set();
    let visibleTemplateIds = [];
    const BASIC_MAX_SG = <?php echo BASIC_MAX_SG; ?>;
    const DEFAULT_SHEET_LABELS = {
        department: 'Department of Environment and Natural Resources',
        region_line: 'Region VI, Western Visayas',
        board: 'Regional Human Resource Merit Promotion and Selection Board (RHRMPSB)',
        sheet_title: 'INTERVIEW SCORING SHEET',
        candidate_name: 'NAME:',
        position: 'Position:',
        office: 'Office:',
        interview_date: 'Date:',
        candidate_salary_grade: 'Candidate salary grade:',
        competency_template: 'Competency template:',
        rated_by: 'Rated by:',
        raters: 'Raters:'
    };

    function renderSheetLabelFields() {
        sheetLabelFields.replaceChildren();
        Object.entries(DEFAULT_SHEET_LABELS).forEach(([key, defaultValue]) => {
            const label = document.createElement('label');
            label.textContent = key.replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
            const input = document.createElement('input');
            input.type = 'text';
            input.maxLength = 255;
            input.dataset.sheetLabel = key;
            input.value = currentTemplate?.layout.labels?.[key] ?? defaultValue;
            label.appendChild(input);
            sheetLabelFields.appendChild(label);
        });
    }

    async function api(action, payload = {}) {
        const isGet = action === 'template_list' || action === 'template_load';
        const url = action === 'template_list'
            ? 'template_api.php?action=template_list&manage_templates=1'
            : action === 'template_load'
                ? 'template_api.php?template_id=' + encodeURIComponent(payload.id) + '&manage_templates=1'
                : 'template_api.php';
        const response = await fetch(url, {
            method: isGet ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: isGet ? {} : { 'Content-Type': 'application/json' },
            body: isGet ? undefined : JSON.stringify({ ...payload, action, csrf_token: CSRF_TOKEN })
        });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Request failed.');
        return data;
    }

    function setStatus(text, state = '') {
        statusEl.textContent = text;
        statusEl.dataset.state = state;
    }

    function freshId(prefix) {
        return prefix + '_' + Math.random().toString(36).slice(2, 10);
    }

    function standardSections() {
        return {
            core: { standard: true, label: 'Core Competencies', level: 'Basic', rating_label: 'Rating', rows: [
                { id: 'cc1', title: 'CC1 - DISCIPLINE', desc: 'Serves as a good role model on DENR values and ethics to staff/peers' },
                { id: 'cc2', title: 'CC2 - EXCELLENCE', desc: 'Anticipates, identifies and manages stakeholders’ standards and requirements towards excellent customer service through improving sense of responsibility and competence' },
                { id: 'cc3', title: 'CC3 - NOBILITY', desc: 'Influences others to observe virtue, goodness, honor, justness and decency in all situations' },
                { id: 'cc4', title: 'CC4 - RESPONSIBILITY', desc: 'Leads in the observance of the principle of transparency and accountability in the workplace' },
                { id: 'cc5', title: 'CC5 - CARING FOR THE ENVIRONMENT AND NATURAL RESOURCES', desc: 'Serves as a good role model in conserving and preserving the environment to peers and staff' }
            ]},
            org: { standard: true, label: 'Organizational Competencies', level: 'Basic', rating_label: 'Rating', rows: [
                { id: 'oc1', title: 'OC1 - WRITING EFFECTIVELY', desc: 'Produces written work from scratch with some guidance while complying to agreed or prescribed standards of communicating with the bureaucracy' },
                { id: 'oc2', title: 'OC2 - SPEAKING EFFECTIVELY', desc: 'Effectively delivers messages that require careful planning for the method used and the possible impact of the message (audience may be a large group, i.e., office, organization) Focus of communication is to relay information and to build motivation' },
                { id: 'oc3', title: 'OC3 - TECHNOLOGY LITERACY AND MANAGING INFORMATION', desc: 'Analyzes appropriateness of office software and equipment in the performance of the assigned tasks. Develops information assets to achieve organizational goals' },
                { id: 'oc4', title: 'OC4 - PROJECT MANAGEMENT', desc: 'Leads in project management activities' },
                { id: 'oc5', title: 'OC5 - COMPLETE STAFF WORK (CSW)', desc: 'Leads the practice of CSW in his/her office/unit' }
            ]},
            lead: { standard: true, label: 'Leadership Competencies', level: 'Basic', rating_label: 'Rating', rows: <?php echo $defaultLeadershipRowsJson; ?> },
            func: { standard: true, label: 'Functional Competencies', level: 'Basic', rating_label: 'Rating', rows: [
                { id: 'pcp1', title: 'PCP1 – PLANNING AND PROGRAMMING', desc: 'Organizes the preparation of the PENRO operational plans' },
                { id: 'pcp2', title: 'PCP2 – MONITORING AND EVALUATION', desc: 'Leads the preparation of monitoring and evaluation reports of all DENR-PENRO programs and projects' },
                { id: 'pcp3', title: 'PCP3 – STATISTICAL COORDINATION AND DATA RESEARCH', desc: 'Leads the conduct of statistical coordination and data research activities' },
                { id: 'pco4', title: 'PCO4 – RESOURCE MANAGEMENT AND RESTORATION/ REHABILITATION OF DEGRADED ECOSYSTEMS', desc: 'Leads the characterization of ecosystem and use of planning tools and procedures' }
            ]}
        };
    }

    function restoreStandardCompetencyTitles(sections) {
        if (!sections || typeof sections !== 'object') return sections;
        const canonicalById = {};
        Object.values(standardSections()).forEach(section => {
            (section.rows || []).forEach(row => {
                if (row && row.id && row.title) canonicalById[String(row.id).toLowerCase()] = row.title;
            });
        });
        Object.values(sections).forEach(section => {
            (section?.rows || []).forEach(row => {
                if (!row || !row.id) return;
                const canonicalTitle = canonicalById[String(row.id).toLowerCase()];
                if (!canonicalTitle) return;
                const currentTitle = String(row.title || '').trim();
                const code = String(row.id).toUpperCase();
                if (!currentTitle || currentTitle.toUpperCase() === code) {
                    row.title = canonicalTitle;
                }
            });
        });
        return sections;
    }

    function withStandardSections(sections) {
        return restoreStandardCompetencyTitles(sections);
    }

    function renderTabs() {
        tabs.replaceChildren();
        const sections = currentTemplate?.layout.sections || {};
        Object.entries(sections).forEach(([key, section]) => {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'template-tab' + (activeSectionKey === key ? ' active' : '');
            tab.textContent = section.label || 'Untitled section';
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', activeSectionKey === key ? 'true' : 'false');
            tab.addEventListener('click', () => {
                const updateSection = () => {
                    activeSectionKey = key;
                    renderSections();
                };
                if (typeof window.runUITransition === 'function') window.runUITransition(updateSection, 'section');
                else updateSection();
            });
            tabs.appendChild(tab);
        });
    }

    function updateButtons() {
        const hasTemplate = !!currentTemplate;
        const hasSections = hasTemplate && Object.keys(currentTemplate.layout.sections).length > 0;
        document.getElementById('saveTemplateBtn').disabled = !hasTemplate;
        document.getElementById('addEmptyRowBtn').disabled = !hasTemplate;
        document.getElementById('addCompetencyBtn').disabled = !hasSections;
        document.getElementById('useTemplateBtn').disabled = !hasTemplate || Number(currentTemplate.is_shared) !== 1;
        document.getElementById('deleteTemplateBtn').disabled = !hasTemplate;
        minSalaryGradeInput.disabled = !hasTemplate;
        maxSalaryGradeInput.disabled = !hasTemplate;
        templateRegionInput.disabled = !hasTemplate;
        templateOfficeTypeInput.disabled = !hasTemplate;
        templateSourceSheetInput.disabled = !hasTemplate;
        templateSharedInput.disabled = !hasTemplate;
        sectionSelect.disabled = !hasSections;
        emptyState.classList.toggle('hidden', !hasTemplate || hasSections);
        document.getElementById('sectionNote').classList.toggle('hidden', !hasSections);
    }

    function syncLeadershipRows(section) {
        if (!section) return false;
        const level = ['Basic', 'Intermediate', 'Advanced'].includes(section.level)
            ? section.level
            : (Number(currentTemplate.min_salary_grade) <= BASIC_MAX_SG ? 'Basic' : 'Advanced');
        const canonicalRows = level === 'Advanced' ? LEADERSHIP_ROWS_ADVANCED : LEADERSHIP_ROWS_BASIC;
        if (!Array.isArray(section.rows)) section.rows = [];
        let changed = false;
        canonicalRows.forEach((canonicalRow, index) => {
            const existing = section.rows[index];
            if (!existing) {
                section.rows[index] = { ...canonicalRow };
                changed = true;
            } else if (existing.title !== canonicalRow.title || existing.desc !== canonicalRow.desc) {
                section.rows[index] = { ...existing, title: canonicalRow.title, desc: canonicalRow.desc };
                changed = true;
            }
        });
        return changed;
    }

    function renderSections() {
        sectionList.replaceChildren();
        sectionSelect.replaceChildren();
        if (!currentTemplate) {
            updateButtons();
            renderTabs();
            return;
        }

        const sections = currentTemplate.layout.sections;
        const leadershipRowsChanged = sections.lead ? syncLeadershipRows(sections.lead) : false;
        const sectionKeys = Object.keys(sections);
        if (!sectionKeys.includes(activeSectionKey)) activeSectionKey = sectionKeys[0] || null;
        Object.entries(currentTemplate.layout.sections).forEach(([key, section]) => {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = section.label || 'Untitled section';
            option.selected = key === activeSectionKey;
            sectionSelect.appendChild(option);
            if (key !== activeSectionKey) return;

            const table = document.createElement('table');
            table.className = 'section-table';
            const colgroup = document.createElement('colgroup');
            ['58%', '27%', '15%'].forEach(width => {
                const col = document.createElement('col');
                col.style.width = width;
                colgroup.appendChild(col);
            });
            table.appendChild(colgroup);
            const head = document.createElement('tr');
            head.innerHTML = '<th class="sec-header"></th><th class="sec-header"></th><th class="sec-header"></th>';
            const labelCell = head.cells[0];
            const labelWrap = document.createElement('div');
            labelWrap.className = 'sec-header-inner';
            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'sec-label';
            labelInput.dataset.section = key;
            labelInput.value = section.label || '';
            labelInput.placeholder = 'Section title (e.g. Technical Competencies)';
            const deleteSection = document.createElement('button');
            deleteSection.type = 'button';
            deleteSection.className = 'del-row-btn';
            deleteSection.dataset.action = 'delete-section';
            deleteSection.dataset.section = key;
            deleteSection.title = 'Delete this section';
            deleteSection.setAttribute('aria-label', 'Delete section ' + (section.label || 'Untitled section'));
            deleteSection.textContent = '×';
            labelWrap.append(labelInput, deleteSection);
            labelCell.appendChild(labelWrap);
            const levelInput = document.createElement('select');
            levelInput.className = 'sec-level';
            levelInput.dataset.section = key;
            ['Basic', 'Intermediate', 'Advanced'].forEach(level => {
                const option = document.createElement('option');
                option.value = level;
                option.textContent = level;
                levelInput.appendChild(option);
            });
            levelInput.value = ['Basic', 'Intermediate', 'Advanced'].includes(section.level)
                ? section.level
                : (Number(currentTemplate.min_salary_grade) <= BASIC_MAX_SG ? 'Basic' : 'Advanced');
            head.cells[1].appendChild(levelInput);
            const ratingLabelInput = document.createElement('input');
            ratingLabelInput.type = 'text';
            ratingLabelInput.className = 'sec-rating-label';
            ratingLabelInput.dataset.section = key;
            ratingLabelInput.value = section.rating_label ?? 'Rating';
            ratingLabelInput.placeholder = 'Rating column label';
            head.cells[2].appendChild(ratingLabelInput);
            const thead = document.createElement('thead');
            thead.appendChild(head);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            (section.rows || []).forEach(row => {
                const titleRow = document.createElement('tr');
                titleRow.className = 'comp-title';
                const titleCell = document.createElement('td');
                const titleWrap = document.createElement('div');
                titleWrap.className = 'comp-title-inner';
                const rowTitle = document.createElement('input');
                rowTitle.type = 'text';
                rowTitle.className = 'row-title';
                rowTitle.dataset.section = key;
                rowTitle.dataset.rowId = row.id;
                rowTitle.value = row.title || '';
                rowTitle.placeholder = 'Competency title';
                const deleteRow = document.createElement('button');
                deleteRow.type = 'button';
                deleteRow.className = 'del-row-btn';
                deleteRow.dataset.action = 'delete-row';
                deleteRow.dataset.section = key;
                deleteRow.dataset.rowId = row.id;
                deleteRow.title = 'Delete this competency';
                deleteRow.setAttribute('aria-label', 'Delete competency ' + (row.title || 'untitled'));
                deleteRow.textContent = '×';
                titleWrap.append(rowTitle, deleteRow);
                titleCell.appendChild(titleWrap);
                titleRow.append(titleCell, document.createElement('td'), document.createElement('td'));
                tbody.appendChild(titleRow);

                const descRow = document.createElement('tr');
                descRow.className = 'comp-desc-row';
                const descCell = document.createElement('td');
                const rowDesc = document.createElement('textarea');
                rowDesc.className = 'row-desc';
                rowDesc.dataset.section = key;
                rowDesc.dataset.rowId = row.id;
                rowDesc.value = row.desc || '';
                rowDesc.placeholder = 'Competency description';
                descCell.appendChild(rowDesc);
                const evidenceCell = document.createElement('td');
                evidenceCell.className = 'evidence-placeholder';
                evidenceCell.textContent = 'Evidence entered during scoring';
                const ratingCell = document.createElement('td');
                ratingCell.className = 'rating-placeholder';
                ratingCell.textContent = '0.00';
                descRow.append(descCell, evidenceCell, ratingCell);
                tbody.appendChild(descRow);
            });
            table.appendChild(tbody);
            sectionList.appendChild(table);

            const actions = document.createElement('div');
            actions.className = 'section-actions';
            const addRow = document.createElement('button');
            addRow.type = 'button';
            addRow.className = 'button';
            addRow.dataset.action = 'add-competency';
            addRow.dataset.section = key;
            addRow.textContent = '＋ Add Competency';
            actions.appendChild(addRow);
            sectionList.appendChild(actions);
        });
        titleInput.value = currentTemplate.title;
        if (leadershipRowsChanged) markDirty();
        updateButtons();
        renderTabs();
    }

    function renderTemplateList() {
        templateList.replaceChildren();
        selectedTemplateIds.clear();
        selectAllVisible.checked = false;
        selectAllVisible.indeterminate = false;
        const query = searchInput.value.trim().toLowerCase();
        const visibleTemplates = templates
            .filter(template => [
                template.title,
                template.office_type === 'FO' ? 'Field' : 'Region',
                SOURCE_SHEET_LABELS[template.source_sheet] || template.source_sheet || '',
                template.min_salary_grade,
                template.max_salary_grade
            ].join(' ').toLowerCase().includes(query))
            .sort((a, b) => Number(b.min_salary_grade ?? -1) - Number(a.min_salary_grade ?? -1)
                || a.title.localeCompare(b.title));
        visibleTemplateIds = visibleTemplates.map(template => template.id);
        updateBatchDeleteControls();
        if (!visibleTemplates.length) {
            const empty = document.createElement('div');
            empty.className = 'empty-list';
            empty.textContent = templates.length ? 'No templates match your search.' : 'No templates yet. Create a new competency template above.';
            templateList.appendChild(empty);
            return;
        }

        [
            { type: 'RO', label: 'Region' },
            { type: 'FO', label: 'Field' }
        ].forEach(group => {
            const groupTemplates = visibleTemplates.filter(template => template.office_type === group.type);
            if (!groupTemplates.length) return;
            const heading = document.createElement('h2');
            heading.className = 'template-group-heading';
            heading.id = group.type === 'RO' ? 'regionSheetsHeading' : 'fieldSheetsHeading';
            heading.textContent = group.label + ' sheets (' + groupTemplates.length + ')';
            templateList.appendChild(heading);

            groupTemplates.forEach(template => {
            const row = document.createElement('div');
            row.className = 'sheet-row';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'template-select';
            checkbox.value = String(template.id);
            checkbox.setAttribute('aria-label', 'Select ' + template.title);
            checkbox.addEventListener('click', event => event.stopPropagation());
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) selectedTemplateIds.add(template.id);
                else selectedTemplateIds.delete(template.id);
                updateBatchDeleteControls();
            });

            const name = document.createElement('button');
            name.type = 'button';
            name.className = 'name';
            name.textContent = template.title;
            name.setAttribute('aria-label', 'Open ' + template.title);
            name.addEventListener('click', () => {
                if (window.matchMedia('(hover: none), (pointer: coarse)').matches && !row.classList.contains('show-actions')) {
                    templateList.querySelectorAll('.sheet-row.show-actions').forEach(item => item.classList.remove('show-actions'));
                    row.classList.add('show-actions');
                    return;
                }
                openTemplate(template.id);
            });

            const date = document.createElement('div');
            date.className = 'col-date';
            const updatedAt = template.updated_at ? new Date(template.updated_at.replace(' ', 'T')) : null;
            const gradeRange = template.min_salary_grade !== null && template.max_salary_grade !== null
                && Number.isInteger(Number(template.min_salary_grade)) && Number.isInteger(Number(template.max_salary_grade))
                ? 'Grades ' + template.min_salary_grade + '–' + template.max_salary_grade
                : 'Set a salary-grade range';
            const accessGroup = template.region ? DEFAULT_TEMPLATE_REGION_LABEL : 'All regions';
            const officeType = template.office_type === 'FO' ? 'Field' : 'Region';
            const sourceSheet = template.source_sheet
                ? ' · ' + (SOURCE_SHEET_LABELS[template.source_sheet] || template.source_sheet)
                : '';
            date.textContent = officeType + sourceSheet + ' · ' + gradeRange + ' · ' + accessGroup + (Number(template.is_shared) === 1 ? ' · Shared' : ' · Private')
                + (updatedAt && !Number.isNaN(updatedAt.getTime()) ? ' · ' + updatedAt.toLocaleString() : '');

            const actions = document.createElement('div');
            actions.className = 'col-actions';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'delete-sheet';
            remove.title = 'Delete template';
            remove.setAttribute('aria-label', 'Delete ' + template.title);
            remove.textContent = '×';
            remove.addEventListener('click', async event => {
                event.stopPropagation();
                if (!await templateShowConfirm('Delete "' + template.title + '"?', { confirmText: 'Delete' })) return;
                try {
                    await api('template_delete', { id: template.id });
                    templates = templates.filter(item => item.id !== template.id);
                    renderTemplateList();
                } catch (error) {
                    templateShowAlert(error.message);
                }
            });
            actions.appendChild(remove);
            row.append(checkbox, name, date, actions);
            templateList.appendChild(row);
            });
        });
    }

    function catalogElement(tagName, className = '', text = '') {
        const element = document.createElement(tagName);
        if (className) element.className = className;
        if (text) element.textContent = text;
        return element;
    }

    function catalogJumpButton(text, targetId) {
        const button = catalogElement('button', 'catalog-sheet', text);
        button.type = 'button';
        button.addEventListener('click', () => {
            document.getElementById(targetId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        return button;
    }

    function appendRegionPositionsCard(id, label, positions) {
        const card = catalogElement('section', 'catalog-card');
        card.id = id;
        card.append(
            catalogElement('h2', '', 'Region positions — ' + label),
            catalogElement('p', '', positions.length + ' configured positions.')
        );
        if (!positions.length) {
            card.appendChild(catalogElement('div', 'catalog-empty', 'No positions are configured for this salary-grade range.'));
        } else {
            const table = catalogElement('table', 'catalog-table');
            const header = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Salary grade', 'Position'].forEach(text => headerRow.appendChild(catalogElement('th', '', text)));
            header.appendChild(headerRow);
            const body = document.createElement('tbody');
            positions.forEach(position => {
                const row = document.createElement('tr');
                row.append(
                    catalogElement('td', '', 'SG ' + position.salary_grade),
                    catalogElement('td', '', position.title)
                );
                body.appendChild(row);
            });
            table.append(header, body);
            card.appendChild(table);
        }
        officeCatalogView.appendChild(card);
    }

    function appendFieldPositionsCard(id, label, sheets, officeTemplates) {
        const positionCount = sheets.reduce((total, sheet) => total + sheet.positions.length, 0);
        const card = catalogElement('section', 'catalog-card');
        card.id = id;
        card.append(
            catalogElement('h2', '', 'Field positions — ' + label),
            catalogElement('p', '', positionCount + ' configured positions across ' + sheets.length + ' sheet mapping(s).')
        );
        if (!sheets.length) {
            card.appendChild(catalogElement('div', 'catalog-empty', 'No positions are configured for this salary-grade range.'));
            officeCatalogView.appendChild(card);
            return;
        }

        sheets.forEach(sheet => {
            card.appendChild(catalogElement('h3', '', 'SG ' + sheet.min_salary_grade + '–' + sheet.max_salary_grade + ' positions'));
            const table = catalogElement('table', 'catalog-table');
            const header = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Position', 'Salary grades', 'Generated sheets'].forEach(text => headerRow.appendChild(catalogElement('th', '', text)));
            header.appendChild(headerRow);
            const body = document.createElement('tbody');
            sheet.positions.forEach(positionTitle => {
                const generated = officeTemplates.filter(template => {
                    if (template.source_sheet !== sheet.name) return false;
                    const match = template.title.match(/^SG (\d+) - (.+)$/);
                    return match
                        && match[2] === positionTitle
                        && Number(match[1]) >= sheet.min_salary_grade
                        && Number(match[1]) <= sheet.max_salary_grade;
                });
                const row = document.createElement('tr');
                row.append(
                    catalogElement('td', '', positionTitle),
                    catalogElement('td', '', 'SG ' + sheet.min_salary_grade + '–' + sheet.max_salary_grade),
                    catalogElement('td', '', generated.length
                        ? generated.map(template => template.title + (Number(template.is_shared) === 1 ? ' · Shared' : ' · Private')).join(', ')
                        : 'Not generated')
                );
                body.appendChild(row);
            });
            table.append(header, body);
            card.appendChild(table);
        });
        officeCatalogView.appendChild(card);
    }

    function renderOfficeCatalog(officeType) {
        const catalog = OFFICE_CATALOG[officeType];
        officeCatalogView.replaceChildren();
        if (!catalog) return;

        const officeTemplates = templates.filter(template => template.office_type === officeType);
        const heading = catalogElement('h1', '', catalog.label + ' sheets and positions');
        const summaryText = officeType === 'RO'
            ? officeTemplates.length + ' Region templates · ' + catalog.positions.length + ' configured Region positions'
            : officeTemplates.length + ' Field templates · 2 salary-grade groups';
        const summary = catalogElement('p', 'catalog-summary', summaryText);
        officeCatalogView.append(heading, summary);

        if (officeType === 'RO') {
            const sheetCard = catalogElement('section', 'catalog-card');
            sheetCard.append(
                catalogElement('h2', '', 'Region positions'),
                catalogElement('p', '', 'Choose a salary-grade range to view its positions.')
            );
            const sheetList = catalogElement('div', 'catalog-sheet-list');
            sheetList.append(
                catalogJumpButton('Basic (SG 16 and below)', 'regionBasicPositionsCard'),
                catalogJumpButton('Advanced (SG 17 and above)', 'regionAdvancedPositionsCard'),
                catalogJumpButton('Generated Region sheets', 'generatedRegionSheetsCard')
            );
            sheetCard.appendChild(sheetList);
            officeCatalogView.appendChild(sheetCard);

            appendRegionPositionsCard(
                'regionBasicPositionsCard',
                'Basic (SG 16 and below)',
                catalog.positions.filter(position => Number(position.salary_grade) <= 16)
            );
            appendRegionPositionsCard(
                'regionAdvancedPositionsCard',
                'Advanced (SG 17 and above)',
                catalog.positions.filter(position => Number(position.salary_grade) >= 17)
            );

            const templateCard = catalogElement('section', 'catalog-card');
            templateCard.id = 'generatedRegionSheetsCard';
            templateCard.append(
                catalogElement('h2', '', 'Generated Region sheets'),
                catalogElement('p', '', 'Region templates and their sharing status.')
            );
            appendOfficeTemplateTable(templateCard, officeTemplates, officeType);
            officeCatalogView.appendChild(templateCard);
            return;
        }

        const fieldSheetGroups = [
            {
                id: 'fieldBasicPositionsCard',
                label: 'Basic (SG 16 and below)',
                sheets: catalog.sheets.filter(sheet => Number(sheet.min_salary_grade) <= 16)
            },
            {
                id: 'fieldAdvancedPositionsCard',
                label: 'Advanced (SG 17 and above)',
                sheets: catalog.sheets.filter(sheet => Number(sheet.min_salary_grade) >= 17)
            }
        ];
        const fieldSheetListCard = catalogElement('section', 'catalog-card');
        fieldSheetListCard.append(
            catalogElement('h2', '', 'Field positions'),
            catalogElement('p', '', 'Choose a salary-grade range to view its positions.')
        );
        const fieldSheetList = catalogElement('div', 'catalog-sheet-list');
        fieldSheetGroups.forEach(group => fieldSheetList.appendChild(catalogJumpButton(group.label, group.id)));
        fieldSheetList.appendChild(catalogJumpButton('Generated Field sheets', 'generatedFieldSheetsCard'));
        fieldSheetListCard.appendChild(fieldSheetList);
        officeCatalogView.appendChild(fieldSheetListCard);

        fieldSheetGroups.forEach(group => appendFieldPositionsCard(
            group.id,
            group.label,
            group.sheets,
            officeTemplates
        ));

        const templateCard = catalogElement('section', 'catalog-card');
        templateCard.id = 'generatedFieldSheetsCard';
        templateCard.append(
            catalogElement('h2', '', 'Generated Field sheets'),
            catalogElement('p', '', 'All Field templates, including their source sheet and sharing status.')
        );
        appendOfficeTemplateTable(templateCard, officeTemplates, officeType);
        officeCatalogView.appendChild(templateCard);
    }

    function appendOfficeTemplateTable(card, officeTemplates, officeType) {
        if (!officeTemplates.length) {
            card.appendChild(catalogElement('div', 'catalog-empty', 'No generated sheets are available yet.'));
            return;
        }
        const table = catalogElement('table', 'catalog-table');
        const header = document.createElement('thead');
        const headerRow = document.createElement('tr');
        ['Sheet', 'Type', 'Salary grades', 'Availability', 'Action'].forEach(label => headerRow.appendChild(catalogElement('th', '', label)));
        header.appendChild(headerRow);
        const body = document.createElement('tbody');
        officeTemplates
            .slice()
            .sort((a, b) => a.title.localeCompare(b.title))
            .forEach(template => {
                const gradeRange = template.min_salary_grade !== null && template.max_salary_grade !== null
                    ? 'SG ' + template.min_salary_grade + '–' + template.max_salary_grade
                    : 'Not set';
                const row = document.createElement('tr');
                const availability = Number(template.is_shared) === 1 ? 'Shared with raters' : 'Private';
                const actionCell = document.createElement('td');
                const shareButton = catalogElement('button', 'button', Number(template.is_shared) === 1 ? 'Unshare' : 'Share');
                shareButton.type = 'button';
                shareButton.addEventListener('click', async () => {
                    shareButton.disabled = true;
                    try {
                        const data = await api('template_set_shared', {
                            id: template.id,
                            is_shared: Number(template.is_shared) !== 1
                        });
                        templates = templates.map(item => item.id === template.id
                            ? { ...item, is_shared: data.is_shared }
                            : item);
                        renderOfficeCatalog(officeType);
                    } catch (error) {
                        templateShowAlert('Sharing status could not be changed: ' + error.message);
                        shareButton.disabled = false;
                    }
                });
                actionCell.appendChild(shareButton);
                row.append(
                    catalogElement('td', '', template.title),
                    catalogElement('td', '', template.source_sheet
                        ? SOURCE_SHEET_LABELS[template.source_sheet] || template.source_sheet
                        : 'Region'),
                    catalogElement('td', '', gradeRange),
                    catalogElement('td', '', availability),
                    actionCell
                );
                body.appendChild(row);
            });
        table.append(header, body);
        card.appendChild(table);
    }

    async function showOfficeCatalog(officeType) {
        if (!await saveCurrentTemplate()) return;
        activeOfficeCatalogType = officeType;
        currentTemplate = null;
        activeSectionKey = null;
        renderSections();
        renderOfficeCatalog(officeType);
        templateHomeContent.classList.add('hidden');
        officeCatalogView.classList.remove('hidden');
        editorView.style.display = 'none';
        homeView.style.display = 'block';
        document.querySelectorAll('.rail button').forEach(button => button.classList.remove('active'));
        document.getElementById(officeType === 'RO' ? 'navRegion' : 'navField').classList.add('active');
    }

    function updateBatchDeleteControls() {
        const count = selectedTemplateIds.size;
        deleteSelectedBtn.textContent = 'Delete selected (' + count + ')';
        deleteSelectedBtn.disabled = count === 0;
        const selectedVisible = visibleTemplateIds.filter(id => selectedTemplateIds.has(id)).length;
        selectAllVisible.checked = visibleTemplateIds.length > 0 && selectedVisible === visibleTemplateIds.length;
        selectAllVisible.indeterminate = selectedVisible > 0 && selectedVisible < visibleTemplateIds.length;
    }

    selectAllVisible.addEventListener('change', () => {
        const shouldSelect = selectAllVisible.checked;
        visibleTemplateIds.forEach(id => {
            if (shouldSelect) selectedTemplateIds.add(id);
            else selectedTemplateIds.delete(id);
        });
        templateList.querySelectorAll('.template-select').forEach(checkbox => {
            checkbox.checked = shouldSelect;
        });
        updateBatchDeleteControls();
    });

    deleteSelectedBtn.addEventListener('click', async () => {
        const ids = Array.from(selectedTemplateIds);
        const count = ids.length;
        if (!count || !await templateShowConfirm('Delete ' + count + ' templates? This cannot be undone.', { confirmText: 'Delete' })) return;
        bulkDeleteStatus.textContent = '';
        try {
            const data = await api('template_delete_many', { ids });
            templates = templates.filter(template => !ids.includes(template.id));
            renderTemplateList();
            bulkDeleteStatus.textContent = 'Deleted ' + data.deleted + ' template(s).';
        } catch (error) {
            bulkDeleteStatus.textContent = error.message;
        }
    });

    async function showHome() {
        if (!await saveCurrentTemplate()) return;
        const updateView = () => {
            activeOfficeCatalogType = null;
            currentTemplate = null;
            activeSectionKey = null;
            renderTemplateList();
            renderSections();
            officeCatalogView.classList.add('hidden');
            templateHomeContent.classList.remove('hidden');
            editorView.style.display = 'none';
            homeView.style.display = 'block';
            document.querySelectorAll('.rail button').forEach(button => button.classList.remove('active'));
            document.getElementById('navHome').classList.add('active');
        };
        if (typeof window.runUITransition === 'function') window.runUITransition(updateView, 'workspace');
        else updateView();
    }

    function showEditor() {
        const updateView = () => {
            homeView.style.display = 'none';
            editorView.style.display = 'flex';
            document.querySelectorAll('.rail button').forEach(button => button.classList.remove('active'));
        };
        if (typeof window.runUITransition === 'function') window.runUITransition(updateView, 'workspace');
        else updateView();
    }

    function markDirty() {
        if (!currentTemplate) return;
        isDirty = true;
        setStatus('Unsaved changes', 'saving');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveCurrentTemplate, 650);
    }

    async function saveCurrentTemplate() {
        if (savePromise) {
            const saved = await savePromise;
            if (saved && isDirty) return saveCurrentTemplate();
            return saved;
        }
        if (!currentTemplate || !isDirty) return true;
        const task = (async () => {
            setStatus('Saving…', 'saving');
            try {
                while (currentTemplate && isDirty) {
                    const template = currentTemplate;
                    const title = titleInput.value.trim() || 'Untitled template';
                    template.layout.office_type = templateOfficeTypeInput.value;
                    template.layout.source_sheet = templateSourceSheetInput.value || '';
                    isDirty = false;
                    const data = await api('template_save', {
                        id: template.id,
                        title,
                        layout: template.layout,
                        min_salary_grade: Number(minSalaryGradeInput.value),
                        max_salary_grade: Number(maxSalaryGradeInput.value),
                        region: templateRegionInput.value || null,
                        field_name: null,
                        is_shared: templateSharedInput.checked
                    });
                    if (currentTemplate === template) {
                        template.title = data.title;
                        template.min_salary_grade = Number(minSalaryGradeInput.value);
                        template.max_salary_grade = Number(maxSalaryGradeInput.value);
                        template.region = templateRegionInput.value || null;
                        template.field_name = null;
                        template.is_shared = templateSharedInput.checked ? 1 : 0;
                        template.office_type = templateOfficeTypeInput.value;
                        titleInput.value = data.title;
                        templates = templates.map(item => item.id === template.id ? {
                            ...item,
                            title: data.title,
                            min_salary_grade: template.min_salary_grade,
                            max_salary_grade: template.max_salary_grade,
                            region: template.region,
                            field_name: template.field_name,
                            is_shared: template.is_shared,
                            office_type: template.office_type
                        } : item);
                    }
                }
                renderTabs();
                renderTemplateList();
                setStatus('All changes saved');
                return true;
            } catch (error) {
                isDirty = true;
                setStatus(error.message, 'error');
                return false;
            }
        })();
        savePromise = task;
        try {
            return await task;
        } finally {
            savePromise = null;
        }
    }

    async function openTemplate(id) {
        if (!await saveCurrentTemplate()) return;
        try {
            const data = await api('template_load', { id });
            currentTemplate = data.template;
            currentTemplate.layout = currentTemplate.layout || { sections: {} };
            currentTemplate.layout.sections = currentTemplate.layout.sections || {};
            currentTemplate.layout.sections = withStandardSections(currentTemplate.layout.sections);
            currentTemplate.layout.labels = { ...DEFAULT_SHEET_LABELS, ...(currentTemplate.layout.labels || {}) };
            currentTemplate.layout.office_type = currentTemplate.layout.office_type || 'RO';
            currentTemplate.layout.source_sheet = currentTemplate.layout.source_sheet || '';
            titleInput.value = currentTemplate.title || 'Untitled template';
            minSalaryGradeInput.value = currentTemplate.min_salary_grade ?? 0;
            maxSalaryGradeInput.value = currentTemplate.max_salary_grade ?? 17;
            templateRegionInput.value = currentTemplate.region || '';
            templateOfficeTypeInput.value = currentTemplate.layout.office_type;
            templateSourceSheetInput.value = currentTemplate.layout.source_sheet;
            syncSourceSheetChoices();
            templateSharedInput.checked = Number(currentTemplate.is_shared) === 1;
            activeSectionKey = Object.keys(currentTemplate.layout.sections)[0] || null;
            isDirty = false;
            isNewTemplate = false;
            renderSections();
            renderSheetLabelFields();
            showEditor();
            setStatus('All changes saved');
        } catch (error) {
            setStatus(error.message, 'error');
        }
    }

    async function createTemplate() {
        if (!await saveCurrentTemplate()) return;
        try {
            const data = await api('template_create', {
                title: 'Untitled template',
                layout: { sections: standardSections(), labels: { ...DEFAULT_SHEET_LABELS }, office_type: 'RO', source_sheet: '' },
                min_salary_grade: 0,
                max_salary_grade: 17,
                region: DEFAULT_TEMPLATE_REGION,
                field_name: null,
                is_shared: false
            });
            const template = {
                id: data.id,
                title: data.title,
                layout: { sections: standardSections(), labels: { ...DEFAULT_SHEET_LABELS } },
                min_salary_grade: 0,
                max_salary_grade: 17,
                region: DEFAULT_TEMPLATE_REGION,
                field_name: null,
                is_shared: 0,
                office_type: 'RO'
            };
            templates.unshift(template);
            currentTemplate = template;
            isNewTemplate = true;
            titleInput.value = template.title;
            minSalaryGradeInput.value = template.min_salary_grade;
            maxSalaryGradeInput.value = template.max_salary_grade;
            templateRegionInput.value = DEFAULT_TEMPLATE_REGION;
            templateOfficeTypeInput.value = 'RO';
            templateSourceSheetInput.value = '';
            syncSourceSheetChoices();
            templateSharedInput.checked = false;
            activeSectionKey = 'core';
            isDirty = false;
            renderSheetLabelFields();
            showEditor();
            renderSections();
        } catch (error) {
            setStatus(error.message, 'error');
        }
    }

    function addEmptySection() {
        if (!currentTemplate) return;
        const secKey = freshId('sec');
        const rowId = freshId('custom');
        currentTemplate.layout.sections[secKey] = {
            custom: true,
            label: '',
            level: Number(currentTemplate.min_salary_grade) <= BASIC_MAX_SG ? 'Basic' : 'Advanced',
            rating_label: 'Rating',
            rows: [{ id: rowId, title: '', desc: '', is_header: false }]
        };
        activeSectionKey = secKey;
        renderSections();
        markDirty();
        const input = sectionList.querySelector('.sec-label[data-section="' + secKey + '"]');
        input?.scrollIntoView({ block: 'center' });
        input?.focus();
    }

    function addCompetency(key) {
        const section = currentTemplate?.layout.sections[key];
        if (!section) return;
        if (section.standard) {
            delete section.standard;
            section.custom = true;
        }
        activeSectionKey = key;
        section.rows.push({ id: freshId('custom'), title: '', desc: '', is_header: false });
        renderSections();
        markDirty();
        const rowInputs = sectionList.querySelectorAll('.row-title[data-section="' + key + '"]');
        const input = rowInputs[rowInputs.length - 1];
        input?.focus();
    }

    async function deleteTemplate() {
        if (!currentTemplate || !await templateShowConfirm('Delete this template?', { confirmText: 'Delete' })) return;
        try {
            const id = currentTemplate.id;
            await api('template_delete', { id });
            templates = templates.filter(template => template.id !== id);
            currentTemplate = null;
            activeSectionKey = null;
            isDirty = false;
            await showHome();
            setStatus('All changes saved');
        } catch (error) {
            setStatus(error.message, 'error');
        }
    }

    async function useTemplate() {
        if (!currentTemplate || !await saveCurrentTemplate()) return;
        const salaryGrade = Number(currentTemplate.min_salary_grade);
        const gradeParam = Number.isInteger(salaryGrade) && salaryGrade >= 0 && salaryGrade <= 26
            ? '&salary_grade=' + encodeURIComponent(salaryGrade)
            : '';
        window.location.href = 'index.php?template_id=' + encodeURIComponent(currentTemplate.id)
            + '&from_template_manager=1' + gradeParam;
    }

    async function saveTemplateNow() {
        clearTimeout(saveTimer);
        if (!currentTemplate) return;
        isDirty = true;
        if (!await saveCurrentTemplate()) {
            templateShowAlert('The template could not be saved. Check the save status and try again.');
            return;
        }
        templateShowAlert(isNewTemplate ? 'New sheet saved successfully.' : 'Sheet saved successfully.');
        isNewTemplate = false;
    }

    titleInput.addEventListener('input', () => {
        if (currentTemplate) currentTemplate.title = titleInput.value.trim() || 'Untitled template';
        markDirty();
    });
    [minSalaryGradeInput, maxSalaryGradeInput, templateRegionInput, templateSharedInput].forEach(input => {
        input.addEventListener('input', markDirty);
        input.addEventListener('change', markDirty);
    });
    templateOfficeTypeInput.addEventListener('change', () => {
        syncSourceSheetChoices();
        if (!currentTemplate) return;
        currentTemplate.layout.office_type = templateOfficeTypeInput.value;
        currentTemplate.layout.source_sheet = templateSourceSheetInput.value || '';
        markDirty();
    });
    templateSourceSheetInput.addEventListener('change', () => {
        if (!currentTemplate) return;
        currentTemplate.layout.source_sheet = templateSourceSheetInput.value || '';
        markDirty();
    });
    sectionList.addEventListener('input', event => {
        const target = event.target;
        const section = currentTemplate?.layout.sections[target.dataset.section];
        if (!section) return;
        if (target.classList.contains('sec-label')) section.label = target.value;
        else if (target.classList.contains('sec-level')) {
            section.level = target.value;
            if (target.dataset.section === 'lead') {
                syncLeadershipRows(section);
                renderSections();
            }
        }
        else if (target.classList.contains('sec-rating-label')) section.rating_label = target.value;
        else if (target.classList.contains('row-title') || target.classList.contains('row-desc')) {
            const row = section.rows.find(item => item.id === target.dataset.rowId);
            if (row) row[target.classList.contains('row-title') ? 'title' : 'desc'] = target.value;
        } else return;
        if (target.classList.contains('sec-label')) {
            const option = sectionSelect.querySelector('option[value="' + target.dataset.section + '"]');
            if (option) option.textContent = target.value || 'Untitled section';
            renderTabs();
        }
        markDirty();
    });
    sectionList.addEventListener('change', event => {
        if (event.target.classList.contains('sec-level')) {
            event.target.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    sheetLabelFields.addEventListener('input', event => {
        const input = event.target;
        const key = input.dataset.sheetLabel;
        if (!key || !currentTemplate) return;
        currentTemplate.layout.labels[key] = input.value;
        markDirty();
    });

    sectionList.addEventListener('click', async event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const key = button.dataset.section;
        const section = currentTemplate?.layout.sections[key];
        if (!section) return;
        if (button.dataset.action === 'add-competency') {
            addCompetency(key);
        } else if (button.dataset.action === 'delete-row') {
            section.rows = section.rows.filter(row => row.id !== button.dataset.rowId);
            renderSections();
            markDirty();
        } else if (button.dataset.action === 'delete-section'
            && await templateShowConfirm('Delete this whole section and all its competencies?', { confirmText: 'Delete' })) {
            delete currentTemplate.layout.sections[key];
            activeSectionKey = Object.keys(currentTemplate.layout.sections)[0] || null;
            renderSections();
            markDirty();
        }
    });

    document.getElementById('newTemplateBtn').addEventListener('click', createTemplate);
    document.getElementById('tplNew').addEventListener('click', createTemplate);
    document.getElementById('navNew').addEventListener('click', createTemplate);
    document.getElementById('navHome').addEventListener('click', showHome);
    document.getElementById('navOpen').addEventListener('click', showHome);
    document.getElementById('navRegion').addEventListener('click', () => showOfficeCatalog('RO'));
    document.getElementById('navField').addEventListener('click', () => showOfficeCatalog('FO'));
    document.getElementById('btnBack').addEventListener('click', showHome);
    document.getElementById('emptyNewBtn').addEventListener('click', addEmptySection);
    document.getElementById('addEmptyRowBtn').addEventListener('click', addEmptySection);
    document.getElementById('addCompetencyBtn').addEventListener('click', () => addCompetency(sectionSelect.value));
    document.getElementById('useTemplateBtn').addEventListener('click', useTemplate);
    document.getElementById('saveTemplateBtn').addEventListener('click', saveTemplateNow);
    document.getElementById('deleteTemplateBtn').addEventListener('click', deleteTemplate);
    sectionSelect.addEventListener('change', () => {
        activeSectionKey = sectionSelect.value;
        renderSections();
    });
    searchInput.addEventListener('input', renderTemplateList);
    [
        ['jumpToRegionSheets', 'regionSheetsHeading'],
        ['jumpToFieldSheets', 'fieldSheetsHeading']
    ].forEach(([buttonId, headingId]) => {
        document.getElementById(buttonId).addEventListener('click', () => {
            if (searchInput.value) {
                searchInput.value = '';
                renderTemplateList();
            }
            document.getElementById(headingId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    renderSheetLabelFields();
    window.addEventListener('beforeunload', event => {
        if (isDirty || savePromise) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    (async function initialize() {
        try {
            const data = await api('template_list');
            templates = data.templates;
            renderTemplateList();
        } catch (error) {
            templateList.textContent = 'Failed to load templates. ' + (error.message || 'Please refresh and try again.');
        }
    })();
</script>
</body>
</html>
