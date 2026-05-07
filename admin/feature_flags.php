<?php

declare(strict_types=1);

// ここは管理画面なので本来は「管理者ログイン済み」チェックを入れる
// 今回は簡易に tenant_id を固定 or GETで受ける
$tenantId = (int)($_GET['tenant_id'] ?? 1);
$featureKey = 'slot_wage_bonus';

// DB接続（punch.phpと同じ）
$pdo = new PDO(
    'mysql:host=mysql80-3.lolipop.lan;dbname=LAA1686629-azure;charset=utf8mb4',
    'LAA1686629',
    'ftpaiwebf0918',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// 現在値取得
$stmt = $pdo->prepare("SELECT enabled FROM tenant_feature_flags WHERE tenant_id=:tid AND feature_key=:fk LIMIT 1");
$stmt->execute([':tid' => $tenantId, ':fk' => $featureKey]);
$enabled = (int)($stmt->fetchColumn() ?? 0);
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>機能設定</title>
    <style>
    body {
        font-family: system-ui, -apple-system;
        padding: 24px;
        max-width: 720px;
        margin: 0 auto;
    }

    .card {
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 16px;
    }

    .row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .desc {
        color: #666;
        font-size: 14px;
        margin-top: 6px;
    }

    .toggle {
        transform: scale(1.4);
    }

    .status {
        font-weight: 700;
    }
    </style>
</head>

<body>
    <h1>機能設定（tenant_id: <?= htmlspecialchars((string)$tenantId) ?>）</h1>

    <div class="card">
        <div class="row">
            <div>
                <div class="status">スロット777で当日だけ時給+50</div>
                <div class="desc">出勤時スロットで777（wagePlus50）の場合に、当日の実効時給へ +50 を加算します。明細にも「slot_777」として残ります。</div>
            </div>

            <label>
                <input id="toggle" class="toggle" type="checkbox" <?= $enabled === 1 ? 'checked' : '' ?>>
            </label>
        </div>

        <p>現在: <span id="current"><?= $enabled === 1 ? 'ON' : 'OFF' ?></span></p>
    </div>

    <script>
    const tenantId = <?= (int)$tenantId ?>;
    const featureKey = "<?= $featureKey ?>";

    // ★ update_feature_flag.php と同じトークンにする
    const ADMIN_TOKEN = "azure202510";

    const toggle = document.getElementById('toggle');
    const current = document.getElementById('current');

    toggle.addEventListener('change', async () => {
        const enabled = toggle.checked ? 1 : 0;

        try {
            const res = await fetch('/api/v1/admin/update_feature_flag.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-ADMIN-TOKEN': ADMIN_TOKEN
                },
                body: JSON.stringify({
                    tenant_id: tenantId,
                    feature_key: featureKey,
                    enabled: enabled
                })
            });

            const json = await res.json();
            if (!res.ok || !json.ok) {
                throw new Error(json.message || json.error || 'update failed');
            }

            current.textContent = enabled === 1 ? 'ON' : 'OFF';
        } catch (e) {
            alert("保存に失敗: " + e.message);
            // 失敗したら元に戻す
            toggle.checked = !toggle.checked;
        }
    });
    </script>
</body>

</html>