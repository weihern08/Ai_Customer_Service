<?php
/** Attachment input shared by ticket forms. Optional: $uploadError */
$maxMb = FileUploader::maxBytes() / 1048576;
?>
<div class="ns-upload" data-ns-upload>
    <label class="form-label" for="attachments"><?= e(__('upload.label')) ?></label>
    <label class="ns-dropzone <?= !empty($uploadError) ? 'is-invalid' : '' ?>" for="attachments">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <span><?= e(__('upload.drop')) ?></span>
        <small><?= e(__('upload.rules', ['types' => 'JPG, PNG, PDF, DOC, DOCX, TXT', 'mb' => $maxMb, 'n' => FileUploader::MAX_FILES])) ?></small>
    </label>
    <input class="visually-hidden" type="file" id="attachments" name="attachments[]" multiple
           accept="<?= e(FileUploader::acceptAttribute()) ?>"
           data-max-bytes="<?= FileUploader::maxBytes() ?>" data-max-files="<?= FileUploader::MAX_FILES ?>">
    <ul class="ns-file-list" data-ns-file-list></ul>
    <?php if (!empty($uploadError)): ?><div class="text-danger small mt-1"><?= e($uploadError) ?></div><?php endif; ?>
</div>
