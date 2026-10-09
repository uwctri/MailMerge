<?php
namespace UWMadison\MailMerge;

/**
 * @psalm-suppress UndefinedConstant, UndefinedClass
 * @noinspection PhpUndefinedClassInspection, PhpUndefinedConstantInspection, PhpUndefinedVariableInspection
 */

require_once __DIR__ . '/DocxEngine.php';

use ExternalModules\AbstractExternalModule;
use REDCap;
use Files;

class MailMerge extends AbstractExternalModule {
    public function checkFileRepoAccess(): array {
        if (($GLOBALS['file_repository_enabled'] ?? '1') != '1')
            return ['allowed' => false, 'message' => 'The File Repository is disabled at the system level on this REDCap server. Please contact a system administrator to enable it.'];

        if (defined('SUPER_USER') && SUPER_USER) return ['allowed' => true];

        $rights = REDCap::getUserRights(USERID);
        $u = $rights[USERID];
        if (empty($u['file_repository']) || $u['file_repository'] == '0')
            return ['allowed' => false, 'message' => 'Access Denied: You do not have File Repository permissions in this project. Please request File Repository access from a project administrator.'];

        return ['allowed' => true];
    }

    public function getMailMergeFolderId($pid): int {
        $res = $this->query("SELECT folder_id FROM redcap_docs_folders WHERE project_id = ? AND name = 'MailMerge' AND deleted = 0 LIMIT 1", [$pid]);
        $row = $res->fetch_assoc();
        if ($row) return (int)$row['folder_id'];

        $this->query("INSERT INTO redcap_docs_folders (project_id, name) VALUES (?, 'MailMerge')", [$pid]);
        $q = $this->query("SELECT folder_id FROM redcap_docs_folders WHERE project_id = ? AND name = 'MailMerge' AND deleted = 0 ORDER BY folder_id DESC LIMIT 1", [$pid]);
        $r = $q->fetch_assoc();
        return (int)$r['folder_id'];
    }

    public function addToFileRepoFolder($docId, $pid, $comment): void {
        REDCap::addFileToRepository($docId, $pid, $comment);
        $folderId = $this->getMailMergeFolderId($pid);
        $res = $this->query("SELECT docs_id FROM redcap_docs_to_edocs WHERE doc_id = ? ORDER BY docs_id DESC LIMIT 1", [$docId]);
        $row = $res->fetch_assoc();
        if ($row)
            $this->query("INSERT IGNORE INTO redcap_docs_folders_files (docs_id, folder_id) VALUES (?, ?)", [(int)$row['docs_id'], $folderId]);
    }

    public function redcap_module_link_check_display($project_id, $link): ?array {
        $access = $this->checkFileRepoAccess();
        if (!$access['allowed']) return null;
        return $link;
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record) {
        $access = $this->checkFileRepoAccess();
        if (!$access['allowed']) return ['success' => false, 'error' => $access['message']];

        if ($action === 'uploadTemplates') return $this->handleTemplateUploads($project_id, $payload['files']);
        if ($action === 'getTemplates') return ['success' => true, 'templates' => $this->getTemplates($project_id)];
        if ($action === 'deleteTemplate') return $this->deleteTemplate($project_id, $payload['doc_id']);
        if ($action === 'getLetterRules') return ['success' => true, 'rules' => $this->getLetterRules($project_id)];
        if ($action === 'saveLetterRules') return $this->saveLetterRules($project_id, $payload['rules']);
        if ($action === 'evaluateQueue') return ['success' => true, 'queue' => $this->evaluateQueue($project_id)];
        if ($action === 'generateLetters') return $this->generateLetters($project_id, $payload['items']);
        if ($action === 'testMerge') return $this->testMerge($project_id, $payload['rule_id'], $payload['record_id']);

        return ['success' => false, 'error' => 'Unknown action'];
    }

