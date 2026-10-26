<?php
require_once INCLUDE_DIR.'class.signal.php';

class Webhook {
    var $id;
    var $ht;

    // Seconds to wait before each retry; the last value repeats
    static $retry_delays = [60, 300, 900, 3600, 21600];
    // Max deliveries retried per cron run (each can block up to its timeout)
    const RETRY_BATCH = 10;
    // Days to keep finished (success/failed) delivery rows
    const DELIVERY_TTL_DAYS = 30;

    // Nesting depth of hold() and the ticket.closed events it held back, by ticket id
    static $held = 0;
    static $closed = [];

    function __construct($id) {
        $this->id = 0;
        $this->load($id);
    }

    function load($id=0) {
        if (!$id && !($id=$this->id)) return;

        $sql='SELECT * FROM '.TABLE_PREFIX.'webhook WHERE id='.db_input($id);
        if (($res=db_query($sql)) && db_num_rows($res))
            $this->ht = db_fetch_array($res);

        $this->id = $this->ht['id'];
        return $this->id;
    }

    function getId() { return $this->id; }
    function getName() { return $this->ht['name']; }
    function getUrl() { return $this->ht['url']; }
    function getStatus() { return $this->ht['status']; }
    function getHeaders() { return $this->ht['headers']; }
    function getTimeout() { return $this->ht['timeout']; }
    function getMaxRetries() { return (int)$this->ht['max_retries']; }
    function getTopicIds() { return self::parseTopicIds($this->ht['topic_ids']); }

    function getEvents() {
        return [
            'ticket.created' => $this->ht['event_new_ticket'],
            'ticket.closed'  => $this->ht['event_ticket_closed'],
            'staff.reply'    => $this->ht['event_staff_reply'],
            'client.reply'   => $this->ht['event_client_reply'],
            'staff.note'     => $this->ht['event_staff_note'],
        ];
    }

