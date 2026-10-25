<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId",
            "system_emails" => array(
                "*" => "*"
            ),
            "thread_entry_recipients" => array (
                "*" => array("to", "cc")
            )
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($forms = $topic->getForms())) {
            foreach ($forms as $form)
                foreach ($form->getDynamicFields() as $field)
                    $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        switch ($format) {
            case 'email':
                $supported = array_merge($supported, [
                    'header', 'mid', 'emailId', 'to-email-id', 'ticketId', 'reply-to',
                    'reply-to-name', 'in-reply-to', 'references', 'thread-type', 'system_emails',
                    'mailflags' => ['bounce', 'auto-reply', 'spam', 'viral'],
                    'recipients' => ['*' => ['name', 'email', 'source']]
                ]);
                $supported['attachments']['*'][] = 'cid';
                break;
            case 'json':
            case 'xml':
                $supported = array_merge($supported, [
                    'duedate', 'slaId', 'staffId'
                ]);
                break;
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $F = $fileField->uploadAttachment($file);
                    $file['id'] = $F->getId();
                }
                catch (FileUploadError $ex) {
                    $name = $file['name'];
                    $file = array();
                    $file['error'] = Format::htmlchars($name) . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }


    function create($format) {

        if (!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if (!strcasecmp($format, 'email')) {
            // Process remotely piped emails - could be a reply...etc.
            $ticket = $this->processEmailRequest();
        } else {
            // Get and Parse request body data for the format
            $ticket = $this->createTicket($this->getRequest($format));
        }



        if ($ticket)
            $this->response(201, $ticket->getNumber());
        else
            $this->exerr(500, _S("unknown error"));

    }

    /* private helper functions */

    function createTicket($data, $source = 'API') {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        // Assign default value to source if not defined, or defined as NULL
        $data['source'] ??= $source;

        // Create the ticket with the data (attempt to anyway)
        $errors = array();
        if (($ticket = Ticket::create($data, $errors, $data['source'],
                        $autorespond, $alert)) &&  !$errors)
            return $ticket;

        // Ticket create failed Bigly - got errors?
        $title = null;
        // Got errors?
        if (count($errors)) {
            // Ticket denied? Say so loudly so it can standout from generic
            // validation errors
            if (isset($errors['errno']) && $errors['errno'] == 403) {
                $title = _S('Ticket denied');
                $error = sprintf("%s: %s\n\n%s",
                        $title, $data['email'], $errors['err']);
            } else {
                // unpack the errors
                $error = Format::array_implode("\n", "\n", $errors);
            }
        } else {
            // unknown reason - default
            $error = _S('unknown error');
        }

        $error = sprintf('%s :%s',
                _S('Unable to create new ticket'), $error);
        return $this->exerr($errors['errno'] ?: 500, $error, $title);
    }

    function processEmailRequest() {
        return $this->processEmail();
    }

    function processEmail($data=false, array $defaults = []) {

        try {
            if (!$data)
                $data = $this->getEmailRequest();
            elseif (!is_array($data))
                $data = $this->parseEmail($data);
        } catch (Exception $ex)  {
            throw new EmailParseError($ex->getMessage());
        }

        $data = array_merge($defaults, $data);
        $seen = false;
        if (($entry = ThreadEntry::lookupByEmailHeaders($data, $seen))
            && ($message = $entry->postEmail($data))
        ) {
            if ($message instanceof ThreadEntry) {
                return $message->getThread()->getObject();
            }
            else if ($seen) {
                // Email has been processed previously
                return $entry->getThread()->getObject();
            }
        }

        // Allow continuation of thread without initial message or note
        elseif (($thread = Thread::lookupByEmailHeaders($data))
            && ($message = $thread->postEmail($data))
        ) {
            return $thread->getObject();
        }

        // All emails which do not appear to be part of an existing thread
        // will always create new "Tickets". All other objects will need to
        // be created via the web interface or the API
        try {
            return $this->createTicket($data, 'Email');
        } catch (TicketApiError $err) {
            // Check if the ticket was denied by a filter or banlist
            if ($err->isDenied() && $data['mid']) {
                // We need to log the Message-Id (mid) so we don't
                // process the same email again in subsequent fetches
                $entry = new ThreadEntry();
                $entry->logEmailHeaders(0, $data['mid']);
                // throw TicketDenied exception so the caller can handle it
                // accordingly
                throw new TicketDenied($err->getMessage());
            } else {
                // otherwise rethrow this bad baby as it is!
                throw $err;
            }
        }
    }
}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    // Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        // It's important to use postfix exit codes for local piping instead
        // of HTTP's so the piping script can process them accordingly
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    static function process($sapi=null) {
        $pipe = new PipeApiController($sapi);
        if (($ticket=$pipe->processEmail()))
           return $pipe->response(201,
                   is_object($ticket) ? $ticket->getNumber() : $ticket);

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }

    static function local() {
        return self::process('cli');
    }
}

