<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin()) die('Access Denied');

require_once(INCLUDE_DIR . 'class.webhook.php');
require_once(INCLUDE_DIR . 'class.topic.php');

$qs = array();
$webhooks = array();
$sql = 'SELECT * FROM '.TABLE_PREFIX.'webhook ORDER BY name';
if (($res=db_query($sql)) && db_num_rows($res)) {
    while ($row = db_fetch_array($res))
        $webhooks[] = $row;
}

$webhook = null;
$errors = array();

// Handle POST actions
if ($_POST) {
    if ($_POST['do'] == 'update') {
        if (($webhook = Webhook::lookup($_POST['id'])) && $webhook->update($_POST, $errors)) {
            $msg = 'Webhook updated successfully';
            $webhook = null; // Return to list view
        } elseif (!$webhook) {
            $errors['err'] = 'Unknown Webhook';
        }
    } elseif ($_POST['do'] == 'create') {
        if (($webhook = Webhook::create($_POST, $errors))) {
            $msg = 'Webhook created successfully';
            $webhook = null; // Return to list view
        } else {
            $errors['err'] = 'Unable to create Webhook. Correct errors below.';
        }
    } elseif ($_POST['do'] == 'delete') {
        if (($webhook = Webhook::lookup($_POST['id'])) && $webhook->delete()) {
            $msg = 'Webhook deleted successfully';
            $webhook = null;
        } else {
            $errors['err'] = 'Unable to delete Webhook';
        }
    }
}

// Handle Editing (GET)
if ($_REQUEST['id'] && !$_POST) {
    $webhook = Webhook::lookup($_REQUEST['id']);
}

