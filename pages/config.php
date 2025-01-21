<?php

use FriendsOfRedaxo\SymfonyMailer\RexSymfonyMailer;
use Symfony\Component\Mime\Email;

$addon = rex_addon::get('symfony_mailer');

// --- Funktionen für Testausgaben ---
function outputTestResult($message, $success = true, $debug = null)
{
    if ($success) {
        echo rex_view::success($message);
    } else {
        $error = $message;
        if (isset($debug) && !empty($debug)) {
            $error .= '<br><br><strong>' . rex_i18n::msg('debug_info') . ':</strong><br>';
            $error .= '<pre class="rex-debug">' . rex_escape(print_r($debug, true)) . '</pre>';
        }
        echo rex_view::error($error);
    }
}

// Handle test connection
if (rex_post('test_connection', 'boolean')) {
    try {
        $mailer = new RexSymfonyMailer();
        $result = $mailer->testConnection();
        
        outputTestResult($result['message'], $result['success'], $result['debug'] ?? null);
    } catch (\Exception $e) {
        outputTestResult($e->getMessage(), false);
    }
}

// Test IMAP connection
if (rex_post('test_imap', 'boolean')) {
    if (!$addon->getConfig('imap_archive')) {
        outputTestResult($addon->i18n('imap_not_enabled'), false);
    } else {
        try {
            $host = $addon->getConfig('imap_host');
            $port = $addon->getConfig('imap_port', 993);
            $username = $addon->getConfig('imap_username');
            $password = $addon->getConfig('imap_password');
            $folder = $addon->getConfig('imap_folder', 'Sent');
            
            $mailbox = sprintf('{%s:%d/imap/ssl}%s', $host, $port, $folder);
            
            // Set timeout for the connection attempt
            imap_timeout(IMAP_OPENTIMEOUT, 10);
            
            // Try to connect
            if ($connection = @imap_open($mailbox, $username, $password)) {
                // Get mailbox info for additional debug data
                $check = imap_check($connection);
                $folders = imap_list($connection, sprintf('{%s:%d}', $host, $port), '*');
                $status = imap_status($connection, $mailbox, SA_ALL);
                
                $debug = [
                    'Connection' => 'Success',
                    'Mailbox' => $mailbox,
                    'Available folders' => $folders,
                    'Messages in folder' => $check->Nmsgs,
                    'Folder status' => [
                        'messages' => $status->messages,
                        'recent' => $status->recent,
                        'unseen' => $status->unseen
                    ]
                ];
                
                imap_close($connection);
                
                outputTestResult(
                    $addon->i18n('imap_connection_success'),
                    true,
                    $debug
                );
            } else {
                $error = $addon->i18n('imap_connection_error') . '<br>' . imap_last_error();
                outputTestResult($error, false);
            }
        } catch (\Exception $e) {
            outputTestResult($addon->i18n('imap_connection_error') . '<br>' . $e->getMessage(), false);
        }
    }
}

// Handle test mail
if (rex_post('test_mail', 'boolean')) {
    if ('' == $addon->getConfig('from') || '' == $addon->getConfig('test_address')) {
        outputTestResult($addon->i18n('test_mail_no_addresses'), false);
    } else {
        try {
            $mailer = new RexSymfonyMailer();
            
            $email = $mailer->createEmail();
            $email->to($addon->getConfig('test_address'));
            $email->subject($addon->i18n('test_mail_default_subject'));
            
            // Build test mail body with debug info
            $body = $addon->i18n('test_mail_greeting') . "\n\n";
            $body .= $addon->i18n('test_mail_body', rex::getServerName()) . "\n\n";
            $body .= str_repeat('-', 50) . "\n\n";
            $body .= 'Server: ' . rex::getServerName() . "\n";
            $body .= 'Domain: ' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '-') . "\n";
            $body .= 'Mailer: Symfony Mailer' . "\n";
            $body .= 'Host: ' . $addon->getConfig('host') . "\n";
            $body .= 'Port: ' . $addon->getConfig('port') . "\n";
            $body .= 'Security: ' . ($addon->getConfig('security') ?: 'none') . "\n";
            
            if ($addon->getConfig('debug') > 0) {
                $body .= 'Debug Level: ' . $addon->getConfig('debug') . "\n";
            }
            
            $email->text($body);
            
            if ($mailer->send($email)) {
                outputTestResult($addon->i18n('test_mail_sent', rex_escape($addon->getConfig('test_address'))), true);
            } else {
                $debugInfo = $mailer->getDebugInfo();
                $error = $addon->i18n('test_mail_error');
                 outputTestResult($error, false, $debugInfo);
            }
            
        } catch (\Exception $e) {
             outputTestResult($addon->i18n('test_mail_error') . '<br>' . $e->getMessage(), false);
        }
    }
}

// Setup config form
$form = rex_config_form::factory('symfony_mailer');

// SMTP Settings Fieldset
$form->addFieldset('SMTP Settings');

