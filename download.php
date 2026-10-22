<?php
/**
 * Streams a ticket attachment after checking that the signed-in user may see the ticket.
 * Files are never served directly from the uploads folder.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = Auth::requireLogin();
$file = DB::row(
    'SELECT f.*, r.is_internal FROM ticket_files f
     LEFT JOIN ticket_replies r ON r.id = f.reply_id
     WHERE f.id = ?',
    [input_int('id')]
);
$ticket = $file ? TicketService::find((int) $file['ticket_id']) : null;

if (!$file || !$ticket || !TicketService::canView($ticket, $user)) {
    abort(404);
}
if ((int) $file['is_internal'] === 1 && $user['role_slug'] === 'customer') {
    abort(404);
}

$path = FileUploader::directory() . DIRECTORY_SEPARATOR . basename($file['stored_name']);
if (!is_file($path)) {
    Logger::warning('Attachment missing on disk', ['file_id' => $file['id']]);
    abort(404, __('upload.missing'));
}

$isImage = in_array($file['mime_type'], ['image/jpeg', 'image/png'], true);
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']) ?: 'attachment';

header('Content-Type: ' . ($isImage || $file['mime_type'] === 'application/pdf' ? $file['mime_type'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header(sprintf(
    'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
    $isImage ? 'inline' : 'attachment',
    $fallbackName,
    rawurlencode($file['original_name'])
));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
exit;
