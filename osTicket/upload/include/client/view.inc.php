<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
if(!defined('OSTCLIENTINC') || !$thisclient || !$ticket || !$ticket->checkUserAccess($thisclient)) die('Access Denied!');

$info=($_POST && $errors)?Format::htmlchars($_POST):array();

$type = array('type' => 'viewed');
Signal::send('object.view', $ticket, $type);

$dept = $ticket->getDept();

if ($ticket->isClosed() && !$ticket->isReopenable())
    $warn = sprintf(__('%s is marked as closed and cannot be reopened.'), __('This ticket'));

//Making sure we don't leak out internal dept names
if(!$dept || !$dept->isPublic())
    $dept = $cfg->getDefaultDept();

if ($thisclient && $thisclient->isGuest()
    && $cfg->isClientRegistrationEnabled()) { ?>

<div id="msg_info">
    <i class="icon-compass icon-2x pull-left"></i>
    <strong><?php echo __('Looking for your other tickets?'); ?></strong><br />
    <a href="<?php echo ROOT_PATH; ?>login.php?e=<?php
        echo urlencode($thisclient->getEmail());
    ?>" style="text-decoration:underline"><?php echo __('Sign In'); ?></a>
    <?php echo sprintf(__('or %s register for an account %s for the best experience on our help desk.'),
        '<a href="account.php?do=create" style="text-decoration:underline">','</a>'); ?>
    </div>

<?php } ?>

<div class="ticket-detail-container">
    <div class="ticket-header-row">
        <h1>
            <a href="tickets.php?id=<?php echo $ticket->getId(); ?>" title="<?php echo __('Reload'); ?>" class="refresh-link"><i class="icon-refresh"></i></a>
            <span class="ticket-subject">
                <?php $subject_field = TicketForm::getInstance()->getField('subject');
                    echo $subject_field->display($ticket->getSubject()); ?>
            </span>
            <span class="ticket-number">#<?php echo $ticket->getNumber(); ?></span>
        </h1>
        <div class="ticket-actions">
            <a class="action-button" href="tickets.php?a=print&id=<?php
                echo $ticket->getId(); ?>" title="<?php echo __('Print'); ?>"><i class="icon-print"></i></a>

            <?php if ($ticket->hasClientEditableFields()
                    // Only ticket owners can edit the ticket details (and other forms)
                    && $thisclient->getId() == $ticket->getUserId()) { ?>
                <a class="action-button" href="tickets.php?a=edit&id=<?php
                     echo $ticket->getId(); ?>" title="<?php echo __('Edit'); ?>"><i class="icon-edit"></i></a>
            <?php } ?>
        </div>
    </div>

    <div class="ticket-info-grid">
        <div class="info-card">
            <h3><?php echo __('Basic Ticket Information'); ?></h3>
            <div class="info-row">
                <span class="info-label"><?php echo __('Ticket Status');?>:</span>
                <span class="info-value"><?php echo ($S = $ticket->getStatus()) ? $S->getLocalName() : ''; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><?php echo __('Department');?>:</span>
                <span class="info-value"><?php echo Format::htmlchars($dept instanceof Dept ? $dept->getName() : ''); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><?php echo __('Create Date');?>:</span>
                <span class="info-value"><?php echo Format::datetime($ticket->getCreateDate()); ?></span>
            </div>
        </div>

        <div class="info-card">
            <h3><?php echo __('User Information'); ?></h3>
            <div class="info-row">
                <span class="info-label"><?php echo __('Name');?>:</span>
                <span class="info-value"><?php echo mb_convert_case(Format::htmlchars($ticket->getName()), MB_CASE_TITLE); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><?php echo __('Email');?>:</span>
                <span class="info-value"><?php echo Format::htmlchars($ticket->getEmail()); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><?php echo __('Phone');?>:</span>
                <span class="info-value"><?php echo $ticket->getPhoneNumber(); ?></span>
            </div>
        </div>
    </div>

    <!-- Custom Data -->
