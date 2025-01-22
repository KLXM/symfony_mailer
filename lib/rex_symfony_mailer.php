<?php

namespace FriendsOfRedaxo\SymfonyMailer;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use rex;
use rex_addon;
use rex_path;
use rex_i18n;
use rex_log_file;
use rex_file;
use rex_dir;

class RexSymfonyMailer
{
    private Mailer $mailer;
    private string $charset;
    private string $fromAddress;
    private string $fromName;
    private bool $archive;
    private bool $imapArchive;
    private array $debugInfo = [];
    private bool $debug;
    
    /**
     * @var array<string, mixed>
     */
     private array $smtpSettings = [];

    /**
     * @var array<string, mixed>
     */
    private array $imapSettings = [];


    public function __construct()
    {
        $addon = rex_addon::get('symfony_mailer');

        $this->fromAddress = $addon->getConfig('from');
        $this->fromName = $addon->getConfig('name');
        $this->charset = $addon->getConfig('charset', 'utf-8');
        $this->archive = (bool)$addon->getConfig('archive', false);
        $this->imapArchive = (bool)$addon->getConfig('imap_archive', false);
        $this->debug = (bool)$addon->getConfig('debug', false);
        
        $this->smtpSettings = [
            'host' => $addon->getConfig('host'),
            'port' => $addon->getConfig('port'),
            'security' => $addon->getConfig('security'),
            'auth' => $addon->getConfig('auth'),
            'username' => $addon->getConfig('username'),
            'password' => $addon->getConfig('password'),
        ];

        $this->imapSettings = [
            'host' => $addon->getConfig('imap_host'),
            'port' => $addon->getConfig('imap_port', 993),
            'username' => $addon->getConfig('imap_username'),
            'password' => $addon->getConfig('imap_password'),
            'folder' => $addon->getConfig('imap_folder', 'Sent')
        ];
        
        $this->initializeMailer();
    }

