<?php
require 'api.inc.php';
require_once INCLUDE_DIR."class.dispatcher.php";

$dispatcher = patterns('',
    url_post("^/tickets\.(?P<format>xml|json|email)$", array('tickets.php:TicketApiController','create')),
    url_get("^/tickets/list\.(?P<format>xml|json)$", array('tickets.php:TicketReplyApiController','getList')),
    url_post("^/tickets/(?P<id>\d+)/reply(?:\.(?P<format>xml|json))?$", array('tickets.php:TicketReplyApiController','reply')),
    url_post("^/tickets/(?P<id>\d+)/assign(?:\.(?P<format>json))?$", array('tickets.php:TicketAssignApiController','assign')),
    url_get("^/tickets/(?P<id>\d+)\.(?P<format>xml|json)$", array('tickets.php:TicketReplyApiController','get')),
    url_get("^/tickets/(?P<id>\d+)/attachments/(?P<file_id>\d+)$", array('tickets.php:TicketReplyApiController','downloadAttachment')),

    // Topics
    url_get("^/tickets/topics(?:\.(?P<format>xml|json))?$", array('tickets.php:TicketReplyApiController', 'getTopics')),

    // Departments
    url_get("^/tickets/departments(?:\.(?P<format>xml|json))?$", array('tickets.php:TicketReplyApiController', 'getDepartments')),

    // Teams
    url_get("^/tickets/teams(?:\.(?P<format>json))?$", array('tickets.php:TicketReplyApiController', 'getTeams')),

    // Lists
    url_get("^/lists(?:\.(?P<format>json))?$", array('lists.php:ListsApiController','getList')),
    url_post("^/lists/(?P<id>\d+)(?:\.(?P<format>json))?$", array('lists.php:ListsApiController','create')),
    
    // Filters
    url_get("^/filters(?:\.(?P<format>json))?$", array('filters.php:FilterApiController','getList')),
    url_post("^/filters(?:\.(?P<format>json))?$", array('filters.php:FilterApiController','create')),
    url_put("^/filters/(?P<id>\d+)$", array('filters.php:FilterApiController','update')),
    url_delete("^/filters/(?P<id>\d+)(?:\.(?P<format>json))?$", array('filters.php:FilterApiController','delete')),
    url_get("^/filters/fields$", array('filters.php:FilterApiController','getFields')),

    url('^/tasks/', patterns('',
        url_post("^cron$", array('api.cron.php:CronApiController', 'execute'))
    ))
);

Signal::send('api', $dispatcher);
print $dispatcher->resolve(Osticket::get_path_info());
?>