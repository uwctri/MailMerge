<?php
namespace UWMadison\MailMerge;

/**
 * @var MailMerge $module
 * @psalm-suppress UndefinedConstant, UndefinedClass
 * @noinspection PhpUndefinedClassInspection, PhpUndefinedConstantInspection, PhpUndefinedVariableInspection
 */

use REDCap;

$projectId = (int)$_GET['pid'];
$module->ensureFileRepoEnabled($projectId);
$module->initializeJavascriptModuleObject();

$fields = REDCap::getFieldNames();
$isLongitudinal = REDCap::isLongitudinal();
$events = [];
if ($isLongitudinal) {
    foreach (REDCap::getEventNames(true, true) as $id => $name)
        $events[] = ['id' => $id, 'name' => $name];
}

$templates = $module->getTemplates($projectId);
$rules = $module->getLetterRules($projectId);
$alpineUrl = $module->getUrl('alpine.min.js');
?>
<script src="<?= htmlspecialchars($alpineUrl, ENT_QUOTES) ?>" defer></script>

<style>
.drop-zone {
    border: 2px dashed #0d6efd;
    background-color: #f8faff;
    transition: all 0.2s ease-in-out;
    cursor: pointer;
}
.drop-zone.dragover {
    background-color: #e7f1ff;
    border-color: #0a58ca;
    transform: scale(1.01);
}
.badge-tag {
    background-color: #e9ecef;
    color: #495057;
    font-weight: 500;
}
</style>

<div class="container-fluid py-4 px-4" x-data="mailMergeStudio()" style="max-width: 1400px;">
    <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-3 p-3 shadow-sm d-flex align-items-center justify-content-center">
                <i class="fas fa-envelope-open-text fa-2x"></i>
            </div>
            <div>
                <h3 class="fw-bold mb-0 text-dark">Mail Merge Studio</h3>
                <span class="text-muted small">Configure Word (.docx) templates, multi-template uploads, and automated letter rules.</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="loadData()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
            <button type="button" class="btn btn-success btn-sm px-3 shadow-sm fw-bold" @click="saveRules()" :disabled="saving">
                <i class="fas me-1" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                <span x-text="saving ? 'Saving...' : 'Save All Rules'"></span>
            </button>
        </div>
    </div>

    <div x-show="alert.msg" x-transition class="alert alert-dismissible mb-4 shadow-sm" :class="'alert-' + alert.type">
        <span x-text="alert.msg"></span>
        <button type="button" class="btn-close" @click="alert.msg = ''"></button>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-file-word me-2"></i>Template Library</h5>
            <span class="badge bg-light text-dark border" x-text="templates.length + ' Templates'"></span>
        </div>
        <div class="card-body">
            <div class="drop-zone rounded-3 p-4 text-center mb-3"
                 :class="{ 'dragover': isDragging }"
                 @dragover.prevent="isDragging = true"
                 @dragleave.prevent="isDragging = false"
                 @drop.prevent="handleDrop($event)"
                 @click="$refs.fileInput.click()">
                <input type="file" x-ref="fileInput" class="d-none" multiple accept=".docx" @change="handleFileSelect($event)">
                <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-2"></i>
                <h5 class="fw-bold text-dark mb-1">Drag and drop Word (.docx) templates here</h5>
                <p class="text-muted small mb-0">or click to browse multiple templates simultaneously</p>
            </div>

            <div x-show="uploadQueue.length > 0" class="mb-3">
                <h6 class="fw-bold small text-muted text-uppercase mb-2">Upload Queue</h6>
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

            <div class="table-responsive" x-show="templates.length > 0">
                <table class="table table-hover align-middle mb-0">
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

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-cogs me-2"></i>Configured Letters & Rules</h5>
            <button type="button" class="btn btn-primary btn-sm" @click="addRule()">
                <i class="fas fa-plus me-1"></i> Add Letter Rule
            </button>
        </div>
        <div class="card-body">
            <template x-for="(r, idx) in rules" :key="r.id">
                <div class="border rounded-3 p-3 mb-3 bg-light position-relative">
                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                        <div class="d-flex align-items-center gap-3">
                            <span class="badge bg-secondary" x-text="'#' + (idx + 1)"></span>
                            <input type="text" class="form-control form-control-sm fw-bold" style="width: 320px;" x-model="r.title" placeholder="Letter Title (e.g. Visit 1 Letter)">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" :id="'sw_' + r.id" x-model="r.enabled">
                                <label class="form-check-label small" :for="'sw_' + r.id" x-text="r.enabled ? 'Enabled' : 'Disabled'"></label>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="removeRule(idx)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Template File (.docx)</label>
                            <select class="form-select form-select-sm" x-model="r.template_doc_id" @change="onTemplateChange(r)">
                                <option value="0">-- Select Template --</option>
                                <template x-for="t in templates" :key="t.doc_id">
                                    <option :value="t.doc_id" x-text="t.name"></option>
                                </template>
                            </select>
                        </div>

                        <?php if ($isLongitudinal): ?>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Target Event</label>
                            <select class="form-select form-select-sm" x-model="r.event_id">
                                <option value="">-- All / Primary Event --</option>
                                <template x-for="e in events" :key="e.id">
                                    <option :value="e.id" x-text="e.name"></option>
                                </template>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Flag Field to Set When Generated</label>
                            <input type="text" class="form-control form-control-sm" list="fieldsList" x-model="r.flag_field" placeholder="e.g. letter_generated">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Trigger Condition (REDCap Logic)</label>
                            <input type="text" class="form-control form-control-sm font-monospace" x-model="r.condition" placeholder="e.g. [study_status] = '1' and [appt_date] != '' and datediff('today', [appt_date], 'd') <= 10">
                        </div>
                    </div>

                    <div class="bg-white rounded-3 p-3 border">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-bold small text-muted text-uppercase">Merge Field Mappings</span>
                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 small" @click="addMapping(r)">
                                <i class="fas fa-plus"></i> Add Tag
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light small text-muted">
                                    <tr>
                                        <th style="width: 35%;">Word Template Tag</th>
                                        <th style="width: 55%;">REDCap Field / Piping Expression</th>
                                        <th style="width: 10%;" class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(m, mIdx) in r.mappings" :key="mIdx">
                                        <tr>
                                            <td>
                                                <input type="text" class="form-control form-control-sm font-monospace" x-model="m.tag" placeholder="Tag Name">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm font-monospace" list="fieldsList" x-model="m.source" placeholder="e.g. [first_name] or [appt_date]">
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

    <datalist id="fieldsList">
        <?php foreach ($fields as $f): ?>
            <option value="[<?= htmlspecialchars($f, ENT_QUOTES) ?>]"><?= htmlspecialchars($f, ENT_QUOTES) ?></option>
        <?php endforeach; ?>
    </datalist>
