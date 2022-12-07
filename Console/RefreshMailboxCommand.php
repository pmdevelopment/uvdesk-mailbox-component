<?php

namespace Webkul\UVDesk\MailboxBundle\Console;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\MicrosoftApp;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\MicrosoftAccount;
use Webkul\UVDesk\CoreFrameworkBundle\Utils\Microsoft\Graph as MicrosoftGraph;
use Webkul\UVDesk\CoreFrameworkBundle\Services\MicrosoftIntegration;
use Webkul\UVDesk\MailboxBundle\Services\MailboxService;
use Webkul\UVDesk\MailboxBundle\Utils\IMAP;

class RefreshMailboxCommand extends Command
{
    private $endpoint;
    private $outlookEndpoint;

    public function __construct(ContainerInterface $container, EntityManagerInterface $entityManager, MicrosoftIntegration $microsoftIntegration, MailboxService $mailboxService)
    {
        $this->container = $container;
        $this->entityManager = $entityManager;
        $this->microsoftIntegration = $microsoftIntegration;
        $this->mailboxService = $mailboxService;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('uvdesk:refresh-mailbox');
        $this->setDescription('Check if any new emails have been received and process them into tickets');

        $this->addArgument('emails', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, "Email address of the mailboxes you wish to update");
        $this->addOption('timestamp', 't', InputOption::VALUE_REQUIRED, "Fetch messages no older than the given timestamp");
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->router = $this->container->get('router');
        $useSecureConnection = $this->isSecureConnectionAvailable();

        $this->router->getContext()->setHost($this->container->getParameter('uvdesk.site_url'));
        $this->router->getContext()->setScheme(false === $useSecureConnection ? 'http' : 'https');

        $this->endpoint = $this->router->generate('helpdesk_member_mailbox_notification', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->outlookEndpoint = $this->router->generate('helpdesk_member_outlook_mailbox_notification', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Sanitize emails
        $mailboxEmailCollection = array_map(function ($email) {
            return filter_var($email, FILTER_SANITIZE_EMAIL);
        }, $input->getArgument('emails'));
       
        // Stop execution if no valid emails have been specified
        if (empty($mailboxEmailCollection)) {
            if (false === $input->getOption('no-interaction')) {
                $output->writeln("\n <comment>No valid mailbox emails specified.</comment>\n");
            }

            return Command::INVALID;
        }

        // Process mailboxes
        $timestamp = new \DateTime(sprintf("-%u minutes", (int) ($input->getOption('timestamp') ?: 1440)));
        
        foreach ($mailboxEmailCollection as $mailboxEmail) {
            $output->writeln("\n# Retrieving mailbox configuration details for <info>$mailboxEmail</info>:\n");

            try {
                $mailbox = $this->container->get('uvdesk.mailbox')->getMailboxByEmail($mailboxEmail);

                if (false == $mailbox['enabled']) {
                    if (false === $input->getOption('no-interaction')) {
                        $output->writeln("  <comment>Error: Mailbox for email </comment><info>$mailboxEmail</info><comment> is not enabled.</comment>");
                    }
    
                    continue;
                } else if (empty($mailbox['imap_server'])) {
                    if (false === $input->getOption('no-interaction')) {
                        $output->writeln("  <comment>Error: No imap configurations defined for email </comment><info>$mailboxEmail</info><comment>.</comment>");
                    }
    
                    continue;
                }
            } catch (\Exception $e) {
                if (false == $input->getOption('no-interaction')) {
                    $output->writeln("  <comment>Error: Mailbox for email </comment><info>$mailboxEmail</info><comment> not found.</comment>");

                    // return Command::INVALID;
                }

                continue;
            }

            try {
                $imapConfiguration = IMAP\Configuration::guessTransportDefinition($mailbox['imap_server']);
    
                if ($imapConfiguration instanceof IMAP\Transport\SimpleTransportConfigurationInterface) {
                    $output->writeln("  <comment>Cannot fetch emails for mailboxes of type simple configuration.</comment>");
                } else if ($imapConfiguration instanceof IMAP\Transport\AppTransportConfigurationInterface) {
                    $microsoftApp = $this->entityManager->getRepository(MicrosoftApp::class)->findOneByClientId($mailbox['imap_server']['client']);

                    if (empty($microsoftApp)) {
                        $output->writeln("  <comment>No microsoft app was found for configured client id '" . $mailbox['imap_server']['client'] . "'.</comment>");

                        continue;
                    } else {
                        $microsoftAccount = $this->entityManager->getRepository(MicrosoftAccount::class)->findOneBy([
                            'email' => $mailbox['imap_server']['username'], 
                            'microsoftApp' => $microsoftApp, 
                        ]);

                        if (empty($microsoftAccount)) {
                            $output->writeln("  <comment>No microsoft account was found with email '" . $mailbox['imap_server']['username'] . "' for configured client id '" . $mailbox['imap_server']['client'] . "'.</comment>");

                            continue;
                        }
                    }

                    $this->refreshOutlookMailbox($microsoftApp, $microsoftAccount, $timestamp, $output, $mailbox);
                } else {
                    $this->refreshMailbox(
                        $mailbox['imap_server']['host'], 
                        $mailbox['imap_server']['username'], 
                        base64_decode($mailbox['imap_server']['password']), 
                        $timestamp, 
                        $output, 
                        $mailbox
                    );
                }
            } catch (\Exception $e) {
                $output->writeln("  <comment>An unexpected error occurred: " . $e->getMessage() . "</comment>");
            }
        }

        $output->writeln("");

        return Command::SUCCESS;
    }

    public function refreshMailbox($server_host, $server_username, $server_password, \DateTime $timestamp, OutputInterface $output, $mailbox)
    {
        $output->writeln("  - Establishing connection with mailbox");

        try {
            $imap = imap_open($server_host, $server_username, $server_password);
        } catch (\Exception $e) {
            $output->writeln("  - <fg=red>Failed to establish connection with mailbox</>");
            $output->writeln("\n  <comment>" . $e->getMessage() . "</comment>\n");
            
            $errorMessages = imap_errors();

            foreach ($errorMessages as $id => $errorMessage) {
                $output->writeln("  <comment>$id: $errorMessage</comment>");
            }

            return;
        }

        if ($imap) {
            $timeSpan = $timestamp->format('d F Y');
            $output->writeln("  - Fetching all emails since <comment>$timeSpan</comment>");

            $emailCollection = imap_search($imap, 'SINCE "' . $timestamp->format('d F Y') . '"');

            if (is_array($emailCollection)) {
                $emailCount = count($emailCollection);

                $output->writeln("  - Found a total of <info>$emailCount</info> emails in mailbox since <comment>$timeSpan</comment>");
                $output->writeln("\n  # Processing all found emails iteratively:");
                $output->writeln("\n    <bg=black;fg=bright-white>API</> <options=underscore>" . $this->endpoint . "</>\n");

                $counter = 1;

                foreach ($emailCollection as $id => $messageNumber) {
                    $output->writeln("    - <comment>Processing email</comment> <info>$counter</info> <comment>of</comment> <info>$emailCount</info>:");
                    
                    $message = imap_fetchbody($imap, $messageNumber, "");
                    list($response, $responseCode, $responseErrorMessage) = $this->parseInboundEmail($message, $output);

                    if ($responseCode == 200) {
                        $output->writeln("\n      <bg=green;fg=bright-white;options=bold>200</> " . $response['message'] . "\n");
                    } else {
                        if (!empty($responseErrorMessage)) {
                            $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>$responseErrorMessage</>\n");
                        } else {
                            $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>" . $response['message'] . "</>\n");
                        }
                    }
                    
                    if (true == $mailbox['deleted']) {
                        imap_delete($imap, $messageNumber);
                    }
                    
                    $counter++;
                }

                $output->writeln("  - <info>Mailbox refreshed successfully!</info>");

                if (true == $mailbox['deleted']) {
                    imap_expunge($imap);
                    imap_close($imap,CL_EXPUNGE);
                }
            }
        }

        return;
    }

    public function refreshOutlookMailbox($microsoftApp, $microsoftAccount, \DateTime $timestamp, OutputInterface $output, $mailbox)
    {
        $timeSpan = $timestamp->format('Y-m-d');
        $credentials = json_decode($microsoftAccount->getCredentials(), true);
        $redirectEndpoint = str_replace('http', 'https', $this->router->generate('uvdesk_member_core_framework_integrations_microsoft_apps_oauth_login', [], UrlGeneratorInterface::ABSOLUTE_URL));

        $filters = [
            'ReceivedDateTime' => [
                'operation' => '>', 
                'value' => $timeSpan, 
            ], 
        ];
        
        $response = MicrosoftGraph\Me::messages($credentials['access_token'], $filters);

        if (!empty($response['error'])) {
            if (!empty($response['error']['code']) && $response['error']['code'] == 'InvalidAuthenticationToken') {
                $tokenResponse = $this->microsoftIntegration->refreshAccessToken($microsoftApp, $credentials['refresh_token']);

                if (!empty($tokenResponse['access_token'])) {
                    $microsoftAccount->setCredentials(json_encode($tokenResponse));
    
                    $this->entityManager->persist($microsoftAccount);
                    $this->entityManager->flush();

                    $response = MicrosoftGraph\Me::messages($credentials['access_token'], $filters);
                } else {
                    $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>Failed to retrieve a valid access token.</>\n");

                    return;
                }
            } else {
                if (!empty($response['error']['code'])) {
                    $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>An unexpected api error occurred of type '" . $response['error']['code'] . "'.</>\n");
                } else {
                    $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>An unexpected api error occurred.</>\n");
                }

                return;
            }
        }


        if (!empty($response['value'])) {
            $emailCount = $response['@odata.count'] ?? 'NA';

            $output->writeln("  - Found a total of <info>$emailCount</info> emails in mailbox since <comment>$timeSpan</comment>");
            $output->writeln("\n  # Processing all found emails iteratively:");
            $output->writeln("\n    <bg=black;fg=bright-white>API</> <options=underscore>" . $this->outlookEndpoint . "</>\n");

            $counter = 1;

            foreach ($response['value'] as $message) {
                $output->writeln("    - <comment>Processing email</comment> <info>$counter</info> <comment>of</comment> <info>$emailCount</info>:");

                $detailedMessage = MicrosoftGraph\Me::message($message['id'], $credentials['access_token']);
                list($response, $responseCode, $responseErrorMessage) = $this->parseOutlookInboundEmail($detailedMessage, $output);

                if ($responseCode == 200) {
                    $output->writeln("\n      <bg=green;fg=bright-white;options=bold>200</> " . $response['message'] . "\n");
                } else {
                    if (!empty($responseErrorMessage)) {
                        $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>$responseErrorMessage</>\n");
                    } else {
                        $output->writeln("\n      <bg=red;fg=white;options=bold>ERROR</> <fg=red>" . $response['message'] . "</>\n");
                    }
                }

                $counter++;
            }

            $output->writeln("  - <info>Mailbox refreshed successfully!</info>");
        }

        return;
    }

    public function parseInboundEmail($message, $output)
    {
        $curlHandler = curl_init();
        
        curl_setopt($curlHandler, CURLOPT_HEADER, 0);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlHandler, CURLOPT_POST, 1);
        curl_setopt($curlHandler, CURLOPT_URL, $this->endpoint);
        curl_setopt($curlHandler, CURLOPT_POSTFIELDS, http_build_query(['email' => $message]));

        $curlResponse = curl_exec($curlHandler);
        
        $response = json_decode($curlResponse, true);
        $responseCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
        $responseErrorMessage = null;

        if (curl_errno($curlHandler) || $responseCode != 200) {
            $responseErrorMessage = curl_error($curlHandler);
        }

        curl_close($curlHandler);

        return [$response, $responseCode, $responseErrorMessage];
    }

    public function parseOutlookInboundEmail($detailedMessage, $output)
    {
        $curlHandler = curl_init();
        
        curl_setopt($curlHandler, CURLOPT_HEADER, 0);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlHandler, CURLOPT_POST, 1);
        curl_setopt($curlHandler, CURLOPT_URL, $this->outlookEndpoint);
        curl_setopt($curlHandler, CURLOPT_POSTFIELDS, http_build_query(['email' => $detailedMessage]));

        $curlResponse = curl_exec($curlHandler);
        
        $response = json_decode($curlResponse, true);
        $responseCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
        $responseErrorMessage = null;

        if (curl_errno($curlHandler) || $responseCode != 200) {
            $responseErrorMessage = curl_error($curlHandler);
        }

        curl_close($curlHandler);

        return [$response, $responseCode, $responseErrorMessage];
    }

    protected function isSecureConnectionAvailable()
    {
        $headers = [CURLOPT_NOBODY => true, CURLOPT_HEADER => false];
        $curlHandler = curl_init('https://' . $this->container->getParameter('uvdesk.site_url'));

        curl_setopt_array($curlHandler, $headers);
        curl_exec($curlHandler);

        $isSecureRequestAvailable = curl_errno($curlHandler) === 0 ? true : false;
        curl_close($curlHandler);

        return $isSecureRequestAvailable;
    }
}
