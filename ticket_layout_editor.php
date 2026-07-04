<?php
require_once __DIR__ . '/auth.php';
auth_require_roles(['admin']);

include 'db.php';
require_once __DIR__ . '/buyer_activation_helper.php';
require_once __DIR__ . '/ticket_layout_helper.php';
require_once __DIR__ . '/dashboard_sidebar_helper.php';

$isAdmin = auth_is_admin();
$dashboardPath = '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());

$flashMessage = '';
$flashType = 'success';
$layout = ticket_layout_load();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $updatedLayout = [
            'background_image' => (string) ($layout['background_image'] ?? 'img/ticket.png'),
            'page_width_mm' => (float) ($_POST['page_width_mm'] ?? $layout['page_width_mm']),
            'page_height_mm' => (float) ($_POST['page_height_mm'] ?? $layout['page_height_mm']),
            'qr_x_mm' => (float) ($_POST['qr_x_mm'] ?? $layout['qr_x_mm']),
            'qr_y_mm' => (float) ($_POST['qr_y_mm'] ?? $layout['qr_y_mm']),
            'qr_size_mm' => (float) ($_POST['qr_size_mm'] ?? $layout['qr_size_mm']),
            'text_x_mm' => (float) ($_POST['text_x_mm'] ?? $layout['text_x_mm']),
            'text_y_mm' => (float) ($_POST['text_y_mm'] ?? $layout['text_y_mm']),
            'text_font_size_pt' => (float) ($_POST['text_font_size_pt'] ?? $layout['text_font_size_pt']),
            'text_color_hex' => trim((string) ($_POST['text_color_hex'] ?? $layout['text_color_hex'])),
            'text_align' => trim((string) ($_POST['text_align'] ?? $layout['text_align'])),
            'text_mode' => trim((string) ($_POST['text_mode'] ?? $layout['text_mode'])),
        ];

        if (isset($_FILES['background_image_file']) && is_array($_FILES['background_image_file']) && (int) ($_FILES['background_image_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath = (string) $_FILES['background_image_file']['tmp_name'];
            $originalName = (string) ($_FILES['background_image_file']['name'] ?? 'layout-background.png');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                throw new RuntimeException('Background image must be PNG, JPG, JPEG, or WEBP.');
            }
            $uploadDirectory = ticket_layout_upload_directory();
            if (!is_dir($uploadDirectory)) {
                mkdir($uploadDirectory, 0777, true);
            }
            $targetPath = $uploadDirectory . '/background.' . $extension;
            if (!move_uploaded_file($tmpPath, $targetPath)) {
                throw new RuntimeException('Unable to save the uploaded layout background image.');
            }
            $updatedLayout['background_image'] = ticket_layout_public_path($targetPath);
        }

        $layout = ticket_layout_save($updatedLayout);
        $flashMessage = 'Ticket layout updated successfully.';
        $flashType = 'success';
    } catch (Throwable $exception) {
        $flashMessage = $exception->getMessage();
        $flashType = 'danger';
    }
}

