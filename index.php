<?php

namespace UWMadison\MailMerge;

/** @var \ExternalModules\MailMerge $module */

use REDCap;

$projectId = (int)$_GET['pid'];
$access = $module->checkFileRepoAccess();
if (!$access['allowed']) {
    echo "<div class='container-fluid py-5 px-4' style='max-width: 900px;'>
        <div class='alert alert-warning border shadow-sm p-4 d-flex align-items-center gap-3 rounded-3'>
            <i class='fas fa-exclamation-triangle fa-2x text-warning'></i>
            <div>
                <h5 class='fw-bold mb-1'>File Repository Access Required</h5>
                <div class='text-dark'>" . htmlspecialchars($access['message'], ENT_QUOTES) . "</div>
            </div>
        </div>
    </div>";
    return;
}

$module->initializeJavascriptModuleObject();

$fields = REDCap::getFieldNames();
$isLongitudinal = REDCap::isLongitudinal();
$events = [];
if ($isLongitudinal) {
    global $Proj;
    $p = (isset($Proj) && $Proj->project_id == $projectId) ? $Proj : new \Project($projectId);
    $numArms = $p->numArms ?? count($p->events ?? [1]);
    foreach (REDCap::getEventNames(true, false) as $id => $name) {
        $displayName = ($numArms <= 1) ? preg_replace('/_arm_\d+$/i', '', $name) : $name;
        $events[] = ['id' => $id, 'name' => $displayName];
    }
}

