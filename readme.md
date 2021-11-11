# ETP Load Balancing for PaperCut using Postfix

This script is designed to allow you to load balance a single Email-To-Print address against multiple print queues using the PaperCut print solution and the open-source Postfix MTA.

PaperCut, by default, allows the administrator to configure a specific TO address for each print queue. This is designed for a mailbox that has multiple aliases against it, as the solution can only connect to a single IMAP mailbox.

When load balancing is required, for example, when a large number of Email-To-Print jobs are expected and only a single email can be provided, this feature can be utilised to balance the incoming jobs against multiple print queues/print servers.

In order to do this, we must intercept the mail handling and dynamically rewrite the TO header before the mail is delivered to the IMAP box that PaperCut is watching. This is achieved by the PHP script enclosed, which is a simple script that accepts a message via STDIN and queues the message in the desired users spool file.

## Installation Instructions
Ensure the system as PHP 7 or greater installed. No other dependencies are required (other than the Postfix MTA itself). This README will not cover the configuration of Postfix, it is assumed that the MTA has already been configured and is accepting mail for the desired domain.

> **Note:** The following instructions will redirect an **Entire Domain** to the load balancing script. This is intended when a specific domain is being used for Email to Print. If you wish to only redirect a specific mailbox, you will need to adjust the Postfix configuration appropriately.

- Create a local user that will hold the mailbox for PaperCut to connect to. In this example, we will use 'etpmailbox':
`adduser etpmailbox`
- Place the PHP script in the users home directory. In this example, the path to the script will be:
`/home/etpmailbox/handler.php`
- Modify `/etc/postfix/master.cf` to enable our script as a mail handler:
```
# ====================================================================
# Interfaces to non-Postfix software. Be sure to examine the manual
# pages of the non-Postfix software to find out what options it wants.
#
# Many of the following services use the Postfix pipe(8) delivery
# agent.  See the pipe(8) man page for information about ${recipient}
# and other message envelope options.
# ====================================================================
#
# maildrop. See the Postfix MAILDROP_README file for details.
# Also specify in main.cf: maildrop_destination_recipient_limit=1
#
emailprint unix  -       n       n       -       -       pipe
  flags=FR user=etpmailbox argv=/home/etpmailbox/handler.php ${sender} ${size} ${recipient}
```
- Modify `/etc/postfix/main.cf` as per the comment from master.cf above:
`emailprint_destination_recipient_limit=1`
- Modify `/etc/postfix/transport` to redirect email from our ETP domain to our new mail handler:
`your-etp-domain.co.uk		emailprint`
- Generate/Regenerate transport.db:
`postmap /etc/postfix/transport`
- Restart the Postfix service to reload the configuration changes:
`systemctl restart postfix`
- Add the Email-To-Print user to the mail group so it can append to the spool files:
`adduser etpmailbox mail`

