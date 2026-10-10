<?php

/** @var \ExternalModules\MailMerge $module */
$docId = (int)($_GET['doc_id'] ?? 0);
$pid = (int)($_GET['pid'] ?? 0);

if ($docId <= 0 || $pid <= 0) {
    http_response_code(400);
    exit('Invalid document request');
}

$access = $module->checkFileRepoAccess();
if (!$access['allowed']) {
    http_response_code(403);
    exit('Access denied: ' . htmlspecialchars($access['message'], ENT_QUOTES));
}

$q = $module->query("SELECT doc_name, mime_type FROM redcap_edocs_metadata WHERE doc_id = ? AND project_id = ? AND delete_date IS NULL", [$docId, $pid]);
$doc = $q->fetch_assoc();
if (!$doc) {
    http_response_code(404);
    exit('Document not found or has been deleted');
}

$tmp = \Files::copyEdocToTemp($docId);
if (!$tmp || !file_exists($tmp)) {
    http_response_code(500);
    exit('Unable to read document file');
}

$filename = $doc['doc_name'] ?: 'letter.docx';
$mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

// Ensure no previous output buffering pollutes the file download
while (ob_get_level()) {
    ob_end_clean();
}

header("Content-Type: $mime");
header("Content-Disposition: attachment; filename=\"" . str_replace('"', '', $filename) . "\"");
header("Content-Length: " . filesize($tmp));
header("Content-Transfer-Encoding: binary");
header("Cache-Control: private, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

readfile($tmp);
@unlink($tmp);
exit;