    // Accepts an array (form post) or the stored comma separated string
    static function parseTopicIds($ids) {
        if (!is_array($ids))
            $ids = explode(',', (string)$ids);
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    static function getSettingsSql($vars) {
        return ', status='.db_input(isset($vars['status']) ? 1 : 0)
             .', name='.db_input($vars['name'])
             .', url='.db_input($vars['url'])
             .', headers='.db_input($vars['headers'])
             .', timeout='.db_input($vars['timeout'])
             .', topic_ids='.db_input(implode(',', self::parseTopicIds($vars['topic_ids'] ?? [])))
             .', max_retries='.db_input(max(0, min(10, (int)($vars['max_retries'] ?? 3))))
             .', event_new_ticket='.db_input(isset($vars['event_new_ticket']) ? 1 : 0)
             .', event_ticket_closed='.db_input(isset($vars['event_ticket_closed']) ? 1 : 0)
             .', event_staff_reply='.db_input(isset($vars['event_staff_reply']) ? 1 : 0)
             .', event_client_reply='.db_input(isset($vars['event_client_reply']) ? 1 : 0)
             .', event_staff_note='.db_input(isset($vars['event_staff_note']) ? 1 : 0);
    }

    function update($vars, &$errors) {
        if (!$vars['name']) $errors['name'] = 'Name is required';
        if (!$vars['url']) $errors['url'] = 'URL is required';

        if ($errors) return false;

        $sql = 'UPDATE '.TABLE_PREFIX.'webhook SET updated=NOW() '
             .self::getSettingsSql($vars)
             .' WHERE id='.db_input($this->id);

        return db_query($sql);
    }

    function delete() {
        db_query('DELETE FROM '.TABLE_PREFIX.'webhook_delivery WHERE webhook_id='.db_input($this->id));
        $sql = 'DELETE FROM '.TABLE_PREFIX.'webhook WHERE id='.db_input($this->id);
        return db_query($sql);
    }

    static function create($vars, &$errors) {
        if (!$vars['name']) $errors['name'] = 'Name is required';
        if (!$vars['url']) $errors['url'] = 'URL is required';

        if ($errors) return false;

        $sql = 'INSERT INTO '.TABLE_PREFIX.'webhook SET created=NOW(), updated=NOW() '
             .self::getSettingsSql($vars);

        if (db_query($sql) && ($id=db_insert_id()))
            return new Webhook($id);

        return null;
    }

    static function lookup($id) {
        return ($id && is_numeric($id) && ($w=new Webhook($id)) && $w->getId()) ? $w : null;
    }

    // --- The Sender Logic ---
    static function send($event, $ticket, $entry=null) {

        $map = [
            'ticket.created' => 'event_new_ticket',
            'ticket.closed'  => 'event_ticket_closed',
            'staff.reply'    => 'event_staff_reply',
            'client.reply'   => 'event_client_reply',
            'staff.note'     => 'event_staff_note'
        ];

        if (!isset($map[$event])) return;
        $col = $map[$event];

        if ($event == 'ticket.closed' && self::$held) {
            self::$closed[$ticket->getId()] = $ticket;
            return;
        }

        // Find active webhooks for this event
        $sql = "SELECT * FROM ".TABLE_PREFIX."webhook WHERE status=1 AND `$col`=1";
        $res = db_query($sql);

        if (!$res || db_num_rows($res) == 0) return;

        // Skip webhooks limited to other help topics (no topics = all)
        $topicId = (int)$ticket->getTopicId();
        $webhooks = [];
        while ($row = db_fetch_array($res)) {
            $topics = self::parseTopicIds($row['topic_ids']);
            if ($topics && !in_array($topicId, $topics))
                continue;
            $webhooks[] = $row;
        }

        if (!$webhooks) return;

        $payload = self::getPayload($event, $ticket, $entry);

        foreach ($webhooks as $row) {
            // Lets receivers deduplicate when a retry follows a delivery they did get
            $payload['delivery_id'] = bin2hex(random_bytes(8));
            $result = self::fire($row, $payload, 1);
            if (!$result['ok'])
                self::queueRetry($row, $event, $ticket->getId(), $payload, $result);
        }
    }

    // Holds back ticket.closed until the matching release(). Replying and closing
    // in one action closes the ticket first, so receivers got the close before the
    // reply (and its files)
    static function hold() {
        self::$held++;
    }

    static function release() {
        if (self::$held > 0 && --self::$held > 0)
            return;

        $tickets = self::$closed;
        self::$closed = [];
        foreach ($tickets as $ticket) {
            // Could be reopened meanwhile
            if ($ticket->isClosed())
                self::send('ticket.closed', $ticket);
        }
    }

    // Helpdesk URL may be configured with or without a trailing slash
    static function getBaseUrl() {
        $cfg = $GLOBALS['cfg'];
        return $cfg ? rtrim($cfg->getBaseUrl(), '/').'/' : '';
    }

    static function getPayload($event, $ticket, $entry=null) {
        $base = self::getBaseUrl();
        $topic = $ticket->getTopic();

        $payload = [
            'event'     => $event,
            'ticket_id' => $ticket->getId(),
            'number'    => $ticket->getNumber(),
            'subject'   => (string)$ticket->getSubject(),
            'status'    => (string)$ticket->getStatus(),
            'priority'  => $ticket->getPriority() ? (string)$ticket->getPriority() : 'Normal',
            'source'    => $ticket->getSource(),
            'topic_id'  => (int)$ticket->getTopicId(),
            'topic'     => $topic ? $topic->getFullName() : null,
            'link' => $base ? $base . 'scp/tickets.php?id=' . $ticket->getId() : '',
            'timestamp' => time()
        ];

        if ($entry && is_object($entry)) {
            $body = $entry->getBody();
            $payload['title'] = (string)$entry->getTitle();
            // Title-only notes are stored with the empty body marker '-'
            $payload['message'] = (is_object($body))
                ? ($body->isEmpty() ? '' : $body->getClean())
                : (string)$body;
            $payload['poster']  = (string)$entry->getPoster();
            $payload['poster_type'] = ($entry->getStaffId() > 0) ? 'Staff' : 'User';
            $payload['staff_id'] = (int)$entry->getStaffId();
            $payload['attachments'] = self::getAttachments($ticket, $entry);
        }

        // The forms the client filled in when opening the ticket
        if ($event == 'ticket.created')
            $payload['forms'] = self::getForms($ticket);

        return $payload;
    }

    static function getAttachments($ticket, $entry) {
        $base = self::getBaseUrl();
        $files = [];
        foreach ($entry->getAttachments() as $att) {
            if (!($file = $att->getFile()))
                continue;
            $files[] = [
                'id'     => (int)$file->getId(),
                'name'   => $att->getFilename(),
                'size'   => (int)$file->getSize(),
                'type'   => $file->getType(),
                'inline' => (bool)$att->inline,
                'download_url' => $base
                    ? sprintf('%sapi/tickets/%d/attachments/%d', $base,
                        $ticket->getId(), $file->getId())
                    : '',
            ];
        }
        return $files;
    }

    static function getForms($ticket) {
        $forms = [];
        foreach (DynamicFormEntry::forTicket($ticket->getId(), true) as $form) {
            $fields = [];
            foreach ($form->getAnswers() as $answer) {
                $field = $answer->getField();
                if ($answer->isDeleted() || !$field->isStorable())
                    continue;
                $fields[] = [
                    'id'    => (int)$field->get('id'),
                    'name'  => $field->get('name'),
                    'label' => $answer->getLocal('label'),
                    'type'  => $field->get('type'),
                    'value' => (string)$answer,
                ];
            }
            $forms[] = [
                'id'     => (int)$form->getFormId(),
                'title'  => $form->getTitle(),
                'fields' => $fields,
            ];
        }
        return $forms;
    }

    static function fire($config, $payload, $attempt=1) {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: osTicket-Webhook/1.0',
            'X-Webhook-Event: '.$payload['event'],
            'X-Webhook-Delivery: '.$payload['delivery_id'],
            'X-Webhook-Attempt: '.$attempt,
        ];

        if (!empty($config['headers'])) {
            foreach (explode("\n", $config['headers']) as $h) {
                if (trim($h)) $headers[] = trim($h);
            }
        }

        $ch = curl_init($config['url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)($config['timeout'] ?: 5));
        // Never follow file://, gopher:// etc. from an admin supplied URL
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');

        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'ok'    => $code >= 200 && $code < 300,
            'code'  => $code,
            'error' => $error ?: ($code ? 'HTTP '.$code : 'No response'),
        ];
    }

    static function getRetryDelay($attempts) {
        $delays = self::$retry_delays;
        return $delays[min(max($attempts, 1), count($delays)) - 1];
    }

    static function queueRetry($config, $event, $ticketId, $payload, $result) {
        if ((int)$config['max_retries'] < 1)
            return;

        $sql = 'INSERT INTO '.TABLE_PREFIX.'webhook_delivery SET created=NOW(), updated=NOW()'
             .', webhook_id='.db_input($config['id'])
             .', event='.db_input($event)
             .', ticket_id='.db_input($ticketId)
             .', payload='.db_input(json_encode($payload))
             .", status='pending', attempts=1"
             .', response_code='.db_input($result['code'])
             .', last_error='.db_input(substr($result['error'], 0, 255))
             .', next_attempt=DATE_ADD(NOW(), INTERVAL '.(int)self::getRetryDelay(1).' SECOND)';

        db_query($sql);
    }

    // Called on every cron run (system cron and autocron)
    static function processRetries() {
        $sql = 'SELECT d.*, w.url, w.headers, w.timeout, w.max_retries, w.status AS webhook_status'
             .' FROM '.TABLE_PREFIX.'webhook_delivery d'
             .' JOIN '.TABLE_PREFIX.'webhook w ON (w.id = d.webhook_id)'
             ." WHERE d.status='pending' AND d.next_attempt <= NOW()"
             .' ORDER BY d.next_attempt LIMIT '.self::RETRY_BATCH;

        if (($res = db_query($sql))) {
            while ($row = db_fetch_array($res))
                self::retry($row);
        }

        db_query('DELETE FROM '.TABLE_PREFIX.'webhook_delivery'
            ." WHERE status IN ('success', 'failed')"
            .' AND updated < DATE_SUB(NOW(), INTERVAL '.self::DELIVERY_TTL_DAYS.' DAY)');
    }

    static function retry($row) {
        $table = TABLE_PREFIX.'webhook_delivery';

        if (!$row['webhook_status']) {
            db_query("UPDATE $table SET status='failed', updated=NOW(), last_error='Webhook disabled'"
                .' WHERE id='.db_input($row['id']));
            return;
        }

        // Claim the row so a concurrent cron run doesn't deliver it twice
        db_query("UPDATE $table SET next_attempt=DATE_ADD(NOW(), INTERVAL 10 MINUTE)"
            .' WHERE id='.db_input($row['id'])." AND status='pending'"
            .' AND next_attempt='.db_input($row['next_attempt']));
        if (db_affected_rows() != 1)
            return;

        $attempt = (int)$row['attempts'] + 1;
        $result = self::fire($row, json_decode($row['payload'], true), $attempt);

        $sql = "UPDATE $table SET updated=NOW(), attempts=".db_input($attempt)
             .', response_code='.db_input($result['code']);

        if ($result['ok']) {
            $sql .= ", status='success', last_error='', next_attempt=NULL";
        } elseif ($attempt > (int)$row['max_retries']) {
            $sql .= ", status='failed', next_attempt=NULL"
                 .', last_error='.db_input(substr($result['error'], 0, 255));
        } else {
            $sql .= ', next_attempt=DATE_ADD(NOW(), INTERVAL '.(int)self::getRetryDelay($attempt).' SECOND)'
                 .', last_error='.db_input(substr($result['error'], 0, 255));
        }

        db_query($sql.' WHERE id='.db_input($row['id']));
    }

    static function onCron($object, $data) {
        self::processRetries();
    }
}
class WebhookManager {
    static function send($e, $t, $m=null) { Webhook::send($e,$t,$m); }
    static function hold() { Webhook::hold(); }
    static function release() { Webhook::release(); }
}

Signal::connect('cron', array('Webhook', 'onCron'));
?>
