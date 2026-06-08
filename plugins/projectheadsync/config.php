<?php
require_once INCLUDE_DIR . 'class.plugin.php';

class ProjectHeadSyncConfig extends PluginConfig {
    function getOptions() {
        return array(
            'projectheadsync' => new SectionBreakField(array(
                'label' => 'Project Head Collaborator Sync',
            )),
            'enabled' => new BooleanField(array(
                'label' => 'Enable project head collaborator sync',
                'default' => true,
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
        );
    }
}
