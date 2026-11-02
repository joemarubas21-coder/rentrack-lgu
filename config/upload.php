<?php
/**
 * Upload helpers: verify the REAL file content (finfo), not just the extension.
 */
if (!function_exists('upload_content_ok')) {
    function upload_content_ok(string $tmpPath, string $ext): bool
    {
        static $map = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf'  => ['application/pdf'],
            'txt'  => ['text/plain'],
            'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
        ];
        $ext = strtolower($ext);
        if (!isset($map[$ext]) || !is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            return false;
        }
        if (!class_exists('finfo')) {
            return false; // fail closed
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!in_array($mime, $map[$ext], true)) {
            return false;
        }
        // Images must really decode as images
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && @getimagesize($tmpPath) === false) {
            return false;
        }
        return true;
    }
}
