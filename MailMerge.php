<?php

namespace UWMadison\MailMerge;

require_once __DIR__ . '/DocxEngine.php';

use ExternalModules\AbstractExternalModule;
use REDCap;
use Files;
use Piping;
use Throwable;

class MailMerge extends AbstractExternalModule
{
    public function redcap_module_link_check_display($project_id, $link): ?array
    {
        $access = $this->checkFileRepoAccess();
        if (!$access['allowed']) return null;
        return $link;
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record)
    {
        $access = $this->checkFileRepoAccess();
        if (!$access['allowed']) return ['success' => false, 'error' => $access['message']];

        if ($action === 'uploadTemplates') return $this->handleTemplateUploads($project_id, $payload['files']);
        if ($action === 'deleteTemplate') return $this->deleteTemplate($project_id, $payload['doc_id']);
        if ($action === 'saveLetterRules') return $this->saveLetterRules($project_id, $payload['rules']);
        if ($action === 'saveSettings') return $this->saveProjectConfig($project_id, $payload['settings']);
        if ($action === 'evaluateQueue') {
            $recordIds = $this->getProjectSetting('queue_record_cache', $project_id);
            if (!is_array($recordIds)) $recordIds = [];
            return [
                'success' => true,
                'queue' => $this->evaluateQueue($project_id, $recordIds),
                'evaluated_at' => $this->getProjectSetting('queue_last_full_evaluation', $project_id) ?: null,
            ];
        }
        if ($action === 'refreshQueue') {
            $queue = $this->refreshQueue($project_id);
            return [
                'success' => true,
                'queue' => $queue,
                'evaluated_at' => $this->getProjectSetting('queue_last_full_evaluation', $project_id),
            ];
        }
        if ($action === 'generateLetters') return $this->generateLetters($project_id, $payload['items']);
        if ($action === 'resolveLetters') return $this->resolveLetters($project_id, $payload['items']);
        if ($action === 'generateAndResolveLetters') return $this->generateAndResolveLetters($project_id, $payload['items']);
        if ($action === 'unresolveLetter') return $this->unresolveLetter($project_id, $payload['record_id'], $payload['rule_id']);
        if ($action === 'getArchive') return ['success' => true, 'archive' => $this->getArchive($project_id)];

        return ['success' => false, 'error' => 'Unknown action'];
    }

    public function checkFileRepoAccess(): array
    {
        if (($GLOBALS['file_repository_enabled'] ?? '1') != '1')
            return ['allowed' => false, 'message' => 'The File Repository is disabled at the system level on this REDCap server. Please contact a system administrator to enable it.'];

        if (defined('SUPER_USER') && SUPER_USER) return ['allowed' => true];

        $rights = REDCap::getUserRights(USERID);
        $u = $rights[USERID];
        if (empty($u['file_repository']) || $u['file_repository'] == '0')
            return ['allowed' => false, 'message' => 'Access Denied: You do not have File Repository permissions in this project. Please request File Repository access from a project administrator.'];

        return ['allowed' => true];
    }

