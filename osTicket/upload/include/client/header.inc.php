<?php
$title=($cfg && is_object($cfg) && $cfg->getTitle())
    ? $cfg->getTitle() : 'osTicket :: '.__('Support Ticket System');
$signin_url = ROOT_PATH . "login.php"
    . ($thisclient ? "?e=".urlencode($thisclient->getEmail()) : "");
$signout_url = ROOT_PATH . "logout.php?auth=".$ost->getLinkToken();

header("Content-Type: text/html; charset=UTF-8");
header(
    "Content-Security-Policy: " .
    "frame-ancestors " . $cfg->getAllowIframes() . "; " .
    "img-src 'self' data: https://www.gravatar.com http://www.gravatar.com; " .
    "script-src 'self' " .
        "https://cdn.quilljs.com " .
        "https://cdn.jsdelivr.net " . 
        "https://www.google.com " .
        "https://www.gstatic.com " .
        "https://www.recaptcha.net " .
        "https://challenges.cloudflare.com " .
        "'unsafe-inline'; " .
    "style-src 'self' 'unsafe-inline' " .
        "https://cdn.quilljs.com " .
        "https://cdn.jsdelivr.net " .
        "https://fonts.googleapis.com; " . // ALLOWS GOOGLE FONT CSS
    "font-src 'self' data: https://fonts.gstatic.com; " . // ALLOWS THE ACTUAL FONT FILES
    "frame-src " .
        "https://www.google.com " .
        "https://www.recaptcha.net " .
        "https://challenges.cloudflare.com; " .
    "object-src 'none'"
);

if (($lang = Internationalization::getCurrentLanguage())) {
    $langs = array_unique(array($lang, $cfg->getPrimaryLanguage()));
    $langs = Internationalization::rfc1766($langs);
    header("Content-Language: ".implode(', ', $langs));
}
?>
<!DOCTYPE html>
<html<?php
if ($lang
        && ($info = Internationalization::getLanguageInfo($lang))
        && (@$info['direction'] == 'rtl'))
    echo ' dir="rtl" class="rtl"';
if ($lang) {
    echo ' lang="' . $lang . '"';
}

// Dropped IE Support Warning
if (osTicket::is_ie())
    $ost->setWarning(__('osTicket no longer supports Internet Explorer.'));
?>>
<head>
    <style>
        :root {
            /* Default osTicket Blue */
            <?php 
            $h_col = '#004976';
            $f_col = '#004976';
            if (isset($ost) && $ost && ($c = $ost->getConfig())) {
                $h_col = $c->get('header_color', '#004976');
                $f_col = $c->get('footer_color', '#004976');
            }
            echo "--header-bg: " . $h_col . ";";
            echo "--footer-bg: " . $f_col . ";";
            ?>
        }

        #header { background: var(--header-bg) !important; }
        #footer { background: var(--footer-bg) !important; }
        #banner { background-color: var(--header-bg) !important; }
    </style>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <title><?php echo Format::htmlchars($title); ?></title>
    <meta name="description" content="customer support platform">
    <meta name="keywords" content="osTicket, Customer support system, support ticket system">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
    html, body { max-width: 100vw; overflow-x: hidden; }
    #nav-toggle {
        display: none;
        background: none;
        border: none;
        font-size: 2em;
        color: #fff;
        position: absolute;
        right: 20px;
        top: 18px;
        z-index: 1001;
        cursor: pointer;
    }
    @media (max-width: 900px) {
        #nav-toggle { display: block; }
        #main-nav {
            display: none;
            position: absolute;
            top: 60px;
            right: 0;
            background: var(--header-bg, #004976);
            width: 100vw;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        #main-nav.open {
            display: block;
        }
        #main-nav ul.flush-right, #main-nav ul.flush-left {
            display: block;
            padding: 0;
            margin: 0;
        }
        #main-nav ul li {
            display: block;
            padding: 12px 20px;
            border-bottom: 1px solid #eee;
        }
        #main-nav ul li:last-child { border-bottom: none; }
    }
    @media (max-width: 600px) {
        #header, #footer, .form-header-main, .form-section, .form-actions, .profile-page, .register-card, .auth-card, .access-card, .ticket-detail-container, .topic-grid, .span8, .span4, .sidebar, .row {
            padding: 8px !important;
            margin: 0 !important;
            width: 100% !important;
            box-sizing: border-box;
        }
        .topic-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
        .ticket-header-row, .form-header-main, .profile-header, .register-card, .auth-card, .access-card { font-size: 1em !important; }
        .btn-select-only, button, input, select, textarea { font-size: 1em !important; padding: 8px !important; }
        h1, h2, h3 { font-size: 1.2em !important; }
    }
    </style>
	<link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/modern/base.css">
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/modern/layout.css">
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/modern/forms.css">
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/quill-modern.css?8d38b06">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

    <?php if (strpos($_SERVER['SCRIPT_NAME'], 'login.php') !== false) { ?>
        <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/modern/auth.css">
    <?php } elseif (strpos($_SERVER['SCRIPT_NAME'], 'view.php') !== false) { ?>
        <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/modern/access.css">
    <?php } elseif (strpos($_SERVER['SCRIPT_NAME'], 'account.php') !== false) { ?>
        <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/modern/auth.css">
    <?php } ?>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/flags.css?8d38b06"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/select2.min.css?8d38b06"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/font-awesome.min.css?8d38b06"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>assets/default/css/notification.css"/>


    <!-- Favicons -->
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-16x16.png" sizes="16x16" />
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jquery-3.7.0.min.js?8d38b06"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jquery-ui-1.13.2.custom.min.js?8d38b06"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jquery-ui-timepicker-addon.js?8d38b06"></script>
    <script src="<?php echo ROOT_PATH; ?>js/osticket.js?8d38b06"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/filedrop.field.js?8d38b06"></script>
    <script src="<?php echo ROOT_PATH; ?>js/bootstrap-typeahead.js?8d38b06"></script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/quill-osticket.js?8d38b06"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/select2.min.js?8d38b06"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>assets/default/js/notification.js"></script>
    <?php
    if($ost && ($headers=$ost->getExtraHeaders())) {
        echo "\n\t".implode("\n\t", $headers)."\n";
    }

    if (($all_langs = Internationalization::getConfiguredSystemLanguages())
        && (count($all_langs) > 1)
    ) {
        $langs = Internationalization::rfc1766(array_keys($all_langs));
        $qs = array();
        parse_str($_SERVER['QUERY_STRING'], $qs);
        foreach ($langs as $L) {
            $qs['lang'] = $L; ?>
        <link rel="alternate" href="//<?php echo $_SERVER['HTTP_HOST'] . htmlspecialchars($_SERVER['REQUEST_URI']); ?>?<?php
            echo http_build_query($qs); ?>" hreflang="<?php echo $L; ?>" />
<?php
        } ?>
        <link rel="alternate" href="//<?php echo $_SERVER['HTTP_HOST'] . htmlspecialchars($_SERVER['REQUEST_URI']); ?>"
            hreflang="x-default" />
<?php
    }
    ?>