// SHOW FORM (Add or Edit)
if ($webhook || $_REQUEST['a']=='add') {
    $action = $webhook ? 'update' : 'create';
    $info = $webhook ? $webhook->ht : $_POST;
?>
<h2><?php echo $webhook ? 'Update Webhook' : 'Add New Webhook'; ?></h2>
<form action="settings.php?t=webhooks" method="post" class="save">
 <?php csrf_token(); ?>
 <input type="hidden" name="do" value="<?php echo $action; ?>" />
 <?php if ($webhook) { ?> <input type="hidden" name="id" value="<?php echo $webhook->getId(); ?>" /> <?php } ?>
 
 <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr>
            <th colspan="2">
                <em><?php echo __('Webhook Configuration'); ?></em>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="180" class="required"><?php echo __('Name'); ?>:</td>
            <td>
                <input type="text" size="40" name="name" value="<?php echo $info['name']; ?>" autofocus>
                &nbsp;<span class="error">*&nbsp;<?php echo $errors['name']; ?></span>
            </td>
        </tr>
        <tr>
            <td width="180" class="required"><?php echo __('Status'); ?>:</td>
            <td>
                <input type="checkbox" name="status" value="1" <?php echo $info['status'] ? 'checked="checked"' : ''; ?>>
                <?php echo __('Active'); ?>
            </td>
        </tr>
        <tr>
            <td width="180" class="required"><?php echo __('Payload URL'); ?>:</td>
            <td>
                <input type="text" size="60" name="url" value="<?php echo $info['url']; ?>" placeholder="https://slack.com/api/webhooks/...">
                &nbsp;<span class="error">*&nbsp;<?php echo $errors['url']; ?></span>
            </td>
        </tr>
        <tr>
            <td width="180"><?php echo __('Custom Headers'); ?>:</td>
            <td>
                <textarea name="headers" rows="3" cols="60" placeholder="Authorization: Bearer key"><?php echo $info['headers']; ?></textarea>
                <br><span class="faded"><?php echo __('One per line'); ?></span>
            </td>
        </tr>
        <tr>
            <td width="180"><?php echo __('Timeout'); ?>:</td>
            <td>
                <input type="number" name="timeout" value="<?php echo $info['timeout'] ?: 10; ?>" style="width: 60px;"> seconds
            </td>
        </tr>
        <tr>
            <td width="180"><?php echo __('Retries'); ?>:</td>
            <td>
                <input type="number" name="max_retries" min="0" max="10" value="<?php echo isset($info['max_retries']) ? (int)$info['max_retries'] : 3; ?>" style="width: 60px;">
                <span class="faded"><?php echo __('Failed deliveries are retried by cron with increasing delays (1m, 5m, 15m, 1h, 6h). 0 disables retries.'); ?></span>
            </td>
        </tr>
    </tbody>
    <thead>
        <tr>
            <th colspan="2"><em><?php echo __('Help Topics'); ?></em></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="180"><?php echo __('Only for'); ?>:</td>
            <td>
                <?php $selectedTopics = Webhook::parseTopicIds($info['topic_ids'] ?? []); ?>
                <div style="max-height: 220px; overflow-y: auto; width: 450px; border: 1px solid #ccc; padding: 4px 8px; background: #fff;">
                    <?php foreach (Topic::getHelpTopics(false, true) as $topicId => $topicName) { ?>
                        <label style="display: block; padding: 2px 0;">
                            <input type="checkbox" name="topic_ids[]" value="<?php echo (int)$topicId; ?>" <?php echo in_array($topicId, $selectedTopics) ? 'checked="checked"' : ''; ?>>
                            <?php echo Format::htmlchars($topicName); ?>
                        </label>
                    <?php } ?>
                </div>
                <span class="faded"><?php echo __('Leave all unchecked to send events for all help topics.'); ?></span>
            </td>
        </tr>
    </tbody>
    <thead>
        <tr>
            <th colspan="2"><em><?php echo __('Trigger Events'); ?></em></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?php echo __('New Ticket'); ?>:</td>
            <td><input type="checkbox" name="event_new_ticket" value="1" <?php echo $info['event_new_ticket']?'checked':''; ?>></td>
        </tr>
        <tr>
            <td><?php echo __('Ticket Closed'); ?>:</td>
            <td><input type="checkbox" name="event_ticket_closed" value="1" <?php echo $info['event_ticket_closed']?'checked':''; ?>></td>
        </tr>
        <tr>
            <td><?php echo __('Staff Reply'); ?>:</td>
            <td><input type="checkbox" name="event_staff_reply" value="1" <?php echo $info['event_staff_reply']?'checked':''; ?>></td>
        </tr>
        <tr>
            <td><?php echo __('Client Reply'); ?>:</td>
            <td><input type="checkbox" name="event_client_reply" value="1" <?php echo $info['event_client_reply']?'checked':''; ?>></td>
        </tr>
        <tr>
            <td><?php echo __('Internal Note by Staff'); ?>:</td>
            <td><input type="checkbox" name="event_staff_note" value="1" <?php echo $info['event_staff_note']?'checked':''; ?>></td>
        </tr>
    </tbody>
 </table>
 <p style="text-align:center">
    <input type="submit" name="submit" value="<?php echo __('Save Changes'); ?>">
    <input type="button" name="cancel" value="<?php echo __('Cancel'); ?>" onclick='window.location.href="settings.php?t=webhooks"'>
 </p>
</form>

<?php 
// SHOW LIST VIEW
} else { ?>

<div style="margin-bottom:10px">
    <div class="pull-left"><h2><?php echo __('Webhooks'); ?></h2></div>
    <div class="pull-right">
        <a href="settings.php?t=webhooks&a=add" class="green button action-button"><i class="icon-plus-sign"></i> <?php echo __('Add New Webhook'); ?></a>
    </div>
    <div class="clear"></div>
</div>

<table class="list" border="0" cellspacing="1" cellpadding="0" width="940">
    <thead>
        <tr>
            <th width="250"><?php echo __('Name'); ?></th>
            <th width="350"><?php echo __('URL'); ?></th>
            <th><?php echo __('Active Events'); ?></th>
            <th><?php echo __('Help Topics'); ?></th>
            <th width="80"><?php echo __('Status'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php
    if ($webhooks) {
        $topicNames = Topic::getHelpTopics(false, true);
        foreach ($webhooks as $w) {
            $topics = [];
            foreach (Webhook::parseTopicIds($w['topic_ids']) as $topicId)
                $topics[] = $topicNames[$topicId] ?? '#'.$topicId;
            $events = [];
            if ($w['event_new_ticket']) $events[] = 'New Ticket';
            if ($w['event_ticket_closed']) $events[] = 'Closed';
            if ($w['event_staff_reply']) $events[] = 'Staff Reply';
            if ($w['event_client_reply']) $events[] = 'Client Reply';
            if ($w['event_staff_note']) $events[] = 'Staff Note';
            ?>
            <tr>
                <td>
                    <a href="settings.php?t=webhooks&id=<?php echo $w['id']; ?>">
                        <strong><?php echo Format::htmlchars($w['name']); ?></strong>
                    </a>
                </td>
                <td><?php echo Format::htmlchars(substr($w['url'],0,50)).(strlen($w['url'])>50?'...':''); ?></td>
                <td><?php echo implode(', ', $events); ?></td>
                <td><?php echo $topics ? Format::htmlchars(implode(', ', $topics)) : __('All'); ?></td>
                <td><?php echo $w['status'] ? '<span class="Active">Active</span>' : '<span class="faded">Disabled</span>'; ?></td>
            </tr>
    <?php }
    } else { ?>
        <tr><td colspan="5"><?php echo __('No webhooks found. Click "Add New Webhook" to create one.'); ?></td></tr>
    <?php } ?>
    </tbody>
</table>

<?php } ?>