    public function handleTemplateUploads($pid, $files): array {
        if (empty($files)) return ['success' => false, 'error' => 'No files provided'];
        $uploaded = [];
        $errors = [];

        foreach ($files as $f) {
            $name = basename($f['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== 'docx') {
                $errors[] = "{$name}: Only .docx files allowed";
                continue;
            }

            if (!preg_match('/^data:([^;]+);base64,(.+)$/', $f['dataUrl'], $m)) {
                $errors[] = "{$name}: Invalid file payload";
                continue;
            }

            $binary = base64_decode($m[2]);
            if (!$binary) {
                $errors[] = "{$name}: Empty file content";
                continue;
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'mmt_') . '.docx';
            file_put_contents($tmpPath, $binary);

            $tags = DocxEngine::extractTags($tmpPath);
            $docId = $this->saveFile($tmpPath, $pid);
            @unlink($tmpPath);

            if (!$docId) {
                $errors[] = "{$name}: Failed to save file";
                continue;
            }

            $this->addToFileRepoFolder($docId, $pid, "Mail Merge Template: " . $name);

            $item = [
                'doc_id' => $docId,
                'name' => $name,
                'size' => round(strlen($binary) / 1024, 1) . ' KB',
                'tags' => $tags,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

            $templates = $this->getTemplates($pid);
            $templates[] = $item;
            $this->setProjectSetting('uploaded_templates', $templates, $pid);
            $uploaded[] = $item;
        }

        return [
            'success' => count($uploaded) > 0,
            'uploaded' => $uploaded,
            'errors' => $errors,
        ];
    }

    public function getTemplates($pid): array {
        return $this->getProjectSetting('uploaded_templates', $pid) ?: [];
    }

    public function deleteTemplate($pid, $docId): array {
        $templates = $this->getTemplates($pid);
        $updated = [];
        foreach ($templates as $t)
            if ($t['doc_id'] != $docId) $updated[] = $t;
        $this->setProjectSetting('uploaded_templates', $updated, $pid);
        return ['success' => true];
    }

    public function getLetterRules($pid): array {
        return $this->getProjectSetting('letter_rules', $pid) ?: [];
    }

    public function saveLetterRules($pid, $rules): array {
        $this->setProjectSetting('letter_rules', $rules, $pid);
        return ['success' => true];
    }

    public function evaluateQueue($pid): array {
        $rules = $this->getLetterRules($pid);
        if (empty($rules)) return [];

        $allData = REDCap::getData($pid, 'array');
        if (empty($allData)) return [];

        $queue = [];
        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) continue;
            $cond = trim($rule['condition'] ?? '');
            $eventId = $rule['event_id'] ?: null;

            foreach ($allData as $recordId => $recordEvents) {
                if ($cond !== '') {
                    $isDue = REDCap::evaluateLogic($cond, $pid, $recordId, $eventId);
                    if (!$isDue) continue;
                }

                $primaryEvent = $eventId ?: array_key_first($recordEvents);
                $row = $recordEvents[$primaryEvent] ?? [];

                $flagField = trim($rule['flag_field'] ?? '');
                if ($flagField !== '') {
                    $val = trim((string)($row[$flagField] ?? ''));
                    if ($val !== '' && $val !== '0') continue;
                }

                $queue[] = [
                    'rule_id' => $rule['id'],
                    'rule_title' => $rule['title'],
                    'template_doc_id' => $rule['template_doc_id'],
                    'record_id' => $recordId,
                    'event_id' => $primaryEvent,
                    'recipient' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $recordId,
                ];
            }
        }

        return $queue;
    }

    public function generateLetters($pid, $items): array {
        if (empty($items)) return ['success' => false, 'error' => 'No items selected'];
        $rules = [];
        foreach ($this->getLetterRules($pid) as $r)
            $rules[$r['id']] = $r;

        $generated = 0;
        $errors = [];

        foreach ($items as $item) {
            $rule = $rules[$item['rule_id']] ?? null;
            $recordId = $item['record_id'];
            if (!$rule || !$recordId) continue;

            $res = $this->mergeSingleLetter($pid, $rule, $recordId);
            if (!$res['success']) {
                $errors[] = "Record {$recordId}: " . $res['error'];
                continue;
            }
            $generated++;
        }

        return ['success' => true, 'generated' => $generated, 'errors' => $errors];
    }