class TicketReplyApiController extends ApiController {
    // Override response to ensure JSON output for arrays
    function response($code, $resp) {
        // If the response is an array, encode as JSON
        if (is_array($resp)) {
            $resp = json_encode($resp);
            $contentType = 'application/json';
        } else {
            $contentType = 'text/plain';
        }
        // Use Http::response directly to set content type
        \Http::response($code, $resp, $contentType);
        exit();
    }

    function getTopics($format='json') {
        $this->requireApiKey();
        
        // In osTicket ost_help_topic:
        // 'ispublic' is the column name (1 = visible, 0 = disabled/internal)
        $topics = Topic::objects()
            ->filter(array('ispublic' => 1)) 
            ->order_by('topic');

        $result = array();
        try {
            foreach ($topics as $T) {
                $result[] = array(
                    'id'   => $T->getId(),
                    'name' => $T->getFullName(), // "Parent / Child"
                    'pid'  => $T->getPid(),
                );
            }
        } catch (Exception $e) {
            $this->exerr(500, "Database Error: " . $e->getMessage());
        }

        $this->response(200, json_encode($result), 'application/json');
    }

    function getTeams($format='json') {
        $this->requireApiKey();
        
        // Fetch all teams from the ost_team table
        // We order by 'name' for a clean list
        $teams = Team::objects()->order_by('name');

        $result = array();
        try {
            foreach ($teams as $T) {
                // We check flags/status if necessary, 
                // but osTicket teams are generally active if they exist.
                $result[] = array(
                    'id'      => $T->getId(),
                    'name'    => $T->getName(),
                    'is_enabled' => $T->isEnabled(),
                    'members_count' => $T->getNumMembers(), // Helpful for UI
                );
            }
        } catch (Exception $e) {
            $this->exerr(500, "Database Error: " . $e->getMessage());
        }

        // Return the JSON response
        $this->response(200, json_encode($result), 'application/json');
    }

    function getDepartments($format='json') {
        $this->requireApiKey();
        
        // Use the ORM to get all objects
        $depts = Dept::objects()->order_by('name');

        $result = array();
        try {
            foreach ($depts as $D) {
                // If you want ONLY active departments:
                // osTicket doesn't have a simple 'isactive' column, 
                // but we can check if it's not archived.
                
                $result[] = array(
                    'id'   => $D->getId(),
                    'name' => $D->getName(),
                    'full_name' => $D->getFullName(), // Handles "Parent / Child"
                    'is_public' => $D->isPublic(),
                );
            }
        } catch (Exception $e) {
            $this->exerr(500, "Database Error: " . $e->getMessage());
        }

        // If the list is still empty, let's remove the try/catch 
        // to see if a hidden error is occurring.
        $this->response(200, json_encode($result), 'application/json');
    }

    function getRequestStructure($format, $data=null) {
        $supported = array(
            "message", "format", "poster", "staffId", "source", "is_note", "status", "alert",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "topicId", "deptId", "teamId", "source"
        );
        return $supported;
    }

    function validate(&$data, $format, $strict=true) {
        global $ost;

        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        if (!$data['message'])
            $this->exerr(400, __('Message is required'));

        return true;
    }

