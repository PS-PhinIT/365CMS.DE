<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$allowedFieldTypes = ['text', 'textarea', 'checkbox', 'select', 'image_upload', 'color', 'number'];
$rawFieldType = (string) ($field['type'] ?? 'text');
$fieldType = in_array($rawFieldType, $allowedFieldTypes, true) ? $rawFieldType : 'text';
$fieldValue = (string) $val;
$previewUrl = $fieldValue !== '' ? htmlspecialchars($fieldValue, ENT_QUOTES) : '';
$fieldLabel = (string) ($field['label'] ?? '');
$fieldDescription = trim((string) ($field['description'] ?? ''));
$safeInputId = htmlspecialchars($inputId, ENT_QUOTES);
$safeInputName = htmlspecialchars($inputName, ENT_QUOTES);
$imagePreviewId = $inputId . '_preview';
$imageStatusId = $inputId . '_status';
?>
<div class="form-group">
    <label for="<?php echo $safeInputId; ?>" class="form-label">
        <?php echo htmlspecialchars($fieldLabel); ?>
    </label>

    <?php if ($fieldType === 'textarea'): ?>
        <textarea id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" class="form-control" rows="4"><?php echo htmlspecialchars($fieldValue); ?></textarea>

    <?php elseif ($fieldType === 'checkbox'): ?>
        <div class="customizer-checkbox-row">
            <input type="checkbox" id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" value="1" <?php echo $fieldValue !== '0' && $fieldValue !== '' ? 'checked' : ''; ?>>
            <label for="<?php echo $safeInputId; ?>" class="customizer-checkbox-label">Aktivieren</label>
        </div>

    <?php elseif ($fieldType === 'select'): ?>
        <select id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" class="form-control">
            <?php foreach (($field['options'] ?? []) as $optVal => $optLabel): ?>
                <option value="<?php echo htmlspecialchars((string) $optVal, ENT_QUOTES); ?>" <?php echo $fieldValue === (string) $optVal ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $optLabel); ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php elseif ($fieldType === 'image_upload'): ?>
        <div class="customizer-logo-upload" data-customizer-image-upload>
            <div id="<?php echo htmlspecialchars($imagePreviewId, ENT_QUOTES); ?>" class="customizer-logo-preview-wrap" data-preview-wrap>
                <?php if ($previewUrl !== ''): ?>
                    <img src="<?php echo $previewUrl; ?>" alt="Logo" class="customizer-logo-preview-img" data-preview-image>
                <?php else: ?>
                    <span class="customizer-logo-preview-empty" data-preview-placeholder>🖼️ Noch kein Logo ausgewählt</span>
                <?php endif; ?>
            </div>
            <div class="customizer-logo-upload-row">
                <label class="customizer-upload-button">
                    📁 Bild hochladen
                    <input type="file" name="logo_upload_file" accept="image/*" class="customizer-upload-input" data-customizer-image-file>
                </label>
                <span class="customizer-upload-hint">oder URL eingeben:</span>
            </div>
            <input type="text" id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" value="<?php echo $previewUrl; ?>" class="form-control" placeholder="https://example.com/logo.svg oder /uploads/theme-logos/logo.png" inputmode="text" autocomplete="off" spellcheck="false" data-customizer-image-url>
            <div id="<?php echo htmlspecialchars($imageStatusId, ENT_QUOTES); ?>" class="customizer-upload-hint" data-preview-status aria-live="polite"></div>
        </div>

    <?php elseif ($fieldType === 'color'): ?>
        <div class="customizer-color-row">
            <input type="color" id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" value="<?php echo htmlspecialchars($fieldValue, ENT_QUOTES); ?>" class="customizer-color-input">
            <input type="text" value="<?php echo htmlspecialchars($fieldValue, ENT_QUOTES); ?>" class="form-control customizer-color-text" inputmode="text" aria-label="<?php echo htmlspecialchars($fieldLabel . ' (Hex-Wert)', ENT_QUOTES); ?>">
        </div>

    <?php elseif ($fieldType === 'number'): ?>
        <input type="number" id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" value="<?php echo htmlspecialchars($fieldValue, ENT_QUOTES); ?>" class="form-control" step="1" inputmode="numeric">

    <?php else: ?>
        <input type="<?php echo htmlspecialchars($fieldType, ENT_QUOTES); ?>" id="<?php echo $safeInputId; ?>" name="<?php echo $safeInputName; ?>" value="<?php echo htmlspecialchars($fieldValue, ENT_QUOTES); ?>" class="form-control">
    <?php endif; ?>

    <?php if ($fieldDescription !== ''): ?>
        <small class="form-text customizer-field-help"><?php echo htmlspecialchars($fieldDescription); ?></small>
    <?php endif; ?>
</div>
