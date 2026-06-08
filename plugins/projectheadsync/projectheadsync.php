<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.list.php';
require_once INCLUDE_DIR . 'class.user.php';
require_once INCLUDE_DIR . 'class.dynamic_forms.php';
require_once 'config.php';

class ProjectHeadSyncPlugin extends Plugin {
    var $config_class = 'ProjectHeadSyncConfig';
    private static $signalsRegistered = false;

    function bootstrap() {
        $config = $this->getConfig();
        if (!$config || !$config->get('enabled'))
            return;

        if (self::$signalsRegistered)
            return;

        self::$signalsRegistered = true;
        $this->debugLog('bootstrap enabled');

        Signal::connect('ticket.created', array($this, 'handleTicketEvent'));
        Signal::connect('model.updated', array($this, 'handleTicketEvent'), 'Ticket');
    }

    function handleTicketEvent($ticket, $data=null) {
        $this->debugLog('ticket event for #' . ($ticket ? $ticket->getId() : 'unknown'));
        if (is_array($data) && isset($data['dirty']) && is_array($data['dirty']))
            $this->debugLog('model.updated dirty keys: ' . implode(', ', array_keys($data['dirty'])));

        $instances = $this->getActiveInstances();
        if ($instances) {
            $result = false;
            foreach ($instances as $instance) {
                $config = $instance->getConfig();
                $this->debugLog('instance namespace=' . var_export($config->getNamespace(), true) . ' project_field_name=' . var_export($config->get('project_field_name'), true) . ' project_list=' . var_export($config->get('project_list'), true) . ' field_names=' . var_export($this->getProjectFieldNamesFromConfig($config), true));
                $service = new ProjectHeadSyncService($config);
                $result = $service->syncFromTicket($ticket) || $result;
            }
            return $result;
        }

        $this->debugLog('no active plugin instances found; falling back to current config');
        $service = new ProjectHeadSyncService($this->getConfig());
        return $service->syncFromTicket($ticket);
    }

    private function getProjectFieldNamesFromConfig($config) {
        $fields = array();
        $configured = (string) $config->get('project_field_name');
        foreach (preg_split('/[\r\n,]+/', $configured) as $fieldName) {
            $fieldName = trim($fieldName);
            if ($fieldName !== '')
                $fields[] = $fieldName;
        }
        return array_unique($fields);
    }

    private function debugLog($message) {
        error_log('[projectheadsync] ' . $message);
    }
}

class ProjectHeadSyncService {
    private $config;

    function __construct(PluginConfig $config) {
        $this->config = $config;
    }

    function syncFromTicket($ticket) {
        if (!$this->isEnabled())
            return false;

        if (!$ticket || !($ticket instanceof Ticket))
            return false;

        if (method_exists($ticket, 'loadDynamicData'))
            $ticket->loadDynamicData(true);

        if (($reloaded = Ticket::lookup($ticket->getId())))
            $ticket = $reloaded;

        $this->log('config namespace=' . $this->config->getNamespace() . ' project_field_name=' . var_export($this->config->get('project_field_name'), true) . ' project_list=' . var_export($this->config->get('project_list'), true) . ' field_names=' . var_export($this->getProjectFieldNames(), true));
        $this->log('checking ticket #' . $ticket->getId());

        if (!($project = $this->resolveProjectItem($ticket)))
        {
            $this->log('no configured project field found on ticket #' . $ticket->getId());
            return false;
        }

        if (!($user = $this->resolveHeadUser($project)))
        {
            $this->log('project #' . $project->getId() . ' has no resolvable head user');
            return false;
        }

        $this->removeStaleProjectHeadCollaborators($ticket, $user, $project);

        $collaborators = $ticket->getCollaborators();
        if ($collaborators && $collaborators->findFirst(array('user_id' => $user->getId()))) {
            $this->log('collaborator ' . $user->getEmail() . ' already exists on ticket #' . $ticket->getId());
            return true;
        }

        $errors = array();
        if (!$ticket->addCollaborators(array($user), array(), $errors, false)) {
            if (!$errors) {
                $this->log('collaborator ' . $user->getEmail() . ' already exists on ticket #' . $ticket->getId());
                return true;
            }
            $this->log('failed to add collaborator: ' . json_encode($errors));
            return false;
        }

        $this->addSystemCollaboratorEvent($ticket, $user, 'add', array(
            'project_head' => true,
            'project_name' => $project->getValue(),
        ));
        $this->log('added collaborator ' . $user->getEmail() . ' to ticket #' . $ticket->getId());

        return true;
    }

    private function isEnabled() {
        return (bool) $this->config->get('enabled');
    }

