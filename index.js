document.addEventListener('alpine:init', () => Alpine.data('mailMergeApp', () => ({
    activeTab: 'queue',
    templates: module.config.templates,
    rules: module.config.rules,
    settings: module.config.settings,
    queueLastFullEvaluation: module.config.queueLastFullEvaluation,
    allowServerPath: module.config.allowServerPath,
    events: module.config.events,
    queue: [],
    queueSearch: '',
    selected: [],
    pageSize: 20,
    currentPage: 1,
    loadingQueue: true,
    evaluatingQueue: false,
    generating: false,
    uploadQueue: [],
    isDragging: false,
    saving: false,
    alert: {
        msg: '',
        type: 'info'
    },

    archive: [],
    archiveSearch: '',
    loadingArchive: false,
    archivePageSize: 20,
    archiveCurrentPage: 1,
    confirmModal: {
        show: false,
        title: '',
        message: '',
        action: null
    },

    showAlert(msg, type = 'info') {
        if (typeof Swal !== 'undefined') {
            const iconMap = {
                'success': 'success',
                'danger': 'error',
                'error': 'error',
                'warning': 'warning',
                'info': 'info'
            };
            const swalIcon = iconMap[type] || 'info';
            const titleMap = {
                'success': 'Success',
                'danger': 'Error',
                'error': 'Error',
                'warning': 'Notice',
                'info': 'Information'
            };
            Swal.fire({
                icon: swalIcon,
                title: titleMap[type] || 'Notice',
                text: msg,
                timer: type === 'danger' || type === 'error' ? 3000 : 2000,
                timerProgressBar: true,
                showConfirmButton: false
            });
        } else {
            this.alert.msg = msg;
            this.alert.type = type;
            setTimeout(() => {
                if (this.alert.msg === msg) {
                    this.alert.msg = '';
                }
            }, 5000);
        }
    },

    triggerFooterUpdate() {
        this.$nextTick(() => {
            if (typeof setProjectFooterPosition === 'function') {
                setProjectFooterPosition();
            }
        });
    },

    init() {
        if (this.queueLastFullEvaluation) {
            this.loadQueue();
        } else {
            this.runFullQueueEvaluation(false);
        }
        this.loadArchive();
        this.triggerFooterUpdate();
        this.$watch('activeTab', () => {
            this.triggerFooterUpdate();
        });
    },

    itemKey(item) {
        return String(item.record_id) + '_' + String(item.rule_id) + '_' + String(item.event_id || '');
    },

    queueEvaluationLabel() {
        if (!this.queueLastFullEvaluation) return 'Never';
        const timestamp = Number(this.queueLastFullEvaluation);
        if (!Number.isFinite(timestamp)) return 'Unknown';
        return new Date(timestamp * 1000).toLocaleString();
    },

    filteredQueue() {
        const query = this.queueSearch.trim().toLowerCase();
        if (!query) return this.queue;
        return this.queue.filter(item => [
            item.record_id,
            item.recipient,
            item.rule_title,
            item.event_name || 'Default',
            item.is_generated ? 'Generated' : 'Due'
        ].some(value => String(value == null ? '' : value).toLowerCase().includes(query)));
    },

    totalPages() {
        const size = Math.max(1, parseInt(this.pageSize, 10) || 20);
        return Math.max(1, Math.ceil(this.filteredQueue().length / size));
    },

    pagedQueue() {
        const size = Math.max(1, parseInt(this.pageSize, 10) || 20);
        const start = (this.currentPage - 1) * size;
        return this.filteredQueue().slice(start, start + size);
    },

    setPage(page) {
        const max = this.totalPages();
        this.currentPage = Math.max(1, Math.min(page, max));
    },

    onPageSizeChange() {
        let size = parseInt(this.pageSize, 10);
        if (isNaN(size) || size < 1) {
            size = 1;
        }
        this.pageSize = size;
        const max = this.totalPages();
        if (this.currentPage > max) {
            this.currentPage = max;
        }
        if (this.currentPage < 1) {
            this.currentPage = 1;
        }
    },

    pageStart() {
        if (this.filteredQueue().length === 0) {
            return 0;
        }
        const size = Math.max(1, parseInt(this.pageSize, 10) || 20);
        return (this.currentPage - 1) * size + 1;
    },

    pageEnd() {
        const size = Math.max(1, parseInt(this.pageSize, 10) || 20);
        return Math.min(this.currentPage * size, this.filteredQueue().length);
    },

    isAllSelected() {
        const visibleQueue = this.filteredQueue();
        return visibleQueue.length > 0 && visibleQueue.every(item => this.selected.includes(this.itemKey(item)));
    },

    toggleSelectAll(e) {
        const visibleKeys = this.filteredQueue().map(item => this.itemKey(item));
        if (e.target.checked) {
            this.selected = [...new Set([...this.selected, ...visibleKeys])];
        } else {
            this.selected = this.selected.filter(key => !visibleKeys.includes(key));
        }
    },

    async loadQueue() {
        this.loadingQueue = true;
        this.selected = [];
        this.currentPage = 1;
        try {
            const res = await module.ajax('evaluateQueue', {});
            if (res.success) {
                this.queue = res.queue || [];
                this.queueLastFullEvaluation = res.evaluated_at || null;
            } else {
                this.showAlert(res.error || 'Failed to evaluate queue', 'danger');
            }
        } catch (e) {
            this.showAlert('Queue evaluation failed: ' + e.message, 'danger');
        } finally {
            this.loadingQueue = false;
        }
    },

    async runFullQueueEvaluation(notify = true) {
        this.evaluatingQueue = true;
        this.loadingQueue = true;
        this.selected = [];
        try {
            const res = await module.ajax('refreshQueue', {});
            if (res.success) {
                this.queue = res.queue || [];
                this.queueLastFullEvaluation = res.evaluated_at || null;
                this.currentPage = 1;
                if (notify) {
                    this.showAlert(`Full queue evaluation complete. ${this.queue.length} letter(s) currently qualify.`, 'success');
                }
            } else {
                this.showAlert(res.error || 'Full queue evaluation failed', 'danger');
            }
        } catch (e) {
            this.showAlert('Full queue evaluation failed: ' + e.message, 'danger');
        } finally {
            this.evaluatingQueue = false;
            this.loadingQueue = false;
        }
    },

    downloadGeneratedFiles(files, zipDownloadUrl = null) {
        if (!Array.isArray(files) || files.length === 0) return;

        let url;
        let filename;
        if (files.length > 1) {
            if (!zipDownloadUrl) {
                this.showAlert('Letters were generated, but the ZIP download link is missing.', 'danger');
                return;
            }
            url = zipDownloadUrl;
            filename = 'mailmerge_letters.zip';
        } else {
            url = files[0].download_url;
            filename = files[0].filename || 'letter.docx';
        }
        if (!url) {
            this.showAlert('Letters were generated, but the download link is missing.', 'danger');
            return;
        }

        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },

    async generateSelected() {
        if (this.selected.length === 0) {
            return;
        }
        this.generating = true;
        const selectedSet = new Set(this.selected);
        const items = this.queue.filter(item => selectedSet.has(this.itemKey(item)));
        if (items.length === 0) {
            this.generating = false;
            return;
        }

        try {
            const res = await module.ajax('generateLetters', {
                items: items
            });
            if (res.success) {
                this.showAlert(`Successfully generated ${res.generated} letter(s).`, 'success');
                if (Array.isArray(res.files)) {
                    this.downloadGeneratedFiles(res.files, res.zip_download_url);
                    // Update items in the current queue to reflect generated status without removing them
                    const generatedKeys = new Set(res.files.map(f => f.record_id + '_' + f.rule_id));
                    this.queue.forEach(qItem => {
                        if (generatedKeys.has(qItem.record_id + '_' + qItem.rule_id)) {
                            qItem.is_generated = true;
                        }
                    });
                }
                await this.loadArchive();
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
            const res = await module.ajax('generateLetters', {
                items: [item]
            });
            if (res.success) {
                this.showAlert(`Generated letter for record ${item.record_id}.`, 'success');
                // Trigger file download
                const fileInfo = (res.files && res.files[0]) ? res.files[0] : null;
                if (fileInfo && fileInfo.download_url) {
                    const a = document.createElement('a');
                    a.href = fileInfo.download_url;
                    a.download = fileInfo.filename || 'letter.docx';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }
                // Mark as generated on the existing queue row without removing or refreshing queue
                item.is_generated = true;
                await this.loadArchive();
            } else {
                this.showAlert(res.error || 'Generation failed', 'danger');
            }
        } catch (e) {
            this.showAlert('Generation failed: ' + e.message, 'danger');
        } finally {
            this.generating = false;
        }
    },

    confirmResolveSingle(item) {
        this.confirmModal = {
            show: true,
            title: 'Mark Letter as Resolved',
            message: `Are you sure you want to mark the letter "${item.rule_title}" for record ${item.record_id} as resolved / printed? This will update the resolved flag and remove it from the queue.`,
            action: () => this.resolveSingle(item)
        };
    },

    confirmResolveSelected() {
        if (this.selected.length === 0) return;
        this.confirmModal = {
            show: true,
            title: 'Mark Selected as Resolved',
            message: `Are you sure you want to mark ${this.selected.length} selected letter(s) as resolved / printed? This will update their resolved flags and remove them from the queue.`,
            action: () => this.resolveSelected()
        };
    },

    confirmBothSelected() {
        if (this.selected.length === 0) return;
        this.confirmModal = {
            show: true,
            title: 'Generate and Mark Selected as Resolved',
            message: `Are you sure you want to generate and mark ${this.selected.length} selected letter(s) as resolved / printed?`,
            action: () => this.generateAndResolveSelected()
        };
    },

    executeConfirmAction() {
        const act = this.confirmModal.action;
        this.confirmModal.show = false;
        this.confirmModal.action = null;
        if (typeof act === 'function') {
            act();
        }
    },

    async resolveSingle(item) {
        this.generating = true;
        try {
            const res = await module.ajax('resolveLetters', {
                items: [item]
            });
            if (res.success) {
                this.showAlert(`Marked record ${item.record_id} as resolved.`, 'success');
                await this.loadQueue();
            } else {
                this.showAlert(res.error || 'Failed to mark as resolved', 'danger');
            }
        } catch (e) {
            this.showAlert('Request failed: ' + e.message, 'danger');
        } finally {
            this.generating = false;
        }
    },

    async resolveSelected() {
        if (this.selected.length === 0) return;
        this.generating = true;
        const selectedSet = new Set(this.selected);
        const items = this.queue.filter(item => selectedSet.has(this.itemKey(item)));
        try {
            const res = await module.ajax('resolveLetters', {
                items: items
            });
            if (res.success) {
                this.showAlert(`Successfully marked ${res.resolved} letter(s) as resolved.`, 'success');
                await this.loadQueue();
            } else {
                this.showAlert(res.error || 'Failed to mark as resolved', 'danger');
            }
        } catch (e) {
            this.showAlert('Request failed: ' + e.message, 'danger');
        } finally {
            this.generating = false;
        }
    },

    async generateAndResolveSelected() {
        if (this.selected.length === 0) return;
        this.generating = true;
        const selectedSet = new Set(this.selected);
        const items = this.queue.filter(item => selectedSet.has(this.itemKey(item)));
        try {
            const res = await module.ajax('generateAndResolveLetters', {
                items: items
            });
            if (res.success) {
                this.showAlert(`Successfully generated ${res.generated} and marked ${res.resolved} letter(s) as resolved.`, 'success');
                this.downloadGeneratedFiles(res.files, res.zip_download_url);
                await this.loadQueue();
                await this.loadArchive();
            } else {
                this.showAlert(res.error || 'Action failed', 'danger');
            }
        } catch (e) {
            this.showAlert('Request failed: ' + e.message, 'danger');
        } finally {
            this.generating = false;
        }
    },

    archiveTotalPages() {
        const size = Math.max(1, parseInt(this.archivePageSize, 10) || 20);
        return Math.max(1, Math.ceil(this.filteredArchive().length / size));
    },

    filteredArchive() {
        const query = this.archiveSearch.trim().toLowerCase();
        if (!query) return this.archive;
        return this.archive.filter(item => {
            const triggerStatus = {
                met: 'Trigger met',
                not_met: 'Trigger no longer met',
                unknown: 'Trigger status unavailable'
            }[item.trigger_status] || 'Trigger status unavailable';
            return [
                item.record,
                item.rule_title,
                item.version,
                item.formatted_date,
                item.username,
                item.filename,
                'Generated',
                triggerStatus
            ].some(value => String(value == null ? '' : value).toLowerCase().includes(query));
        });
    },

    pagedArchive() {
        const size = Math.max(1, parseInt(this.archivePageSize, 10) || 20);
        const start = (this.archiveCurrentPage - 1) * size;
        return this.filteredArchive().slice(start, start + size);
    },

    setArchivePage(page) {
        const max = this.archiveTotalPages();
        this.archiveCurrentPage = Math.max(1, Math.min(page, max));
    },

    onArchivePageSizeChange() {
        let size = parseInt(this.archivePageSize, 10);
        if (isNaN(size) || size < 1) size = 1;
        this.archivePageSize = size;
        const max = this.archiveTotalPages();
        if (this.archiveCurrentPage > max) this.archiveCurrentPage = max;
        if (this.archiveCurrentPage < 1) this.archiveCurrentPage = 1;
    },

    archivePageStart() {
        if (this.filteredArchive().length === 0) return 0;
        const size = Math.max(1, parseInt(this.archivePageSize, 10) || 20);
        return (this.archiveCurrentPage - 1) * size + 1;
    },

    archivePageEnd() {
        const size = Math.max(1, parseInt(this.archivePageSize, 10) || 20);
        return Math.min(this.archiveCurrentPage * size, this.filteredArchive().length);
    },

    async loadArchive() {
        this.loadingArchive = true;
        this.archiveCurrentPage = 1;
        try {
            const res = await module.ajax('getArchive', {});
            if (res.success) {
                this.archive = res.archive || [];
            } else {
                this.showAlert(res.error || 'Failed to load archive', 'danger');
            }
        } catch (e) {
            this.showAlert('Archive load failed: ' + e.message, 'danger');
        } finally {
            this.loadingArchive = false;
        }
    },

    async regenerateFromArchive(item) {
        this.generating = true;
        try {
            const res = await module.ajax('generateLetters', {
                items: [{
                    record_id: item.record,
                    rule_id: item.rule_id
                }]
            });
            if (res.success) {
                this.showAlert(`Re-generated letter for record ${item.record}.`, 'success');
                const fileInfo = (res.files && res.files[0]) ? res.files[0] : null;
                if (fileInfo && fileInfo.download_url) {
                    const a = document.createElement('a');
                    a.href = fileInfo.download_url;
                    a.download = fileInfo.filename || 'letter.docx';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }
                await this.loadArchive();
                await this.loadQueue();
            } else {
                this.showAlert(res.error || 'Re-generation failed', 'danger');
            }
        } catch (e) {
            this.showAlert('Re-generation failed: ' + e.message, 'danger');
        } finally {
            this.generating = false;
        }
    },

    confirmReturnToQueue(item) {
        this.confirmModal = {
            show: true,
            title: 'Return Letter to Queue',
            message: `Are you sure you want to return the letter "${item.rule_title}" for record ${item.record} back to the active queue? This will clear its resolved status.`,
            action: () => this.returnToQueue(item)
        };
    },

    async returnToQueue(item) {
        this.generating = true;
        try {
            const res = await module.ajax('unresolveLetter', {
                record_id: item.record,
                rule_id: item.rule_id
            });
            if (res.success) {
                this.showAlert(`Returned letter for record ${item.record} to the queue.`, 'success');
                await this.loadQueue();
            } else {
                this.showAlert(res.error || 'Failed to return to queue', 'danger');
            }
        } catch (e) {
            this.showAlert('Failed to return to queue: ' + e.message, 'danger');
        } finally {
            this.generating = false;
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
                reader.onload = () => resolve({
                    name: file.name,
                    dataUrl: reader.result
                });
                reader.onerror = reject;
                reader.readAsDataURL(file);
            }));
        }

        if (toRead.length === 0) return;

        try {
            const filesPayload = await Promise.all(toRead);
            const data = await module.ajax('uploadTemplates', {
                files: filesPayload
            });

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
            const res = await module.ajax('deleteTemplate', {
                doc_id: docId
            });
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
        const id = this.createRuleId();
        this.rules.push({
            id: id,
            title: 'New Letter Rule',
            enabled: true,
            template_doc_id: 0,
            event_id: '',
            flag_field: 'letter_generated',
            printed_flag_field: '',
            filename_scheme: '{record}_{letter_name}',
            condition: '',
            mappings: []
        });
    },

    createRuleId() {
        let id;
        do {
            id = 'rule_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        } while (this.rules.some(rule => String(rule.id) === id));
        return id;
    },

    duplicateRule(rule) {
        const copy = JSON.parse(JSON.stringify(rule));
        copy.id = this.createRuleId();
        copy.title = `${rule.title || 'New Letter Rule'} (Copy)`;
        const index = this.rules.indexOf(rule);
        this.rules.splice(index + 1, 0, copy);
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
        rule.mappings.push({
            tag: '',
            source: ''
        });
    },

    async saveAllConfiguration() {
        this.saving = true;
        try {
            const resRules = await module.ajax('saveLetterRules', {
                rules: this.rules
            });
            const resSettings = await module.ajax('saveSettings', {
                settings: this.settings
            });
            this.saving = false;

            if (resRules.success && resSettings.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Settings Saved',
                        text: 'Configuration and rules have been saved successfully.',
                        timer: 2000,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                } else {
                    this.showAlert('Configuration and rules saved successfully', 'success');
                }
                await this.loadQueue();
            } else {
                const errMsg = (resRules.error || resSettings.error) || 'Failed to save configuration';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Save Failed',
                        text: errMsg
                    });
                } else {
                    this.showAlert(errMsg, 'danger');
                }
            }
        } catch (e) {
            const errMsg = 'Save failed: ' + e.message;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: errMsg
                });
            } else {
                this.showAlert(errMsg, 'danger');
            }
        } finally {
            this.saving = false;
        }
    }
})));
