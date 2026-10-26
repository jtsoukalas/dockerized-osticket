<?php
global $cfg;
$entryTypes = ThreadEntry::getTypes();
$user = $entry->getUser() ?: $entry->getStaff();

if ($entry->staff && $cfg->hideStaffName()) {
    $name = __('Staff');
} else {
    $name = $user ? $user->getName() : $entry->poster;
}

// 1. Identify System Canned Replies
$isSystemCannedReply = (strpos($entry->poster, 'SYSTEM') !== false && strpos($entry->poster, 'Canned Reply') !== false);

// Identify Mail Delivery / mailer-daemon type senders
$isMailDelivery = (
    stripos((string)$entry->poster, 'mail delivery') !== false ||
    stripos((string)$entry->poster, 'mailer-daemon') !== false ||
    stripos((string)$entry->poster, 'postmaster') !== false ||
    stripos((string)$name, 'mail delivery') !== false
);

$avatar = '';
if ($isSystemCannedReply) {
    // System Canned Reply — specific Gravatar
    $name = 'SYSTEM';
    $avatar = '<img class="avatar" alt="Avatar" src="//www.gravatar.com/avatar/b1891d1dea3aeb76b4896e451623ac39?s=80&amp;d=mm" width="64" height="64">';
} elseif ($isMailDelivery) {
    // Distinctive mail-bounce / mailer-daemon icon
    $avatar = '<span class="avatar" style="display:inline-flex;align-items:center;justify-content:center;'
            . 'width:64px;height:64px;border-radius:50%;background:#c0392b;flex-shrink:0;">'
            . '<i class="icon-envelope-alt" style="font-size:26px;color:#fff;line-height:1;"></i>'
            . '</span>';
} else {
    // Normal Avatar Logic for Users/Staff
    if ($cfg->isAvatarsEnabled() && $user) {
        $avatar = $user->getAvatar();
    }
}

// Fallback if no avatar exists (Standard osTicket sprite)
if (!$avatar) {
    $avatar = '<img class="avatar" src="images/avatar-sprite-ateam.png" alt="Avatar" width="64" height="64">';
}

$type = $entryTypes[$entry->type];
?>

<div class="thread-entry <?php echo $type; ?> <?php if ($avatar) echo 'avatar'; ?>">
    <div class="thread-meta-column">
        <div class="avatar-stack">
            <?php echo $avatar; ?>
        </div>
        <div class="meta-stack">
            <div class="name-time">
                <b><?php echo Format::htmlchars($name); ?></b>
                <div class="thread-time">
                    <?php echo sprintf('<time datetime="%s" title="%s">%s</time>',
                        date(DateTime::W3C, Misc::db2gmtime($entry->created)),
                        Format::daydatetime($entry->created),
                        Format::datetime($entry->created)
                    ); ?>
                </div>
            </div>
            <div class="thread-title-stacked">
                <span class="faded title truncate" style="max-width:220px; display:block; margin-top:6px;">
                    <?php echo $entry->title; ?>
                </span>
            </div>
        </div>
        <?php if ($entry->flags & ThreadEntry::FLAG_EDITED) { ?>
            <span class="label label-bare" style="margin-top:6px;" title="<?php
                echo sprintf(__('Edited on %s by %s'), Format::datetime($entry->updated), 'You');
            ?>"><?php echo __('Edited'); ?></span>
        <?php } ?>
    </div>

    <div class="header" style="display:none;"></div>

    <div class="thread-body" id="thread-id-<?php echo $entry->getId(); ?>">
        <div><?php echo $entry->getBody()->toHtml(); ?></div>
        <div class="clear"></div>

        <?php if ($entry->has_attachments) { ?>
        <div class="attachments">
            <?php
            foreach ($entry->attachments as $A) {
                if ($A->inline) continue;
                $size = '';
                if ($A->file->size) {
                    $size = sprintf('<small class="filesize faded">%s</small>', Format::file_size($A->file->size));
                }
                ?>
                <span class="attachment-info">
                    <i class="icon-paperclip icon-flip-horizontal"></i>
                    <a class="no-pjax truncate filename"
                       href="<?php echo $A->file->getDownloadUrl(['id' => $A->getId()]); ?>" 
                       download="<?php echo Format::htmlchars($A->getFilename()); ?>"
                       target="_blank">
                       <?php echo Format::htmlchars($A->getFilename()); ?>
                    </a>
                    <?php echo $size; ?>
                </span>
            <?php } ?>
        </div>
        <?php } ?>
    </div>

    <?php if ($urls = $entry->getAttachmentUrls()) { ?>
        <script type="text/javascript">
            $('#thread-id-<?php echo $entry->getId(); ?>')
                .data('urls', <?php echo JsonDataEncoder::encode($urls); ?>)
                .data('id', <?php echo $entry->getId(); ?>);
        </script>
    <?php } ?>
</div>