</div>

<script>
function mailMergeStudio() {
    const jsmObj = <?= $module->getJavascriptModuleObjectName() ?>;
    return {
        templates: <?= json_encode($templates) ?>,
        rules: <?= json_encode($rules) ?>,
        events: <?= json_encode($events) ?>,
        uploadQueue: [],
        isDragging: false,
        saving: false,
        alert: { msg: '', type: 'info' },

        showAlert(msg, type = 'info') {
            this.alert.msg = msg;
            this.alert.type = type;
            setTimeout(() => { if (this.alert.msg === msg) this.alert.msg = ''; }, 5000);
        },

        async loadData() {
            try {
                const resTmpl = await jsmObj.ajax('getTemplates', {});
                if (resTmpl.success) this.templates = resTmpl.templates;
                const resRules = await jsmObj.ajax('getLetterRules', {});
                if (resRules.success) this.rules = resRules.rules;
                this.showAlert('Data refreshed successfully', 'success');
            } catch (e) {
                this.showAlert('Failed to refresh data', 'danger');
            }
        },

        handleDrop(e) {
            this.isDragging = false;
            const files = e.dataTransfer.files;
            if (files && files.length > 0) this.uploadFiles(files);
        },

        handleFileSelect(e) {
            const files = e.target.files;
            if (files && files.length > 0) this.uploadFiles(files);
            e.target.value = '';
        },

        async uploadFiles(fileList) {
            const toRead = [];
            for (let i = 0; i < fileList.length; i++) {
                const file = fileList[i];
                if (!file.name.toLowerCase().endsWith('.docx')) {
                    this.showAlert(`Skipped ${file.name}: only .docx allowed`, 'warning');
                    continue;
                }
                this.uploadQueue.push({
                    name: file.name,
                    size: Math.round(file.size / 1024) + ' KB',
                    status: 'uploading',
                    error: ''
                });
                toRead.push(new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = () => resolve({ name: file.name, dataUrl: reader.result });
                    reader.onerror = reject;
                    reader.readAsDataURL(file);
                }));
            }

            if (toRead.length === 0) return;

            try {
                const filesPayload = await Promise.all(toRead);
                const data = await jsmObj.ajax('uploadTemplates', { files: filesPayload });

                if (data.success && data.uploaded) {
                    data.uploaded.forEach(u => {
                        this.templates.push(u);
                        const qItem = this.uploadQueue.find(item => item.name === u.name);
                        if (qItem) qItem.status = 'done';
                    });
                    this.showAlert(`Successfully uploaded ${data.uploaded.length} template(s)`, 'success');
                } else {
                    this.showAlert(data.error || 'Failed to upload templates', 'danger');
                }
            } catch (err) {
                this.showAlert('Upload request failed: ' + err.message, 'danger');
            }
        },

        async deleteTemplate(docId) {
            if (!confirm('Are you sure you want to delete this template?')) return;
            try {
                const res = await jsmObj.ajax('deleteTemplate', { doc_id: docId });
                if (res.success) {
                    this.templates = this.templates.filter(t => t.doc_id != docId);
                    this.showAlert('Template deleted', 'success');
                } else {
                    this.showAlert(res.error || 'Delete failed', 'danger');
                }
            } catch (e) {
                this.showAlert('Delete failed', 'danger');
            }
        },

        addRule() {
            const id = 'rule_' + Date.now();
            this.rules.push({
                id: id,
                title: 'New Letter Rule',
                enabled: true,
                template_doc_id: 0,
                event_id: '',
                flag_field: 'letter_generated',
                flag_value: '1',
                condition: '',
                mappings: []
            });
        },

        removeRule(idx) {
            if (confirm('Delete this letter rule?')) this.rules.splice(idx, 1);
        },

        onTemplateChange(rule) {
            const tmpl = this.templates.find(t => t.doc_id == rule.template_doc_id);
            if (!tmpl || !tmpl.tags) return;
            const existingTags = (rule.mappings || []).map(m => m.tag);
            tmpl.tags.forEach(tag => {
                if (!existingTags.includes(tag)) {
                    rule.mappings.push({
                        tag: tag,
                        source: '[' + tag.toLowerCase() + ']'
                    });
                }
            });
        },

        addMapping(rule) {
            if (!rule.mappings) rule.mappings = [];
            rule.mappings.push({ tag: '', source: '' });
        },

        async saveRules() {
            this.saving = true;
            try {
                const res = await jsmObj.ajax('saveLetterRules', { rules: this.rules });
                if (res.success) this.showAlert('Letter rules saved successfully', 'success');
                else this.showAlert(res.error || 'Failed to save rules', 'danger');
            } catch (e) {
                this.showAlert('Save failed: ' + e.message, 'danger');
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>