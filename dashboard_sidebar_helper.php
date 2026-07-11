<?php

if (!function_exists('render_dashboard_sidebar')) {
    function render_dashboard_sidebar(string $activePath, bool $isAdmin, ?string $dashboardPath = null): void
    {
        $dashboardHref = $dashboardPath ?? '/CPMraffle/' . auth_dashboard_path_for_role(auth_role());
        $currentRole = auth_role();
        $isCpmadmin = $currentRole === 'cpmadmin';
        $bulkUploadPath = $isAdmin ? '/CPMraffle/bulk_buyer_upload_admin.php' : '/CPMraffle/bulk_buyer_upload_secretariat.php';

        // For cpmadmin, show only their allowed pages
        if ($isCpmadmin) {
            $items = [
                ['path' => '/CPMraffle/activated_tickets.php', 'icon' => 'fas fa-check-circle', 'label' => 'Activated Tickets'],
                ['path' => '/CPMraffle/pending_tickets.php', 'icon' => 'fas fa-hourglass-half', 'label' => 'Pending Tickets'],
                ['path' => '/CPMraffle/admin_purchase_requests.php', 'icon' => 'fas fa-cash-register', 'label' => 'Onsite Requests'],
                ['path' => '/CPMraffle/bulk_buyer_upload_admin.php', 'icon' => 'fas fa-file-upload', 'label' => 'Bulk Buyer Upload'],
                ['path' => '/CPMraffle/buyer.php', 'icon' => 'fas fa-user-plus', 'label' => 'Buyer Assignment'],
                ['path' => '/CPMraffle/participant_list.php', 'icon' => 'fas fa-users', 'label' => 'Assigned Tickets'],
                ['path' => '/CPMraffle/assign_tickets_to_chapter.php', 'icon' => 'fas fa-share-square', 'label' => 'Chapter Allocation'],
                ['path' => '/CPMraffle/admin_chapter_allocation.php', 'icon' => 'fas fa-diagram-project', 'label' => 'Unified Allocation'],
                ['path' => '/CPMraffle/admin_chapter_list.php', 'icon' => 'fas fa-table-list', 'label' => 'Chapter Monitoring'],
                ['path' => '/CPMraffle/reports.php', 'icon' => 'fas fa-chart-line', 'label' => 'Reports'],
                ['path' => '/CPMraffle/logout.php', 'icon' => 'fas fa-right-from-bracket', 'label' => 'Logout'],
            ];
        } else {
            // Regular menu for other roles
            $items = [
                ['path' => '/CPMraffle/activated_tickets.php', 'icon' => 'fas fa-check-circle', 'label' => 'Activated Tickets'],
                ['path' => '/CPMraffle/pending_tickets.php', 'icon' => 'fas fa-hourglass-half', 'label' => 'Pending Tickets'],
                ['path' => '/CPMraffle/generate_qr.php', 'icon' => 'fas fa-ticket-alt', 'label' => 'Ticket Generation', 'adminOnly' => true],
                ['path' => '/CPMraffle/pool_tickets.php', 'icon' => 'fas fa-layer-group', 'label' => 'Ticket Pool'],
                ['path' => '/CPMraffle/buyer.php', 'icon' => 'fas fa-user-plus', 'label' => 'Buyer Assignment'],
                ['path' => '/CPMraffle/admin_purchase_requests.php', 'icon' => 'fas fa-cash-register', 'label' => 'Onsite Requests'],
                ['path' => $bulkUploadPath, 'icon' => 'fas fa-file-upload', 'label' => 'Bulk Buyer Upload'],
                ['path' => '/CPMraffle/participant_list.php', 'icon' => 'fas fa-users', 'label' => 'Assigned Tickets'],
                ['path' => '/CPMraffle/chapter_management.php', 'icon' => 'fas fa-building', 'label' => 'Chapter Management'],
                ['path' => '/CPMraffle/assign_tickets_to_chapter.php', 'icon' => 'fas fa-share-square', 'label' => 'Chapter Allocation'],
                ['path' => '/CPMraffle/admin_chapter_allocation.php', 'icon' => 'fas fa-diagram-project', 'label' => 'Unified Allocation'],
                ['path' => '/CPMraffle/admin_chapter_list.php', 'icon' => 'fas fa-table-list', 'label' => 'Chapter Monitoring'],
                ['path' => '/CPMraffle/ticket_layout_editor.php', 'icon' => 'fas fa-object-group', 'label' => 'Ticket Layout', 'adminOnly' => true],
                ['path' => '/CPMraffle/email_template_settings.php', 'icon' => 'fas fa-envelope-open-text', 'label' => 'Email Templates', 'adminOnly' => true],
                ['path' => '/CPMraffle/reg.php', 'icon' => 'fas fa-user-shield', 'label' => 'Register User', 'adminOnly' => true],
                ['path' => '/CPMraffle/reports.php', 'icon' => 'fas fa-chart-line', 'label' => 'Reports', 'adminOnly' => true],
                ['path' => '/CPMraffle/logout.php', 'icon' => 'fas fa-right-from-bracket', 'label' => 'Logout'],
            ];
        }

        echo '<aside class="sidebar">';
        echo '<div class="sidebar-header">';
        echo '<a href="' . htmlspecialchars($dashboardHref, ENT_QUOTES) . '" style="text-decoration:none;">';
        echo '<h1>6th PSME CPM National Conference Dashboard</h1>';
        echo '</a>';
        echo '</div>';
        echo '<hr>';
        echo '<nav><ul>';

        foreach ($items as $item) {
            $adminOnly = (bool) ($item['adminOnly'] ?? false);
            if ($adminOnly && !$isAdmin) {
                continue;
            }

            $path = (string) $item['path'];
            $icon = (string) $item['icon'];
            $label = (string) $item['label'];
            $activeClass = $activePath === $path ? ' class="active"' : '';

            echo '<li><a' . $activeClass . ' href="' . htmlspecialchars($path, ENT_QUOTES) . '"><i class="' . htmlspecialchars($icon, ENT_QUOTES) . '"></i> ' . htmlspecialchars($label, ENT_QUOTES) . '</a></li>';
        }

        echo '</ul></nav>';
        echo '</aside>';
    }
}