$templates = $module->getTemplates($projectId);
$rules = $module->getLetterRules($projectId);
$settings = $module->getProjectConfig($projectId);
$queueLastFullEvaluation = $module->getProjectSetting('queue_last_full_evaluation', $projectId) ?: null;
$allowServerPath = (bool)$module->getSystemSetting('allow_server_path_output');
$alpineUrl = $module->getUrl('alpine.min.js');
$indexJsUrl = $module->getUrl('index.js');
?>
<script>
    const module = <?= $module->getJavascriptModuleObjectName() ?>;
    module.config = <?= json_encode([
        'templates' => $templates,
        'rules' => $rules,
        'settings' => $settings,
        'queueLastFullEvaluation' => $queueLastFullEvaluation,
        'allowServerPath' => $allowServerPath,
        'events' => $events,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= htmlspecialchars($indexJsUrl, ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars($alpineUrl, ENT_QUOTES) ?>" defer></script>

<style>
    [x-cloak] {
        display: none !important;
    }

    .card {
        border: 1px solid #c2c9d2 !important;
        border-radius: 0.5rem !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08) !important;
        background-color: #ffffff;
    }

    .card-header {
        background-color: #f4f6f9 !important;
        border-bottom: 1px solid #c2c9d2 !important;
    }

    .rule-card {
        border: 1px solid #c2c9d2 !important;
        background-color: #fcfdfe !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }

    .drop-zone {
        border: 2px dashed #0d6efd;
        background-color: #f8faff;
        transition: all 0.2s ease-in-out;
        cursor: pointer;
        text-align: center !important;
    }

    .drop-zone p,
    .drop-zone h5 {
        text-align: center !important;
    }

    .drop-zone.dragover {
        background-color: #e7f1ff;
        border-color: #0a58ca;
    }

    .badge-tag {
        background-color: #e9ecef;
        color: #495057;
        font-weight: 500;
    }

    .nav-tabs .nav-link {
        color: #495057;
        border: none;
        border-bottom: 2px solid transparent;
        padding: 0.6rem 1.2rem;
    }

    .nav-tabs .nav-link.active {
        color: #0d6efd;
        font-weight: 600;
        border-bottom: 2px solid #0d6efd;
        background: transparent;
    }

    .hover-tip {
        position: absolute;
        bottom: 130%;
        left: 50%;
        transform: translateX(-50%);
        background: #212529;
        color: #fff;
        padding: 0.45rem 0.65rem;
        border-radius: 0.375rem;
        font-size: 0.75rem;
        line-height: 1.35;
        white-space: normal;
        width: 260px;
        z-index: 1060;
        pointer-events: none;
        text-align: left;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.2);
    }

    .hover-tip::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border-width: 5px;
        border-style: solid;
        border-color: #212529 transparent transparent transparent;
    }

    [x-cloak] {
        display: none !important;
    }

    .rule-card,
    .card {
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .rule-card .row,
    .card-body .row {
        margin-left: 0 !important;
        margin-right: 0 !important;
        max-width: 100% !important;
    }

    .rule-card .row>*,
    .card-body .row>* {
        min-width: 0 !important;
        padding-left: 0.5rem !important;
        padding-right: 0.5rem !important;
    }

    .rule-card input,
    .rule-card select,
    .card-body input,
    .card-body select {
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .rule-card code,
    .alert code {
        white-space: normal !important;
        word-break: break-word !important;
    }

    .rule-card table {
        table-layout: fixed !important;
        width: 100% !important;
    }

    .rule-card table td,
    .rule-card table th {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    #center {
        min-height: calc(100vh - 120px) !important;
        min-width: 0 !important;
        overflow-x: hidden !important;
    }

    .queue-table-wrapper,
    .rule-mapping-table-wrapper {
        max-width: 100%;
        overflow-x: hidden !important;
    }

    #queueTable th,
    #queueTable td {
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .mailmerge-main-container {
        min-height: calc(100vh - 220px);
        min-width: 0;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow-x: hidden;
    }

    .mailmerge-main-container>[x-show] {
        min-width: 0;
        width: 100%;
        max-width: 100%;
    }
</style>

<div class="pe-3 py-3 w-100 mailmerge-main-container" style="max-width: 100%;" x-data="mailMergeApp" x-cloak>
    <!-- Top Bar -->
    <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-3 p-3 shadow-sm d-flex align-items-center justify-content-center">
                <i class="fas fa-envelope-open-text fa-2x"></i>
            </div>
            <div>
                <h3 class="fw-bold mb-0 text-dark">Mail Merge</h3>
                <span class="text-muted small">Manage templates, automated letter rules, and participant generation queue.</span>
            </div>
        </div>

        <div class="d-flex gap-2 align-items-center">
            <!-- Queue Tab Actions -->
            <template x-if="activeTab === 'queue'">
                <div class="dropdown">
                    <button type="button" class="btn btn-primary px-3 py-2 shadow-sm fw-bold dropdown-toggle d-inline-flex align-items-center" data-bs-toggle="dropdown" aria-expanded="false" :disabled="generating || evaluatingQueue">
                        <i class="fas fa-tasks me-2"></i>
                        <span x-text="generating ? 'Processing...' : ('Actions (' + selected.length + ')')"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li>
                            <button type="button" class="dropdown-item py-2" @click="generateSelected()" :disabled="selected.length === 0 || generating || evaluatingQueue">
                                <i class="fas fa-print text-primary me-2"></i>Generate Selected
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item py-2" @click="confirmResolveSelected()" :disabled="selected.length === 0 || generating || evaluatingQueue">
                                <i class="fas fa-check text-success me-2"></i>Mark Selected as Resolved
                            </button>
                        </li>
                        <li>
                            <hr class="dropdown-divider my-1">
                        </li>
                        <li>
                            <button type="button" class="dropdown-item py-2" @click="confirmBothSelected()" :disabled="selected.length === 0 || generating || evaluatingQueue">
                                <i class="fas fa-magic text-primary me-2"></i>Both (Generate & Mark Resolved)
                            </button>
                        </li>
                        <li>
                            <hr class="dropdown-divider my-1">
                        </li>
                        <li>
                            <button type="button" class="dropdown-item py-2" @click="runFullQueueEvaluation()" :disabled="generating || evaluatingQueue">
                                <i class="fas fa-sync-alt text-secondary me-2"></i>Run Full Evaluation Now
                            </button>
                        </li>
                    </ul>
                </div>
            </template>

            <!-- Configuration Tab Actions -->
            <template x-if="activeTab === 'config'">
                <button type="button" class="btn btn-success px-4 py-2 shadow-sm fw-bold d-inline-flex align-items-center justify-content-center" style="min-width: 105px;" @click="saveAllConfiguration()" :disabled="saving" title="Save Configuration">
                    <i class="fas me-2" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                    <span>Save</span>
                </button>
            </template>
        </div>
    </div>

    <!-- Alert Banner -->
    <div x-show="alert.msg" x-transition class="alert alert-dismissible mb-3 shadow-sm" :class="'alert-' + alert.type">
        <span x-text="alert.msg"></span>
        <button type="button" class="btn-close" @click="alert.msg = ''"></button>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4 border-bottom">
        <li class="nav-item">
            <button class="nav-link" :class="{ 'active': activeTab === 'queue' }" @click="activeTab = 'queue'">
                <i class="fas fa-print me-1"></i> Queue
                <span class="badge bg-primary ms-1" x-text="queue.length" x-show="queue.length > 0"></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" :class="{ 'active': activeTab === 'templates' }" @click="activeTab = 'templates'">
                <i class="fas fa-file-word me-1"></i> Template Library
                <span class="badge bg-secondary ms-1" x-text="templates.length" x-show="templates.length > 0"></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" :class="{ 'active': activeTab === 'config' }" @click="activeTab = 'config'">
                <i class="fas fa-cogs me-1"></i> Configuration
                <span class="badge bg-light text-muted border ms-1" x-text="rules.length" x-show="rules.length > 0"></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" :class="{ 'active': activeTab === 'archive' }" @click="activeTab = 'archive'; if (archive.length === 0) loadArchive();">
                <i class="fas fa-archive me-1"></i> Archive
                <span class="badge bg-secondary ms-1" x-text="archive.length" x-show="archive.length > 0"></span>
            </button>
        </li>
    </ul>

    <!-- TAB 1: QUEUE -->
    <div x-show="activeTab === 'queue'">
        <div class="card shadow">
            <div class="card-header py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-tasks me-2 text-primary"></i>Letters Due</h5>
                    <span class="badge bg-primary" x-text="queue.length + ' Pending'"></span>
                </div>
                <div>
                    <label for="queueSearch" class="visually-hidden">Search queue</label>
                    <input id="queueSearch" type="search" class="form-control" style="width: 240px; max-width: 45vw;" placeholder="Search queue..." x-model="queueSearch" @input="currentPage = 1">
                </div>
            </div>
            <div class="card-body p-0">
                <!-- Records Table & Pagination -->
                <template x-if="!loadingQueue && queue.length > 0">
                    <div>
                        <div class="table-responsive queue-table-wrapper" x-show="filteredQueue().length > 0">
                            <table id="queueTable" class="table table-hover align-middle mb-0 w-100" style="table-layout: fixed;">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr>
                                        <th style="width: 40px;" class="text-center">
                                            <input class="form-check-input" type="checkbox" id="selectAll" @change="toggleSelectAll($event)" :checked="isAllSelected()">
                                        </th>
                                        <th>Record ID</th>
                                        <th>Recipient</th>
                                        <th>Letter Title</th>
                                        <th>Event</th>
                                        <th>Status</th>
                                        <th class="text-end" style="width: 105px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="item in pagedQueue()" :key="itemKey(item)">
                                        <tr>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" :value="itemKey(item)" x-model="selected">
                                            </td>
                                            <td class="fw-bold text-primary" x-text="item.record_id"></td>
                                            <td x-text="item.recipient"></td>
                                            <td>
                                                <span class="badge bg-light text-dark border px-2 py-1" style="padding: 0.35rem 0.55rem; font-size: 0.8rem;" x-text="item.rule_title"></span>
                                            </td>
                                            <td class="small text-muted" x-text="item.event_name || 'Default'"></td>
                                            <td>
                                                <span class="badge bg-info text-dark px-2 py-1" style="padding: 0.35rem 0.55rem; font-size: 0.8rem;" x-show="item.is_generated">
                                                    <i class="fas fa-file-alt me-1"></i>Generated
                                                </span>
                                                <span class="badge bg-light text-muted border px-2 py-1" style="padding: 0.35rem 0.55rem; font-size: 0.8rem;" x-show="!item.is_generated">
                                                    <i class="fas fa-clock me-1"></i>Due
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex align-items-center justify-content-end gap-1">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" style="padding: 0.35rem 0.65rem;" title="Generate Letter" @click="generateSingle(item)" :disabled="generating || evaluatingQueue">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success btn-sm" style="padding: 0.35rem 0.65rem;" title="Mark as Resolved / Printed" @click="confirmResolveSingle(item)" :disabled="generating || evaluatingQueue">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Footer -->
                        <div class="d-flex align-items-center justify-content-between p-3 border-top bg-light flex-wrap gap-2" x-show="filteredQueue().length > 0">
                            <div class="d-flex align-items-center gap-2 text-muted small flex-wrap">
                                <span>Showing <strong class="text-dark" x-text="pageStart()"></strong> to <strong class="text-dark" x-text="pageEnd()"></strong> of <strong class="text-dark" x-text="filteredQueue().length"></strong> records</span>
                                <span class="mx-1 text-muted">|</span>
                                <div class="d-inline-flex align-items-center gap-1">
                                    <label for="pageSizeInput" class="mb-0 text-nowrap">Per page:</label>
                                    <input id="pageSizeInput" type="number" min="1" step="1" class="form-control form-control-sm text-center bg-white py-0 px-1" style="width: 60px; height: 28px;" x-model.number="pageSize" @input="onPageSizeChange()">
                                </div>
                                <span class="mx-1 text-muted">|</span>
                                <span>Full evaluation last ran: <strong class="text-dark" x-text="queueEvaluationLabel()"></strong></span>
                            </div>
                            <div class="d-flex align-items-center gap-1" x-show="totalPages() > 1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="currentPage <= 1" @click="setPage(1)" title="First Page">
                                    <i class="fas fa-angle-double-left"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="currentPage <= 1" @click="setPage(currentPage - 1)" title="Previous Page">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <span class="small text-muted mx-2">
                                    Page <strong class="text-dark" x-text="currentPage"></strong> of <strong class="text-dark" x-text="totalPages()"></strong>
                                </span>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="currentPage >= totalPages()" @click="setPage(currentPage + 1)" title="Next Page">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="currentPage >= totalPages()" @click="setPage(totalPages())" title="Last Page">
                                    <i class="fas fa-angle-double-right"></i>
                                </button>
                            </div>
                        </div>
                        <div class="text-center py-4 text-muted" x-show="filteredQueue().length === 0">
                            No queue records match your search.
                        </div>
                    </div>
                </template>

                <!-- Loading State -->
                <template x-if="loadingQueue">
                    <div class="d-flex flex-column align-items-center justify-content-center text-center py-5 w-100">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2 text-primary"></i>
                        <p class="small text-muted mb-0">Evaluating letter rules against study records...</p>
                    </div>
                </template>

                <!-- Empty State 1: No rules configured yet -->
                <template x-if="!loadingQueue && rules.length === 0">
                    <div class="d-flex flex-column align-items-center justify-content-center text-center py-5 w-100">
                        <i class="fas fa-sliders-h fa-3x text-muted mb-3 opacity-50"></i>
                        <h5 class="fw-bold text-dark">No Letter Rules Configured</h5>
                        <p class="small text-muted mb-3" style="max-width: 420px;">You have not created any letter rules yet. Head to the Configuration tab to define rules and triggers.</p>
                        <button type="button" class="btn btn-primary btn-sm" @click="activeTab = 'config'">
                            <i class="fas fa-cogs me-1"></i> Go to Configuration
                        </button>
                    </div>
                </template>

                <!-- Empty State 2: Rules exist but no records qualify -->
                <template x-if="!loadingQueue && rules.length > 0 && queue.length === 0">
                    <div class="d-flex flex-column align-items-center justify-content-center text-center py-5 w-100">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h5 class="fw-bold text-dark">All caught up!</h5>
                        <p class="small text-muted mb-0" x-text="queueLastFullEvaluation ? 'No participants currently qualify for letters.' : 'Run a full evaluation to find participants who qualify for letters.'"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- TAB 2: TEMPLATE LIBRARY -->
    <div x-show="activeTab === 'templates'">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-file-word me-2 text-primary"></i>Template Library</h5>
                <span class="badge bg-light text-dark border" x-text="templates.length + ' Templates'"></span>
            </div>
            <div class="card-body">
                <!-- Large Dedicated Drag & Drop Area -->
                <div class="drop-zone rounded-3 py-5 px-4 d-flex flex-column align-items-center justify-content-center text-center mb-4"
                    :class="{ 'dragover': isDragging }"
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="handleDrop($event)"
                    @click="$refs.fileInput.click()">
                    <input type="file" x-ref="fileInput" class="d-none" multiple accept=".docx" @change="handleFileSelect($event)">
                    <div class="mb-3 text-center">
                        <i class="fas fa-cloud-upload-alt text-primary" style="font-size: 3.5rem;"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1 text-center w-100" style="text-align: center !important;">Drag and drop Word (.docx) templates here</h5>
                    <p class="text-muted small mb-3 text-center w-100" style="text-align: center !important;">or <span class="text-primary text-decoration-underline fw-semibold">click to browse</span> from your computer</p>
                    <div class="d-flex justify-content-center flex-wrap gap-2 text-center">
                        <span class="badge bg-light text-muted border px-2 py-1 small">Supports uploading multiple templates simultaneously</span>
                        <span class="badge bg-light text-muted border px-2 py-1 small">Extracts merge tags automatically</span>
                    </div>
                </div>

                <!-- Upload Queue -->
                <div x-show="uploadQueue.length > 0" class="mb-3">
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Upload Progress</h6>
                    <div class="list-group shadow-sm">
                        <template x-for="(item, idx) in uploadQueue" :key="idx">
                            <div class="list-group-item d-flex align-items-center justify-content-between py-2">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fas fa-file-word text-primary"></i>
                                    <span class="fw-semibold small" x-text="item.name"></span>
                                    <span class="text-muted small" x-text="'(' + item.size + ')'"></span>
                                </div>
                                <div>
                                    <span x-show="item.status === 'uploading'" class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i> Uploading</span>
                                    <span x-show="item.status === 'done'" class="badge bg-success"><i class="fas fa-check me-1"></i> Done</span>
                                    <span x-show="item.status === 'error'" class="badge bg-danger" x-text="item.error"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Stored Templates -->
                <div class="table-responsive" x-show="templates.length > 0">
                    <table class="table table-hover align-middle mb-0 w-100" style="table-layout: auto;">
                        <thead class="table-light small text-muted text-uppercase">
                            <tr>
                                <th>Template Name</th>
                                <th>Detected Tags</th>
                                <th>Size</th>
                                <th>Uploaded</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="t in templates" :key="t.doc_id">
                                <tr>
                                    <td class="fw-semibold">
                                        <i class="fas fa-file-word text-primary me-2"></i>
                                        <span x-text="t.name"></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <template x-for="tag in t.tags" :key="tag">
                                                <span class="badge badge-tag px-2 py-1 small" x-text="tag"></span>
                                            </template>
                                            <span x-show="!t.tags || t.tags.length === 0" class="text-muted small fst-italic">No tags detected</span>
                                        </div>
                                    </td>
                                    <td class="small text-muted" x-text="t.size"></td>
                                    <td class="small text-muted" x-text="t.uploaded_at"></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-danger btn-sm" @click="deleteTemplate(t.doc_id)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div x-show="templates.length === 0 && uploadQueue.length === 0" class="text-center py-4 text-muted small">
                    No templates uploaded yet. Drag and drop Word files above to begin.
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 4: ARCHIVE -->
    <div x-show="activeTab === 'archive'">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-archive me-2 text-primary"></i>Letter Archive</h5>
                    <span class="badge bg-secondary" x-text="archive.length + ' Logged Letters'"></span>
                </div>
                <div>
                    <label for="archiveSearch" class="visually-hidden">Search archive</label>
                    <input id="archiveSearch" type="search" class="form-control" style="width: 240px; max-width: 45vw;" placeholder="Search archive..." x-model="archiveSearch" @input="archiveCurrentPage = 1">
                </div>
            </div>
            <div class="card-body p-0">
                <!-- Archive Table & Pagination -->
                <template x-if="!loadingArchive && archive.length > 0">
                    <div>
                        <div class="table-responsive" x-show="filteredArchive().length > 0">
                            <table class="table table-hover align-middle mb-0 w-100" style="table-layout: auto;">
                                <thead class="table-light small text-muted text-uppercase">
                                    <tr>
                                        <th>Record ID</th>
                                        <th>Letter Title</th>
                                        <th>Current Status</th>
                                        <th>Generated Date</th>
                                        <th>Generated By</th>
                                        <th>File Name</th>
                                        <th class="text-end" style="width: 160px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="item in pagedArchive()" :key="item.log_id">
                                        <tr>
                                            <td class="fw-bold">
                                                <a class="text-primary text-decoration-none" :href="item.record_url" x-text="item.record"></a>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-light text-dark border" x-text="item.rule_title"></span>
                                                    <span class="badge bg-secondary" x-text="item.version" :title="'Generation ' + item.version" x-show="item.version_count > 1"></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-1 flex-wrap">
                                                    <span class="badge bg-info text-dark">Generated</span>
                                                    <span class="badge bg-success" x-show="item.trigger_status === 'met'">Trigger met</span>
                                                    <span class="badge bg-secondary" x-show="item.trigger_status === 'not_met'">Trigger no longer met</span>
                                                    <span class="badge bg-light text-muted border" x-show="item.trigger_status !== 'met' && item.trigger_status !== 'not_met'">Trigger status unavailable</span>
                                                </div>
                                            </td>
                                            <td class="small text-muted" x-text="item.formatted_date"></td>
                                            <td>
                                                <span class="small fw-semibold text-secondary">
                                                    <i class="fas fa-user text-muted me-1"></i>
                                                    <span x-text="item.username"></span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="small font-monospace text-truncate d-inline-block" style="max-width: 260px;" x-text="item.filename"></span>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex align-items-center justify-content-end gap-1">
                                                    <template x-if="item.download_url">
                                                        <a :href="item.download_url" class="btn btn-outline-primary btn-sm" style="padding: 0.35rem 0.65rem;" title="Download Letter" target="_blank">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </template>
                                                    <template x-if="!item.download_url">
                                                        <span class="badge bg-light text-muted border me-1">Missing</span>
                                                    </template>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" style="padding: 0.35rem 0.65rem;" title="Re-generate Letter" @click="regenerateFromArchive(item)" :disabled="generating || evaluatingQueue">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-warning text-dark btn-sm" style="padding: 0.35rem 0.65rem;" title="Return to Queue" @click="confirmReturnToQueue(item)" :disabled="generating || evaluatingQueue">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Archive Pagination Footer -->
                        <div class="d-flex align-items-center justify-content-between p-3 border-top bg-light flex-wrap gap-2" x-show="filteredArchive().length > 0">
                            <div class="d-flex align-items-center gap-2 text-muted small flex-wrap">
                                <span>Showing <strong class="text-dark" x-text="archivePageStart()"></strong> to <strong class="text-dark" x-text="archivePageEnd()"></strong> of <strong class="text-dark" x-text="filteredArchive().length"></strong> records</span>
                                <span class="mx-1 text-muted">|</span>
                                <div class="d-inline-flex align-items-center gap-1">
                                    <label for="archivePageSizeInput" class="mb-0 text-nowrap">Per page:</label>
                                    <input id="archivePageSizeInput" type="number" min="1" step="1" class="form-control form-control-sm text-center bg-white py-0 px-1" style="width: 60px; height: 28px;" x-model.number="archivePageSize" @input="onArchivePageSizeChange()">
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1" x-show="archiveTotalPages() > 1">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="archiveCurrentPage <= 1" @click="setArchivePage(1)" title="First Page">
                                    <i class="fas fa-angle-double-left"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="archiveCurrentPage <= 1" @click="setArchivePage(archiveCurrentPage - 1)" title="Previous Page">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <span class="small text-muted mx-2">
                                    Page <strong class="text-dark" x-text="archiveCurrentPage"></strong> of <strong class="text-dark" x-text="archiveTotalPages()"></strong>
                                </span>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="archiveCurrentPage >= archiveTotalPages()" @click="setArchivePage(archiveCurrentPage + 1)" title="Next Page">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" :disabled="archiveCurrentPage >= archiveTotalPages()" @click="setArchivePage(archiveTotalPages())" title="Last Page">
                                    <i class="fas fa-angle-double-right"></i>
                                </button>
                            </div>
                        </div>
                        <div class="text-center py-4 text-muted" x-show="filteredArchive().length === 0">
                            No archive records match your search.
                        </div>
                    </div>
                </template>

                <!-- Loading State -->
                <template x-if="loadingArchive">
                    <div class="d-flex flex-column align-items-center justify-content-center text-center py-5 w-100">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2 text-primary"></i>
                        <p class="small text-muted mb-0">Loading generated letters from module log...</p>
                    </div>
                </template>

                <!-- Empty State -->
                <template x-if="!loadingArchive && archive.length === 0">
                    <div class="d-flex flex-column align-items-center justify-content-center text-center py-5 w-100">
                        <i class="fas fa-history fa-3x text-muted mb-3 opacity-50"></i>
                        <h5 class="fw-bold text-dark">No Letters in Archive</h5>
                        <p class="small text-muted mb-0">No letters have been generated by MailMerge yet.</p>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- TAB 4: CONFIGURATION -->
    <div x-show="activeTab === 'config'">
        <!-- Automation & Delivery Section -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-robot me-2 text-primary"></i>Automation & Storage Settings</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold d-flex align-items-center">
                            Delivery & Automation Mode
                            <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                <div x-show="tip" x-transition class="hover-tip">
                                    Determines how due letters are processed by the daily automated cron. Hold in Queue allows manual staff generation, while other options generate letters automatically.
                                </div>
                            </span>
                        </label>
                        <select class="form-select form-select-sm" x-model="settings.automation_mode">
                            <option value="queued">Hold in Queue (Manual Staff Review & Generation)</option>
                            <option value="filerepo">Automated: Generate & Save to File Repository</option>
                            <template x-if="allowServerPath">
                                <option value="server_path">Automated: Generate & Save to Local Server Path</option>
                            </template>
                        </select>
                    </div>

                    <template x-if="allowServerPath && settings.automation_mode === 'server_path'">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold d-flex align-items-center">
                                Server Directory Output Path
                                <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                    <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                    <div x-show="tip" x-transition class="hover-tip">
                                        Absolute path on the server filesystem or network share where generated letters will be saved.
                                    </div>
                                </span>
                            </label>
                            <input type="text" class="form-control form-control-sm font-monospace" x-model="settings.server_output_path" placeholder="e.g. /mnt/letters or C:\Letters">
                        </div>
                    </template>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold d-flex align-items-center">
                            File Repository Templates Folder
                            <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                <div x-show="tip" x-transition class="hover-tip">
                                    Folder path in the project's File Repository where uploaded Word templates will be organized. Supports subfolder paths (e.g. MailMerge Templates or Templates/{date}) and placeholder tags: {date}, {timestamp}.
                                </div>
                            </span>
                        </label>
                        <input type="text" class="form-control form-control-sm" x-model="settings.file_repo_templates_folder" placeholder="e.g. MailMerge Templates">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold d-flex align-items-center">
                            File Repository Generated Letters Folder
                            <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                <div x-show="tip" x-transition class="hover-tip">
                                    Folder path in the project's File Repository where generated letters will be archived. Default: MailMerge Letters/{date}. Supports subfolder paths (e.g. Letters/{date}/{record}) and placeholder tags: {record}, {letter_name}, {date}, {timestamp}.
                                </div>
                            </span>
                        </label>
                        <input type="text" class="form-control form-control-sm" x-model="settings.file_repo_letters_folder" placeholder="e.g. MailMerge Letters/{date}">
                    </div>
                </div>
            </div>
        </div>

        <!-- Rules & Logic Section -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-cogs me-2 text-primary"></i>Letter Rules & Logic</h5>
                <button type="button" class="btn btn-primary px-3 py-2 shadow-sm fw-bold d-inline-flex align-items-center" @click="addRule()">
                    <i class="fas fa-plus me-2"></i> Add Letter Rule
                </button>
            </div>
            <div class="card-body">
                <template x-for="(r, idx) in rules" :key="r.id">
                    <div class="rule-card rounded-3 p-3 mb-3 position-relative">
                        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2 flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width: 0; max-width: 560px;">
                                <div class="input-group input-group-sm flex-grow-1">
                                    <span class="input-group-text bg-secondary text-white fw-bold" x-text="'Rule #' + (idx + 1)"></span>
                                    <input type="text" class="form-control fw-bold" x-model="r.title" placeholder="Letter Title (e.g. Visit 1 Letter)">
                                </div>
                                <span x-show="!r.enabled" class="badge bg-secondary text-white px-2 py-1 text-nowrap shadow-sm" style="font-size: 0.75rem;">
                                    <i class="fas fa-pause-circle me-1"></i>Deactivated
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-3 ms-auto">
                                <div class="form-check form-switch mb-0 ps-0" style="min-height: 0;" title="Toggle Enable / Deactivate Rule">
                                    <input class="form-check-input m-0" type="checkbox" role="switch" :id="'sw_' + r.id" x-model="r.enabled" style="float: none; width: 2.75rem; height: 1.4rem; cursor: pointer;" :title="r.enabled ? 'Click to Deactivate Rule' : 'Click to Enable Rule'">
                                </div>
                                <button type="button" class="btn btn-link btn-sm p-1 text-primary" @click="duplicateRule(r)" title="Duplicate Rule" aria-label="Duplicate Rule">
                                    <i class="fas fa-copy" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="removeRule(idx)" title="Delete Rule">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold">Template File (.docx)</label>
                                <select class="form-select form-select-sm" x-model="r.template_doc_id" @change="onTemplateChange(r)">
                                    <option value="0">-- Select Template --</option>
                                    <template x-for="t in templates" :key="t.doc_id">
                                        <option :value="t.doc_id" x-text="t.name"></option>
                                    </template>
                                </select>
                            </div>

                            <?php if ($isLongitudinal): ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-bold d-flex align-items-center">
                                        Target Event
                                        <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                            <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                            <div x-show="tip" x-transition class="hover-tip">
                                                In longitudinal studies, specifies the event context to evaluate trigger logic against and store the flag field.
                                            </div>
                                        </span>
                                    </label>
                                    <select class="form-select form-select-sm" x-model="r.event_id">
                                        <option value="">-- All / Primary Event --</option>
                                        <template x-for="e in events" :key="e.id">
                                            <option :value="e.id" x-text="e.name"></option>
                                        </template>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold d-flex align-items-center">
                                    Generated Flag Field
                                    <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                        <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                        <div x-show="tip" x-transition class="hover-tip">
                                            A field updated when the letter is generated. If a date or datetime field is selected, it will be set to current date/time. Otherwise, set to 1.
                                        </div>
                                    </span>
                                </label>
                                <input type="text" class="form-control form-control-sm" list="fieldsList" x-model="r.flag_field" placeholder="e.g. letter_generated or ltr_dt">
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold d-flex align-items-center">
                                    Printed / Resolved Flag Field
                                    <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                        <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                        <div x-show="tip" x-transition class="hover-tip">
                                            Optional field updated when marked resolved/printed. If configured, letters stay in the queue until marked resolved. If blank, Generated Flag clears the queue.
                                        </div>
                                    </span>
                                </label>
                                <input type="text" class="form-control form-control-sm" list="fieldsList" x-model="r.printed_flag_field" placeholder="e.g. letter_printed or ltr_sent_dt">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold d-flex align-items-center">
                                    File Naming Scheme
                                    <span class="position-relative d-inline-block ms-1" x-data="{ tip: false }" @mouseenter="tip = true" @mouseleave="tip = false">
                                        <i class="fas fa-info-circle text-muted cursor-pointer"></i>
                                        <div x-show="tip" x-transition class="hover-tip">
                                            Naming pattern for generated Word files. Available tags: {record}, {letter_name}, {date}, {timestamp}. Default: {record}_{letter_name}
                                        </div>
                                    </span>
                                </label>
                                <input type="text" class="form-control form-control-sm font-monospace" x-model="r.filename_scheme" placeholder="{record}_{letter_name}">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Trigger Condition (REDCap Logic)</label>
                                <input type="text" class="form-control form-control-sm font-monospace" x-model="r.condition" placeholder="e.g. [study_status] = '1' and [appt_date] != '' and datediff('today', [appt_date], 'd') <= 10">
                            </div>
                        </div>

                        <!-- Merge Field Mappings Section -->
                        <div class="border-top pt-3 mt-3">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <h6 class="mb-0 fw-bold text-dark text-uppercase small" style="letter-spacing: 0.5px;">
                                        <i class="fas fa-tags text-primary me-1"></i> Merge Field Mappings
                                    </h6>
                                    <span class="badge bg-light text-muted border">Tags</span>
                                </div>
                                <button type="button" class="btn btn-outline-primary btn-sm py-1 px-2 small" @click="addMapping(r)">
                                    <i class="fas fa-plus me-1"></i> Add Tag
                                </button>
                            </div>

                            <div class="row g-2 g-md-3 mb-3">
                                <!-- Card 1 -->
                                <div class="col-12 col-md-6 col-xl-3">
                                    <div class="bg-light border rounded-2 p-2 p-md-3 h-100 d-flex flex-column">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1 border-bottom pb-1">
                                            <i class="fas fa-database text-primary"></i> Fields & Piping
                                        </div>
                                        <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-1">
                                            <li><span class="text-secondary fw-semibold">Standard:</span> <code>[first_name]</code></li>
                                            <li><span class="text-secondary fw-semibold">Event:</span> <code>[baseline][address]</code></li>
                                            <li><span class="text-secondary fw-semibold">Combined:</span> <code>[first_name] [last_name]</code></li>
                                            <li><span class="text-secondary fw-semibold">Special:</span> <code>[record_id]</code></li>
                                            <li class="mt-auto pt-2 text-success" style="font-size: 0.72rem;">
                                                <i class="fas fa-check-circle me-1"></i>Cross-event fallback enabled
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Card 2 -->
                                <div class="col-12 col-md-6 col-xl-3">
                                    <div class="bg-light border rounded-2 p-2 p-md-3 h-100 d-flex flex-column">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1 border-bottom pb-1">
                                            <i class="fas fa-paint-brush text-primary"></i> Formats (<code>:format</code>)
                                        </div>
                                        <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-1">
                                            <li><span class="text-secondary fw-semibold">Date:</span> <code>[dob:m/d/y]</code>, <code>[appt:F j, Y]</code></li>
                                            <li><span class="text-secondary fw-semibold">Text Case:</span> <code>[city:upper]</code>, <code>[name:title]</code></li>
                                            <li><span class="text-secondary fw-semibold">Currency:</span> <code>[fee:currency]</code> <span class="text-dark">($50.00)</span></li>
                                            <li><span class="text-secondary fw-semibold">Decimals:</span> <code>[val:number:2]</code> <span class="text-dark">(12.50)</span></li>
                                            <li><span class="text-secondary fw-semibold">Integer:</span> <code>[val:number:0]</code> <span class="text-dark">(13)</span></li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Card 3 -->
                                <div class="col-12 col-md-6 col-xl-3">
                                    <div class="bg-light border rounded-2 p-2 p-md-3 h-100 d-flex flex-column">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1 border-bottom pb-1">
                                            <i class="fas fa-clock text-primary"></i> Dates & Times
                                        </div>
                                        <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-1">
                                            <li><span class="text-secondary fw-semibold">Today:</span> <code>today</code> &rarr; <span class="text-dark"><?= date('F j, Y') ?></span></li>
                                            <li><span class="text-secondary fw-semibold">Date Format:</span> <code>today:m/d/y</code> &rarr; <span class="text-dark"><?= date('m/d/Y') ?></span></li>
                                            <li><span class="text-secondary fw-semibold">Now:</span> <code>now</code> &rarr; <span class="text-dark"><?= date('M j, Y g:i A') ?></span></li>
                                            <li><span class="text-secondary fw-semibold">Time:</span> <code>time</code> (12h) | <code>time:24</code> (24h)</li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Card 4 -->
                                <div class="col-12 col-md-6 col-xl-3">
                                    <div class="bg-light border rounded-2 p-2 p-md-3 h-100 d-flex flex-column">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1 border-bottom pb-1">
                                            <i class="fas fa-code-branch text-primary"></i> Logic &amp; Functions
                                        </div>
                                        <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-1">
                                            <li><span class="text-secondary fw-semibold">If / Else:</span> <code>if([age] &gt;= 18, 'Adult', 'Minor')</code></li>
                                            <li><span class="text-secondary fw-semibold">Join Text:</span> <code>concat([first_name], ' ', [last_name])</code></li>
                                            <li><span class="text-secondary fw-semibold">Age / Days:</span> <code>datediff([dob], 'today', 'y')</code></li>
                                            <li><span class="text-secondary fw-semibold">Rounding:</span> <code>round([weight] / 2.2, 1)</code></li>
                                            <li class="mt-auto pt-1 text-muted fst-italic" style="font-size: 0.72rem;">
                                                Plain text renders as static text
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive rule-mapping-table-wrapper">
                                <table class="table table-sm align-middle mb-0 w-100" style="table-layout: fixed;">
                                    <thead class="table-light small text-muted">
                                        <tr>
                                            <th style="width: 40%;">Word Template Tag</th>
                                            <th style="width: 50%;">REDCap Field / Expression</th>
                                            <th style="width: 10%;" class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(m, mIdx) in r.mappings" :key="mIdx">
                                            <tr>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm font-monospace w-100" x-model="m.tag" placeholder="Tag Name">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm font-monospace w-100" list="fieldsList" x-model="m.source" placeholder="e.g. [first_name] or [appt_date]">
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" @click="r.mappings.splice(mIdx, 1)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <div x-show="!r.mappings || r.mappings.length === 0" class="text-muted small py-2 text-center fst-italic">
                                Select a template above to automatically populate detected tags.
                            </div>
                        </div>
                    </div>
                </template>

                <div x-show="rules.length === 0" class="text-center py-4 text-muted small">
                    No letter rules configured. Click "Add Letter Rule" to create one.
                </div>
            </div>
        </div>
    </div>

    <datalist id="fieldsList">
        <option value="[today]">[today] - Today's Date (<?= date('F j, Y') ?>)</option>
        <option value="[today:m/d/y]">[today:m/d/y] - US Date (<?= date('m/d/Y') ?>)</option>
        <option value="[today:y-m-d]">[today:y-m-d] - ISO Date (<?= date('Y-m-d') ?>)</option>
        <option value="[today_usa]">[today_usa] - US Date (<?= date('m/d/Y') ?>)</option>
        <option value="[date]">[date] - ISO Date (<?= date('Y-m-d') ?>)</option>
        <option value="[now]">[now] - Current Date & Time (<?= date('F j, Y g:i A') ?>)</option>
        <option value="[time]">[time] - 12-Hour Time (<?= date('g:i A') ?>)</option>
        <option value="time:12">time:12 - 12-Hour Time (<?= date('g:i A') ?>)</option>
        <option value="[time:24]">[time:24] - 24-Hour Time (<?= date('H:i') ?>)</option>
        <option value="now:24">now:24 - Current Date &amp; Time (24-hour) (<?= date('Y-m-d H:i') ?>)</option>
        <option value="now:iso">now:iso - Current Date &amp; Time (ISO) (<?= date('Y-m-d H:i:s') ?>)</option>
        <option value="timestamp">timestamp - Current Date &amp; Time (ISO) (<?= date('Y-m-d H:i:s') ?>)</option>
        <option value="if([field] = '1', 'Yes', 'No')">if([field] = '1', 'Yes', 'No') - Conditional Variable</option>
        <option value="concat([field1], ' ', [field2])">concat([field1], ' ', [field2]) - Join text values</option>
        <option value="concat_ws(' ', [field1], [field2])">concat_ws(' ', [field1], [field2]) - Join values with a separator</option>
        <option value="datediff([date1], 'today', 'd')">datediff([date1], 'today', 'd') - Days between dates</option>
        <option value="trim([field])">trim([field]) - Remove leading/trailing spaces</option>
        <option value="upper([field])">upper([field]) - Convert text to uppercase</option>
        <option value="lower([field])">lower([field]) - Convert text to lowercase</option>
        <option value="round([field], 2)">round([field], 2) - Round to 2 decimal places</option>
        <option value="roundup([field], 0)">roundup([field], 0) - Round up to an integer</option>
        <option value="rounddown([field], 0)">rounddown([field], 0) - Round down to an integer</option>
        <option value="abs([field])">abs([field]) - Absolute value</option>
        <option value="sqrt([field])">sqrt([field]) - Square root</option>
        <option value="sum([field1], [field2])">sum([field1], [field2]) - Add values</option>
        <option value="mean([field1], [field2])">mean([field1], [field2]) - Average values</option>
        <option value="min([field1], [field2])">min([field1], [field2]) - Lowest value</option>
        <option value="max([field1], [field2])">max([field1], [field2]) - Highest value</option>
        <option value="left([field], 3)">left([field], 3) - First 3 characters</option>
        <option value="right([field], 3)">right([field], 3) - Last 3 characters</option>
        <option value="mid([field], 1, 3)">mid([field], 1, 3) - Select characters from text</option>
        <option value="year([date_field])">year([date_field]) - Get the year from a date</option>
        <option value="month([date_field])">month([date_field]) - Get the month from a date</option>
        <option value="day([date_field])">day([date_field]) - Get the day from a date</option>
        <option value="[field]:upper">[field]:upper - Format a field as uppercase</option>
        <option value="[field]:trim">[field]:trim - Trim surrounding spaces</option>
        <option value="[amount]:currency">[amount]:currency - Format as currency</option>
        <option value="[amount]:number:2">[amount]:number:2 - Format with 2 decimals</option>
        <option value="[date_field]:m/d/y">[date_field]:m/d/y - Format a date</option>
        <option value="[datetime_field]:iso">[datetime_field]:iso - Format as ISO date/time</option>
        <option value="[record]">[record] - Record ID</option>
        <option value="[record_id]">[record_id] - Record ID</option>
        <option value="[user]">[user] - Current REDCap User</option>
        <option value="[user_name]">[user_name] - Current REDCap User</option>
        <?php foreach ($fields as $f): ?>
            <option value="[<?= htmlspecialchars($f, ENT_QUOTES) ?>]">[<?= htmlspecialchars($f, ENT_QUOTES) ?>]</option>
        <?php endforeach; ?>
    </datalist>

    <!-- Confirmation Modal -->
    <template x-if="confirmModal.show">
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5); z-index: 1055;" role="dialog" @click.self="confirmModal.show = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content shadow border-0">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" x-text="confirmModal.title">Confirm Action</h5>
                        <button type="button" class="btn-close" @click="confirmModal.show = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0" x-text="confirmModal.message"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" @click="confirmModal.show = false">Cancel</button>
                        <button type="button" class="btn btn-primary btn-sm" @click="executeConfirmAction()">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>