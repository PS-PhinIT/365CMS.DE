<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->enforceProfileCompletion('messages');
$controller->handleMessagesRequest();

$pageTitle = $t('member.nav.messages');
$pageKey = 'messages';
$pageAssets = [];
$messageService = \CMS\Services\MessageService::getInstance();
$userId = $controller->getUserId();
$conversations = $messageService->getConversations($userId, 20, 0);
$inboxCount = $messageService->getInboxCount($userId);
$selectedThreadId = (int)($_GET['thread'] ?? 0);
$thread = $selectedThreadId > 0 ? $controller->getThread($selectedThreadId) : [];
$messagesToken = $controller->csrfToken('messages_action');
$lastMessage = $thread !== [] ? $thread[count($thread) - 1] : null;
$replyRecipient = $lastMessage === null ? 0 : ((int)($lastMessage->sender_id ?? 0) === $userId ? (int)($lastMessage->recipient_id ?? 0) : (int)($lastMessage->sender_id ?? 0));

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-xl-4 vstack gap-4">
        <section class="card" aria-labelledby="member-conversations-title">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title" id="member-conversations-title"><?= $e($t('member.messages.conversations')) ?></h2>
                <span class="badge bg-primary-lt"><?= (int)$inboxCount ?><span class="visually-hidden"> <?= $e($t('member.messages.in_inbox')) ?></span></span>
            </div>
            <?php if ($conversations === []): ?>
                <div class="card-body text-secondary"><?= $e($t('member.messages.empty')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush list-group-hoverable member-conversation-list">
                    <?php foreach ($conversations as $conversation): ?>
                        <?php
                        $threadId = (int)($conversation->thread_id ?? $conversation->id ?? 0);
                        $isCurrent = $selectedThreadId === $threadId;
                        $partner = (int)($conversation->sender_id ?? 0) === $userId
                            ? ((string)($conversation->recipient_display_name ?? $conversation->recipient_name ?? $t('member.messages.recipient')))
                            : ((string)($conversation->sender_display_name ?? $conversation->sender_name ?? $t('member.messages.sender')));
                        ?>
                        <li>
                            <a class="list-group-item list-group-item-action<?= $isCurrent ? ' active' : '' ?>" href="/member/messages?thread=<?= $threadId ?>"<?= $isCurrent ? ' aria-current="true"' : '' ?>>
                                <span class="d-flex justify-content-between gap-2">
                                    <span class="fw-medium"><?= $e($partner) ?></span>
                                    <span class="small text-secondary text-nowrap"><?= $e(MemberI18n::formatDate($conversation->created_at ?? '', false)) ?></span>
                                </span>
                                <span class="d-block text-secondary small"><?= $e(($conversation->subject ?? '') !== '' ? $conversation->subject : $t('member.messages.no_subject')) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <form class="card" method="post" action="" aria-labelledby="member-new-message-title">
            <input type="hidden" name="action" value="message_send">
            <input type="hidden" name="csrf_token" value="<?= $e($messagesToken) ?>">
            <div class="card-header"><h2 class="card-title" id="member-new-message-title"><?= $e($t('member.messages.new')) ?></h2></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="recipient"><?= $e($t('member.messages.recipient')) ?></label>
                    <input class="form-control" id="recipient" name="recipient" type="text" required aria-describedby="recipient_hint" autocomplete="off">
                    <div class="form-hint" id="recipient_hint"><?= $e($t('member.messages.recipient_hint')) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="subject"><?= $e($t('member.messages.subject')) ?></label>
                    <input class="form-control" id="subject" name="subject" type="text" maxlength="255">
                </div>
                <div>
                    <label class="form-label" for="body"><?= $e($t('member.messages.body')) ?></label>
                    <textarea class="form-control" id="body" name="body" rows="6" required></textarea>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><?= $e($t('member.messages.send')) ?></button>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-8">
        <section class="card member-thread-card" aria-labelledby="member-thread-title">
            <div class="card-header"><h2 class="card-title" id="member-thread-title"><?= $e($thread !== [] && ($thread[0]->subject ?? '') !== '' ? $thread[0]->subject : $t('member.messages.thread')) ?></h2></div>
            <div class="card-body">
                <?php if ($thread === []): ?>
                    <div class="member-empty">
                        <span class="member-empty__icon" aria-hidden="true">✉️</span>
                        <p class="member-empty__text mb-0"><?= $e($t('member.messages.select_thread')) ?></p>
                    </div>
                <?php else: ?>
                    <ol class="member-thread-list list-unstyled mb-4">
                        <?php foreach ($thread as $message): ?>
                            <?php $isOwn = (int)($message->sender_id ?? 0) === $userId; ?>
                            <li class="member-thread-bubble<?= $isOwn ? ' is-own' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div class="small text-secondary">
                                        <?= $e($isOwn ? $t('member.messages.you') : (string)($message->sender_display_name ?? $message->sender_name ?? $t('member.messages.sender'))) ?>
                                        · <time datetime="<?= $e($message->created_at ?? '') ?>"><?= $e(MemberI18n::formatDate($message->created_at ?? '')) ?></time>
                                    </div>
                                    <form method="post" action="" data-cms-confirm="<?= $e($t('member.messages.confirm_delete')) ?>">
                                        <input type="hidden" name="action" value="message_delete">
                                        <input type="hidden" name="message_id" value="<?= (int)($message->id ?? 0) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $e($messagesToken) ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost-danger"><?= $e($t('member.common.delete')) ?><span class="visually-hidden">: <?= $e($t('member.messages.message_from', ['date' => MemberI18n::formatDate($message->created_at ?? '')])) ?></span></button>
                                    </form>
                                </div>
                                <div><?= nl2br($e($message->body ?? '')) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ($replyRecipient > 0): ?>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="message_send">
                            <input type="hidden" name="csrf_token" value="<?= $e($messagesToken) ?>">
                            <input type="hidden" name="parent_id" value="<?= $selectedThreadId ?>">
                            <input type="hidden" name="recipient" value="<?= $replyRecipient ?>">
                            <div class="mb-3">
                                <label class="form-label" for="reply_subject"><?= $e($t('member.messages.subject')) ?></label>
                                <input type="text" class="form-control" id="reply_subject" name="subject" maxlength="255" value="<?= $e(($thread[0]->subject ?? '') !== '' ? $thread[0]->subject : $t('member.messages.reply_subject')) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reply_body"><?= $e($t('member.messages.reply')) ?></label>
                                <textarea class="form-control" id="reply_body" name="body" rows="5" required></textarea>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><?= $e($t('member.messages.send_reply')) ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