<?php
$sections = $forms = array();
foreach (DynamicFormEntry::forTicket($ticket->getId()) as $i=>$form) {
    // Skip core fields shown earlier in the ticket view
    $answers = $form->getAnswers()->exclude(Q::any(array(
        'field__flags__hasbit' => DynamicFormField::FLAG_EXT_STORED,
        'field__name__in' => array('subject', 'priority'),
        Q::not(array('field__flags__hasbit' => DynamicFormField::FLAG_CLIENT_VIEW)),
    )));
    // Skip display of forms without any answers
    foreach ($answers as $j=>$a) {
        if ($v = $a->display())
            $sections[$i][$j] = array($v, $a);
    }
    // Set form titles
    $forms[$i] = $form->getTitle();
}
foreach ($sections as $i=>$answers) {
    ?>
        <table class="custom-data" cellspacing="0" cellpadding="4" width="100%" border="0">
        <tr><td colspan="2" class="headline flush-left"><?php echo $forms[$i]; ?></th></tr>
<?php foreach ($answers as $A) {
    list($v, $a) = $A; ?>
        <tr>
            <th><?php
echo $a->getField()->get('label');
            ?>:</th>
            <td><?php
echo $v;
            ?></td>
        </tr>
<?php } ?>
        </table>
    <?php
} ?>
</div>

<br>
<div class="ticket-detail-container">
  <?php
    $email = $thisclient->getUserName();
    $clientId = TicketUser::lookupByEmail($email)->getId();

    $ticket->getThread()->render(array('M', 'R', 'user_id' => $clientId), array(
                    'mode' => Thread::MODE_CLIENT,
                    'html-id' => 'ticketThread')
                );