$backgroundWebPath = htmlspecialchars((string) $layout['background_image'], ENT_QUOTES);
$previewText = htmlspecialchars(ticket_layout_display_text($layout, ['PSMECPMXWEN_00001', 'PSMECPMXWEN_00010']), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Layout Editor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; }
        :root {
            --primary: #283555;
            --secondary: #3a4a6f;
            --accent: #ff6b35;
            --light: #f4f6f9;
            --dark: #2c3e50;
            --border-color: #e8eef5;
        }
        body { background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%); min-height: 100vh; color: var(--dark); }
        .dashboard-container { display: flex; min-height: 100vh; }
        aside.sidebar {
            width: 250px; height: 100vh; top: 0; z-index: 50;
            background: linear-gradient(180deg, rgba(40, 53, 85, 0.95) 0%, rgba(58, 74, 111, 0.95) 100%);
            color: white; padding: 22px 20px 30px 20px; position: fixed; left: 0; bottom: 0;
            box-shadow: 2px 0 8px rgba(40, 53, 85, 0.2);
        }
        aside.sidebar nav ul { list-style: none; padding: 0; margin-top: 10px; }
        aside.sidebar nav ul li { margin: 10px 0; }
        aside.sidebar nav ul li a { display: flex; align-items: center; color: var(--light); text-decoration: none; padding: 8px 12px; border-radius: 5px; }
        aside.sidebar nav ul li a i { margin-right: 10px; width: 20px; text-align: center; }
        aside.sidebar nav ul li a:hover, aside.sidebar nav ul li a.active { background: rgba(255, 107, 53, 0.2); padding-left: 15px; border-left: 3px solid var(--accent); }
        aside.sidebar .sidebar-header h1 { font-size: 16px; color: #fff; margin: 0; }
        main.main-content { flex: 1; padding: 2rem; margin-left: 280px; }
        .card { border: 1px solid var(--border-color); border-radius: 14px; box-shadow: 0 8px 30px rgba(40,53,85,0.12); }
        .card-header { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: #fff; font-weight: 600; }
        .layout-preview-wrap { overflow: hidden; background: #f1f5f9; border-radius: 14px; padding: 16px; }
        .layout-preview {
            position: relative;
            width: 100%;
            min-height: 160px;
            aspect-ratio: <?php echo round((float) $layout['page_width_mm'], 4); ?> / <?php echo round((float) $layout['page_height_mm'], 4); ?>;
            background-image: url('<?php echo $backgroundWebPath; ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center center;
            background-color: #ffffff;
            border-radius: 12px;
            border: 2px dashed #cbd5e1;
            margin: 0 auto;
        }
        .placeholder {
            position: absolute;
            cursor: move;
            user-select: none;
            touch-action: none;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            text-align: center;
            border-radius: 10px;
            transition: box-shadow 0.15s ease;
        }
        .placeholder.active { box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.24); }
        .placeholder.qr { border: 2px dashed #0f766e; background: rgba(20,184,166,0.12); color: #0f766e; }
        .placeholder.text { border: 2px dashed #1d4ed8; background: rgba(59,130,246,0.10); color: <?php echo htmlspecialchars((string) $layout['text_color_hex'], ENT_QUOTES); ?>; padding: 4px 10px; }
        .size-adjust { display: flex; gap: 8px; align-items: center; }
        .size-adjust .form-control { flex: 1; }
        .size-adjust button { width: 40px; }
        .note { font-size: 0.9rem; color: #64748b; }
        @media (max-width: 768px) {
            aside.sidebar { width: 100%; height: auto; position: relative; box-shadow: none; }
            main.main-content { margin-left: 0; padding: 12px; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php render_dashboard_sidebar('/CPMraffle/ticket_layout_editor.php', $isAdmin, $dashboardPath); ?>
    <main class="main-content">
        <div class="container py-3">
            <h2 class="mb-3">Ticket Layout Editor</h2>
            <p class="note mb-3">This editor controls the two required placeholders for buyer ticket PDFs: the QR code area and the ticket number or ticket range text.</p>

            <?php if ($flashMessage !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES); ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">Live Preview</div>
                        <div class="card-body">
                            <div class="layout-preview-wrap">
                                <div id="layoutPreview" class="layout-preview">
                                    <div id="qrPlaceholder" class="placeholder qr">Activation QR</div>
                                    <div id="textPlaceholder" class="placeholder text"><?php echo $previewText; ?></div>
                                </div>
                            </div>
                            <div class="note mt-3">Drag each placeholder directly on the preview to update its coordinates.</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">Layout Settings</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data" id="layoutForm" class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Background Image</label>
                                    <input type="file" class="form-control" name="background_image_file" accept=".png,.jpg,.jpeg,.webp">
                                    <div class="note mt-2">Current file: <?php echo htmlspecialchars((string) $layout['background_image'], ENT_QUOTES); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Page Width (mm)</label>
                                    <input type="number" step="0.01" class="form-control" name="page_width_mm" id="page_width_mm" value="<?php echo htmlspecialchars((string) $layout['page_width_mm'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Page Height (mm)</label>
                                    <input type="number" step="0.01" class="form-control" name="page_height_mm" id="page_height_mm" value="<?php echo htmlspecialchars((string) $layout['page_height_mm'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">QR X (mm)</label>
                                    <input type="number" step="0.01" class="form-control position-field" name="qr_x_mm" id="qr_x_mm" value="<?php echo htmlspecialchars((string) $layout['qr_x_mm'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">QR Y (mm)</label>
                                    <input type="number" step="0.01" class="form-control position-field" name="qr_y_mm" id="qr_y_mm" value="<?php echo htmlspecialchars((string) $layout['qr_y_mm'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">QR Size (mm)</label>
                                    <div class="size-adjust">
                                        <input type="number" step="0.01" class="form-control" name="qr_size_mm" id="qr_size_mm" value="<?php echo htmlspecialchars((string) $layout['qr_size_mm'], ENT_QUOTES); ?>">
                                        <button type="button" class="btn btn-outline-secondary" id="qr_size_down" title="Shrink QR">-</button>
                                        <button type="button" class="btn btn-outline-secondary" id="qr_size_up" title="Expand QR">+</button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Text X (mm)</label>
                                    <input type="number" step="0.01" class="form-control position-field" name="text_x_mm" id="text_x_mm" value="<?php echo htmlspecialchars((string) $layout['text_x_mm'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Text Y (mm)</label>
                                    <input type="number" step="0.01" class="form-control position-field" name="text_y_mm" id="text_y_mm" value="<?php echo htmlspecialchars((string) $layout['text_y_mm'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Font Size (pt)</label>
                                    <div class="size-adjust">
                                        <input type="number" step="0.01" class="form-control" name="text_font_size_pt" id="text_font_size_pt" value="<?php echo htmlspecialchars((string) $layout['text_font_size_pt'], ENT_QUOTES); ?>">
                                        <button type="button" class="btn btn-outline-secondary" id="text_size_down" title="Shrink text">-</button>
                                        <button type="button" class="btn btn-outline-secondary" id="text_size_up" title="Expand text">+</button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Text Color</label>
                                    <input type="color" class="form-control form-control-color" name="text_color_hex" id="text_color_hex" value="<?php echo htmlspecialchars((string) $layout['text_color_hex'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Text Align</label>
                                    <select class="form-select" name="text_align" id="text_align">
                                        <option value="left" <?php echo $layout['text_align'] === 'left' ? 'selected' : ''; ?>>Left</option>
                                        <option value="center" <?php echo $layout['text_align'] === 'center' ? 'selected' : ''; ?>>Center</option>
                                        <option value="right" <?php echo $layout['text_align'] === 'right' ? 'selected' : ''; ?>>Right</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Text Mode</label>
                                    <select class="form-select" name="text_mode" id="text_mode">
                                        <option value="ticket_number" <?php echo $layout['text_mode'] === 'ticket_number' ? 'selected' : ''; ?>>Ticket Number</option>
                                        <option value="ticket_range" <?php echo $layout['text_mode'] === 'ticket_range' ? 'selected' : ''; ?>>Ticket Range</option>
                                        <option value="auto" <?php echo $layout['text_mode'] === 'auto' ? 'selected' : ''; ?>>Auto</option>
                                    </select>
                                </div>
                                <div class="col-12 d-grid">
                                    <div class="note mb-2">Tip: Hover a placeholder in Live Preview and use the mouse wheel to shrink/expand it. Hold Shift for faster resizing.</div>
                                    <button type="submit" class="btn btn-primary">Save Layout</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
const preview = document.getElementById('layoutPreview');
const qrPlaceholder = document.getElementById('qrPlaceholder');
const textPlaceholder = document.getElementById('textPlaceholder');
const pageWidthField = document.getElementById('page_width_mm');
const pageHeightField = document.getElementById('page_height_mm');
const qrXField = document.getElementById('qr_x_mm');
const qrYField = document.getElementById('qr_y_mm');
const qrSizeField = document.getElementById('qr_size_mm');
const textXField = document.getElementById('text_x_mm');
const textYField = document.getElementById('text_y_mm');
const textFontField = document.getElementById('text_font_size_pt');
const textColorField = document.getElementById('text_color_hex');
const textModeField = document.getElementById('text_mode');
const qrSizeDownBtn = document.getElementById('qr_size_down');
const qrSizeUpBtn = document.getElementById('qr_size_up');
const textSizeDownBtn = document.getElementById('text_size_down');
const textSizeUpBtn = document.getElementById('text_size_up');

function mmToPx(mm, totalMm, totalPx) {
    return (Number(mm) / Number(totalMm)) * totalPx;
}

function pxToMm(px, totalPx, totalMm) {
    return (Number(px) / totalPx) * Number(totalMm);
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function setActivePlaceholder(element) {
    qrPlaceholder.classList.remove('active');
    textPlaceholder.classList.remove('active');
    if (element) {
        element.classList.add('active');
    }
}

function adjustSizeField(field, delta, min, max) {
    const next = clamp(Number(field.value || 0) + delta, min, max);
    field.value = next.toFixed(2);
    syncPreview();
}

function syncPreview() {
    const pageWidth = Math.max(1, Number(pageWidthField.value || 101.6));
    const pageHeight = Math.max(1, Number(pageHeightField.value || 50.8));
    preview.style.aspectRatio = pageWidth + ' / ' + pageHeight;
    const previewWidth = Math.max(1, preview.clientWidth);
    const previewHeight = Math.max(1, preview.clientHeight || (previewWidth * pageHeight / pageWidth));
    const qrSizePx = mmToPx(qrSizeField.value || 15, pageWidth, previewWidth);
    qrPlaceholder.style.width = `${qrSizePx}px`;
    qrPlaceholder.style.height = `${mmToPx(qrSizeField.value || 15, pageHeight, previewHeight)}px`;
    const qrLeft = Math.max(0, Math.min(mmToPx(qrXField.value || 0, pageWidth, previewWidth), previewWidth - qrPlaceholder.offsetWidth));
    const qrTop = Math.max(0, Math.min(mmToPx(qrYField.value || 0, pageHeight, previewHeight), previewHeight - qrPlaceholder.offsetHeight));
    qrPlaceholder.style.left = `${qrLeft}px`;
    qrPlaceholder.style.top = `${qrTop}px`;

    const textLeft = Math.max(0, Math.min(mmToPx(textXField.value || 0, pageWidth, previewWidth), previewWidth - textPlaceholder.offsetWidth));
    const textTop = Math.max(0, Math.min(mmToPx(textYField.value || 0, pageHeight, previewHeight) - 18, previewHeight - textPlaceholder.offsetHeight));
    textPlaceholder.style.left = `${textLeft}px`;
    textPlaceholder.style.top = `${textTop}px`;
    textPlaceholder.style.fontSize = `${Number(textFontField.value || 8) * 2}px`;
    textPlaceholder.style.color = textColorField.value || '#4b5563';
    textPlaceholder.textContent = textModeField.value === 'ticket_number' ? 'PSMECPMXWEN_00001' : 'PSMECPMXWEN_00001 to PSMECPMXWEN_00010';
}

function makeDraggable(element, xField, yField) {
    let dragging = false;
    let startX = 0;
    let startY = 0;

    element.addEventListener('pointerdown', (event) => {
        const rect = preview.getBoundingClientRect();
        dragging = true;
        setActivePlaceholder(element);
        startX = event.clientX - rect.left - element.offsetLeft;
        startY = event.clientY - rect.top - element.offsetTop;
        element.setPointerCapture(event.pointerId);
    });

    element.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        const rect = preview.getBoundingClientRect();
        const left = Math.max(0, Math.min(event.clientX - rect.left - startX, preview.clientWidth - element.offsetWidth));
        const top = Math.max(0, Math.min(event.clientY - rect.top - startY, preview.clientHeight - element.offsetHeight));
        element.style.left = `${left}px`;
        element.style.top = `${top}px`;
        xField.value = pxToMm(left, preview.clientWidth, pageWidthField.value || 101.6).toFixed(2);
        const yPx = element === textPlaceholder ? top + 18 : top;
        yField.value = pxToMm(yPx, preview.clientHeight, pageHeightField.value || 50.8).toFixed(2);
    });

    element.addEventListener('pointerup', () => { dragging = false; });
    element.addEventListener('pointercancel', () => { dragging = false; });
    element.addEventListener('lostpointercapture', () => { dragging = false; });

    element.addEventListener('wheel', (event) => {
        event.preventDefault();
        setActivePlaceholder(element);
        const baseStep = event.shiftKey ? 1.0 : 0.25;
        const direction = event.deltaY < 0 ? 1 : -1;
        if (element === qrPlaceholder) {
            adjustSizeField(qrSizeField, direction * baseStep, 1.0, 300.0);
        } else {
            adjustSizeField(textFontField, direction * baseStep, 4.0, 120.0);
        }
    }, { passive: false });
}

makeDraggable(qrPlaceholder, qrXField, qrYField);
makeDraggable(textPlaceholder, textXField, textYField);

qrSizeDownBtn.addEventListener('click', () => adjustSizeField(qrSizeField, -0.25, 1.0, 300.0));
qrSizeUpBtn.addEventListener('click', () => adjustSizeField(qrSizeField, 0.25, 1.0, 300.0));
textSizeDownBtn.addEventListener('click', () => adjustSizeField(textFontField, -0.25, 4.0, 120.0));
textSizeUpBtn.addEventListener('click', () => adjustSizeField(textFontField, 0.25, 4.0, 120.0));

qrPlaceholder.addEventListener('click', () => setActivePlaceholder(qrPlaceholder));
textPlaceholder.addEventListener('click', () => setActivePlaceholder(textPlaceholder));

window.addEventListener('resize', syncPreview);
[pageWidthField, pageHeightField, qrXField, qrYField, qrSizeField, textXField, textYField, textFontField, textColorField, textModeField].forEach((field) => {
    field.addEventListener('input', syncPreview);
});
setActivePlaceholder(qrPlaceholder);
syncPreview();
</script>
</body>
</html>