    private function resolveProjectItem($ticket) {
        $list = $this->resolveConfiguredList();
        if (!$list)
            return null;

        $resolvedCandidates = array();
        $matchedFields = array();
        $fallbackCandidates = array();
        $fieldNames = $this->getProjectFieldNames();

        foreach ($this->getTicketAnswers($ticket) as $answer) {
            $field = $answer->getField();
            if (!$field)
                continue;

            $fieldDesc = sprintf('%s/%s', $field->getId(), $this->fieldDisplayName($field));
            $fieldListId = method_exists($field, 'getListId') ? $field->getListId() : null;
            $itemId = $this->extractSelectionItemId($answer);

            if (!$this->isActiveTicketField($field)) {
                $this->log('skipping inactive or hidden field ' . $fieldDesc);
                continue;
            }

            if ($fieldListId == $list->getId() && $itemId && ($project = DynamicListItem::lookup($itemId)))
                $fallbackCandidates[$fieldDesc] = $project;

            $matchedName = $this->getMatchingConfiguredFieldName($field, $fieldNames);
            if (!$matchedName)
                continue;

            $matchedFields[] = $fieldDesc;

            if ($fieldListId != $list->getId()) {
                $this->log('matched field ' . $fieldDesc . ' but list mismatch: field list=' . var_export($fieldListId, true) . ' configured=' . $list->getId());
                continue;
            }

            if (!$itemId) {
                $this->log('matched field ' . $fieldDesc . ' but no item id available, answer=' . var_export($answer->getValue(), true));
                continue;
            }

            if ($project = DynamicListItem::lookup($itemId)) {
                $entry = $answer->getEntry();
                $entryId = $entry ? $entry->getId() : 0;
                $configIndex = array_search($matchedName, $fieldNames, true);

                $resolvedCandidates[] = array(
                    'fieldName' => $matchedName,
                    'fieldDesc' => $fieldDesc,
                    'project' => $project,
                    'itemId' => $itemId,
                    'entryId' => $entryId,
                    'configIndex' => $configIndex !== false ? $configIndex : PHP_INT_MAX,
                );
                continue;
            }

            $this->log('matched field ' . $fieldDesc . ' with itemId=' . $itemId . ' but item not found');
        }

        if ($resolvedCandidates) {
            usort($resolvedCandidates, function($a, $b) {
                if ($a['entryId'] !== $b['entryId'])
                    return $b['entryId'] - $a['entryId'];
                return $a['configIndex'] - $b['configIndex'];
            });

            $candidate = $resolvedCandidates[0];
            $this->log('resolved project ' . $candidate['project']->getId() . ' (' . $candidate['project']->getValue() . ') from configured field ' . $candidate['fieldName'] . ' via ' . $candidate['fieldDesc'] . ' using itemId=' . $candidate['itemId'] . ' entryId=' . $candidate['entryId']);
            return $candidate['project'];
        }

        if ($matchedFields) {
            if ($fallbackCandidates) {
                $fieldDesc = key($fallbackCandidates);
                $this->log('matched project field name(s) but no usable value; falling back to list-matched field ' . $fieldDesc . ' on ticket #' . $ticket->getId());
                return reset($fallbackCandidates);
            }
            $this->log('matched project field name(s) but no usable value on ticket #' . $ticket->getId() . ': ' . implode(', ', $matchedFields));
        } elseif ($fallbackCandidates) {
            $fieldDesc = key($fallbackCandidates);
            $this->log('falling back to list-matched field ' . $fieldDesc . ' on ticket #' . $ticket->getId());
            return reset($fallbackCandidates);
        } else {
            $this->log('ticket #' . $ticket->getId() . ' has fields: ' . $this->describeTicketFields($ticket));
        }

        return null;
    }

    private function removeStaleProjectHeadCollaborators($ticket, $currentUser, $currentProject) {
        $knownHeads = $this->getKnownProjectHeadEmails($currentProject);
        if (!$knownHeads)
            return;

        $currentEmail = mb_strtolower(trim((string) $currentUser->getEmail()));
        $removed = array();

        foreach ($ticket->getCollaborators() as $collaborator) {
            $email = mb_strtolower(trim((string) $collaborator->getEmail()));
            if ($email === '' || $email === $currentEmail)
                continue;

            if (!isset($knownHeads[$email]))
                continue;

            $formerProject = $this->findProjectByHeadEmail($collaborator->getEmail(), $currentProject);
            $this->addSystemCollaboratorEvent($ticket, $collaborator->getUser(), 'del', array(
                'former_project_head' => true,
                'project_name' => $formerProject ? $formerProject->getValue() : $currentProject->getValue(),
            ));

            if ($collaborator->delete())
                $removed[] = $collaborator->getEmail();
        }

        if ($removed)
            $this->log('removed stale project head collaborator(s) from ticket #' . $ticket->getId() . ': ' . implode(', ', $removed));
    }

