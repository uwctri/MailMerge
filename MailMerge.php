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
use Piping;

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

    public function resolvePlaceholders(string $pattern, string $recordId = '', string $letterName = ''): string {
        $cleanLetter = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $letterName);
        return str_replace(
            ['{record}', '{letter_name}', '{date}', '{timestamp}'],
            [$recordId, $cleanLetter, date('Y-m-d'), date('Ymd_His')],
            $pattern
        );
    }

    public function getFolderId($pid, $folderPath, string $recordId = '', string $letterName = ''): int {
        $resolved = $this->resolvePlaceholders((string)$folderPath, $recordId, $letterName);
        $normalized = trim(str_replace('\\', '/', $resolved), '/ ');
        if ($normalized === '') return 0;

        $segments = array_values(array_filter(explode('/', $normalized), fn($s) => trim($s) !== ''));
        if (empty($segments)) return 0;

        $parentId = null;
        foreach ($segments as $seg) {
            $cleanName = trim($seg);
            if ($cleanName === '') continue;

            $sql = $parentId === null
                ? "SELECT folder_id FROM redcap_docs_folders WHERE project_id = ? AND name = ? AND parent_folder_id IS NULL AND deleted = 0 LIMIT 1"
                : "SELECT folder_id FROM redcap_docs_folders WHERE project_id = ? AND name = ? AND parent_folder_id = ? AND deleted = 0 LIMIT 1";
            $params = $parentId === null ? [$pid, $cleanName] : [$pid, $cleanName, $parentId];
            $row = $this->query($sql, $params)->fetch_assoc();
            if ($row) {
                $parentId = (int)$row['folder_id'];
                continue;
            }

            if ($parentId === null) {
                $this->query("INSERT INTO redcap_docs_folders (project_id, name, parent_folder_id) VALUES (?, ?, NULL)", [$pid, $cleanName]);
                $q = $this->query("SELECT folder_id FROM redcap_docs_folders WHERE project_id = ? AND name = ? AND parent_folder_id IS NULL AND deleted = 0 ORDER BY folder_id DESC LIMIT 1", [$pid, $cleanName]);
            } else {
                $this->query("INSERT INTO redcap_docs_folders (project_id, name, parent_folder_id) VALUES (?, ?, ?)", [$pid, $cleanName, $parentId]);
                $q = $this->query("SELECT folder_id FROM redcap_docs_folders WHERE project_id = ? AND name = ? AND parent_folder_id = ? AND deleted = 0 ORDER BY folder_id DESC LIMIT 1", [$pid, $cleanName, $parentId]);
            }
            $parentId = (int)$q->fetch_assoc()['folder_id'];
        }

        return (int)($parentId ?? 0);
    }

    public function addToFileRepoFolder($docId, $pid, $comment, $folderPath = 'MailMerge', string $recordId = '', string $letterName = ''): void {
        REDCap::addFileToRepository($docId, $pid, $comment);
        $folderId = $this->getFolderId($pid, $folderPath, $recordId, $letterName);
        if ($folderId <= 0) return;
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
        if ($action === 'getSettings') return ['success' => true, 'settings' => $this->getProjectConfig($project_id)];
        if ($action === 'saveSettings') return $this->saveProjectConfig($project_id, $payload['settings']);
        if ($action === 'evaluateQueue') return ['success' => true, 'queue' => $this->evaluateQueue($project_id)];
        if ($action === 'generateLetters') return $this->generateLetters($project_id, $payload['items']);
        if ($action === 'testMerge') return $this->testMerge($project_id, $payload['rule_id'], $payload['record_id']);

        return ['success' => false, 'error' => 'Unknown action'];
    }

    public function getProjectConfig($pid): array {
        return [
            'automation_mode' => $this->getProjectSetting('automation_mode', $pid) ?: 'queued',
            'server_output_path' => $this->getProjectSetting('server_output_path', $pid) ?: '',
            'file_repo_templates_folder' => $this->getProjectSetting('file_repo_templates_folder', $pid) ?: 'MailMerge Templates',
            'file_repo_letters_folder' => $this->getProjectSetting('file_repo_letters_folder', $pid) ?: 'MailMerge Letters/{date}',
        ];
    }

    public function saveProjectConfig($pid, $cfg): array {
        $this->setProjectSetting('automation_mode', $cfg['automation_mode'] ?? 'queued', $pid);
        $this->setProjectSetting('server_output_path', $cfg['server_output_path'] ?? '', $pid);
        $this->setProjectSetting('file_repo_templates_folder', $cfg['file_repo_templates_folder'] ?? 'MailMerge Templates', $pid);
        $this->setProjectSetting('file_repo_letters_folder', $cfg['file_repo_letters_folder'] ?? 'MailMerge Letters/{date}', $pid);
        return ['success' => true];
    }

    public function handleTemplateUploads($pid, $files): array {
        if (empty($files)) return ['success' => false, 'error' => 'No files provided'];
        $uploaded = [];
        $errors = [];
        $tmplFolder = $this->getProjectSetting('file_repo_templates_folder', $pid) ?: 'MailMerge Templates';

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

            $this->addToFileRepoFolder($docId, $pid, "Mail Merge Template: " . $name, $tmplFolder);

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

    public function formatValue(string $val, string $format): string {
        $fmt = trim($format);
        $lowerFmt = strtolower($fmt);

        if ($lowerFmt === 'upper' || $lowerFmt === 'uppercase') return mb_strtoupper($val, 'UTF-8');
        if ($lowerFmt === 'lower' || $lowerFmt === 'lowercase') return mb_strtolower($val, 'UTF-8');
        if ($lowerFmt === 'title' || $lowerFmt === 'capitalize') return mb_convert_case(mb_strtolower($val, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        if ($lowerFmt === 'trim') return trim($val);

        if ($lowerFmt === 'currency') return '$' . number_format((float)$val, 2);
        if (str_starts_with($lowerFmt, 'number:') || str_starts_with($lowerFmt, 'decimal:')) {
            $decimals = (int)substr($fmt, strpos($fmt, ':') + 1);
            return number_format((float)$val, $decimals);
        }
        if ($lowerFmt === 'number' || $lowerFmt === 'integer' || $lowerFmt === 'int') return number_format((float)$val, 0);

        if ($lowerFmt === '24' || $lowerFmt === 'time:24') $fmt = 'H:i';
        else if ($lowerFmt === '12' || $lowerFmt === 'time:12') $fmt = 'g:i A';
        else if ($lowerFmt === 'm/d/y') $fmt = 'm/d/Y';
        else if ($lowerFmt === 'y-m-d') $fmt = 'Y-m-d';
        else if ($lowerFmt === 'iso') $fmt = 'Y-m-d H:i:s';

        $ts = strtotime($val);
        if ($ts !== false) return date($fmt, $ts);

        return $val;
    }

    public function resolveMappingSource(string $source, $recordId, $eventId, $pid, array $recordData, array $allRecordEvents = []): string {
        $clean = trim($source);
        $lower = strtolower($clean);
        $stripped = trim($lower, '[]{}');

        if ($stripped === 'today') return date('F j, Y');
        if ($stripped === 'today:m/d/y' || $stripped === 'today_usa') return date('m/d/Y');
        if ($stripped === 'today:y-m-d' || $stripped === 'date') return date('Y-m-d');
        if (str_starts_with($stripped, 'today:')) return date(substr($stripped, 6));

        if ($stripped === 'now') return date('F j, Y g:i A');
        if ($stripped === 'now:24') return date('H:i');
        if ($stripped === 'now:iso' || $stripped === 'timestamp') return date('Y-m-d H:i:s');
        if (str_starts_with($stripped, 'now:')) return date(substr($stripped, 4));

        if ($stripped === 'time' || $stripped === 'time:12') return date('g:i A');
        if ($stripped === 'time:24') return date('H:i');
        if (str_starts_with($stripped, 'time:')) return date(substr($stripped, 5));

        if ($stripped === 'record' || $stripped === 'record_id') return (string)$recordId;
        if ($stripped === 'user' || $stripped === 'user_name') return defined('USERID') ? USERID : '';

        // Conditional logic: if(...) or @IF(...) or @CALCTEXT(...)
        $expr = $clean;
        if (preg_match('/^@(CALCTEXT|calctext)\s*\((.+)\)$/is', $expr, $m)) $expr = trim($m[2]);
        if (str_starts_with($expr, '@')) $expr = substr($expr, 1);
        if (preg_match('/^if\s*\(.+/i', $expr)) {
            $eval = REDCap::evaluateLogic($expr, $pid, $recordId, $eventId, 1, "", "", null, true);
            if (($eval === null || $eval === false || $eval === '') && !empty($allRecordEvents)) {
                $uniqueEvents = REDCap::isLongitudinal() ? REDCap::getEventNames(true, false) : [];
                $prefixedExpr = preg_replace_callback('/(?<!\])\[([a-zA-Z0-9_\-]+)\]/', function($m) use ($eventId, $recordData, $allRecordEvents, $uniqueEvents) {
                    $f = $m[1];
                    if (!isset($recordData[$f]) || (string)$recordData[$f] === '') {
                        foreach ($allRecordEvents as $evId => $evRow) {
                            if ($evId != $eventId && isset($evRow[$f]) && (string)$evRow[$f] !== '') {
                                $uName = $uniqueEvents[$evId] ?? '';
                                if ($uName !== '') return '[' . $uName . '][' . $f . ']';
                            }
                        }
                    }
                    return $m[0];
                }, $expr);

                if ($prefixedExpr !== $expr) {
                    $eval = REDCap::evaluateLogic($prefixedExpr, $pid, $recordId, $eventId, 1, "", "", null, true);
                }
            }
            if ($eval !== null && $eval !== false) return (string)$eval;
        }

        // Support [field:format] and [field]:format syntax within expressions
        if (str_contains($clean, '[')) {
            $clean = preg_replace_callback('/\[([a-zA-Z0-9_\-]+)(?::([^\]]+))?\](?::([a-zA-Z0-9_\/:\s\-]+))?/', function($m) use ($recordId, $eventId, $pid, $recordData, $allRecordEvents) {
                $fName = $m[1];
                $format = !empty($m[2]) ? $m[2] : (!empty($m[3]) ? $m[3] : '');
                $pipedVal = Piping::replaceVariablesInLabel('[' . $fName . ']', $recordId, $eventId, 1, [], false, $pid, false);
                $rawVal = ($pipedVal !== '' && $pipedVal !== false) ? $pipedVal : ($recordData[$fName] ?? '');

                if ((string)$rawVal === '' && !empty($allRecordEvents)) {
                    foreach ($allRecordEvents as $evId => $evRow) {
                        if ($evId == $eventId) continue;
                        if (isset($evRow[$fName]) && !is_array($evRow[$fName]) && (string)$evRow[$fName] !== '') {
                            $rawVal = (string)$evRow[$fName];
                            break;
                        }
                    }
                }

                if ($format !== '') return $this->formatValue((string)$rawVal, $format);
                return (string)$rawVal;
            }, $clean);

            $piped = Piping::replaceVariablesInLabel($clean, $recordId, $eventId, 1, [], false, $pid, false);
            if ($piped !== '' && $piped !== false) return (string)$piped;
        }

        if (isset($recordData[$clean]) && !is_array($recordData[$clean]))
            return (string)$recordData[$clean];

        if (!empty($allRecordEvents)) {
            foreach ($allRecordEvents as $evId => $evRow) {
                if ($evId == $eventId) continue;
                if (isset($evRow[$clean]) && !is_array($evRow[$clean]) && (string)$evRow[$clean] !== '') {
                    return (string)$evRow[$clean];
                }
            }
        }

        return $clean;
    }

    public function evaluateQueue($pid): array {
        $rules = $this->getLetterRules($pid);
        if (empty($rules)) return [];

        $allData = REDCap::getData($pid, 'array');
        if (empty($allData)) return [];

        $isLongitudinal = REDCap::isLongitudinal();
        $eventNames = $isLongitudinal ? REDCap::getEventNames(true, false) : [];
        $numArms = 1;
        if ($isLongitudinal) {
            global $Proj;
            $p = (isset($Proj) && $Proj->project_id == $pid) ? $Proj : new \Project($pid);
            $numArms = $p->numArms ?? count($p->events ?? [1]);
        }

        $queue = [];
        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) continue;
            $cond = trim($rule['condition'] ?? '');
            $eventId = $rule['event_id'] ?: null;
            $flagField = trim($rule['flag_field'] ?? '');

            $loggedRecords = [];
            if ($flagField === '') {
                $logRes = $this->queryLogs("SELECT record WHERE message = 'Generated letter' AND rule_id = ?", [$rule['id']]);
                while ($r = $logRes->fetch_assoc())
                    if (!empty($r['record'])) $loggedRecords[$r['record']] = true;
            }

            foreach ($allData as $recordId => $recordEvents) {
                if ($cond !== '') {
                    $isDue = REDCap::evaluateLogic($cond, $pid, $recordId, $eventId);
                    if (!$isDue) continue;
                }

                $primaryEvent = $eventId ?: array_key_first($recordEvents);
                $row = $recordEvents[$primaryEvent] ?? [];

                if ($flagField !== '') {
                    $val = trim((string)($row[$flagField] ?? ''));
                    if ($val !== '' && $val !== '0') continue;
                } else if (isset($loggedRecords[$recordId])) {
                    continue;
                }

                $eventName = !empty($eventNames[$primaryEvent]) ? $eventNames[$primaryEvent] : 'Default';
                if ($numArms <= 1) {
                    $eventName = preg_replace('/_arm_\d+$/i', '', $eventName);
                }

                $recipientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($recipientName === '') {
                    foreach ($recordEvents as $eRow) {
                        $candidate = trim(($eRow['first_name'] ?? '') . ' ' . ($eRow['last_name'] ?? ''));
                        if ($candidate !== '') {
                            $recipientName = $candidate;
                            break;
                        }
                    }
                }
                $recipient = $recipientName ?: $recordId;

                $queue[] = [
                    'rule_id' => $rule['id'],
                    'rule_title' => $rule['title'],
                    'template_doc_id' => $rule['template_doc_id'],
                    'record_id' => $recordId,
                    'event_id' => $primaryEvent,
                    'event_name' => $eventName,
                    'recipient' => $recipient,
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
        $allRecordEvents = $data[$recordId] ?? [];
        $primaryEvent = $eventId ?: array_key_first($allRecordEvents);
        $recordData = $allRecordEvents[$primaryEvent] ?? [];

        $mergePayload = [
            'record_id' => $recordId,
            'today' => date('m/d/Y'),
            'today_date' => date('F j, Y'),
        ];

        foreach ($allRecordEvents as $evId => $evRow) {
            if ($evId == $primaryEvent) continue;
            foreach ($evRow as $f => $v) {
                if (!is_array($v) && (string)$v !== '' && !isset($mergePayload[$f])) {
                    $mergePayload[$f] = (string)$v;
                }
            }
        }

        foreach ($recordData as $f => $v) {
            if (!is_array($v) && (string)$v !== '') {
                $mergePayload[$f] = (string)$v;
            }
        }

        foreach ($rule['mappings'] ?: [] as $m) {
            $tag = trim($m['tag']);
            $source = trim($m['source']);
            if (!$tag || $source === '') continue;
            $mergePayload[$tag] = $this->resolveMappingSource($source, $recordId, $primaryEvent, $pid, $recordData, $allRecordEvents);
        }

        $outPath = tempnam(sys_get_temp_dir(), 'ltr_') . '.docx';
        $ok = DocxEngine::merge($tmplPath, $mergePayload, $outPath);
        @unlink($tmplPath);

        if (!$ok || !file_exists($outPath))
            return ['success' => false, 'error' => 'Word document merge failed'];

        $scheme = trim($rule['filename_scheme'] ?? '') ?: '{record}_{letter_name}';
        $filename = $this->resolvePlaceholders($scheme, $recordId, $rule['title']);
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        if (!str_ends_with(strtolower($filename), '.docx')) $filename .= '.docx';

        $autoMode = $this->getProjectSetting('automation_mode', $pid) ?: 'queued';
        $serverPath = trim((string)$this->getProjectSetting('server_output_path', $pid));
        if ($autoMode === 'server_path' && $serverPath !== '') {
            @mkdir($serverPath, 0775, true);
            copy($outPath, rtrim($serverPath, '/\\') . DIRECTORY_SEPARATOR . $filename);
        }

        $outDocId = $this->saveFile($outPath, $pid);
        @unlink($outPath);
        if (!$outDocId) return ['success' => false, 'error' => 'Failed to store generated letter'];

        $letterFolder = $this->getProjectSetting('file_repo_letters_folder', $pid) ?: 'MailMerge Letters/{date}';
        $this->addToFileRepoFolder($outDocId, $pid, "Generated Letter: {$filename}", $letterFolder, $recordId, $rule['title']);

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

        if (!$isTest)
            $this->log('Generated letter', [
                'project_id' => $pid,
                'record' => $recordId,
                'rule_id' => $rule['id'],
                'rule_title' => $rule['title'],
                'filename' => $filename,
                'doc_id' => $outDocId,
            ]);

        return ['success' => true, 'doc_id' => $outDocId, 'filename' => $filename];
    }

    public function cronMailMergeLetters($cronInfo): string {
        $pids = $this->getProjectsWithModuleEnabled();
        $total = 0;
        foreach ($pids as $pid) {
            $mode = $this->getProjectSetting('automation_mode', $pid) ?: 'queued';
            if ($mode === 'queued') continue;
            $queue = $this->evaluateQueue($pid);
            if (empty($queue)) continue;
            $res = $this->generateLetters($pid, $queue);
            $total += $res['generated'];
        }
        return "MailMerge cron completed: generated {$total} letters across projects.";
    }
}