</head>
<body>
    <div id="container">
        <?php
        if($ost->getError())
            echo sprintf('<div class="error_bar">%s</div>', $ost->getError());
        elseif($ost->getWarning())
            echo sprintf('<div class="warning_bar">%s</div>', $ost->getWarning());
        elseif($ost->getNotice())
            echo sprintf('<div class="notice_bar">%s</div>', $ost->getNotice());
        ?>
        <div id="header">
            <a class="pull-left" id="logo" href="<?php echo ROOT_PATH; ?>index.php"
            title="<?php echo __('Support Center'); ?>">
                <span class="valign-helper"></span>
                <img src="<?php echo ROOT_PATH; ?>logo.php?lang=<?php echo urlencode($lang ?: 'en_US'); ?>" border=0 alt="<?php
                echo $ost->getConfig()->getTitle(); ?>">
            </a>
            <button id="nav-toggle" aria-expanded="false" aria-controls="main-nav">☰</button>
            <nav id="main-nav" role="navigation">
                <ul class="flush-left">
                    <!-- logo side; intentionally left empty to keep nav on the right -->
                </ul>
                <ul class="flush-right">
                    <?php
                    // Render primary nav items on the right (short labels)
                    if($nav && ($navs=$nav->getNavLinks()) && is_array($navs)){
                        $short_labels = array(
                            'home' => 'Home',
                            'kb'   => 'KB',
                            'new'  => 'New ticket',
                            'tickets' => 'Tickets',
                            'status' => 'Status'
                        );
                        foreach($navs as $name =>$nav) {
                            if (isset($short_labels[$name])) {
                                $candidate = $short_labels[$name];
                                $translated = __($candidate);
                                // If translation for the short label isn't available, fall back to the
                                // already-localized full description provided by the nav.
                                $label = ($translated === $candidate && $nav['desc']) ? $nav['desc'] : $translated;
                            } else {
                                $label = $nav['desc'];
                            }
                            // Show ticket count for Tickets nav if user is logged in
                            if ($name === 'tickets' && $thisclient && $thisclient->isValid() && !$thisclient->isGuest()) {
                                $ticketCount = 0;
                                if (class_exists('Ticket')) {
                                    $ticketCount = Ticket::objects()
                                        ->filter(array('user_id' => $thisclient->getId()))
                                        ->count();
                                }
                                // Remove Greek suffix if present (e.g., '(αιτημάτων)')
                                $label = preg_replace('/\s*\(.*?\)/u', '', $label);
                                $label = sprintf("%s (%d)", $label, $ticketCount);
                            }
                            echo sprintf('<li><a class="%s %s" href="%s">%s</a></li>%s',
                                $nav['active']?'active':'',$name,(ROOT_PATH.$nav['href']),Format::htmlchars($label),"\n");
                        }
                    }
                    ?>
                    <?php
                    if ($thisclient && is_object($thisclient) && $thisclient->isValid()
                        && !$thisclient->isGuest()) {
                        // Show Profile link first
                        echo sprintf('<li><a href="%s">%s</a></li>',
                            ROOT_PATH.'profile.php', __('Profile'));
                        // Show logged-in user's name with Sign Out button
                        echo '<li class="guest-cta">';
                        echo '<span class="guest-label">'.Format::htmlchars($thisclient->getName()).'</span>';
                        echo '<a class="signin-link" href="'.Format::htmlchars($signout_url).'">'.Format::htmlchars(__('Sign Out')).'</a>';
                        echo '</li>';
                    } elseif($nav) {
                        // Show Guest label with a prominent Sign In CTA under it when registration is public
                        if ($cfg->getClientRegistrationMode() == 'public') {
                            echo '<li class="guest-cta">';
                            echo '<span class="guest-label">'.Format::htmlchars(__('Guest User')).'</span>';
                            if ($cfg->getClientRegistrationMode() != 'disabled') {
                                echo '<a class="signin-link" href="'.Format::htmlchars($signin_url).'">'.Format::htmlchars(__('Sign In')).'</a>';
                            }
                            echo '</li>';
                        } else {
                            if ($cfg->getClientRegistrationMode() != 'disabled') {
                                echo sprintf('<li><a href="%s">%s</a></li>', $signin_url, __('Sign In'));
                            }
                        }

                        if ($thisclient && $thisclient->isValid() && $thisclient->isGuest()) {
                            echo sprintf('<li><a href="%s">%s</a></li>', $signout_url, __('Sign Out'));
                        }
                    }
                    ?>
                    <?php
                    // Render language flags in the header nav (if multiple languages configured)
                    if (($all_langs = Internationalization::getConfiguredSystemLanguages())
                        && (count($all_langs) > 1)
                    ) {
                        $qs = array();
                        parse_str($_SERVER['QUERY_STRING'], $qs);
                        foreach ($all_langs as $code=>$info) {
                            list($lang, $locale) = explode('_', $code);
                            $qs['lang'] = $code;
                            $flag = strtolower(($locale ?: $info['flag'] ?: $lang));
                            echo sprintf('<li class="lang"><a class="flag flag-%s" href="?%s" title="%s">&nbsp;</a></li>',
                                $flag, http_build_query($qs), Internationalization::getLanguageDescription($code));
                        }
                    }
                    ?>
                    </ul>
            </nav>
            <script type="text/javascript">
            (function(){
                var btn = document.getElementById('nav-toggle');
                var nav = document.getElementById('main-nav');
                if(!btn || !nav) return;
                btn.addEventListener('click', function(){
                    var open = nav.classList.toggle('open');
                    btn.setAttribute('aria-expanded', open? 'true':'false');
                });
            })();
            </script>
            <script type="text/javascript">
            // Highlight form fields that have server-side validation errors.
            (function(){
                function highlightErrors() {
                    var form = document.getElementById('ticketForm');
                    if (!form) return;
                    // Clear previous markers
                    form.querySelectorAll('.field-error').forEach(function(el){ el.classList.remove('field-error'); });
                    // For each .error message, find the nearest input/select/textarea or editor and mark it
                    form.querySelectorAll('.error').forEach(function(err){
                        var cell = err.closest('td') || err.parentElement;
                        if (!cell) return;
                        var field = cell.querySelector('input, select, textarea, .redactor-editor, .richtext');
                        if (field) field.classList.add('field-error');
                    });
                }
                if (document.readyState === 'loading')
                    document.addEventListener('DOMContentLoaded', highlightErrors);
                else
                    highlightErrors();

                // Re-run after AJAX updates (topic change loads dynamic form html)
                if (window.jQuery) {
                    (function($){
                        $(document).ajaxComplete(function(){ highlightErrors(); });
                    })(jQuery);
                }
            })();
            </script>
            <script type="text/javascript">
            // Toggle focus class for Redactor / richtext editors so outlines appear
            (function(){
                function findBox(el){
                    return el && (el.classList && el.classList.contains('redactor-editor') ? el.closest('.redactor-box') : el.closest && el.closest('.redactor-box'));
                }
                document.addEventListener('focusin', function(e){
                    var box = findBox(e.target) || (e.target && e.target.nodeName==='IFRAME' && e.target.closest && e.target.closest('.redactor-box'));
                    if(box) box.classList.add('focused');
                });
                document.addEventListener('focusout', function(e){
                    var box = findBox(e.target) || (e.target && e.target.nodeName==='IFRAME' && e.target.closest && e.target.closest('.redactor-box'));
                    if(box) box.classList.remove('focused');
                });
            })();
            </script>
        </div>
        <div id="content">
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
         <?php } ?>