    function reply($id, $format='json') {
        $this->requireApiKey();

        if (!$id)
            $this->exerr(404, __('Ticket ID required'));

        // Lookup ticket by Number or ID
        $ticket = Ticket::lookupByNumber($id) ?: Ticket::lookup($id);
        if (!$ticket)
            $this->exerr(404, __('Ticket not found'));

        $data = $this->getRequest($format);
        
        // 1. Identify the Actor (Staff/Agent)
        // Default to ID 1 if no staffId is provided in JSON
        $staffId = isset($data['staffId']) ? $data['staffId'] : 1;
        $agent = Staff::lookup($staffId);
        
        if (!$agent)
            $this->exerr(400, __('Valid Staff ID required for Agent actions.'));

        // Set global staff context for internal osTicket methods that check it
        global $thisstaff;
        $thisstaff = $agent;

        // 2. Prepare Thread Entry Variables
        $vars = array(
            'staffId' => $agent->getId(),
            'poster'  => (string) $agent->getName(),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'message' => $data['message'],
        );

        // Handle Attachments
        $tform = TicketForm::getInstance();
        if ($tform && ($messageField = $tform->getField('message'))) {
            $fileField = $messageField->getWidget()->getAttachments();
            if (isset($data['attachments']) && is_array($data['attachments']) && $fileField) {
                $vars['files'] = array();
                foreach($data['attachments'] as $file) {
                    if (isset($file['encoding']) && !strcasecmp($file['encoding'], 'base64')) {
                        $file['data'] = base64_decode($file['data'], true);
                    }
                    if ($file['data'] === false || $file['data'] === null || $file['data'] === '')
                        $this->exerr(400, sprintf('Attachment %s: invalid or empty data', $file['name']));
                    // Fail the whole reply rather than post it without the file
                    try {
                        if ($F = $fileField->uploadAttachment($file))
                            // Thread code expects file info arrays, not bare ids
                            // Keep the sent name: identical content reuses an existing file
                            $vars['files'][] = array('id' => $F->getId(), 'name' => $file['name']);
                    } catch (Exception $ex) {
                        $this->exerr(400, sprintf('Attachment %s: %s', $file['name'], $ex->getMessage()));
                    }
                }
            }
        }

        // 3. Handle Email Alerts
        $alert = isset($data['alert']) ? (bool)$data['alert'] : true;
        if (!$alert) {
            $vars['no_alert'] = true;
            $vars['sendemail'] = false;
        }

        // 4. Post to Thread (Response vs Note)
        $errors = array();
        $is_note = isset($data['is_note']) ? (bool)$data['is_note'] : false;

        try {
            if ($is_note) {
                $vars['note'] = $data['message'];
                $entry = $ticket->postNote($vars, $errors, $agent);
            } else {
                $vars['response'] = $data['message'];
                // postReply handles marking the ticket as 'Answered'
                $entry = $ticket->postReply($vars, $errors, $alert);
            }
            
            if (!$entry) {
                $this->exerr(500, "Post Failed: " . implode(', ', (array)$errors));
            }
        } catch (Throwable $e) {
            $this->exerr(500, "Thread Post Error: " . $e->getMessage());
        }

        // 5. Handle Help Topic Change (with Audit Log)
        if (isset($data['topicId'])) {
            $topic = is_numeric($data['topicId']) 
                ? Topic::lookup($data['topicId']) 
                : Topic::objects()->filter(array('topic' => $data['topicId']))->first();

            if ($topic instanceof Topic) {
                $oldTopicId = $ticket->topic_id;
                $newTopicId = $topic->getId();

                if ($oldTopicId != $newTopicId) {
                    $ticket->topic_id = $newTopicId;
                    
                    // Sync Dept/SLA from Topic
                    if ($topic->dept_id) $ticket->dept_id = $topic->dept_id;
                    if ($topic->sla_id) $ticket->sla_id = $topic->sla_id;

                    // Log the "Grey Bar" event
                    $changes = array('topic_id' => array($oldTopicId, $newTopicId));
                    $ticket->logEvent('edited', $changes, $agent);
                }
            }
        }

        // 6. Handle Status Change (with Audit Log)
        if (isset($data['status'])) {
            $status = null;
            if (is_numeric($data['status'])) {
                $status = TicketStatus::lookup($data['status']);
            } else {
                $status = TicketStatus::objects()->filter(array('state' => $data['status']))->first();
            }

            if ($status instanceof TicketStatus) {
                $oldStatusId = $ticket->status_id;
                $newStatusId = $status->getId();

                if ($oldStatusId != $newStatusId) {
                    $ticket->status_id = $newStatusId;
                    
                    if ($status->getState() == 'closed') {
                        $ticket->closed = SqlFunction::NOW();
                        $ticket->isanswered = 1; // Mark answered if closing
                    }

                    // Log the "Grey Bar" event for status change
                    $changes = array('status_id' => array($oldStatusId, $newStatusId));
                    $ticket->logEvent('edited', $changes, $agent);
                }
            }
        }

        // 7. Handle Department Change (Transfer)
        if (isset($data['deptId'])) {
            $dept = is_numeric($data['deptId']) 
                ? Dept::lookup($data['deptId']) 
                : Dept::objects()->filter(array('name' => $data['deptId']))->first();

            if ($dept instanceof Dept) {
                $oldDeptId = $ticket->getDeptId();
                $newDeptId = $dept->getId();

                if ($oldDeptId != $newDeptId) {
                    // 1. Update the property directly
                    $ticket->dept_id = $newDeptId;

                    // 2. Prepare the Changes array for the audit log
                    $changes = array(
                        'dept_id' => array($oldDeptId, $newDeptId)
                    );

                    // 3. Log the 'transferred' event (This creates the specific "Transferred to..." grey bar)
                    $ticket->logEvent('transferred', $changes, $agent);
                    
                    // Note: If the ticket was assigned to an agent who doesn't have 
                    // access to the new department, you might want to unassign it:
                    // $ticket->staff_id = 0; 
                }
            } else {
                error_log("API: Department not found: " . $data['deptId']);
            }
        }

        // 8. Handle Team Change (Assignment)
        if (isset($data['teamId'])) {
            $team = is_numeric($data['teamId']) 
                ? Team::lookup($data['teamId']) 
                : Team::objects()->filter(array('name' => $data['teamId']))->first();

            if ($team instanceof Team) {
                $oldTeamId = $ticket->getTeamId();
                $newTeamId = $team->getId();

                if ($oldTeamId != $newTeamId) {
                    // Update the actual ticket record
                    $ticket->team_id = $newTeamId;
                    
                    // Fetch the display names for the "Grey Bar"
                    $oldTeam = $oldTeamId ? Team::lookup($oldTeamId) : null;
                    $oldName = $oldTeam ? $oldTeam->getName() : 'None';
                    $newName = $team->getName();

                    // Create the changes array using the 'team' key
                    $changes = array(
                        'team' => array($oldName, $newName)
                    );

                    // LOG THE EVENT
                    // We use 'assigned' to trigger the specific "assigned this to..." text.
                    $ticket->logEvent('assigned', $changes, $agent);
                }
            } else {
                error_log("API: Team not found: " . $data['teamId']);
            }
        }

        // 9. Handle Source Change (Strict Validation)
        if (isset($data['source'])) {
            // Get the official list of allowed sources from osTicket (Web, Email, Phone, API, Other)
            $validSources = Ticket::getSources(); 
            $requestedSource = trim($data['source']);
            
            $newSource = null;

            // Loop through allowed sources to do a case-insensitive match
            foreach ($validSources as $key => $value) {
                if (strcasecmp($key, $requestedSource) === 0) {
                    $newSource = $key; // Grab the exact system casing (e.g., 'Email')
                    break;
                }
            }

            // If it didn't match anything in the system, reject the request completely
            if (!$newSource) {
                return $this->exerr(400, sprintf(
                    __('Invalid source: %s. Allowed sources are: %s'), 
                    $requestedSource, 
                    implode(', ', array_keys($validSources))
                ));
            }

            $oldSource = $ticket->getSource();

            // Only update and log if it actually changed
            if ($oldSource != $newSource) {
                $ticket->source = $newSource;

                $changes = array(
                    'source' => array($oldSource, $newSource)
                );

                $ticket->logEvent('edited', $changes, $agent);
            }
        }

        if (!$ticket->save()) {
             error_log("API: Ticket save failed.");
        }

        $result = array(
            'ticket' => $ticket->getNumber(),
            'entry_id' => $entry->getId(),
            'status' => $ticket->getStatus()->getName()
        );

        $this->response(201, json_encode($result));
    }