    public function testMerge($pid, $ruleId, $recordId): array {
        $rules = [];
        foreach ($this->getLetterRules($pid) as $r)
            $rules[$r['id']] = $r;

        $rule = $rules[$ruleId] ?? null;
        if (!$rule) return ['success' => false, 'error' => 'Rule not found'];

        return $this->mergeSingleLetter($pid, $rule, $recordId, true);
    }

    private function mergeSingleLetter($pid, $rule, $recordId, $isTest = false): array {
        $docId = $rule['template_doc_id'];
        if (!$docId) return ['success' => false, 'error' => 'No template associated with rule'];

        $tmplPath = Files::copyEdocToTemp($docId);
        if (!$tmplPath || !file_exists($tmplPath))
            return ['success' => false, 'error' => 'Failed to access template file'];

        $eventId = $rule['event_id'] ?: null;
        $data = REDCap::getData($pid, 'array', $recordId);
        $recordData = $data[$recordId][$eventId ?: array_key_first($data[$recordId] ?? [])] ?? [];

        $mergePayload = [
            'record_id' => $recordId,
            'today' => date('m/d/Y'),
            'today_date' => date('F j, Y'),
        ];

        foreach ($recordData as $f => $v)
            if (!is_array($v)) $mergePayload[$f] = (string)$v;

        foreach ($rule['mappings'] ?: [] as $m) {
            $tag = trim($m['tag']);
            $source = trim($m['source']);
            if (!$tag || !$source) continue;
            $piped = REDCap::piping_replace_tags($source, $recordId, $eventId, 1, [], false, $pid);
            $mergePayload[$tag] = $piped ?: ($recordData[$source] ?? '');
        }

        $outPath = tempnam(sys_get_temp_dir(), 'ltr_') . '.docx';
        $ok = DocxEngine::merge($tmplPath, $mergePayload, $outPath);
        @unlink($tmplPath);

        if (!$ok || !file_exists($outPath))
            return ['success' => false, 'error' => 'Word document merge failed'];

        $outDocId = $this->saveFile($outPath, $pid);
        @unlink($outPath);
        if (!$outDocId) return ['success' => false, 'error' => 'Failed to store generated letter'];

        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rule['title']) . "_{$recordId}_" . date('Ymd_His') . '.docx';
        $this->addToFileRepoFolder($outDocId, $pid, "Generated Letter: {$filename}");

        if (!$isTest && !empty($rule['flag_field'])) {
            $flagField = trim($rule['flag_field']);
            $flagValue = '1';
            $dict = REDCap::getDataDictionary($pid, 'array', false, [$flagField]);
            $valType = $dict[$flagField]['text_validation_type_or_show_slider_number'] ?? '';
            if (str_starts_with($valType, 'datetime_seconds')) $flagValue = date('Y-m-d H:i:s');
            else if (str_starts_with($valType, 'datetime')) $flagValue = date('Y-m-d H:i');
            else if (str_starts_with($valType, 'date')) $flagValue = date('Y-m-d');

            REDCap::saveData($pid, 'json', json_encode([
                [
                    'record_id' => $recordId,
                    'redcap_event_name' => REDCap::getEventNames(true, false, $eventId),
                    $flagField => $flagValue,
                ]
            ]));
        }

        return ['success' => true, 'doc_id' => $outDocId, 'filename' => $filename];
    }

    public function cronMailMergeLetters($cronInfo): string {
        $pids = $this->getProjectsWithModuleEnabled();
        $total = 0;
        foreach ($pids as $pid) {
            $queue = $this->evaluateQueue($pid);
            if (empty($queue)) continue;
            $res = $this->generateLetters($pid, $queue);
            $total += $res['generated'];
        }
        return "MailMerge cron completed: generated {$total} letters across projects.";
    }
}