if ($blockReply = $ticket->isChild() && $ticket->getMergeType() != 'visual')
    $warn = sprintf(__('This Ticket is Merged into another Ticket. Please go to the %s%d%s to reply.'),
        '<a href="tickets.php?id=', $ticket->getPid(), '" style="text-decoration:underline">Parent</a>');
  ?>

  <div class="clear" style="padding-bottom:10px;"></div>
  <?php if($errors['err']) { ?>
      <script>
        (function() {
          const popup = Notification({ position: 'top-right', duration: 5000 });
          popup.error({ 
            title: '<?php echo __('Error'); ?>',
            message: '<?php echo htmlspecialchars(strip_tags($errors['err']), ENT_QUOTES, 'UTF-8'); ?>'
          });
        })();
      </script>
  <?php }elseif($msg) { ?>
      <script>
        (function() {
          const popup = Notification({ position: 'top-right', duration: 5000 });
          popup.success({ 
            title: '<?php echo __('Success'); ?>',
            message: '<?php echo htmlspecialchars(strip_tags($msg), ENT_QUOTES, 'UTF-8'); ?>'
          });
        })();
      </script>
  <?php }elseif($warn) { ?>
      <script>
        (function() {
          const popup = Notification({ position: 'top-right', duration: 5000 });
          popup.warning({ 
            title: '<?php echo __('Warning'); ?>',
            message: '<?php echo htmlspecialchars(strip_tags($warn), ENT_QUOTES, 'UTF-8'); ?>'
          });
        })();
      </script>
  <?php }
  if ((!$ticket->isClosed() || $ticket->isReopenable()) && !$blockReply) { ?>
  <form id="reply" action="tickets.php?id=<?php echo $ticket->getId();
  ?>#reply" name="reply" method="post" enctype="multipart/form-data">
      <?php csrf_token(); ?>
      <h2><?php echo __('Post a Reply');?></h2>
      <input type="hidden" name="id" value="<?php echo $ticket->getId(); ?>">
      <input type="hidden" name="a" value="reply">
      <div>
          <p><em><?php
           echo __('To best assist you, we request that you be specific and detailed'); ?></em>
          <font class="error">*&nbsp;<?php echo $errors['message']; ?></font>
          </p>
          <textarea name="<?php echo $messageField->getFormName(); ?>" id="message" cols="50" rows="9" wrap="soft"
              class="<?php if ($cfg->isRichTextEnabled()) echo 'richtext';
                  ?> draft" <?php
list($draft, $attrs) = Draft::getDraftAndDataAttrs('ticket.client', $ticket->getId(), $info['message']);
echo $attrs; ?>><?php echo $draft ?: $info['message'];
              ?></textarea>
      <?php
      if ($messageField->isAttachmentsEnabled()) {
          print $attachments->render(array('client'=>true));
      } ?>
      </div>
  <?php
    if ($ticket->isClosed() && $ticket->isReopenable()) { ?>
      <div class="warning-banner">
          <?php echo __('Ticket will be reopened on message post'); ?>
      </div>
  <?php } ?>
      <p style="text-align:center">
          <input type="submit" value="<?php echo __('Post Reply'); ?>">
          <input type="reset" value="<?php echo __('Reset'); ?>">
          <input type="button" value="<?php echo __('Cancel'); ?>" onClick="history.go(-1)">
      </p>
  </form>
  <script type="text/javascript">
    (function() {
      const replyForm = document.getElementById('reply');
      if (replyForm) {
        replyForm.addEventListener('submit', function(e) {
          const messageField = document.querySelector('textarea[name="<?php echo $messageField->getFormName(); ?>"]');
          if (!messageField) return;
          
          // Get the message content
          let messageContent = messageField.value;
          
          // If rich text editor is enabled, get the text content
          if (typeof messageField.classList !== 'undefined' && messageField.classList.contains('richtext')) {
            const editor = messageField.closest('.redactor-box');
            if (editor) {
              const editorContent = editor.querySelector('.redactor-editor');
              if (editorContent) {
                messageContent = editorContent.innerText || editorContent.textContent;
              }
            }
          }
          
          // Check if message is empty after stripping HTML and whitespace
          const strippedContent = messageContent.replace(/<[^>]*>/g, '').trim();

          // A file alone is enough, the message is optional then
          const hasFiles = Array.from(replyForm.querySelectorAll('.filedrop .files input[type="hidden"]'))
            .some(function(input) { return input.value; });

          if ((!strippedContent || strippedContent === '') && !hasFiles) {
            e.preventDefault();
            // Stop osticket.js from disabling the submit button and showing the overlay,
            // otherwise the form stays stuck until a page refresh
            e.stopImmediatePropagation();
            const popup = Notification({ position: 'top-right', duration: 5000 });
            popup.error({ 
              title: '<?php echo __('Error'); ?>',
              message: '<?php echo __('Message cannot be empty. Please enter a message before submitting.'); ?>'
            });
            messageField.focus();
            return false;
          }
        });
      }
    })();
  </script>
  <?php
  } ?>
</div>

<script type="text/javascript">
<?php
// Hover support for all inline images
$urls = array();
foreach (AttachmentFile::objects()->filter(array(
    'attachments__thread_entry__thread__id' => $ticket->getThreadId(),
    'attachments__inline' => true,
)) as $file) {
    $urls[strtolower($file->getKey())] = array(
        'download_url' => $file->getDownloadUrl(['type' => 'H']),
        'filename' => $file->name,
    );
}
?>
// Record the URL for inline attachments
$('#ticketThread').data('inline-attachments', <?php echo json_encode($urls); ?>);
</script>

<script type="text/javascript" src="js/filedrop.field.js"></script>

<script type="text/javascript">
$(function() {
    $('.dialog#emailLogin').unbind('dialogclose');
    $('#reply input, #reply textarea, #reply select').on('change', function() {
        draft.ticket_id = <?php echo (int) $ticket->getId(); ?>;
        draft.saveDraft();
    });
});
</script>