    function get($id, $format) {
        $this->requireApiKey();

        $ticket = null;
        if (!$id)
            $this->exerr(404, __('Ticket ID required'));

        // Try to lookup by number (external ID) first, then by database ID
        $ticket = Ticket::lookupByNumber($id) ?: Ticket::lookup($id);
        if (!$ticket)
            $this->exerr(404, __('Ticket not found'));

        // Build a serializable array for the API response
        $data = [
            'id' => $ticket->getId(),
            'number' => $ticket->getNumber(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus() ? (is_object($ticket->getStatus()) ? $ticket->getStatus()->getName() : $ticket->getStatus()) : null,
            'owner' => $ticket->getOwner() ? $ticket->getOwner()->getName() : null,
            'email' => $ticket->getEmail(),
            'created' => $ticket->getCreateDate(),
            'updated' => $ticket->getUpdateDate(),
        ];

        // Add thread conversation
        $thread = $ticket->getThread();
        $data['thread'] = [];
        if ($thread) {
            foreach ($thread->getEntries() as $entry) {
                $data['thread'][] = [
                    'id' => $entry->getId(),
                    'type' => method_exists($entry, 'getTypeName') ? $entry->getTypeName() : $entry->getType(),
                    'poster' => $entry->getPoster(),
                    'created' => $entry->getCreateDate(),
                    'body' => method_exists($entry, 'getBody') ? (string)$entry->getBody() : (string)$entry,
                ];
            }
        }
        return $this->response(200, $data);
    }

    function downloadAttachment($id, $file_id) {
        $this->requireApiKey();

        $ticket = Ticket::lookupByNumber($id) ?: Ticket::lookup($id);
        if (!$ticket)
            $this->exerr(404, __('Ticket not found'));

        // Only serve files attached to this ticket's own thread entries
        $attachment = Attachment::objects()->filter(array(
            'file_id' => $file_id,
            'type' => 'H',
            'thread_entry__thread_id' => $ticket->getThreadId(),
        ))->first();
        if (!$attachment || !($file = $attachment->getFile()))
            $this->exerr(404, __('Attachment not found'));

        // Sends the file (or a redirect for external storage) and exits
        $file->download($attachment->getFilename(), 'attachment');
    }

    function getList($format) {
        $this->requireApiKey();

        // Get query parameters for date range
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $limit = intval($_GET['limit'] ?? 100);
        $offset = intval($_GET['offset'] ?? 0);
        $sortBy = $_GET['sort_by'] ?? 'created';
        $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

        // Validate sort order
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        // Validate sort by field
        $allowedSortFields = ['created', 'updated', 'number', 'subject'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created';
        }

        // Limit sanity check
        if ($limit > 1000) {
            $limit = 1000;
        }
        if ($limit < 1) {
            $limit = 1;
        }

        // Build query
        $query = Ticket::objects();

        // Apply date range filters if provided
        if ($startDate) {
            try {
                // Parse the date string and validate it's a valid format
                $start = new DateTime($startDate);
                $query = $query->filter(array('created__gte' => $start->format('Y-m-d H:i:s')));
            } catch (Exception $e) {
                return $this->exerr(400, sprintf(__('Invalid start date format: %s'), $startDate));
            }
        }

        if ($endDate) {
            try {
                // Parse the date string and validate it's a valid format
                $end = new DateTime($endDate);
                $query = $query->filter(array('created__lte' => $end->format('Y-m-d H:i:s')));
            } catch (Exception $e) {
                return $this->exerr(400, sprintf(__('Invalid end date format: %s'), $endDate));
            }
        }

        // Apply sorting and pagination
        if ($sortOrder === 'DESC') {
            $query = $query->order_by('-' . $sortBy);
        } else {
            $query = $query->order_by($sortBy);
        }
        $totalCount = $query->count();
        $tickets = $query->limit($limit)->offset($offset);

        // Build response
        $data = [
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'tickets' => []
        ];

        foreach ($tickets as $ticket) {
            $data['tickets'][] = [
                'id' => $ticket->getId(),
                'number' => $ticket->getNumber(),
                'subject' => $ticket->getSubject(),
                'status' => $ticket->getStatus() ? (is_object($ticket->getStatus()) ? $ticket->getStatus()->getName() : $ticket->getStatus()) : null,
                'owner' => $ticket->getOwner() ? $ticket->getOwner()->getName() : null,
                'email' => $ticket->getEmail(),
                'created' => $ticket->getCreateDate(),
                'updated' => $ticket->getUpdateDate(),
            ];
        }

        return $this->response(200, $data);
    }
}

class TicketAssignApiController extends TicketReplyApiController {