    private function extractSelectionItemId($answer) {
        $value = $answer->getValue();

        if (is_array($value) && $value) {
            foreach (array('value_id', 'id', 'item_id') as $key) {
                if (isset($value[$key]) && is_numeric($value[$key]))
                    return (int) $value[$key];
            }

            foreach ($value as $key => $item) {
                if (is_numeric($key))
                    return (int) $key;
            }

            foreach ($value as $item) {
                if (is_numeric($item) && !is_array($item))
                    return (int) $item;
            }
        }

        $valueId = $answer->get('value_id');
        if (is_numeric($valueId))
            return (int) $valueId;

        $rawValue = $answer->get('value');
        if (is_numeric($rawValue))
            return (int) $rawValue;

        return 0;
    }

    private function fieldMatchesName($field, $configuredName) {
        $configured = mb_strtolower(trim((string) $configuredName));
        if ($configured === '')
            return false;

        $candidates = array(
            (string) $field->get('name'),
            (string) $field->getId(),
            method_exists($field, 'getLabel') ? (string) $field->getLabel() : '',
            method_exists($field, 'getLocal') ? (string) $field->getLocal('label') : '',
        );

        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim($candidate));
            if ($candidate !== '' && $candidate === $configured)
                return true;
        }

        $normalize = function($value) {
            return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim((string) $value)));
        };

        $configuredNorm = $normalize($configured);
        if ($configuredNorm === '')
            return false;

        foreach ($candidates as $candidate) {
            $candidateNorm = $normalize($candidate);
            if ($candidateNorm === '')
                continue;

            if ($candidateNorm === $configuredNorm)
                return true;

            if (str_starts_with($candidateNorm, $configuredNorm)
                && strlen($candidateNorm) > strlen($configuredNorm)
                && preg_match('/^' . preg_quote($configuredNorm, '/') . '(?:_|-|\s).+$/', $candidateNorm)) {
                return true;
            }
        }

        return false;
    }

    private function getMatchingConfiguredFieldName($field, array $fieldNames) {
        foreach ($fieldNames as $fieldName) {
            if ($this->fieldMatchesName($field, $fieldName))
                return $fieldName;
        }
        return '';
    }

    private function isActiveTicketField($field) {
        if (method_exists($field, 'isVisible') && !$field->isVisible())
            return false;

        if (method_exists($field, 'isEnabled') && !$field->isEnabled())
            return false;

        if (!method_exists($field, 'get'))
            return true;

        $flags = $field->get('flags');
        if (!is_numeric($flags))
            return true;

        if (!($flags & DynamicFormField::FLAG_ENABLED))
            return false;

        if (!($flags & DynamicFormField::FLAG_AGENT_VIEW))
            return false;

        return true;
    }

    private function fieldDisplayName($field) {
        $label = method_exists($field, 'getLabel') ? trim((string) $field->getLabel()) : '';
        return $label ?: trim((string) $field->get('name'));
    }

    private function describeTicketFields($ticket) {
        $fields = array();
        foreach ($this->getTicketAnswers($ticket) as $answer) {
            $field = $answer->getField();
            if ($field)
                $fields[] = sprintf('%s:%s', $field->getId(), $this->fieldDisplayName($field));
        }

        return $fields ? implode(', ', $fields) : '(none)';
    }

    private function getTicketAnswers($ticket) {
        $entries = DynamicFormEntry::forTicket($ticket->getId(), true);
        $answers = array();

        foreach ($entries as $entry) {
            foreach ($entry->getAnswers() as $answer)
                $answers[] = $answer;
        }

        return $answers;
    }

    private function getKnownProjectHeadEmails($excludeProject = null) {
        $list = $this->resolveConfiguredList();
        if (!$list)
            return array();

        $emails = array();
        foreach ($list->getAllItems() as $item) {
            if ($excludeProject && $item->getId() == $excludeProject->getId())
                continue;

            if (($info = $this->resolveProjectEmail($item, $item->getConfiguration(), trim((string) $this->config->get('head_email_property')))))
                $emails[mb_strtolower($info['email'])] = true;
        }

        return $emails;
    }

    private function findProjectByHeadEmail($email, $excludeProject = null) {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '')
            return null;

        $list = $this->resolveConfiguredList();
        if (!$list)
            return null;

        foreach ($list->getAllItems() as $item) {
            if ($excludeProject && $item->getId() == $excludeProject->getId())
                continue;

            $info = $this->resolveProjectEmail($item, $item->getConfiguration(), trim((string) $this->config->get('head_email_property')));
            if ($info && mb_strtolower($info['email']) === $email)
                return $item;
        }

        return null;
    }

    private function getProjectFieldNames() {
        $fields = array();
        $configured = (string) $this->config->get('project_field_name');
        foreach (preg_split('/[\r\n,]+/', $configured) as $fieldName) {
            $fieldName = trim($fieldName);
            if ($fieldName !== '')
                $fields[] = $fieldName;
        }

        return array_unique($fields);
    }

    private function resolveConfiguredList() {
        $configured = trim((string) $this->config->get('project_list'));
        if ($configured === '') {
            $this->log('configured project list is empty');
            return null;
        }

        if (preg_match('/^list-(\d+)$/i', $configured, $matches))
            return DynamicList::lookup((int) $matches[1]);

        if (ctype_digit((string) $configured))
            return DynamicList::lookup((int) $configured);

        $list = DynamicList::objects()->filter(array('name' => $configured))->first();
        if ($list)
            return $list;

        $configuredLower = mb_strtolower($configured);
        foreach (DynamicList::objects()->all() as $candidate) {
            if (mb_strtolower((string) $candidate->getName()) === $configuredLower)
                return $candidate;
        }

        foreach (DynamicList::objects()->all() as $candidate) {
            if (mb_stripos((string) $candidate->getName(), $configured) !== false)
                return $candidate;
        }

        $this->log('configured project list not found: ' . var_export($configured, true));
        return null;
    }

    private function resolveHeadUser($project) {
        $configuration = $project->getConfiguration();

        $property = trim((string) $this->config->get('head_email_property'));
        $emailInfo = $this->resolveProjectEmail($project, $configuration, $property);
        $email = $emailInfo ? $emailInfo['email'] : '';

        if ($email === '')
            return null;

        if (($user = User::lookupByEmail($email)))
            return $user;

        $name = $this->resolveHeadName($configuration, $email);
        $user = User::fromVars(array(
            'email' => $email,
            'name' => $name,
        ), true);

        if ($user)
            $this->log('created collaborator user ' . $email . ' for project #' . $project->getId());

        return $user;
    }

    private function resolveProjectEmail($project, $configuration, $property) {
        if ($property !== '') {
            $value = $this->getConfigurationValue($configuration, $property);
            if ($value !== '' && Validator::is_email($value))
                return array('key' => $property, 'email' => $value);
        }

        if (method_exists($project, 'getConfigurationForm')) {
            $form = $project->getConfigurationForm();
            if ($form) {
                foreach ($form->getFields() as $field) {
                    foreach ($this->getConfigurationKeysForField($field) as $key) {
                        $value = $this->getConfigurationValue($configuration, $key);
                        if ($value !== '' && Validator::is_email($value))
                            return array('key' => $key, 'email' => $value);
                    }
                }
            }
        }

        foreach ($configuration as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '' && Validator::is_email($value))
                return array('key' => $key, 'email' => $value);
        }

        return null;
    }

    private function getConfigurationValue($configuration, $key) {
        if (isset($configuration[$key]))
            return trim((string) $configuration[$key]);

        if (is_numeric($key)) {
            $numericKey = (int) $key;
            if (isset($configuration[$numericKey]))
                return trim((string) $configuration[$numericKey]);
        }

        return '';
    }

    private function getConfigurationKeysForField($field) {
        $keys = array((string) $field->get('id'));

        if ($field->get('name'))
            $keys[] = (string) $field->get('name');

        if (method_exists($field, 'getLabel') && $field->getLabel())
            $keys[] = (string) $field->getLabel();

        return array_unique($keys);
    }

    private function addSystemCollaboratorEvent($ticket, $user, $action, $extra=array()) {
        if (!$ticket || !$ticket->getThread() || !$user)
            return false;

        $data = array(
            $action => array(
                $user->getId() => array('name' => $user->getName()),
            ),
        );

        if (is_array($extra))
            $data = array_merge($data, $extra);

        $event = new ThreadEvent(array(
            'username' => 'SYSTEM',
            'timestamp' => SqlFunction::NOW(),
            'event_id' => Event::getIdByName('collab'),
            'data' => JsonDataEncoder::encode($data),
        ));

        $ticket->getThread()->getEvents()->add($event, false);
        return $event->save();
    }

    private function resolveHeadName($configuration, $email) {
        foreach (array('head_name', 'head_fullname', 'head_user_name', 'name') as $key) {
            if (!empty($configuration[$key]))
                return trim((string) $configuration[$key]);
        }

        $parts = explode('@', $email, 2);
        return trim(str_replace(array('.', '_', '-'), ' ', $parts[0]));
    }

    private function log($message) {
        error_log('[projectheadsync] ' . $message);
    }
}
