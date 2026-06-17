<?php
require_once INCLUDE_DIR . 'class.plugin.php';

class ProjectHeadSyncConfig extends PluginConfig {
    function getOptions() {
        return array(
            'projectheadsync' => new SectionBreakField(array(
                'label' => 'Configuration',
            )),
            'enabled' => new BooleanField(array(
                'label' => 'Enable project head collaborator sync',
                'default' => true,
            )),
            'suppress_collaborator_notifications' => new BooleanField(array(
                'label' => 'Add project heads as inactive collaborators',
                'default' => false,
                'hint' => 'When enabled, project heads are added to the ticket for web access but are marked inactive so they do not receive collaborator email notifications.',
            )),
            'debug' => new BooleanField(array(
                'label' => 'Enable debug logs',
                'default' => false,
                'hint' => 'Write project head sync activity to the PHP error log when enabled.',
            )),
            'project_field_name' => new TextboxField(array(
                'label' => 'Ticket project field name',
                'required' => true,
                'default' => 'project',
                'configuration' => array('size' => 40, 'length' => 100, 'autocomplete' => 'off'),
                'hint' => 'Name of the ticket form field that stores the project selection. Use a comma-separated list to support more than one project field.',
            )),
            'project_list' => new TextboxField(array(
                'label' => 'Project list id or name',
                'required' => true,
                'default' => 'Projects',
                'configuration' => array('size' => 40, 'length' => 100, 'autocomplete' => 'off'),
                'hint' => 'Use the list id or the exact list name used for projects.',
            )),
            'head_email_property' => new TextboxField(array(
                'label' => 'Project head email property name',
                'required' => true,
                'default' => 'head_email',
                'configuration' => array('size' => 40, 'length' => 100, 'autocomplete' => 'off'),
                'hint' => 'Property key stored on the selected list item that holds the head email.',
            )),
            'logging_note' => new SectionBreakField(array(
                'label' => 'Ticket UI Logging Enhancement',
            )),
            'logging_info' => new TextboxField(array(
                'label' => 'For best logging in ticket UI',
                'required' => false,
                'configuration' => array('size' => 60, 'length' => 255, 'disabled' => true),
                'hint' => 'To see detailed project head sync events in the ticket timeline, update your class.thread.php file. Download the enhanced version from: https://github.com/jtsoukalas/dockerized-osticket/blob/main/plugins/projectheadsync/class.thread.php.UPDATED',
            )),
            'email_variables_note' => new SectionBreakField(array(
                'label' => 'Email Template Custom Variables',
            )),
            'email_variables_info' => new TextboxField(array(
                'label' => 'Available template variables',
                'required' => false,
                'configuration' => array('size' => 60, 'rows' => 8, 'disabled' => true),
                'hint' => 'Use these variables in your email templates: {{is_project_head}} (boolean), {{ticket.project_head_role}} (role description), {{ticket.project_head_future_notifications}} (notification preference text), {{project_head_message}} (HTML message), {{recipient.ticket_link}} (ticket link), {{ticket_owner_name}} (owner name), {{ticket_first_message}} (first message on creation only).',
            )),            
        );
    }
}