    function getRequestStructure($format, $data=null) {
        return array("staffId", "actorId", "comments", "alert");
    }

    function validate(&$data, $format, $strict=true) {
        if (!ApiController::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        if (!array_key_exists('staffId', (array)$data))
            $this->exerr(400, __('staffId is required (0 to unassign)'));

        return true;
    }

    // Change the assigned agent, e.g. hand over a ticket auto-claimed by an API user.
    // staffId 0/null releases the agent assignment (team assignment is kept).
    function assign($id, $format='json') {
        $this->requireApiKey();

        $ticket = Ticket::lookupByNumber($id) ?: Ticket::lookup($id);
        if (!$ticket)
            $this->exerr(404, __('Ticket not found'));

        $data = $this->getRequest($format);

        // staff_id on closed tickets means "closed by", so leave it alone
        if ($ticket->isClosed())
            $this->exerr(409, __('Ticket is closed'));

        // Who made the change, shown in the ticket history (defaults to "API")
        $actor = 'API';
        if (!empty($data['actorId'])) {
            if (!($actor = Staff::lookup($data['actorId'])))
                $this->exerr(400, __('Unknown actorId'));
        }

        $current = $ticket->getStaff();
        $staffId = (int)$data['staffId'];

        if (!$staffId) {
            if ($current) {
                if (!$ticket->setStaffId(0))
                    $this->exerr(500, __('Unable to release ticket'));
                $ticket->logEvent('released', array(
                    'staff' => array($current->getId(), (string)$current->getName()->getOriginal())
                ), $actor);
            }
        } else {
            if (!($staff = Staff::lookup($staffId)))
                $this->exerr(404, __('Agent not found'));
            if (!$staff->isAvailable())
                $this->exerr(400, __('Agent is unavailable for assignment'));
            if (!$ticket->getDept()->canAssign($staff))
                $this->exerr(400, __('Agent cannot be assigned tickets in this department'));

            if (!$current || $current->getId() != $staff->getId()) {
                $alert = isset($data['alert']) ? (bool)$data['alert'] : true;
                if (!$ticket->assignToStaff($staff, $data['comments'] ?? '', $alert, $actor))
                    $this->exerr(500, __('Unable to assign ticket'));
            }
        }

        $assignee = $ticket->getStaff();
        return $this->response(200, array(
            'ticket' => $ticket->getNumber(),
            'assignee' => $assignee
                ? array('id' => $assignee->getId(), 'name' => (string)$assignee->getName())
                : null,
            'team' => $ticket->getTeam() ? (string)$ticket->getTeam()->getName() : null,
        ));
    }
}

class TicketApiError extends Exception {

    // Check if exception is because of denial
    public function isDenied() {
        return ($this->getCode() === 403);
    }
}

class TicketDenied extends Exception {}
class EmailParseError extends Exception {}

?>
