<?php

if (!function_exists('chapter_portal_require_user')) {
    function chapter_portal_require_user(mysqli $conn, int $chapterId): array
    {
        $stmt = $conn->prepare('SELECT id, username, name, email, chapter_name FROM users WHERE id = ? AND LOWER(role) = "chapter" LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to load chapter account.');
        }

        $stmt->bind_param('i', $chapterId);
        $stmt->execute();
        $result = $stmt->get_result();
        $chapterUser = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$chapterUser) {
            throw new RuntimeException('Chapter account not found.');
        }

        return $chapterUser;
    }
}

if (!function_exists('chapter_portal_chapter_name')) {
    function chapter_portal_chapter_name(array $chapterUser): string
    {
        return trim((string) ($chapterUser['chapter_name'] ?? $chapterUser['name'] ?? $chapterUser['username'] ?? 'Chapter'));
    }
}

if (!function_exists('chapter_portal_metrics')) {
    function chapter_portal_metrics(mysqli $conn, int $chapterId): array
    {
        $stmt = $conn->prepare('SELECT
                COUNT(*) AS total_allocated,
                SUM(CASE WHEN ta.ticket_number IS NOT NULL THEN 1 ELSE 0 END) AS total_assigned
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.assigned_chapter_id = ?');

        $totals = [
            'total_allocated' => 0,
            'total_assigned' => 0,
            'total_remaining' => 0,
        ];

        if (!$stmt) {
            return $totals;
        }

        $stmt->bind_param('i', $chapterId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $totals['total_allocated'] = (int) ($row['total_allocated'] ?? 0);
        $totals['total_assigned'] = (int) ($row['total_assigned'] ?? 0);
        $totals['total_remaining'] = max(0, $totals['total_allocated'] - $totals['total_assigned']);

        return $totals;
    }
}

if (!function_exists('chapter_portal_next_available_ticket')) {
    function chapter_portal_next_available_ticket(mysqli $conn, int $chapterId): string
    {
        $stmt = $conn->prepare('SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.assigned_chapter_id = ?
              AND ta.ticket_number IS NULL
              AND LOWER(COALESCE(t.status, "")) = "pending"
            ORDER BY t.ticket_number ASC
            LIMIT 1');

        if (!$stmt) {
            return '';
        }

        $stmt->bind_param('i', $chapterId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return trim((string) ($row['ticket_number'] ?? ''));
    }
}

if (!function_exists('chapter_portal_available_tickets')) {
    function chapter_portal_available_tickets(mysqli $conn, int $chapterId, int $limit = 0): array
    {
        $sql = 'SELECT t.ticket_number
            FROM tickets t
            LEFT JOIN ticket_assignments ta ON ta.ticket_number = t.ticket_number
            WHERE t.assigned_chapter_id = ?
              AND ta.ticket_number IS NULL
              AND LOWER(COALESCE(t.status, "")) = "pending"
            ORDER BY t.ticket_number ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $chapterId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $tickets[] = (string) ($row['ticket_number'] ?? '');
        }
        $stmt->close();

        return $tickets;
    }
}

if (!function_exists('chapter_portal_render_sidebar')) {
    function chapter_portal_render_sidebar(string $activePath): void
    {
        $items = [
            '/CPMraffle/chapter_dashboard.php' => ['icon' => 'fas fa-gauge-high', 'label' => 'Dashboard'],
            '/CPMraffle/chapter_buyer_assignment.php' => ['icon' => 'fas fa-user-plus', 'label' => 'Assign Tickets'],
            '/CPMraffle/chapter_assigned_list.php' => ['icon' => 'fas fa-list-check', 'label' => 'Assigned List'],
            '/CPMraffle/logout.php' => ['icon' => 'fas fa-right-from-bracket', 'label' => 'Logout'],
        ];

        echo '<aside class="chapter-sidebar">';
        echo '<div class="chapter-sidebar-brand">';
        echo '<div class="chapter-sidebar-kicker">Chapter President Portal</div>';
        echo '<div class="chapter-sidebar-title">Ticket Management</div>';
        echo '</div>';
        echo '<nav><ul>';
        foreach ($items as $path => $item) {
            $activeClass = $activePath === $path ? ' class="active"' : '';
            echo '<li><a' . $activeClass . ' href="' . htmlspecialchars($path, ENT_QUOTES) . '"><i class="' . htmlspecialchars((string) $item['icon'], ENT_QUOTES) . '"></i>' . htmlspecialchars((string) $item['label'], ENT_QUOTES) . '</a></li>';
        }
        echo '</ul></nav>';
        echo '</aside>';

        echo '<button type="button" class="chapter-mobile-toggle" aria-controls="chapter-mobile-drawer" aria-expanded="false"><i class="fas fa-bars"></i><span>Menu</span></button>';
        echo '<div class="chapter-mobile-overlay" hidden></div>';
        echo '<aside id="chapter-mobile-drawer" class="chapter-mobile-drawer" aria-hidden="true">';
        echo '<div class="chapter-mobile-drawer-header">';
        echo '<div><div class="chapter-sidebar-kicker">Chapter President Portal</div><div class="chapter-sidebar-title">Ticket Management</div></div>';
        echo '<button type="button" class="chapter-mobile-close" aria-label="Close menu"><i class="fas fa-xmark"></i></button>';
        echo '</div>';
        echo '<nav><ul>';
        foreach ($items as $path => $item) {
            $activeClass = $activePath === $path ? ' class="active"' : '';
            echo '<li><a' . $activeClass . ' href="' . htmlspecialchars($path, ENT_QUOTES) . '"><i class="' . htmlspecialchars((string) $item['icon'], ENT_QUOTES) . '"></i>' . htmlspecialchars((string) $item['label'], ENT_QUOTES) . '</a></li>';
        }
        echo '</ul></nav>';
        echo '</aside>';

        echo '<script>(function(){var toggle=document.querySelector(".chapter-mobile-toggle");var drawer=document.getElementById("chapter-mobile-drawer");var overlay=document.querySelector(".chapter-mobile-overlay");if(!toggle||!drawer||!overlay){return;}var closeBtn=drawer.querySelector(".chapter-mobile-close");var links=drawer.querySelectorAll("a");function openMenu(){drawer.classList.add("is-open");overlay.hidden=false;overlay.classList.add("is-visible");toggle.setAttribute("aria-expanded","true");drawer.setAttribute("aria-hidden","false");document.body.classList.add("chapter-menu-open");}function closeMenu(){drawer.classList.remove("is-open");overlay.classList.remove("is-visible");overlay.hidden=true;toggle.setAttribute("aria-expanded","false");drawer.setAttribute("aria-hidden","true");document.body.classList.remove("chapter-menu-open");}toggle.addEventListener("click",function(){if(drawer.classList.contains("is-open")){closeMenu();}else{openMenu();}});overlay.addEventListener("click",closeMenu);if(closeBtn){closeBtn.addEventListener("click",closeMenu);}Array.prototype.forEach.call(links,function(link){link.addEventListener("click",closeMenu);});document.addEventListener("keydown",function(event){if(event.key==="Escape"){closeMenu();}});})();</script>';
    }
}

if (!function_exists('chapter_portal_render_head')) {
    function chapter_portal_render_head(string $title): void
    {
        ?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --chapter-navy: #17325c;
            --chapter-blue: #24497d;
            --chapter-sky: #eef5ff;
            --chapter-orange: #d96a1d;
            --chapter-green: #237e52;
            --chapter-text: #1d2939;
            --chapter-muted: #526173;
            --chapter-border: #d4dfec;
            --chapter-shadow: 0 20px 50px rgba(23, 50, 92, 0.12);
        }

        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at top right, rgba(217, 106, 29, 0.14), transparent 24%), linear-gradient(180deg, #f6f9fd 0%, #ffffff 100%); color: var(--chapter-text); font-size: 18px; line-height: 1.6; }
        body.chapter-menu-open { overflow: hidden; }
        .dashboard-container { display: flex; min-height: 100vh; }
        .chapter-mobile-toggle, .chapter-mobile-drawer, .chapter-mobile-overlay { display: none; }

        .chapter-sidebar { width: 290px; min-height: 100vh; position: fixed; left: 0; top: 0; z-index: 20; background: linear-gradient(180deg, #17325c 0%, #24497d 100%); color: #f8fbff; padding: 28px 18px; box-shadow: 8px 0 24px rgba(23, 50, 92, 0.18); }
        .chapter-sidebar-brand { padding: 10px 12px 18px; border-bottom: 1px solid rgba(255, 255, 255, 0.14); margin-bottom: 18px; }
        .chapter-sidebar-kicker { font-size: 0.92rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #d5e5ff; margin-bottom: 8px; }
        .chapter-sidebar-title { color: #fff; font-size: 1.35rem; font-weight: 700; letter-spacing: 0.02em; margin-bottom: 10px; }
        .chapter-sidebar ul { list-style: none; padding: 0; margin: 0; }
        .chapter-sidebar li { margin: 10px 0; }
        .chapter-sidebar a { color: #f8fbff; text-decoration: none; display: flex; align-items: center; gap: 14px; font-size: 1.05rem; font-weight: 600; padding: 14px 16px; border-radius: 16px; border: 2px solid transparent; transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.2s ease; }
        .chapter-sidebar a i { width: 24px; font-size: 1.2rem; text-align: center; }
        .chapter-sidebar a:hover, .chapter-sidebar a:focus-visible { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.3); outline: none; transform: translateX(2px); }
        .chapter-sidebar a.active { background: #fff7ef; color: var(--chapter-navy); border-color: #ffd2ae; box-shadow: 0 10px 24px rgba(11, 24, 44, 0.15); }
        .chapter-sidebar a.active i { color: var(--chapter-orange); }

        .page-shell, .shell, .chapter-shell { width: min(1240px, calc(100% - 330px)); margin-left: 306px; padding: 36px 22px 56px; }
        .hero, .card, .action-card, .metric-card, .info-strip, .chapter-hero, .chapter-panel, .chapter-metric-card, .chapter-upload-box, .chapter-mobile-record { background: #fff; border: 1px solid var(--chapter-border); border-radius: 24px; box-shadow: var(--chapter-shadow); }
        .hero, .chapter-hero { padding: 32px; margin-bottom: 24px; position: relative; overflow: hidden; }
        .hero h1, .chapter-hero h1 { margin: 0 0 14px; color: var(--chapter-navy); font-size: clamp(2rem, 3vw, 2.8rem); line-height: 1.2; font-weight: 700; }
        .hero-lead, .section-intro, .chapter-section-intro, .chapter-muted, .muted { color: var(--chapter-muted); }
        .card, .action-card, .info-strip, .chapter-panel { padding: 28px; }
        .section-title, .chapter-section-title { font-size: 1.4rem; font-weight: 700; color: var(--chapter-navy); margin-bottom: 8px; }
        .chapter-eyebrow { display: inline-flex; align-items: center; gap: 10px; padding: 8px 14px; border-radius: 999px; background: var(--chapter-sky); color: var(--chapter-navy); font-size: 0.95rem; font-weight: 700; margin-bottom: 16px; }
        .chapter-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 18px; }
        .chapter-meta-chip, .chapter-inline-summary { display: inline-flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 16px; background: #f8fbff; border: 1px solid var(--chapter-border); font-size: 0.98rem; color: var(--chapter-text); }
        .metric-card, .chapter-metric-card { min-height: 100%; padding: 24px; color: #fff; border: none; background: linear-gradient(135deg, #17325c 0%, #2e5f9b 100%); }
        .metric-card.is-accent { background: linear-gradient(145deg, #e87522 0%, #bc5710 100%); }
        .metric-card.is-light { color: var(--chapter-text); background: linear-gradient(145deg, #edf5ff 0%, #dfeeff 100%); border: 1px solid #c9def6; }
        .metric-label, .chapter-metric-label { font-size: 1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.92; margin-bottom: 14px; }
        .metric-value, .chapter-metric-value { font-size: clamp(2.4rem, 4vw, 3.3rem); line-height: 1; font-weight: 700; margin-bottom: 12px; }
        .action-card, .chapter-action-card { display: flex; flex-direction: column; gap: 16px; min-height: 100%; }
        .action-icon, .chapter-action-icon { width: 68px; height: 68px; display: inline-flex; align-items: center; justify-content: center; border-radius: 20px; background: linear-gradient(135deg, #fff1e5 0%, #ffe3cb 100%); color: var(--chapter-orange); font-size: 1.7rem; }

        .chapter-action-button, .chapter-primary-button, .chapter-secondary-button, .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 58px; padding: 14px 22px; border-radius: 16px; font-size: 1.05rem; font-weight: 700; text-decoration: none; }
        .chapter-action-button, .chapter-primary-button { background: var(--chapter-navy); border: 1px solid var(--chapter-navy); color: #fff; }
        .chapter-action-button:hover, .chapter-primary-button:hover, .chapter-action-button:focus-visible, .chapter-primary-button:focus-visible { background: #122745; border-color: #122745; color: #fff; }
        .chapter-secondary-button { background: #fff; border: 1px solid #b8c9dc; color: var(--chapter-navy); }
        .chapter-secondary-button:hover, .chapter-secondary-button:focus-visible { background: #edf4fb; color: var(--chapter-navy); }
        .chapter-form-label { display: block; font-size: 1.02rem; font-weight: 700; color: var(--chapter-navy); margin-bottom: 8px; }
        .chapter-form-help { color: var(--chapter-muted); font-size: 0.95rem; margin-top: 8px; }
        .form-control.chapter-input, .form-select.chapter-input { min-height: 60px; border-radius: 16px; border: 2px solid #c6d4e4; padding: 14px 18px; font-size: 1.02rem; }
        .form-control.chapter-input:focus, .form-select.chapter-input:focus { border-color: #2e5f9b; box-shadow: 0 0 0 0.25rem rgba(46, 95, 155, 0.18); }

        .chapter-upload-box { border: 2px dashed #8eadcf; background: linear-gradient(180deg, #f7fbff 0%, #eef5ff 100%); }
        .chapter-upload-icon { width: 72px; height: 72px; display: inline-flex; align-items: center; justify-content: center; border-radius: 20px; background: #fff; color: var(--chapter-blue); font-size: 1.8rem; box-shadow: 0 12px 24px rgba(23, 50, 92, 0.1); margin-bottom: 16px; }
        .chapter-steps { display: grid; gap: 14px; }
        .chapter-step { display: grid; grid-template-columns: auto 1fr; gap: 14px; align-items: start; padding: 16px 18px; border-radius: 18px; background: #fbfdff; border: 1px solid var(--chapter-border); }
        .chapter-step-number { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--chapter-orange); color: #fff; font-weight: 700; font-size: 1rem; }
        .chapter-table { margin-bottom: 0; --bs-table-bg: transparent; }
        .chapter-table thead th, .table thead th { background: linear-gradient(135deg, #17325c 0%, #24497d 100%); color: #fff; border: none; padding: 18px 16px; font-size: 1rem; white-space: nowrap; }
        .chapter-table tbody td, .table tbody td { padding: 18px 16px; vertical-align: middle; border-color: #dce6f0; font-size: 1rem; }
        .chapter-table tbody tr:nth-child(even), .table tbody tr:nth-child(even) { background: #f8fbff; }
        .chapter-status-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 8px 14px; font-size: 0.95rem; font-weight: 700; }
        .chapter-status-active { background: #def4e8; color: #145434; }
        .chapter-status-pending { background: #fff0c7; color: #855d00; }
        .chapter-results { max-height: 340px; overflow: auto; padding-right: 6px; }
        .chapter-ticket-chip { display: inline-flex; align-items: center; gap: 8px; border-radius: 999px; border: 1px solid #cddbeb; background: #f8fbff; padding: 10px 16px; font-size: 0.98rem; font-weight: 600; color: var(--chapter-navy); }
        .chapter-mobile-record { display: grid; gap: 10px; padding: 18px; border-radius: 18px; }
        .chapter-mobile-record-kicker { font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--chapter-muted); margin-bottom: 2px; }
        .chapter-mobile-record-title { font-size: 1.2rem; font-weight: 700; color: var(--chapter-text); }
        .chapter-mobile-record-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding-top: 10px; border-top: 1px solid #e6edf5; font-size: 0.98rem; }
        .chapter-mobile-record-row span { color: var(--chapter-muted); font-weight: 600; flex: 0 0 44%; }
        .chapter-mobile-record-row strong { text-align: right; color: var(--chapter-text); font-weight: 700; flex: 1; overflow-wrap: anywhere; }
        .alert { border: none; border-radius: 18px; padding: 18px 20px; font-size: 1rem; }

        @media (max-width: 991.98px) {
            .chapter-sidebar { display: none; }
            .chapter-mobile-toggle { position: fixed; top: 14px; left: 14px; z-index: 45; display: inline-flex; align-items: center; gap: 10px; min-height: 48px; padding: 10px 16px; border: none; border-radius: 999px; background: #122745; color: #fff; font-weight: 700; box-shadow: 0 14px 30px rgba(15, 34, 57, 0.22); }
            .chapter-mobile-overlay { position: fixed; inset: 0; z-index: 38; background: rgba(12, 24, 41, 0.5); backdrop-filter: blur(2px); }
            .chapter-mobile-overlay.is-visible { display: block; }
            .chapter-mobile-drawer { position: fixed; top: 0; left: 0; z-index: 40; display: block; width: min(320px, 86vw); height: 100vh; padding: 22px 16px; background: linear-gradient(180deg, rgba(20, 45, 78, 0.99) 0%, rgba(31, 76, 122, 0.99) 100%); box-shadow: 12px 0 30px rgba(18, 34, 54, 0.28); transform: translateX(-105%); transition: transform 0.22s ease; overflow-y: auto; }
            .chapter-mobile-drawer.is-open { transform: translateX(0); }
            .chapter-mobile-drawer-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.14); }
            .chapter-mobile-drawer nav ul { list-style: none; padding: 0; margin: 0; }
            .chapter-mobile-drawer nav li { margin: 10px 0; }
            .chapter-mobile-drawer nav a { color: #f8fbff; text-decoration: none; display: flex; align-items: center; gap: 14px; font-size: 1.05rem; font-weight: 600; padding: 14px 16px; border-radius: 16px; border: 2px solid transparent; }
            .chapter-mobile-drawer nav a.active { background: #fff7ef; color: var(--chapter-navy); border-color: #ffd2ae; }
            .chapter-mobile-close { width: 48px; height: 48px; border: none; border-radius: 14px; background: rgba(255, 255, 255, 0.12); color: #fff; font-size: 1.2rem; }
            .page-shell, .shell, .chapter-shell { width: 100%; margin-left: 0; padding: 72px 14px 42px; }
        }

        @media (max-width: 575.98px) {
            body { font-size: 16px; }
            .hero, .card, .action-card, .info-strip, .metric-card, .chapter-hero, .chapter-panel, .chapter-metric-card, .chapter-upload-box { padding: 22px; border-radius: 20px; width: 100%; }
            .chapter-meta { display: grid; gap: 10px; }
            .chapter-meta-chip, .chapter-inline-summary, .chapter-ticket-chip { width: 100%; }
            .chapter-ticket-chip { justify-content: flex-start; }
            .chapter-form-actions { display: grid; gap: 12px; }
            .chapter-form-actions .chapter-primary-button, .chapter-form-actions .chapter-secondary-button { width: 100%; }
            .table thead th, .table tbody td, .chapter-table thead th, .chapter-table tbody td { padding: 14px 12px; }
        }
    </style>
</head>
<?php
    }
}
