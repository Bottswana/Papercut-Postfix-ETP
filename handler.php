#!/usr/bin/php
<?php

/**********************************************************************************************************
 * PaperCut Email-To-Print Handler for Postfix V1.1                                                       *
 * Recieve email from a specific TO address and                                                           *
 * Rewrite TO header, storing in a local mailbox                                                          *
 * james@pylonone.com                                                                                     *
 * Based on: https://thecodingmachine.io/triggering-a-php-script-when-your-postfix-server-receives-a-mail *
 **********************************************************************************************************/

/// CONFIG SECTION \\\

// Should we output the last received email to a file
$debug_file = false;
$debug_output_file = "/tmp/lastemail";
$log_file = "/var/log/emailtoprint.log";

// Accepted TO addresses
// All other emails will be dropped
$accepted_to = array(
    "etp@your-etp-domain.co.uk",
    "etp@your-etp-domain.com"
);

// Target TO addresses
// We will round-robin over these to cause load-balancing across the print queues
$desired_to = array(
    "etp-mailer1@your-etp-domain.co.uk",
    "etp-mailer2@your-etp-domain.co.uk",
    "etp-mailer3@your-etp-domain.co.uk",
    "etp-mailer4@your-etp-domain.co.uk"
);

// Target Local Mailbox
// The path to the local mailbox file we will deliver this to
// This is the mailbox file the IMAP/POP server will serve to PaperCut
$mailbox = "/var/mail/etpmailbox";

/// SCRIPT \\\

// Error Handling
function error_handler($errno, $errstr, $errfile, $errline)
{

    // Log the error in a panic file
    try
    {
        $log_file = fopen("/var/log/emailtoprint.error", "w");
        fwrite($log_file, $errstr);
        fclose($log_file);
    }
    catch( Exception $e )
    {

    }

    // Return an error the MTA can handle
    echo "The MTA was unable to deliver your message. Please contact postmaster@domain.com";
    die();
}

set_error_handler("error_handler");
ini_set('display_errors', 0);

// Open the log file
$log_file = fopen($log_file, "a");
fwrite($log_file, "---\nExecuting PaperCut Email-To-Print script at ".date("Y-m-d H:i:s")."\n");

// Read the email from STDIN
fwrite($log_file, "Reading email from STDIN\n");
$email = "";
$email_file = fopen("php://stdin", "r");
while( !feof($email_file) )
{
    $line = fread($email_file, 1024);
    $email .= $line;
}

fclose($email_file);
fwrite($log_file, "Email Data read: ".strlen($email)."\n");
if( $debug_file === true && strlen($debug_output_file) >= 1 )
{
    // Write the debug file if enabled
    $debug_output = fopen($debug_output_file, "w");
    fwrite($debug_output, $email);
    fclose($debug_output);
}

// Put sender addres in logfile
$from_header_regex_name = preg_match('/^From:.*<(.*@.*)>$/im', $email, $match_from_email);
$from_header_regex = preg_match('/^From:[\s]*([^<@]*[^\n]*)$/im', $email, $match_from_email2);
if( $from_header_regex_name === 1 ) fwrite($log_file, "Sender Address: ".$match_from_email[1]."\n");
else if( $from_header_regex === 1 ) fwrite($log_file, "Sender Address: ".$match_from_email2[1]."\n");
else fwrite($log_file, "Could not determine sender address\n");

// Check the TO is one of the valid emails
fwrite($log_file, "Validating TO Header against configuration\n");
$to_header_regex_name = preg_match('/^To:.*<(.*@.*)>$/im', $email, $match_name);
$to_header_regex = preg_match('/^To:[\s]*([^<@]*[^\n]*)$/im', $email, $match_email);
if( $to_header_regex_name !== 1 && $to_header_regex !== 1 )
{
    fwrite($log_file, "Email rejected - Unable to find the TO header in the email!\n");
    fclose($log_file);
    exit();
}

$to_header_addresses = array();
if( count($match_name) == 2 )
{
    foreach( explode(",", $match_name[1]) as $key => $value )
    {
        $valid_email = strtolower(filter_var($value, FILTER_VALIDATE_EMAIL));
        if( !in_array($valid_email, $to_header_addresses) )
        {
            $to_header_addresses[] = $valid_email;
        }
    }
}
if( count($match_email) == 2 )
{
    foreach( explode(",", $match_email[1]) as $key => $value )
    {
        $valid_email = strtolower(filter_var($value, FILTER_VALIDATE_EMAIL));
        if( !in_array($valid_email, $to_header_addresses) )
        {
            $to_header_addresses[] = $valid_email;
        }
    }
}

fwrite($log_file, "Emails found in To header:\n");
foreach( $to_header_addresses as $key => $value )
{
    fwrite($log_file, $value."\n");
}

// Validate against the configuration
$found_valid_email = false;
foreach( $to_header_addresses as $key => $value )
{
    if( in_array($value, $accepted_to) === true )
    {
        $found_valid_email = true;
        break;
    }
}

if( $found_valid_email === true )
{
    fwrite($log_file, "Email accepted - At least one TO address contained within configuration\n");
}
else
{
    fwrite($log_file, "Email rejected - None of the TO addresses exist in the configuration\n");
    file_put_contents("/tmp/rejectedmail.log", $email, FILE_APPEND);
    fclose($log_file);
    exit();
}

// Get the next round-robin entry
$tmp_file = fopen("/tmp/emailtoprint.tmp", "w+");
$current_position = intval(fread($tmp_file, 10));
fwrite($log_file, "Current position is: ".$current_position."\n");

// Update the file with the next entry
$new_position = ($current_position >= (count($desired_to)-1)) ? 1 : $current_position + 1;
fwrite($log_file, "New position is: ".$new_position."\n");
ftruncate($tmp_file, 0);
rewind($tmp_file);

fwrite($tmp_file, $new_position);
fclose($tmp_file);

// Update the To header
$this_address = $desired_to[$current_position];
fwrite($log_file, "Replacing TO header with address: ".$this_address."\n");

$new_header = sprintf("To: %s\n", $this_address);
fwrite($log_file, "Replacement header: ".$new_header."\n");
$email = preg_replace('/To:(.*)\n/im', $new_header, $email);

// Add email to user spool file
fwrite($log_file, "Storing mail in target spoolfile: ".$mailbox."\n");
file_put_contents($mailbox, $email, FILE_APPEND);

// Close log file
fwrite($log_file, "---\nLog closed at ".date("Y-m-d H:i:s")."\n");
fclose($log_file);

?>