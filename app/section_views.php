<?php
/**
 * app/section_views.php
 * Модальные окна разделов деревни (Казармы/Оборона/Лаборатория/Клан/Ратуша)
 */

if (!function_exists('sectionHeader')) {
    function sectionHeader(string $title, string $modalId, string $backView = ''): void {
        ?>
        <div class="modal-header">
            <?php if ($backView !== ''): ?>
                <button class="modal-button-corner" onclick="goBack('<?php echo e($modalId); ?>', '<?php echo e($backView); ?>')">←</button>
            <?php endif; ?>
            <div class="modal-title"><?php echo e($title); ?></div>
            <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?php echo e($modalId); ?>')">×</button>
        </div>
        <?php
    }
}

if (!function_exists('fmt')) {
    function fmt($n): string {
        return number_format((int)$n, 0, '.', ',');
    }
}

function renderSectionMain(array $ctx): void {
    $section = $ctx['section'];
    $modalId = $ctx['modalId'];
    $title   = $ctx['title'];
    $items   = $ctx['items']; // building_ids
    ?>
    <?php sectionHeader($title, $modalId); ?>
    <div class="modal-body">
        <div class="storage-main-grid">
            <?php foreach ($items as $bid): 
                $info = getObjectInfo($bid);
                if (!$info) continue;
            ?>
            <button class="storage-card" onclick="loadSectionList('<?php echo e($modalId); ?>','<?php echo e($section); ?>','<?php echo e($bid); ?>')">
                <div class="storage-card-title"><?php echo e($info['name'] ?? $bid); ?></div>
                <div class="storage-card-subtitle">Открыть</div>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function renderSectionList(array $ctx): void {
    $section = $ctx['section'];
    $modalId = $ctx['modalId'];
    $bid     = $ctx['building_id'];
    $title   = $ctx['title'];
    $th      = (int)$ctx['townhall_lvl'];
    $built   = $ctx['built']; // rows
    $max     = (int)$ctx['max'];
    $canBuild = (bool)$ctx['canBuild'];
    ?>
    <?php sectionHeader($title, $modalId, $section . '_main'); ?>
    <div class="modal-body">
        <div class="storage-list">
            <?php if ($built): ?>
                <?php foreach ($built as $row): ?>
                    <div class="storage-item" onclick="loadSectionDetail('<?php echo e($modalId); ?>','<?php echo e($section); ?>',<?php echo (int)$row['id']; ?>)">
                        <div class="storage-item-title"><?php echo e($title); ?> ур. <?php echo (int)$row['level']; ?></div>
                        <div class="storage-item-subtitle">Статус: <?php echo e($row['status']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="storage-empty">Нет построенных зданий.</div>
            <?php endif; ?>

            <?php if (count($built) < $max): ?>
                <?php if ($canBuild): ?>
                    <button class="modal-action" onclick="buySectionBuilding('<?php echo e($modalId); ?>','<?php echo e($section); ?>','<?php echo e($bid); ?>')">Построить</button>
                <?php else: ?>
                    <div class="storage-locked">Недоступно. Нужна Ратуша выше.</div>
                <?php endif; ?>
            <?php else: ?>
                <div class="storage-locked">Все здания этого типа уже построены (лимит: <?php echo (int)$max; ?>).</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function renderSectionDetail(array $ctx): void {
    $section = $ctx['section'];
    $modalId = $ctx['modalId'];
    $row     = $ctx['row'];
    $info    = $ctx['info'];
    $lvlInfo = $ctx['lvlInfo'];
    $canUpgrade = (bool)$ctx['canUpgrade'];
    $upgradeCost = (int)$ctx['upgradeCost'];
    $upgradeRes  = (string)$ctx['upgradeRes'];
    $upgradeTime = (int)$ctx['upgradeTime'];
    ?>
    <?php sectionHeader(($info['name'] ?? $row['building_id']) . ' — ур. ' . (int)$row['level'], $modalId, $section . '_list:' . $row['building_id']); ?>
    <div class="modal-body">
        <div class="detail-block">
            <div class="detail-row">HP: <?php echo fmt($lvlInfo['hp'] ?? 0); ?></div>
            <?php if (!empty($lvlInfo['capacity'])): ?>
                <div class="detail-row">Ёмкость: <?php echo fmt($lvlInfo['capacity']); ?></div>
            <?php endif; ?>
            <?php if (!empty($lvlInfo['rate'])): ?>
                <div class="detail-row">Добыча/час: <?php echo fmt($lvlInfo['rate']); ?></div>
            <?php endif; ?>
            <div class="detail-row">Статус: <?php echo e($row['status']); ?></div>
        </div>

        <?php if ($row['status'] !== 'active' && !empty($row['finish_time'])): ?>
            <div class="detail-row">Завершение: <?php echo (int)$row['finish_time']; ?></div>
        <?php endif; ?>

        <?php if ($canUpgrade): ?>
            <button class="modal-action" onclick="startSectionUpgrade('<?php echo e($modalId); ?>',<?php echo (int)$row['id']; ?>)">
                Улучшить (<?php echo e($upgradeRes); ?> <?php echo fmt($upgradeCost); ?>, <?php echo fmt($upgradeTime); ?>с)
            </button>
        <?php else: ?>
            <div class="storage-locked">Улучшение недоступно (либо идет стройка, либо нет ресурсов/уровня Ратуши).</div>
        <?php endif; ?>
    </div>
    <?php
}
