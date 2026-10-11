<?php

/** @var \ExternalModules\MailMerge $module */
$docId = (int)($_GET['doc_id'] ?? 0);
$pid = (int)($_GET['pid'] ?? 0);

if ($pid <= 0) {
    http_response_code(400);
    exit('Invalid document request');
}

$access = $module->checkFileRepoAccess();
if (!$access['allowed']) {
    http_response_code(403);
    exit('Access denied: ' . htmlspecialchars($access['message'], ENT_QUOTES));
}

if (isset($_GET['doc_ids'])) {
    $docIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)$_GET['doc_ids'])),
        static fn($id) => $id > 0
    )));
    if (empty($docIds)) {
        http_response_code(400);
        exit('Invalid document request');
    }
    if (!class_exists(\ZipArchive::class)) {
        http_response_code(500);
        exit('ZIP downloads are not available on this server');
    }

    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    $q = $module->query(
        "SELECT doc_id, doc_name FROM redcap_edocs_metadata WHERE doc_id IN ($placeholders) AND project_id = ? AND delete_date IS NULL",
        array_merge($docIds, [$pid])
    );
    $documents = [];
    while ($row = $q->fetch_assoc()) {
        $documents[(int)$row['doc_id']] = $row;
    }
    if (count($documents) !== count($docIds)) {
        http_response_code(404);
        exit('One or more documents were not found or have been deleted');
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'mailmerge_zip_');
    if ($zipPath === false) {
        http_response_code(500);
        exit('Unable to prepare ZIP download');
    }
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        http_response_code(500);
        exit('Unable to create ZIP download');
    }

    $tempFiles = [];
    $entryNames = [];
    foreach ($docIds as $id) {
        $tmp = \Files::copyEdocToTemp($id);
        if (!$tmp || !file_exists($tmp)) {
            $zip->close();
            foreach ($tempFiles as $tempFile) @unlink($tempFile);
            @unlink($zipPath);
            http_response_code(500);
            exit('Unable to read one or more document files');
        }
        $tempFiles[] = $tmp;

        $filename = basename($documents[$id]['doc_name'] ?: "letter_{$id}.docx");
        $entryName = $filename;
        $suffix = 2;
        while (isset($entryNames[$entryName])) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $entryName = $base . '_' . $suffix++ . ($extension !== '' ? '.' . $extension : '');
        }
        $entryNames[$entryName] = true;
        if (!$zip->addFile($tmp, $entryName)) {
            $zip->close();
            foreach ($tempFiles as $tempFile) @unlink($tempFile);
            @unlink($zipPath);
            http_response_code(500);
            exit('Unable to add a document to the ZIP download');
        }
    }

    if (!$zip->close()) {
        foreach ($tempFiles as $tempFile) @unlink($tempFile);
        @unlink($zipPath);
        http_response_code(500);
        exit('Unable to finish ZIP download');
    }
    foreach ($tempFiles as $tempFile) @unlink($tempFile);

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="mailmerge_letters.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if ($docId <= 0) {
    http_response_code(400);
    exit('Invalid document request');
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