    private function initializeMailer(): void
    {
        $dsn = $this->buildDsn();
        try {
            $transport = Transport::fromDsn($dsn);
            
            if ($this->debug && $transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                $transport->setStream(new \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream());
                $transport->getStream()->setDebug(true);
            }
            
            $this->mailer = new Mailer($transport);
        } catch (\Exception $e) {
            $this->debugInfo['error'] = $e->getMessage();
            $this->debugInfo['trace'] = $e->getTraceAsString();
            throw new \RuntimeException('Failed to initialize mailer: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $smtpSettings
     */
    private function buildDsn(array $smtpSettings = []): string
    {
        $settings = empty($smtpSettings) ? $this->smtpSettings : $smtpSettings;

        $host = $settings['host'];
        $port = $settings['port'];
        $security = $settings['security'];
        $auth = $settings['auth'];
        $username = $settings['username'];
        $password = $settings['password'];

        $dsn = 'smtp://';

        if ($auth && $username && $password) {
            $dsn .= urlencode($username) . ':' . urlencode($password) . '@';
        }

        $dsn .= $host . ':' . $port;

        $options = [];
        if ($security) {
            $options['transport'] = 'smtp';
            $options['encryption'] = $security;
        }

        if (!empty($options)) {
            $dsn .= '?' . http_build_query($options);
        }

        return $dsn;
    }

    /**
     * @return array<string,mixed>
     */
    public function testConnection(array $smtpSettings = []): array
    {
        try {
            $transport = Transport::fromDsn($this->buildDsn($smtpSettings));
            
            // Start with debug output capture if debug is enabled
            if ($this->debug && $transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                $transport->setStream(new \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream());
                $transport->getStream()->setDebug(true);
                ob_start();
                $transport->start();
                $this->debugInfo['smtp_debug'] = ob_get_clean();
            } else {
                $transport->start();
            }

            return [
                'success' => true,
                'message' => rex_i18n::msg('symfony_mailer_test_connection_success'),
                'debug' => $this->debug ? $this->debugInfo : null
            ];

        } catch (\Exception $e) {
            $this->debugInfo['error'] = $e->getMessage();
            $this->debugInfo['trace'] = $e->getTraceAsString();
            
            return [
                'success' => false,
                'message' => rex_i18n::msg('symfony_mailer_test_connection_error', $e->getMessage()),
                'debug' => $this->debugInfo
            ];
        }
    }

    public function createEmail(): Email
    {
        $email = new Email();
        $email->from(new Address($this->fromAddress, $this->fromName));
        $email->charset = $this->charset;
        
        return $email;
    }

    /**
     * @param array<string, mixed> $smtpSettings
     */
    public function send(Email $email, array $smtpSettings = [], string $imapFolder = ''): bool
    {
        $mailer = $this->mailer;
        if (!empty($smtpSettings)) {
            $dsn = $this->buildDsn($smtpSettings);
            try {
                $transport = Transport::fromDsn($dsn);
                if ($this->debug && $transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                    $transport->setStream(new \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream());
                    $transport->getStream()->setDebug(true);
                }
                $mailer = new Mailer($transport);
            } catch (\Exception $e) {
                $this->debugInfo['error'] = $e->getMessage();
                $this->log('ERROR', $email, $e->getMessage());
                return false;
            }
        }
       
        try {
            if ($this->debug) {
                ob_start();
                $mailer->send($email);
                $this->debugInfo['smtp_debug'] = ob_get_clean();
            } else {
                $mailer->send($email);
            }

            if ($this->archive) {
                $this->archiveEmail($email);
            }

            if ($this->imapArchive) {
                $this->archiveToImap($email, $imapFolder);
            }

            $this->log('OK', $email);
            return true;

        } catch (TransportExceptionInterface $e) {
            $this->debugInfo['error'] = $e->getMessage();
            if ($this->debug) {
                $this->debugInfo['trace'] = $e->getTraceAsString();
            }
            $this->log('ERROR', $email, $e->getMessage());
            return false;
        }
    }
    
    private function archiveEmail(Email $email): void
    {
        $dir = self::getArchiveFolder() . '/' . date('Y') . '/' . date('m');
        if (!is_dir($dir)) {
            rex_dir::create($dir);
        }

        $count = 1;
        $filename = date('Y-m-d_H_i_s') . '.eml';
        while (is_file($dir . '/' . $filename)) {
            $filename = date('Y-m-d_H_i_s') . '_' . (++$count) . '.eml';
        }

        rex_file::put($dir . '/' . $filename, $email->toString());
    }

    private function archiveToImap(Email $email, string $folder = ''): void
    {
        $settings = $this->imapSettings;
        if (!empty($folder)) {
            $settings['folder'] = $folder;
        }

        $host = $settings['host'];
        $port = $settings['port'];
        $username = $settings['username'];
        $password = $settings['password'];
        $folder = $settings['folder'];

        $mailbox = sprintf('{%s:%d/imap/ssl}%s', $host, $port, $folder);

        if ($connection = imap_open($mailbox, $username, $password)) {
            imap_append($connection, $mailbox, $email->toString());
            imap_close($connection);
        }
    }

    private function log(string $status, Email $email, string $error = ''): void
    {
        $addon = rex_addon::get('symfony_mailer');
        if (!$addon->getConfig('logging')) {
            return;
        }

        $fromAddresses = [];
        foreach ($email->getFrom() as $address) {
            $fromAddresses[] = $address->toString();
        }

        $toAddresses = [];
        foreach ($email->getTo() as $address) {
            $toAddresses[] = $address->toString();
        }

        $log = rex_log_file::factory(self::getLogFile());
        $data = [
            $status,
            implode(', ', $fromAddresses),
            implode(', ', $toAddresses),
            $email->getSubject(),
            $error
        ];
        $log->add($data);
    }

    /**
     * @return array<string,mixed>
     */
    public function getDebugInfo(): array
    {
        return $this->debugInfo;
    }

    public static function getArchiveFolder(): string
    {
        return rex_path::addonData('symfony_mailer', 'mail_archive');
    }

    public static function getLogFile(): string
    {
        return rex_path::log('symfony_mailer.log');
    }
}
