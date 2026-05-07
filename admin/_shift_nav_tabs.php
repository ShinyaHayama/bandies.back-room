<?php
declare(strict_types=1);

$tabStoreId = (int)($storeId ?? ($_GET['store_id'] ?? 0));
$tabDate = (string)($baseDate ?? $_GET['date'] ?? date('Y-m-d'));
if (isset($base) && $base instanceof DateTimeInterface) {
    $tabDate = $base->format('Y-m-d');
}
$tabPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$tabItems = [
    [
        'key' => 'attendance_exceptions.php',
        'label' => '勤怠注意',
        'href' => '/admin/attendance_exceptions.php?store_id=' . $tabStoreId . '&date=' . rawurlencode($tabDate),
    ],
    [
        'key' => 'shift_templates.php',
        'label' => '固定出勤登録',
        'href' => '/admin/shift_templates.php?store_id=' . $tabStoreId,
    ],
    [
        'key' => 'public_calendar_hours.php',
        'label' => '公開営業時間',
        'href' => '/admin/public_calendar_hours.php?store_id=' . $tabStoreId . '&date=' . rawurlencode($tabDate),
    ],
    [
        'key' => 'public_calendar.php',
        'label' => '公開カレンダー',
        'href' => '/admin/public_calendar.php?store_id=' . $tabStoreId . '&date=' . rawurlencode($tabDate),
    ],
];
?>
<style>
    .shiftNavTabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        margin: 0;
    }

    .shiftNavTabs .shiftNavTab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 0 16px;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #555;
        text-decoration: none;
        font-size: 14px;
        font-weight: 800;
        line-height: 1;
        box-shadow: 0 1px 2px rgba(17, 24, 39, .06);
        white-space: nowrap;
    }

    .shiftNavTabs .shiftNavTab.active {
        background: #111;
        border-color: #111;
        color: #fff;
        box-shadow: none;
    }

    .shiftNavTabsHost {
        padding: 24px 24px 0;
    }

    .shiftNavTabsHost + .page,
    .shiftNavTabsHost + .wrap {
        padding-top: 12px !important;
    }

    @media (max-width: 760px) {
        .shiftNavTabsHost {
            padding: 16px 16px 0;
        }

        .shiftNavTabs {
            gap: 6px;
        }

        .shiftNavTabs .shiftNavTab {
            min-height: 34px;
            padding: 0 12px;
            font-size: 13px;
        }
    }
</style>
<nav class="shiftNavTabs" aria-label="シフト関連メニュー">
    <?php foreach ($tabItems as $item): ?>
        <a class="shiftNavTab <?= $tabPath === $item['key'] ? 'active' : '' ?>" href="<?= h($item['href']) ?>">
            <?= h($item['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