$field = $form->addTextField('from');
$field->setLabel($addon->i18n('sender_email'));
$field->setNotice($addon->i18n('sender_email_notice'));

$field = $form->addTextField('test_address');
$field->setLabel($addon->i18n('test_address'));
$field->setNotice($addon->i18n('test_address_notice'));

$field = $form->addTextField('name');
$field->setLabel($addon->i18n('sender_name'));

$field = $form->addTextField('host');
$field->setLabel($addon->i18n('smtp_host'));

$field = $form->addTextField('port');
$field->setLabel($addon->i18n('smtp_port'));
$field->setNotice($addon->i18n('smtp_port_notice'));

$field = $form->addSelectField('security');
$field->setLabel($addon->i18n('smtp_security'));
$select = $field->getSelect();
$select->addOption($addon->i18n('smtp_security_none'), '');
$select->addOption('TLS', 'tls');
$select->addOption('SSL', 'ssl');

$field = $form->addCheckboxField('auth');
$field->setLabel($addon->i18n('smtp_auth'));
$field->addOption($addon->i18n('smtp_auth_enabled'), 1);

$field = $form->addTextField('username');
$field->setLabel($addon->i18n('smtp_username'));

$field = $form->addTextField('password');
$field->setLabel($addon->i18n('smtp_password'));
$field->getAttributes()['type'] = 'password';

$field = $form->addSelectField('debug');
$field->setLabel($addon->i18n('smtp_debug'));
$select = $field->getSelect();
$select->addOption($addon->i18n('smtp_debug_disabled'), 0);
$select->addOption($addon->i18n('smtp_debug_client'), 1);
$select->addOption($addon->i18n('smtp_debug_server'), 2);
$select->addOption($addon->i18n('smtp_debug_connection'), 3);
$select->addOption($addon->i18n('smtp_debug_lowlevel'), 4);
$field->setNotice($addon->i18n('smtp_debug_info'));

// Log and Archive Settings Fieldset
$form->addFieldset('Log & Archive');

$field = $form->addSelectField('logging');
$field->setLabel($addon->i18n('logging'));
$select = $field->getSelect();
$select->addOption($addon->i18n('log_disabled'), 0);
$select->addOption($addon->i18n('log_errors'), 1);
$select->addOption($addon->i18n('log_all'), 2);

$field = $form->addCheckboxField('archive');
$field->setLabel($addon->i18n('archive_emails'));
$field->addOption($addon->i18n('archive_enabled'), 1);

// IMAP Archive Settings Fieldset
$form->addFieldset('IMAP Archive');

$field = $form->addCheckboxField('imap_archive');
$field->setLabel($addon->i18n('imap_archive'));
$field->addOption($addon->i18n('imap_archive_enabled'), 1);

$field = $form->addTextField('imap_host');
$field->setLabel($addon->i18n('imap_host'));

$field = $form->addTextField('imap_port');
$field->setLabel($addon->i18n('imap_port'));
$field->setNotice($addon->i18n('imap_port_notice'));

$field = $form->addTextField('imap_username');
$field->setLabel($addon->i18n('imap_username'));

$field = $form->addTextField('imap_password');
$field->setLabel($addon->i18n('imap_password'));
$field->getAttributes()['type'] = 'password';

$field = $form->addTextField('imap_folder');
$field->setLabel($addon->i18n('imap_folder'));
$field->setNotice($addon->i18n('imap_folder_notice'));

// Output form
$fragment = new rex_fragment();
$fragment->setVar('class', 'col-md-8', false); // Use col-md-8 for wider column
$fragment->setVar('title', $addon->i18n('configuration'), false);
$fragment->setVar('body', $form->get(), false);
$content = $fragment->parse('core/page/section.php');

// Test panel
$testContent = '
<form action="' . rex_url::currentBackendPage() . '" method="post">
    <div class="alert alert-info">
        ' . $addon->i18n('test_info') . '
    </div>
    
    <fieldset>
        <legend>' . $addon->i18n('smtp_test') . '</legend>
        <div class="form-group">
            <button type="submit" name="test_connection" value="1" class="btn btn-block btn-primary">' . $addon->i18n('test_connection') . '</button>
        </div>
        <div class="form-group">
            <button type="submit" name="test_mail" value="1" class="btn btn-block btn-primary">' . $addon->i18n('test_mail_send') . '</button>
        </div>
    </fieldset>

    <fieldset class="imap-test">
        <legend>' . $addon->i18n('imap_test') . '</legend>
        <div class="form-group">
            <button type="submit" name="test_imap" value="1" class="btn btn-block btn-primary">' . $addon->i18n('test_imap_connection') . '</button>
        </div>
    </fieldset>
</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'col-md-4', false); // Use col-md-4 for smaller sidebar
$fragment->setVar('title', $addon->i18n('test_title'), false);
$fragment->setVar('body', $testContent, false);
$sidebar = $fragment->parse('core/page/section.php');

// Output complete page
echo '<div class="row">' . $content . $sidebar . '</div>';
?>
