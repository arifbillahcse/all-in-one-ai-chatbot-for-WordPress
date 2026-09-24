<?php
/**
 * Conversation list — split console (list + transcript), with search and paging.
 *
 * Message and visitor-supplied content is customer-written, so every field
 * still goes through $e() here exactly as it did in the plain list.
 *
 * @var callable $e
 * @var string $base
 * @var string $search
 * @var int $page
 * @var int $perPage
 * @var int $total
 * @var array<int, array<string, mixed>> $rows
 * @var array<string, mixed> $stats
 * @var string $selectedId
 * @var array<string, mixed>|null $selected
 * @var array<int, array<string, mixed>> $selectedMessages
 */
$pages = max(1, (int) ceil($total / max(1, $perPage)));

/**
 * How a conversation identifies its visitor, for the list row and the
 * transcript header alike: the pre-chat form's name/email when given, then
 * the WHMCS customer id, then plainly anonymous.
 */
$identityLine = static function (array $row) use ($e): string {
    if ($row['visitor']['name'] !== '') {
        return $e($row['visitor']['name']);
    }

    if ($row['customer_id'] !== null) {
        return '#' . $e((int) $row['customer_id']);
    }

    return 'Anonymous';
};

$deptClass = static function (array $row): string {
    return $row['visitor']['department'] === 'services' ? 'services' : 'sales';
};

$deptLabel = static function (array $row): string {
    return match ($row['visitor']['department']) {
        'services' => 'Services',
        'sales'    => 'Sales',
        default    => '—',
    };
};

$qs = static function (array $overrides) use ($search, $page): string {
    $params = array_filter(array_merge(['q' => $search, 'page' => $page], $overrides), static fn ($v) => $v !== '' && $v !== null);

    return http_build_query($params);
};
?>
<div class="mock stats-strip">
    <div class="strip-stat">Today <b><?= $e(number_format((int) $stats['conversations'])) ?></b></div>
    <div class="strip-stat">Messages today <b><?= $e(number_format((int) $stats['messages'])) ?></b></div>
    <div class="strip-stat">Spend today <b>$<?= $e(number_format((float) $stats['cost'], 4)) ?></b></div>
    <div class="strip-stat">Avg messages <b><?= $e(number_format((float) $stats['avg_messages'], 1)) ?></b></div>
</div>

<form method="get" action="<?= $e($base . '/conversations') ?>" class="card" style="margin-top:14px">
    <div class="row">
        <div class="field" style="margin-bottom:0">
            <label for="q">Search conversations</label>
            <input type="search" id="q" name="q" value="<?= $e($search) ?>"
                   placeholder="Search titles and message text">
        </div>
        <div class="field" style="margin-bottom:0;align-self:end">
            <button class="btn" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn secondary" href="<?= $e($base . '/conversations') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<p class="muted" style="font-size:13px">
    <?= $e(number_format($total)) ?> conversation<?= $total === 1 ? '' : 's' ?><?php
        echo $search !== '' ? ' matching “' . $e($search) . '”' : ''; ?>.
</p>

<?php if ($rows === []): ?>
    <div class="empty">No conversations<?= $search !== '' ? ' matched that search' : ' yet' ?>.</div>
<?php else: ?>
    <div class="mock console">
        <div class="clist">
            <?php foreach ($rows as $row): ?>
                <a class="crow<?= $row['public_id'] === $selectedId ? ' sel' : '' ?>"
                   href="<?= $e($base . '/conversations?' . $qs(['id' => $row['public_id']])) ?>">
                    <span class="dept-dot <?= $e($deptClass($row)) ?>"></span>
                    <span class="crow-body">
                        <span class="who"><?= $identityLine($row) ?></span>
                        <span class="prev"><?= $row['title'] === '' ? '(untitled)' : $e($row['title']) ?></span>
                        <span class="meta">
                            <?= $e($deptLabel($row)) ?> ·
                            <?= $e((int) $row['message_count']) ?> msg<?= (int) $row['message_count'] === 1 ? '' : 's' ?> ·
                            <?= $e($row['updated_at']) ?>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="transcript">
            <?php if ($selected === null): ?>
                <div class="empty" style="border:0;background:transparent">
                    <?= $selectedId === '' ? 'Select a conversation to read it here.' : 'That conversation does not exist.' ?>
                </div>
            <?php else: ?>
                <div class="t-head">
                    <div>
                        <div class="who"><?= $identityLine($selected) ?></div>
                        <div class="sub">
                            <?= $selected['visitor']['email'] !== '' ? $e($selected['visitor']['email']) : 'no email on file' ?>
                            · started <?= $e($selected['created_at']) ?>
                            · $<?= $e(number_format((float) $selected['total_cost_usd'], 4)) ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <span class="pill <?= $e($deptClass($selected)) ?>"><?= $e($deptLabel($selected)) ?></span>
                        <a class="btn secondary"
                           href="<?= $e($base . '/conversation/export?id=' . urlencode((string) $selected['public_id'])) ?>">Export</a>
                    </div>
                </div>

                <?php if ($selectedMessages === []): ?>
                    <div class="empty" style="border:0;background:transparent">This conversation has no messages yet.</div>
                <?php else: ?>
                    <?php foreach ($selectedMessages as $message): ?>
                        <div class="bubble <?= $message['role'] === 'user' ? 'user' : 'bot' ?>"><?= $e($message['content']) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <div class="actions">
            <?php if ($page > 1): ?>
                <a class="btn secondary" href="<?= $e($base . '/conversations?' . $qs(['page' => $page - 1, 'id' => null])) ?>">Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= $e($page) ?> of <?= $e($pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn secondary" href="<?= $e($base . '/conversations?' . $qs(['page' => $page + 1, 'id' => null])) ?>">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