    public function resolvePlaceholders(string $pattern, string $recordId = '', string $letterName = ''): string
    {
        $cleanLetter = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $letterName);
        return str_replace(
            ['{record}', '{letter_name}', '{date}', '{timestamp}'],
            [$recordId, $cleanLetter, date('Y-m-d'), date('Ymd_His')],
            $pattern
        );
    }

    public function getFolderId($pid, $folderPath, string $recordId = '', string $letterName = ''): int
    {
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

    public function addToFileRepoFolder($docId, $pid, $comment, $folderPath = 'MailMerge', string $recordId = '', string $letterName = ''): void
    {
        REDCap::addFileToRepository($docId, $pid, $comment);
        $folderId = $this->getFolderId($pid, $folderPath, $recordId, $letterName);
        if ($folderId <= 0) return;
        $res = $this->query("SELECT docs_id FROM redcap_docs_to_edocs WHERE doc_id = ? ORDER BY docs_id DESC LIMIT 1", [$docId]);
        $row = $res->fetch_assoc();
        if ($row)
            $this->query("INSERT IGNORE INTO redcap_docs_folders_files (docs_id, folder_id) VALUES (?, ?)", [(int)$row['docs_id'], $folderId]);
    }

    public function getProjectConfig($pid): array
    {
        return [
            'automation_mode' => $this->getProjectSetting('automation_mode', $pid) ?: 'queued',
            'server_output_path' => $this->getProjectSetting('server_output_path', $pid) ?: '',
            'file_repo_templates_folder' => $this->getProjectSetting('file_repo_templates_folder', $pid) ?: 'MailMerge Templates',
            'file_repo_letters_folder' => $this->getProjectSetting('file_repo_letters_folder', $pid) ?: 'MailMerge Letters/{date}',
        ];
    }

    public function saveProjectConfig($pid, $cfg): array
    {
        $this->setProjectSetting('automation_mode', $cfg['automation_mode'] ?? 'queued', $pid);
        $this->setProjectSetting('server_output_path', $cfg['server_output_path'] ?? '', $pid);
        $this->setProjectSetting('file_repo_templates_folder', $cfg['file_repo_templates_folder'] ?? 'MailMerge Templates', $pid);
        $this->setProjectSetting('file_repo_letters_folder', $cfg['file_repo_letters_folder'] ?? 'MailMerge Letters/{date}', $pid);
        return ['success' => true];
    }

    public function handleTemplateUploads($pid, $files): array
    {
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

    public function getTemplates($pid): array
    {
        return $this->getProjectSetting('uploaded_templates', $pid) ?: [];
    }

    public function deleteTemplate($pid, $docId): array
    {
        $templates = $this->getTemplates($pid);
        $updated = [];
        foreach ($templates as $t)
            if ($t['doc_id'] != $docId) $updated[] = $t;
        $this->setProjectSetting('uploaded_templates', $updated, $pid);
        return ['success' => true];
    }

    public function getLetterRules($pid): array
    {
        $rules = $this->getProjectSetting('letter_rules', $pid) ?: [];
        foreach ($rules as &$r) {
            if (!isset($r['enabled'])) {
                $r['enabled'] = true;
            } else {
                $r['enabled'] = !($r['enabled'] === false || $r['enabled'] === 0 || $r['enabled'] === '0' || $r['enabled'] === 'false' || $r['enabled'] === '');
            }
        }
        unset($r);
        return $rules;
    }

    public function saveLetterRules($pid, $rules): array
    {
        $this->setProjectSetting('letter_rules', $rules, $pid);
        return ['success' => true];
    }

    public function formatValue(string $val, string $format): string
    {
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

    private function getProjectObject($pid): \Project
    {
        global $Proj;
        return (isset($Proj) && $Proj->project_id == $pid) ? $Proj : new \Project($pid);
    }

    private function isProjectLongitudinal($pid): bool
    {
        return (bool)$this->getProjectObject($pid)->longitudinal;
    }

    private function getProjectEventNames($pid, $eventId = null)
    {
        $project = $this->getProjectObject($pid);
        if (!$project->longitudinal) return $eventId === null ? [] : null;
        return $project->getUniqueEventNames($eventId);
    }

    public function evaluateCondition(string $cond, $pid, $recordId, $eventId = null, array $allRecordEvents = []): bool
    {
        $cleanCond = trim($cond);
        if ($cleanCond === '') return true;

        $uniqueEvents = $this->getProjectEventNames($pid);

        // Build list of candidate expressions to test
        $candidates = [$cleanCond];

        // Substitute 'today', "today", [today] with literal date 'YYYY-MM-DD'
        $todayStr = "'" . date('Y-m-d') . "'";
        $withToday = preg_replace('/(?:\'today\'|"today"|\[today\])/i', $todayStr, $cleanCond);
        if ($withToday !== $cleanCond) {
            $candidates[] = $withToday;
        }

        // Cross-event fallback: replace unprefixed [field] with [event_name][field] where field has data
        if (!empty($allRecordEvents) && !empty($uniqueEvents)) {
            $replaceFallback = function ($expr) use ($eventId, $allRecordEvents, $uniqueEvents) {
                return preg_replace_callback('/(?<!\])\[([a-zA-Z0-9_\-]+)\](?!\s*\[)/', function ($m) use ($eventId, $allRecordEvents, $uniqueEvents) {
                    $f = $m[1];
                    if (in_array($f, $uniqueEvents)) return $m[0];

                    $currentVal = ($eventId && isset($allRecordEvents[$eventId][$f])) ? (string)$allRecordEvents[$eventId][$f] : '';
                    if ($currentVal === '') {
                        foreach ($allRecordEvents as $evId => $evRow) {
                            if ($evId != $eventId && isset($evRow[$f]) && (string)$evRow[$f] !== '') {
                                $uName = $uniqueEvents[$evId] ?? '';
                                if ($uName !== '') return '[' . $uName . '][' . $f . ']';
                            }
                        }
                    }
                    return $m[0];
                }, $expr);
            };

            $prefixed = $replaceFallback($cleanCond);
            if ($prefixed !== $cleanCond) {
                $candidates[] = $prefixed;
                $prefixedToday = preg_replace('/(?:\'today\'|"today"|\[today\])/i', $todayStr, $prefixed);
                if ($prefixedToday !== $prefixed) {
                    $candidates[] = $prefixedToday;
                }
            }
        }

        $candidates = array_unique($candidates);
        $targetEvent = $eventId ?: (!empty($allRecordEvents) ? array_key_first($allRecordEvents) : null);

        foreach ($candidates as $expr) {
            // 1. Try specific or primary event context first
            if ($targetEvent) {
                try {
                    $res = REDCap::evaluateLogic($expr, $pid, $recordId, $targetEvent);
                    if ($res === true || $res === 1 || $res === '1') return true;
                } catch (Throwable $e) {
                }
            }

            // 2. Try global record context (passing null event allows cross-event references [event][field] to evaluate cleanly)
            try {
                $res = REDCap::evaluateLogic($expr, $pid, $recordId, null);
                if ($res === true || $res === 1 || $res === '1') return true;
            } catch (Throwable $e) {
            }

            // 3. Fallback: try remaining events (only when the rule isn't tied to a specific event)
            if (!$eventId && !empty($allRecordEvents)) {
                foreach (array_keys($allRecordEvents) as $otherEvId) {
                    if ($otherEvId == $targetEvent) continue;
                    try {
                        $res = REDCap::evaluateLogic($expr, $pid, $recordId, $otherEvId);
                        if ($res === true || $res === 1 || $res === '1') return true;
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        return false;
    }

    public function resolveMappingSource(string $source, $recordId, $eventId, $pid, array $recordData, array $allRecordEvents = []): string
    {
        $clean = trim($source);
        $lower = strtolower($clean);
        $stripped = trim($lower, '[]{}');

        if ($stripped === 'today') return date('F j, Y');
        if ($stripped === 'today:m/d/y' || $stripped === 'today_usa') return date('m/d/Y');
        if ($stripped === 'today:y-m-d' || $stripped === 'date') return date('Y-m-d');
        if (str_starts_with($stripped, 'today:')) return date(substr($stripped, 6));

        if ($stripped === 'now') return date('F j, Y g:i A');
        if ($stripped === 'now:24') return date('Y-m-d H:i');
        if ($stripped === 'now:iso' || $stripped === 'timestamp') return date('Y-m-d H:i:s');
        if (str_starts_with($stripped, 'now:')) return date(substr($stripped, 4));

        if ($stripped === 'time' || $stripped === 'time:12') return date('g:i A');
        if ($stripped === 'time:24') return date('H:i');
        if (str_starts_with($stripped, 'time:')) return date(substr($stripped, 5));

        if ($stripped === 'record' || $stripped === 'record_id') return (string)$recordId;
        if ($stripped === 'user' || $stripped === 'user_name') return defined('USERID') ? USERID : '';

        // Logic / function expressions: if(...), datediff(...), concat(...), round(...), @IF(...), @CALCTEXT(...)
        $expr = $clean;
        if (preg_match('/^@(CALCTEXT|calctext)\s*\((.+)\)$/is', $expr, $m)) $expr = trim($m[2]);
        if (str_starts_with($expr, '@')) $expr = substr($expr, 1);
        $logicFunctions = 'if|datediff|concat|concat_ws|round|roundup|rounddown|sum|mean|min|max|abs|sqrt|upper|lower|trim|left|right|mid|length|contains|starts_with|ends_with|replace_text|year|month|day|isnumber|isinteger|isblankormissingcode';
        if (preg_match('/^(' . $logicFunctions . ')\s*\(.+/i', $expr)) {
            $eval = REDCap::evaluateLogic($expr, $pid, $recordId, $eventId, 1, "", "", null, true);
            if (($eval === null || $eval === false || $eval === '') && !empty($allRecordEvents)) {
                $uniqueEvents = $this->getProjectEventNames($pid);
                $prefixedExpr = preg_replace_callback('/(?<!\])\[([a-zA-Z0-9_\-]+)\]/', function ($m) use ($eventId, $recordData, $allRecordEvents, $uniqueEvents) {
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
            $clean = preg_replace_callback('/\[([a-zA-Z0-9_\-]+)(?::([^\]]+))?\](?::([a-zA-Z0-9_\/:\s\-]+))?/', function ($m) use ($recordId, $eventId, $pid, $recordData, $allRecordEvents) {
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

    public function evaluateQueue($pid, ?array $recordIds = null): array
    {
        $rules = $this->getLetterRules($pid);
        if (empty($rules)) return [];

        if ($recordIds !== null && empty($recordIds)) return [];

        $allData = REDCap::getData($pid, 'array', $recordIds);
        if (empty($allData)) return [];

        $project = $this->getProjectObject($pid);
        $isLongitudinal = (bool)$project->longitudinal;
        $eventNames = $isLongitudinal ? $project->getUniqueEventNames() : [];
        $numArms = $isLongitudinal ? ($project->numArms ?? count($project->events ?? [1])) : 1;
        $queue = [];
        foreach ($rules as $rule) {
            $isEnabled = !isset($rule['enabled']) || ($rule['enabled'] === true || $rule['enabled'] === 'true' || $rule['enabled'] === 1 || $rule['enabled'] === '1');
            if (!$isEnabled) continue;
            $cond = trim($rule['condition'] ?? '');
            $eventId = $rule['event_id'] ?: null;
            $flagField = trim($rule['flag_field'] ?? '');
            $printedField = trim($rule['printed_flag_field'] ?? '');

            // Track latest resolution log action ('Marked letter resolved' or 'Returned letter to queue')
            $resolutionLogAction = [];
            try {
                $resLogRes = $this->queryLogs(
                    "SELECT log_id, record, message WHERE (message = 'Marked letter resolved' OR message = 'Returned letter to queue') AND rule_id = ? ORDER BY log_id ASC",
                    [(string)$rule['id']]
                );
                if ($resLogRes) {
                    while ($r = $resLogRes->fetch_assoc()) {
                        if (empty($r['record'])) continue;
                        $resolutionLogAction[(string)$r['record']] = $r['message'];
                    }
                }
            } catch (Throwable $e) {
            }

            // Track generated status from logs
            $generatedInLog = [];
            try {
                $genLogRes = $this->queryLogs(
                    "SELECT record WHERE message = 'Generated letter' AND rule_id = ?",
                    [(string)$rule['id']]
                );
                if ($genLogRes) {
                    while ($r = $genLogRes->fetch_assoc()) {
                        if (!empty($r['record'])) {
                            $generatedInLog[(string)$r['record']] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
            }

            foreach ($allData as $recordId => $recordEvents) {
                $recStr = (string)$recordId;
                if ($cond !== '') {
                    $isDue = $this->evaluateCondition($cond, $pid, $recordId, $eventId, $recordEvents);
                    if (!$isDue) continue;
                }

                $primaryEvent = $eventId ?: array_key_first($recordEvents);
                $row = $recordEvents[$primaryEvent] ?? [];

                // Determine resolution status (removal from queue):
                // 1. If user explicitly returned letter to queue, do not consider resolved
                $lastRes = $resolutionLogAction[$recStr] ?? null;
                $isResolved = false;

                // Read a flag field value. When the rule targets a specific event, only that event
                // is checked so a shared field name on another event can't leak into this rule.
                $readFlag = function (string $field) use ($row, $recordEvents, $eventId): bool {
                    $val = trim((string)($row[$field] ?? ''));
                    if (($val === '' || $val === '0') && !$eventId) {
                        foreach ($recordEvents as $eRow) {
                            $candidateVal = trim((string)($eRow[$field] ?? ''));
                            if ($candidateVal !== '' && $candidateVal !== '0') {
                                $val = $candidateVal;
                                break;
                            }
                        }
                    }
                    return $val !== '' && $val !== '0';
                };

                if ($lastRes === 'Returned letter to queue') {
                    $isResolved = false;
                } else if ($printedField !== '') {
                    // When printed_flag_field is defined, REDCap field value is the authority
                    $isResolved = $readFlag($printedField);
                } else if ($lastRes === 'Marked letter resolved') {
                    // Only when NO printed_flag_field is configured do we fall back to log action
                    $isResolved = true;
                }

                if ($isResolved) continue;

                // Determine whether it has been generated
                $isGenerated = false;
                if ($flagField !== '') {
                    $isGenerated = $readFlag($flagField);
                } else if (isset($generatedInLog[$recStr])) {
                    $isGenerated = true;
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
                    'is_generated' => $isGenerated,
                ];
            }
        }

        return $queue;
    }

    public function refreshQueue($pid): array
    {
        $queue = $this->evaluateQueue($pid);
        $recordIds = array_values(array_unique(array_map(
            static fn($item) => (string)$item['record_id'],
            $queue
        )));
        $this->setProjectSetting('queue_record_cache', $recordIds, $pid);
        $this->setProjectSetting('queue_last_full_evaluation', time(), $pid);
        return $queue;
    }

    public function generateLetters($pid, $items): array
    {
        if (empty($items)) return ['success' => false, 'error' => 'No items selected'];
        $rules = [];
        foreach ($this->getLetterRules($pid) as $r)
            $rules[$r['id']] = $r;

        $generated = 0;
        $errors = [];
        $files = [];

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
            $files[] = [
                'record_id' => $recordId,
                'rule_id' => $rule['id'],
                'doc_id' => $res['doc_id'],
                'filename' => $res['filename'],
                'download_url' => $this->getUrl('download.php') . '&doc_id=' . $res['doc_id'],
            ];
        }

        $result = ['success' => true, 'generated' => $generated, 'files' => $files, 'errors' => $errors];
        if (count($files) > 1) {
            $docIds = implode(',', array_column($files, 'doc_id'));
            $result['zip_download_url'] = $this->getUrl('download.php') . '&doc_ids=' . rawurlencode($docIds);
        }
        return $result;
    }

    public function resolveLetters($pid, $items): array
    {
        if (empty($items)) return ['success' => false, 'error' => 'No items specified'];

        $rules = [];
        foreach ($this->getLetterRules($pid) as $r)
            $rules[(string)$r['id']] = $r;

        $resolved = 0;
        $username = defined('USERID') ? USERID : 'System';
        $isLongitudinal = $this->isProjectLongitudinal($pid);

        foreach ($items as $item) {
            $recordId = $item['record_id'] ?? null;
            $ruleId = $item['rule_id'] ?? null;
            $rule = $rules[(string)$ruleId] ?? null;
            if (!$recordId || !$rule) continue;

            $eventId = $rule['event_id'] ?: null;
            $targetField = trim($rule['printed_flag_field'] ?? '');

            if ($targetField !== '') {
                $flagValue = '1';
                $dict = REDCap::getDataDictionary($pid, 'array', false, [$targetField]);
                $valType = $dict[$targetField]['text_validation_type_or_show_slider_number'] ?? '';
                if (str_starts_with($valType, 'datetime_seconds')) $flagValue = date('Y-m-d H:i:s');
                else if (str_starts_with($valType, 'datetime')) $flagValue = date('Y-m-d H:i');
                else if (str_starts_with($valType, 'date')) $flagValue = date('Y-m-d');

                $targetEvId = $eventId;
                if ($isLongitudinal && !$targetEvId) {
                    $recData = REDCap::getData($pid, 'array', $recordId)[$recordId] ?? [];
                    $targetEvId = array_key_first($recData);
                }
                $eventName = ($isLongitudinal && $targetEvId) ? $this->getProjectEventNames($pid, $targetEvId) : null;
                $saveData = [
                    'record_id' => $recordId,
                    $targetField => $flagValue,
                ];
                if ($eventName) $saveData['redcap_event_name'] = $eventName;
                REDCap::saveData($pid, 'json', json_encode([$saveData]));
            }

            $this->log('Marked letter resolved', [
                'project_id' => $pid,
                'record' => (string)$recordId,
                'rule_id' => (string)$rule['id'],
                'rule_title' => $rule['title'],
                'username' => $username,
            ]);

            $resolved++;
        }

        return ['success' => true, 'resolved' => $resolved];
    }

    public function unresolveLetter($pid, $recordId, $ruleId): array
    {
        if (!$recordId || !$ruleId) return ['success' => false, 'error' => 'Missing record or rule'];

        $rules = [];
        foreach ($this->getLetterRules($pid) as $r)
            $rules[(string)$r['id']] = $r;

        $rule = $rules[(string)$ruleId] ?? null;
        if (!$rule) return ['success' => false, 'error' => 'Rule not found'];

        $eventId = $rule['event_id'] ?: null;
        $fieldsToReset = [];
        if (!empty($rule['printed_flag_field'])) $fieldsToReset[] = trim($rule['printed_flag_field']);
        $fieldsToReset = array_unique(array_filter($fieldsToReset));

        $isLongitudinal = $this->isProjectLongitudinal($pid);

        if (!empty($fieldsToReset)) {
            $recData = REDCap::getData($pid, 'array', $recordId)[$recordId] ?? [];
            $saveBatch = [];

            if ($isLongitudinal) {
                $eventsModified = [];
                foreach ($recData as $evId => $evFields) {
                    // Event-specific rules only touch their own event
                    if ($eventId && $evId != $eventId) continue;
                    $evUniqueName = $this->getProjectEventNames($pid, $evId);
                    if (!$evUniqueName) continue;
                    $row = ['record_id' => $recordId, 'redcap_event_name' => $evUniqueName];
                    $hasField = false;
                    foreach ($fieldsToReset as $f) {
                        if (isset($evFields[$f]) && (string)$evFields[$f] !== '') {
                            $row[$f] = '';
                            $hasField = true;
                        }
                    }
                    if ($hasField) {
                        $saveBatch[] = $row;
                        $eventsModified[$evId] = true;
                    }
                }

                $targetEvId = $eventId ?: array_key_first($recData);
                if ($targetEvId && !isset($eventsModified[$targetEvId])) {
                    $evUniqueName = $this->getProjectEventNames($pid, $targetEvId);
                    if ($evUniqueName) {
                        $row = ['record_id' => $recordId, 'redcap_event_name' => $evUniqueName];
                        foreach ($fieldsToReset as $f) {
                            $row[$f] = '';
                        }
                        $saveBatch[] = $row;
                    }
                }
            } else {
                $row = ['record_id' => $recordId];
                foreach ($fieldsToReset as $f) {
                    $row[$f] = '';
                }
                $saveBatch[] = $row;
            }

            if (!empty($saveBatch)) {
                REDCap::saveData($pid, 'json', json_encode($saveBatch));
            }
        }

        $username = defined('USERID') ? USERID : 'System';
        $this->log('Returned letter to queue', [
            'project_id' => $pid,
            'record' => (string)$recordId,
            'rule_id' => (string)$rule['id'],
            'rule_title' => $rule['title'],
            'username' => $username,
        ]);

        $cachedRecordIds = $this->getProjectSetting('queue_record_cache', $pid);
        if (!is_array($cachedRecordIds)) $cachedRecordIds = [];
        $cachedRecordIds[] = (string)$recordId;
        $this->setProjectSetting('queue_record_cache', array_values(array_unique($cachedRecordIds)), $pid);

        return ['success' => true];
    }

    public function generateAndResolveLetters($pid, $items): array
    {
        $genRes = $this->generateLetters($pid, $items);
        if (!$genRes['success']) return $genRes;
        $resRes = $this->resolveLetters($pid, $items);
        return [
            'success' => true,
            'generated' => $genRes['generated'],
            'resolved' => $resRes['resolved'],
            'files' => $genRes['files'] ?? [],
            'zip_download_url' => $genRes['zip_download_url'] ?? null,
            'errors' => $genRes['errors'] ?? [],
        ];
    }

    public function getArchive($pid): array
    {
        $q = $this->queryLogs("SELECT log_id, timestamp, ui_id, record, message, rule_id, rule_title, filename, doc_id, username WHERE message = 'Generated letter' ORDER BY timestamp DESC", []);
        $rows = [];
        $docIds = [];
        $uiIds = [];

        while ($r = $q->fetch_assoc()) {
            if (!empty($r['doc_id'])) $docIds[] = (int)$r['doc_id'];
            if (!empty($r['ui_id'])) $uiIds[] = (int)$r['ui_id'];
            $rows[] = $r;
        }

        $userMap = [];
        if (!empty($uiIds)) {
            $uniqueUiIds = array_values(array_unique($uiIds));
            $placeholders = implode(',', array_fill(0, count($uniqueUiIds), '?'));
            $uRes = $this->query("SELECT ui_id, username FROM redcap_user_information WHERE ui_id IN ($placeholders)", $uniqueUiIds);
            while ($uRow = $uRes->fetch_assoc())
                $userMap[$uRow['ui_id']] = $uRow['username'];
        }

        $validDocs = [];
        if (!empty($docIds)) {
            $uniqueDocIds = array_values(array_unique($docIds));
            $placeholders = implode(',', array_fill(0, count($uniqueDocIds), '?'));
            $params = array_merge($uniqueDocIds, [$pid]);
            $dRes = $this->query("SELECT doc_id FROM redcap_edocs_metadata WHERE doc_id IN ($placeholders) AND project_id = ? AND delete_date IS NULL", $params);
            while ($dRow = $dRes->fetch_assoc())
                $validDocs[(int)$dRow['doc_id']] = true;
        }

        // Calculate version numbers (v1, v2, ...) based on chronological generation per record and rule
        $versionTotals = [];
        // Walk chronologically (oldest first) to count up versions
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $key = ($rows[$i]['record'] ?? '') . '_' . ($rows[$i]['rule_id'] ?? '');
            $versionTotals[$key] = ($versionTotals[$key] ?? 0) + 1;
            $rows[$i]['version'] = 'v' . $versionTotals[$key];
        }

        $archive = [];
        foreach ($rows as $r) {
            $docId = (int)($r['doc_id'] ?? 0);
            $hasFile = isset($validDocs[$docId]);
            $username = !empty($r['username']) ? $r['username'] : ($userMap[$r['ui_id']] ?? (defined('USERID') ? USERID : 'System'));
            $versionKey = ($r['record'] ?? '') . '_' . ($r['rule_id'] ?? '');

            $archive[] = [
                'log_id' => $r['log_id'],
                'record' => $r['record'],
                'rule_id' => $r['rule_id'],
                'rule_title' => $r['rule_title'] ?: 'Letter',
                'filename' => $r['filename'] ?: "letter_{$r['record']}.docx",
                'doc_id' => $docId,
                'has_file' => $hasFile,
                'download_url' => $hasFile ? ($this->getUrl('download.php') . '&doc_id=' . $docId) : null,
                'username' => $username,
                'timestamp' => $r['timestamp'],
                'formatted_date' => date('M j, Y g:i A', strtotime($r['timestamp'])),
                'version' => $r['version'] ?? 'v1',
                'version_count' => $versionTotals[$versionKey] ?? 1,
            ];
        }

        return $archive;
    }

    public function cronMailMergeLetters($cronInfo): string
    {
        $pids = $this->getProjectsWithModuleEnabled();
        $total = 0;
        foreach ($pids as $pid) {
            $mode = $this->getProjectSetting('automation_mode', $pid) ?: 'queued';
            $queue = $this->refreshQueue($pid);
            if ($mode === 'queued') continue;
            if (empty($queue)) continue;
            $res = $this->generateLetters($pid, $queue);
            $total += $res['generated'];
        }
        return "MailMerge cron completed: generated {$total} letters across projects.";
    }

    private function mergeSingleLetter($pid, $rule, $recordId): array
    {
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
            $safeServerPath = $this->getSafePath($filename, $serverPath);
            copy($outPath, $safeServerPath);
        }

        $tempNamedDir = $this->getSafePath('mm_' . uniqid(), sys_get_temp_dir());
        @mkdir($tempNamedDir, 0777, true);
        $namedOutPath = $this->getSafePath($filename, $tempNamedDir);
        copy($outPath, $namedOutPath);

        $outDocId = $this->saveFile($namedOutPath, $pid);
        @unlink($namedOutPath);
        @rmdir($tempNamedDir);
        @unlink($outPath);
        if (!$outDocId) return ['success' => false, 'error' => 'Failed to store generated letter'];

        $this->query("UPDATE redcap_edocs_metadata SET doc_name = ? WHERE doc_id = ? AND project_id = ?", [$filename, $outDocId, $pid]);

        $letterFolder = $this->getProjectSetting('file_repo_letters_folder', $pid) ?: 'MailMerge Letters/{date}';
        $this->addToFileRepoFolder($outDocId, $pid, "Generated Letter: {$filename}", $letterFolder, $recordId, $rule['title']);

        if (!empty($rule['flag_field'])) {
            $flagField = trim($rule['flag_field']);
            $flagValue = '1';
            $dict = REDCap::getDataDictionary($pid, 'array', false, [$flagField]);
            $valType = $dict[$flagField]['text_validation_type_or_show_slider_number'] ?? '';
            if (str_starts_with($valType, 'datetime_seconds')) $flagValue = date('Y-m-d H:i:s');
            else if (str_starts_with($valType, 'datetime')) $flagValue = date('Y-m-d H:i');
            else if (str_starts_with($valType, 'date')) $flagValue = date('Y-m-d');

            $isLongitudinal = $this->isProjectLongitudinal($pid);
            $targetEvId = $eventId ?: $primaryEvent;
            $evName = ($isLongitudinal && $targetEvId) ? $this->getProjectEventNames($pid, $targetEvId) : null;
            $saveRow = [
                'record_id' => $recordId,
                $flagField => $flagValue,
            ];
            if ($evName) $saveRow['redcap_event_name'] = $evName;

            REDCap::saveData($pid, 'json', json_encode([$saveRow]));
        }

        $this->log('Generated letter', [
            'project_id' => $pid,
            'record' => (string)$recordId,
            'rule_id' => (string)$rule['id'],
            'rule_title' => $rule['title'],
            'filename' => $filename,
            'doc_id' => $outDocId,
            'username' => defined('USERID') ? USERID : 'System',
        ]);

        return ['success' => true, 'doc_id' => $outDocId, 'filename' => $filename];
    }
}
