<?php
namespace UWMadison\MailMerge;

/**
 * @var MailMerge $module
 * @psalm-suppress UndefinedConstant, UndefinedClass
 * @noinspection PhpUndefinedClassInspection, PhpUndefinedConstantInspection, PhpUndefinedVariableInspection
 */

$projectId = (int)$_GET['pid'];
$module->ensureFileRepoEnabled($projectId);
$module->initializeJavascriptModuleObject();
$alpineUrl = $module->getUrl('alpine.min.js');
?>
<script src="<?= htmlspecialchars($alpineUrl, ENT_QUOTES) ?>" defer></script>

<div class="container-fluid py-4 px-4" x-data="letterQueue()" style="max-width: 1400px;">
    <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-success text-white rounded-3 p-3 shadow-sm d-flex align-items-center justify-content-center">
                <i class="fas fa-print fa-2x"></i>
            </div>
            <div>
                <h3 class="fw-bold mb-0 text-dark">Letter Generation Queue</h3>
                <span class="text-muted small">Review and process participant letters due based on configured rules.</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="loadQueue()" :disabled="loading">
                <i class="fas me-1" :class="loading ? 'fa-spinner fa-spin' : 'fa-sync-alt'"></i> Refresh Queue
            </button>
            <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm fw-bold" @click="generateSelected()" :disabled="selected.length === 0 || generating">
                <i class="fas me-1" :class="generating ? 'fa-spinner fa-spin' : 'fa-file-export'"></i>
                <span x-text="generating ? 'Generating...' : ('Generate Selected (' + selected.length + ')')"></span>
            </button>
        </div>
    </div>

    <div x-show="alert.msg" x-transition class="alert alert-dismissible mb-4 shadow-sm" :class="'alert-' + alert.type">
        <span x-text="alert.msg"></span>
        <button type="button" class="btn-close" @click="alert.msg = ''"></button>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-tasks me-2"></i>Letters Due</h5>
                <span class="badge bg-primary" x-text="queue.length + ' Pending'"></span>
            </div>
            <div class="form-check" x-show="queue.length > 0">
                <input class="form-check-input" type="checkbox" id="selectAll" @change="toggleSelectAll($event)">
                <label class="form-check-label small fw-semibold" for="selectAll">Select All</label>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" x-show="queue.length > 0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>Record ID</th>
                            <th>Recipient</th>
                            <th>Letter Title</th>
                            <th>Event</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, idx) in queue" :key="idx">
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" :value="idx" x-model="selected">
                                </td>
                                <td class="fw-bold text-primary" x-text="item.record_id"></td>
                                <td x-text="item.recipient"></td>
                                <td>
                                    <span class="badge bg-light text-dark border" x-text="item.rule_title"></span>
                                </td>
                                <td class="small text-muted" x-text="item.event_id || 'Default'"></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-primary btn-sm py-1 px-2" @click="generateSingle(item)" :disabled="generating">
                                        <i class="fas fa-play me-1"></i> Generate
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="queue.length === 0 && !loading" class="text-center py-5 text-muted">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h6 class="fw-bold text-dark">All caught up!</h6>
                <p class="small text-muted mb-0">No participants currently qualify for letters.</p>
            </div>
            <div x-show="loading" class="text-center py-5 text-muted">
                <i class="fas fa-spinner fa-spin fa-2x mb-2 text-primary"></i>
                <p class="small text-muted mb-0">Evaluating letter rules against study records...</p>
            </div>
        </div>
    </div>
</div>

<script>
function letterQueue() {
    const jsmObj = <?= $module->getJavascriptModuleObjectName() ?>;
    return {
        queue: [],
        selected: [],
        loading: false,
        generating: false,
        alert: { msg: '', type: 'info' },

        showAlert(msg, type = 'info') {
            this.alert.msg = msg;
            this.alert.type = type;
            setTimeout(() => { if (this.alert.msg === msg) this.alert.msg = ''; }, 6000);
        },

        init() {
            this.loadQueue();
        },

        async loadQueue() {
            this.loading = true;
            this.selected = [];
            try {
                const res = await jsmObj.ajax('evaluateQueue', {});
                if (res.success) this.queue = res.queue || [];
                else this.showAlert(res.error || 'Failed to evaluate queue', 'danger');
            } catch (e) {
                this.showAlert('Queue evaluation failed: ' + e.message, 'danger');
            } finally {
                this.loading = false;
            }
        },

        toggleSelectAll(e) {
            this.selected = e.target.checked ? this.queue.map((_, i) => i) : [];
        },

        async generateSelected() {
            if (this.selected.length === 0) return;
            this.generating = true;
            const items = this.selected.map(idx => this.queue[idx]);

            try {
                const res = await jsmObj.ajax('generateLetters', { items: items });
                if (res.success) {
                    this.showAlert(`Successfully generated ${res.generated} letter(s) and saved to File Repository.`, 'success');
                    await this.loadQueue();
                } else {
                    this.showAlert(res.error || 'Generation failed', 'danger');
                }
            } catch (e) {
                this.showAlert('Generation request failed: ' + e.message, 'danger');
            } finally {
                this.generating = false;
            }
        },

        async generateSingle(item) {
            this.generating = true;
            try {
                const res = await jsmObj.ajax('generateLetters', { items: [item] });
                if (res.success) {
                    this.showAlert(`Generated letter for record ${item.record_id} and saved to File Repository.`, 'success');
                    await this.loadQueue();
                } else {
                    this.showAlert(res.error || 'Generation failed', 'danger');
                }
            } catch (e) {
                this.showAlert('Generation failed: ' + e.message, 'danger');
            } finally {
                this.generating = false;
            }
        }
    };
}
</script>